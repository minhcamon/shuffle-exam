<?php

namespace App\Services;

use Exception;
use RuntimeException;
use ZipArchive;
use DOMDocument;
use DOMXPath;
use Illuminate\Support\Facades\Log;

class QuizShufflerService
{
    // ================================================================
    // PUBLIC ENTRY POINT
    // ================================================================

    public function shuffle(string $filePath, int $copies): string
    {
        Log::info("===== BẮT ĐẦU TẠO {$copies} MÃ ĐỀ ĐA CHIỀU =====");

        // BƯỚC 1: Chuẩn bị workspace và file ZIP tổng
        [$workspace, $xmlBackupPath, $outputZipPath, $finalZip] = $this->prepareWorkspace($filePath);

        $generatedFiles = [];

        // BƯỚC 2: Vòng lặp sinh N mã đề
        for ($i = 1; $i <= $copies; $i++) {
            $maDe = str_pad(rand(100, 999), 3, '0', STR_PAD_LEFT);
            Log::info("--- Đang sinh Mã đề: {$maDe} (lần {$i}/{$copies}) ---");

            // 2a. Reset XML về trạng thái gốc
            $xmlOriginalPath = $workspace . '/word/document.xml';
            copy($xmlBackupPath, $xmlOriginalPath);

            // 2b. Parse cấu trúc câu hỏi từ XML
            [$dom, $body, $sections] = $this->parseDocumentSections($xmlOriginalPath);

            // 2c. Xáo trộn và ráp lại vào DOM
            $this->shuffleAndRebuild($dom, $body, $sections);

            // 2d. Lưu XML và đóng gói thành .docx rồi nạp vào ZIP tổng
            $tempDocx = $this->packExamCopy($dom, $xmlOriginalPath, $workspace, $maDe);
            $finalZip->addFile($tempDocx, "De_Thi_Ma_{$maDe}.docx");
            $generatedFiles[] = $tempDocx;
        }

        // BƯỚC 3: Xuất xưởng & dọn rác
        $finalZip->close();
        $this->cleanup($workspace, $xmlBackupPath, $generatedFiles);

        Log::info("===== HOÀN THÀNH TẠO BỘ ĐỀ ZIP =====");
        return $outputZipPath;
    }

    // ================================================================
    // BƯỚC 1: CHUẨN BỊ WORKSPACE
    // ================================================================

    /**
     * Tạo workspace tạm, giải nén file .docx gốc, backup XML, tạo ZIP tổng đầu ra.
     * @return array [workspace, xmlBackupPath, outputZipPath, ZipArchive $finalZip]
     */
    private function prepareWorkspace(string $filePath): array
    {
        $workspace = storage_path('app/temp_workspace_' . uniqid());
        if (!is_dir($workspace)) {
            mkdir($workspace, 0777, true);
        }

        // Tạo ZIP tổng chứa tất cả mã đề
        $outputZipPath = storage_path('app/temp_uploads/Bo_De_Thi_Tron_' . time() . '.zip');
        $finalZip = new ZipArchive();
        if ($finalZip->open($outputZipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException("Không thể tạo file ZIP tổng.");
        }

        // Giải nén file .docx gốc vào workspace (docx thực chất là zip)
        $templateZipPath = $workspace . '/template.zip';
        if (!copy($filePath, $templateZipPath)) {
            throw new RuntimeException("Không thể copy file gốc vào workspace.");
        }
        $zip = new ZipArchive();
        if ($zip->open($templateZipPath) === true) {
            $zip->extractTo($workspace);
            $zip->close();
            unlink($templateZipPath);
        } else {
            throw new RuntimeException("Không thể mở file .docx gốc như một ZIP.");
        }

        // Backup XML gốc để reset sau mỗi vòng lặp sinh mã đề
        $xmlOriginalPath = $workspace . '/word/document.xml';
        $xmlBackupPath   = storage_path('app/temp_uploads/document_backup_' . uniqid() . '.xml');
        copy($xmlOriginalPath, $xmlBackupPath);

        Log::info("BƯỚC 1: Workspace sẵn sàng tại {$workspace}");

        return [$workspace, $xmlBackupPath, $outputZipPath, $finalZip];
    }

    // ================================================================
    // BƯỚC 2a: PARSE CẤU TRÚC CÂU HỎI TỪ XML
    // ================================================================

    private function parseDocumentSections(string $xmlPath): array
    {
        $dom = new DOMDocument();
        libxml_use_internal_errors(true);
        $dom->load($xmlPath);
        libxml_clear_errors();

        $xpath = new DOMXPath($dom);
        $xpath->registerNamespace('w', 'http://schemas.openxmlformats.org/wordprocessingml/2006/main');
        $body = $dom->getElementsByTagName('body')->item(0);

        $sections        = [];
        $currentSection  = 'default';
        $currentQuestion = null;
        $nodesToRemove   = [];

        $childNodes = [];
        foreach ($body->childNodes as $node) {
            $childNodes[] = $node;
        }

        foreach ($childNodes as $node) {
            $text = $node->textContent;
            
            // ====================================================
            // [VÁ LỖI KHOẢNG TRẮNG KHỔNG LỒ]
            // ====================================================
            if (trim($text) === '') {
                // Chỉ xử lý các dòng Enter (w:p trống)
                if ($node->nodeName === 'w:p') {
                    if ($currentQuestion !== null) {
                        // 1. Dòng trắng ở đuôi câu hỏi -> Dính nó vào câu hỏi
                        $sections[$currentSection]['questions'][$currentQuestion]['question_nodes'][] = $node;
                        $nodesToRemove[] = $node;
                    } elseif ($currentSection !== 'default') {
                        // 2. Dòng trắng nằm giữa "Phần I" và "Câu 1" -> Dính nó vào Header
                        $sections[$currentSection]['header_nodes'][] = $node;
                        $nodesToRemove[] = $node;
                    }
                    // 3. Nếu là dòng trắng ở khu Tiêu đề gốc (Chưa vào Phần nào), cứ để nguyên trong DOM
                }
                continue;
            }

            if (preg_match('/^\s*Phần\s+([I,V,X,1-9]+)/i', $text, $matches)) {
                $currentSection  = trim($matches[0]);
                $currentQuestion = null;
                $sections[$currentSection] = ['header_nodes' => [$node], 'questions' => []];
                $nodesToRemove[] = $node;
                continue;
            }

            if (!isset($sections[$currentSection])) {
                $sections[$currentSection] = ['header_nodes' => [], 'questions' => []];
            }

            if (preg_match('/^\s*Câu\s+(\d+)\s*[\.\\:]/i', $text, $matches)) {
                $currentQuestion = 'Câu ' . $matches[1];
                $sections[$currentSection]['questions'][$currentQuestion] = [
                    'question_nodes'        => [$node],
                    'answers'               => [],
                    'table_answers'         => [],
                    'answer_line_node'      => null,
                    'correct_key'           => null, 
                    'tf_answer_header_node' => null,
                    'tf_key_nodes'          => [],
                    'is_lower_case'         => false,
                ];
                $nodesToRemove[] = $node;
                continue;
            }

            if ($currentQuestion !== null) {
                if (preg_match('/^\s*Đáp\s+án\s*:\s*([A-Da-dĐS])\s*$/iu', trim($text), $matches)) {
                    $sections[$currentSection]['questions'][$currentQuestion]['answer_line_node'] = $node;
                    $sections[$currentSection]['questions'][$currentQuestion]['correct_key']      = strtoupper($matches[1]);
                    $nodesToRemove[] = $node;
                    continue;
                }

                if (preg_match('/^\s*Đáp\s+án\s*:\s*$/iu', trim($text))) {
                    $sections[$currentSection]['questions'][$currentQuestion]['tf_answer_header_node'] = $node;
                    $nodesToRemove[] = $node;
                    continue;
                }

                if (preg_match('/^\s*([A-Da-d])\s*[\.\)]\s*(Đúng|Sai)/iu', $text, $matches)) {
                    $tfKey = strtoupper($matches[1]);
                    $sections[$currentSection]['questions'][$currentQuestion]['tf_key_nodes'][$tfKey] = [
                        'node'  => $node,
                        'value' => $matches[2]
                    ];
                    $nodesToRemove[] = $node;
                    continue;
                }

                $isAnswerNode = false;

                if ($node->nodeName === 'w:p' && preg_match('/^\s*([A-Da-d])\s*([\.\\)])\s*(.*)/u', $text, $matches)) {
                    $ansKey = strtoupper($matches[1]);
                    if (!isset($sections[$currentSection]['questions'][$currentQuestion]['answers'][$ansKey])) {
                        $sections[$currentSection]['questions'][$currentQuestion]['answers'][$ansKey]       = $node;
                        $sections[$currentSection]['questions'][$currentQuestion]['is_lower_case']          = ctype_lower($matches[1]);
                        $nodesToRemove[] = $node;
                        $isAnswerNode    = true;
                    }
                }
                elseif ($node->nodeName === 'w:tbl') {
                    $answerCells = [];
                    $tcs = $xpath->query('.//w:tc', $node);
                    foreach ($tcs as $tc) {
                        $tcText = trim($tc->textContent);
                        if (preg_match('/^([A-Da-d])\s*([\.\\)])\s*/u', $tcText, $matches)) {
                            $ansKey               = strtoupper($matches[1]);
                            $answerCells[$ansKey] = $tc;
                            $sections[$currentSection]['questions'][$currentQuestion]['is_lower_case'] = ctype_lower($matches[1]);
                        }
                    }
                    if (count($answerCells) >= 2) {
                        $sections[$currentSection]['questions'][$currentQuestion]['table_answers'] = [
                            'table_node' => $node,
                            'cells'      => $answerCells,
                        ];
                        $sections[$currentSection]['questions'][$currentQuestion]['question_nodes'][] = $node;
                        $nodesToRemove[] = $node;
                        continue;
                    }
                }

                if (!$isAnswerNode) {
                    $sections[$currentSection]['questions'][$currentQuestion]['question_nodes'][] = $node;
                    $nodesToRemove[] = $node;
                }
            } elseif ($currentSection !== 'default') {
                $sections[$currentSection]['header_nodes'][] = $node;
                $nodesToRemove[] = $node;
            }
        }

        foreach ($nodesToRemove as $n) {
            if ($n->parentNode) $n->parentNode->removeChild($n);
        }

        return [$dom, $body, $sections];
    }

    // ================================================================
    // BƯỚC 2b: XÁO TRỘN VÀ RÁP LẠI VÀO DOM
    // ================================================================

    private function shuffleAndRebuild(DOMDocument $dom, $body, array $sections): void
    {
        $xpath = new DOMXPath($dom);
        $xpath->registerNamespace('w', 'http://schemas.openxmlformats.org/wordprocessingml/2006/main');

        foreach ($sections as $sectionName => $sectionData) {
            foreach ($sectionData['header_nodes'] as $hn) {
                $body->appendChild($hn);
            }

            $questions    = $sectionData['questions'];
            $questionKeys = array_keys($questions);
            shuffle($questionKeys); // XÁO VỊ TRÍ CÂU HỎI

            $newQuestionIndex = 1;

            foreach ($questionKeys as $qKey) {
                $qData = $questions[$qKey];

                // Cập nhật số thứ tự câu hỏi
                $firstQNode = $qData['question_nodes'][0];
                $this->replacePrefixInNode($firstQNode, '/^\s*Câu\s+\d+\s*([\.\\:])/iu', "Câu {$newQuestionIndex}$1");

                // Gắn nội dung đề bài (và các câu phát biểu nằm trong bảng)
                foreach ($qData['question_nodes'] as $qn) {
                    $body->appendChild($qn);
                }

                // Xáo và lấy lại bản đồ vị trí (Tracking Map)
                $shuffleResult   = $this->shuffleAnswers($dom, $xpath, $body, $qData);
                $newCorrectKey   = $shuffleResult['newCorrectKey'];
                $shuffledKeysMap = $shuffleResult['shuffledKeysMap']; // VD: [0 => 'C', 1 => 'A', 2 => 'D', 3 => 'B']

                // --- XỬ LÝ ĐÁP ÁN CHO PHẦN I ---
                if ($qData['answer_line_node']) {
                    if ($newCorrectKey) {
                        $this->replacePrefixInNode(
                            $qData['answer_line_node'],
                            '/^\s*Đáp\s+án\s*:\s*[A-Da-dĐS]/iu',
                            "Đáp án: {$newCorrectKey}"
                        );
                    }
                    $body->appendChild($qData['answer_line_node']);
                }

                // --- XỬ LÝ ĐÁP ÁN ĐÚNG/SAI CHO PHẦN II ---
                if (isset($qData['tf_answer_header_node'])) {
                    $body->appendChild($qData['tf_answer_header_node']);
                }

                if (!empty($qData['tf_key_nodes'])) {
                    if (!empty($shuffledKeysMap)) {
                        $visualKeys = $qData['is_lower_case'] ? range('a', 'z') : range('A', 'Z');
                        // Duyệt qua bản đồ xáo trộn để cập nhật lại nhãn a, b, c, d cho khớp với Đúng/Sai cũ
                        foreach ($shuffledKeysMap as $newIndex => $oldKey) {
                            $newLetter = $visualKeys[$newIndex];
                            if (isset($qData['tf_key_nodes'][$oldKey])) {
                                $tfData   = $qData['tf_key_nodes'][$oldKey];
                                $tfNode   = $tfData['node'];
                                $oldValue = $tfData['value']; // Chữ "Đúng" hoặc "Sai" nguyên gốc
                                
                                // Ghi đè thành nhãn mới. VD: Câu C cũ bị đẩy lên đầu -> Đổi nhãn thành "a. Sai"
                                $this->replacePrefixInNode($tfNode, '/^\s*[A-Da-d]\s*[\.\)]\s*(Đúng|Sai)/iu', "{$newLetter}. {$oldValue}");
                                $body->appendChild($tfNode);
                            }
                        }
                    } else {
                        // Nếu câu này bị khóa form (không xáo), thì in ra y nguyên
                        ksort($qData['tf_key_nodes']); 
                        foreach ($qData['tf_key_nodes'] as $tfData) {
                            $body->appendChild($tfData['node']);
                        }
                    }
                }

                $newQuestionIndex++;
            }
        }
    }

    private function shuffleAnswers(DOMDocument $dom, DOMXPath $xpath, $body, array $qData): array
    {
        $answers         = $qData['answers'];
        $tableAnswers    = $qData['table_answers'];
        $visualKeys      = $qData['is_lower_case'] ? range('a', 'z') : range('A', 'Z');
        $newCorrectKey   = $qData['correct_key'];
        $shuffledKeysMap = []; 

        $hasInlineAnswers = false;
        foreach ($answers as $ansNode) {
            $textBody = preg_replace('/^\s*[A-Da-d]\s*[\.\\)]/u', '', $ansNode->textContent);
            if (preg_match('/\s+[A-Da-d]\s*[\.\\)]/u', $textBody)) {
                $hasInlineAnswers = true;
                break;
            }
        }

        $canShuffleAnswers = !$hasInlineAnswers 
            && (count($answers) >= 2 || (!empty($tableAnswers) && count($tableAnswers['cells']) >= 2));

        if ($canShuffleAnswers && !empty($answers)) {
            $ansKeys = array_keys($answers);
            shuffle($ansKeys);
            $shuffledKeysMap = $ansKeys; // Lưu vết
            
            $ansIndex = 0;
            foreach ($ansKeys as $shuffledKey) {
                $ansNode   = $answers[$shuffledKey];
                $newLetter = $visualKeys[$ansIndex];
                
                $this->replacePrefixInNode($ansNode, '/^\s*[A-Da-d]\s*([\.\\)])/u', "{$newLetter}$1");
                $body->appendChild($ansNode);
                
                if ($shuffledKey === $qData['correct_key']) {
                    $newCorrectKey = strtoupper($newLetter);
                }
                $ansIndex++;
            }
        } elseif ($canShuffleAnswers && !empty($tableAnswers)) {
            $cells           = $tableAnswers['cells'];
            $ansKeys         = array_keys($cells);
            $originalAnsKeys = $ansKeys;
            sort($originalAnsKeys);
            
            shuffle($ansKeys);
            $shuffledKeysMap = $ansKeys; // Lưu vết

            $clonedContents = [];
            foreach ($ansKeys as $key) {
                $clonedContents[$key] = [];
                foreach ($cells[$key]->childNodes as $child) {
                    $clonedContents[$key][] = $child->cloneNode(true);
                }
            }

            $ansIndex = 0;
            foreach ($originalAnsKeys as $origKey) {
                $targetCell  = $cells[$origKey];
                $shuffledKey = $ansKeys[$ansIndex];
                $newLetter   = $visualKeys[$ansIndex];

                while ($targetCell->hasChildNodes()) {
                    $targetCell->removeChild($targetCell->firstChild);
                }
                foreach ($clonedContents[$shuffledKey] as $clonedNode) {
                    $targetCell->appendChild($clonedNode);
                }

                $firstP = $xpath->query('.//w:p', $targetCell)->item(0);
                if ($firstP) {
                    $this->replacePrefixInNode($firstP, '/^\s*[A-Da-d]\s*([\.\\)])/u', "{$newLetter}$1");
                }

                if ($shuffledKey === $qData['correct_key']) {
                    $newCorrectKey = strtoupper($newLetter);
                }
                $ansIndex++;
            }
        } else {
            // Không xáo trộn (Chốt chặn form ngang)
            $ansKeys = array_keys($answers);
            foreach ($ansKeys as $ansKey) {
                $body->appendChild($answers[$ansKey]);
                $shuffledKeysMap[] = $ansKey;
            }
        }

        return [
            'newCorrectKey'   => $newCorrectKey,
            'shuffledKeysMap' => $shuffledKeysMap
        ];
    }


    // ================================================================
    // BƯỚC 2c: ĐÓNG GÓI MÃ ĐỀ THÀNH .DOCX
    // ================================================================

    /**
     * Lưu DOM ra XML, zip toàn bộ workspace thành file .docx tạm, trả về đường dẫn.
     */
    private function packExamCopy(DOMDocument $dom, string $xmlPath, string $workspace, string $maDe): string
    {
        $dom->save($xmlPath);

        $tempDocx = storage_path('app/temp_uploads/De_Thi_Ma_' . $maDe . '_' . uniqid() . '.docx');
        $this->zipDirectory($workspace, $tempDocx);

        Log::info("BƯỚC 2c: Đóng gói xong mã đề {$maDe} → {$tempDocx}");

        return $tempDocx;
    }

    // ================================================================
    // BƯỚC 3: DỌN RÁC
    // ================================================================

    /**
     * Xóa workspace tạm, file backup XML và các file .docx lẻ đã nạp vào ZIP tổng.
     */
    private function cleanup(string $workspace, string $xmlBackupPath, array $generatedFiles): void
    {
        $this->deleteDirectory($workspace);
        @unlink($xmlBackupPath);
        foreach ($generatedFiles as $f) {
            @unlink($f);
        }

        Log::info("BƯỚC 3: Dọn rác hoàn tất.");
    }

    // ================================================================
    // HELPERS
    // ================================================================

    /**
     * Thay thế prefix của văn bản trong node XML, giữ nguyên định dạng các run con.
     */
    private function replacePrefixInNode($node, $pattern, $replacement): void
    {
        $fullText = $node->textContent;
        if (preg_match($pattern, $fullText, $matches)) {
            $matchText = $matches[0];
            $newPrefix = preg_replace($pattern, $replacement, $matchText);

            $charsToRemove = mb_strlen($matchText, 'UTF-8');
            $texts  = $node->getElementsByTagName('t');
            $isFirst = true;

            foreach ($texts as $t) {
                if ($charsToRemove <= 0) break;

                $tText = $t->nodeValue;
                $tLen  = mb_strlen($tText, 'UTF-8');

                if ($isFirst) {
                    if ($tLen <= $charsToRemove) {
                        $t->nodeValue  = $newPrefix;
                        $charsToRemove -= $tLen;
                    } else {
                        $t->nodeValue  = $newPrefix . mb_substr($tText, $charsToRemove, null, 'UTF-8');
                        $charsToRemove = 0;
                    }
                    $isFirst = false;
                } else {
                    if ($tLen <= $charsToRemove) {
                        $t->nodeValue  = '';
                        $charsToRemove -= $tLen;
                    } else {
                        $t->nodeValue  = mb_substr($tText, $charsToRemove, null, 'UTF-8');
                        $charsToRemove = 0;
                    }
                }
            }
        }
    }

    private function zipDirectory(string $sourcePath, string $outZipPath): void
    {
        $zip = new ZipArchive();
        if ($zip->open($outZipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) return;
        $files = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($sourcePath),
            \RecursiveIteratorIterator::LEAVES_ONLY
        );
        foreach ($files as $name => $file) {
            if (!$file->isDir()) {
                $filePath     = $file->getRealPath();
                $relativePath = substr($filePath, strlen($sourcePath) + 1);
                $relativePath = str_replace('\\', '/', $relativePath);
                $zip->addFile($filePath, $relativePath);
            }
        }
        $zip->close();
    }

    private function deleteDirectory(string $dirPath): void
    {
        if (!is_dir($dirPath)) return;
        $files = array_diff(scandir($dirPath), ['.', '..']);
        foreach ($files as $file) {
            $path = "$dirPath/$file";
            is_dir($path) ? $this->deleteDirectory($path) : unlink($path);
        }
        rmdir($dirPath);
    }
}