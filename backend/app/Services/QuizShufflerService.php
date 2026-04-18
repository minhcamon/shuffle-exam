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
    // Biến lưu trữ đáp án đa tầng: [Mã đề][Phần][Câu] = Đáp án
    private array $answerMap = []; 

    // ================================================================
    // NHẠC TRƯỞNG: PUBLIC ENTRY POINT
    // ================================================================

    public function shuffle(string $filePath, int $copies, array $customCodes = [], string $originalFileName = 'De_Thi'): string
    {
        Log::info("===== BẮT ĐẦU TẠO {$copies} MÃ ĐỀ (BẢN V3+ ULTIMATE) =====");
        $this->answerMap = [];
        $generatedFiles = [];

        $baseName = pathinfo($originalFileName, PATHINFO_FILENAME);
        [$workspace, $xmlBackupPath, $outputZipPath, $finalZip] = $this->prepareWorkspace($filePath);

        for ($i = 0; $i < $copies; $i++) {
            $maDe = !empty($customCodes[$i]) ? (string) $customCodes[$i] : str_pad(rand(100, 999), 3, '0', STR_PAD_LEFT);
            $customCodes[$i] = $maDe; // Đảm bảo mảng mã đề được cập nhật để in CSV

            Log::info("--- Đang sinh Mã đề: {$maDe} ---");

            $xmlOriginalPath = $workspace . '/word/document.xml';
            copy($xmlBackupPath, $xmlOriginalPath);

            // Bước 1: Đọc và nhận diện (Parse)
            [$dom, $body, $sections] = $this->parseDocumentSections($xmlOriginalPath);

            // Bước 2: Thay mã đề
            $this->updateExamCodeInDoc($dom, $maDe);
            $this->updateExamCodeInHeadersAndFooters($workspace, $maDe);

            // Bước 3: Tráo và Ráp (Rebuild)
            $this->shuffleAndRebuild($dom, $body, $sections, $maDe);

            // Bước 4: Đóng gói Word
            $tempDocx = $this->packExamCopy($dom, $xmlOriginalPath, $workspace, $maDe);
            $finalZip->addFile($tempDocx, $baseName . "_MaDe_" . $maDe . ".docx");
            $generatedFiles[] = $tempDocx;
        }

        // Bước 5: Xuất Excel (CSV)
        $csvPath = $this->generateAnswerKeyCSV($customCodes, $originalFileName);
        $finalZip->addFile($csvPath, "DapAn_TongHop_" . $baseName . ".csv");

        $finalZip->close();
        $this->cleanup($workspace, $xmlBackupPath, array_merge($generatedFiles, [$csvPath]));

        return $outputZipPath;
    }

    // ================================================================
    // TIỆN ÍCH 1: MẮT THẦN (SOII GẠCH CHÂN) VÀ CỤC TẨY
    // ================================================================

    private function isNodeUnderlined($node, $dom): bool 
    {
        if (!($node instanceof \DOMElement)) return false;
        $xpath = new DOMXPath($dom);
        $xpath->registerNamespace('w', 'http://schemas.openxmlformats.org/wordprocessingml/2006/main');
        
        // Quét 1: Thẻ Underline tiêu chuẩn
        $underlines = $xpath->query('.//w:u[not(@w:val="none")]', $node);
        if ($underlines->length > 0) return true;

        // Quét 2: Viền dưới (Border Bottom - Rất hay bị nhầm là underline)
        $borders = $xpath->query('.//w:pBdr/w:bottom[not(@w:val="none")] | .//w:bdr[not(@w:val="none")]', $node);
        if ($borders->length > 0) return true;

        // Quét 3: Highlight màu (Nhiều GV dùng highlight thay vì gạch chân)
        $highlights = $xpath->query('.//w:highlight[not(@w:val="none")]', $node);
        if ($highlights->length > 0) return true;

        // Quét 4: Style Character có chứa chữ Underline (Trường hợp copy từ web)
        $styles = $xpath->query('.//w:rStyle', $node);
        foreach ($styles as $style) {
            if (stripos($style->getAttribute('w:val'), 'underline') !== false) {
                return true;
            }
        }
        
        return false;
    }

    private function stripUnderlineFromNode($node): void 
    {
        if (!($node instanceof \DOMElement)) return;
        $xpath = new DOMXPath($node->ownerDocument);
        $xpath->registerNamespace('w', 'http://schemas.openxmlformats.org/wordprocessingml/2006/main');
        
        // Tìm tất cả các thẻ tạo ra dòng gạch/màu nhấn/viền
        $query = './/w:u | .//w:pBdr/w:bottom | .//w:bdr | .//w:highlight';
        $elements = $xpath->query($query, $node);
        
        $remove = [];
        foreach ($elements as $el) { $remove[] = $el; }
        
        // Dọn dẹp cả Style ẩn
        $styles = $xpath->query('.//w:rStyle', $node);
        foreach ($styles as $style) {
            if (stripos($style->getAttribute('w:val'), 'underline') !== false) {
                $remove[] = $style;
            }
        }
        
        // Tiến hành xóa
        foreach ($remove as $el) { 
            if ($el->parentNode) {
                $el->parentNode->removeChild($el); 
            }
        }
    }

    // ================================================================
    // TIỆN ÍCH 2: THAY MÃ ĐỀ (DOCUMENT & FOOTERS)
    // ================================================================

    private function updateExamCodeInDoc(DOMDocument $dom, string $newCode): void {
        $xpath = new DOMXPath($dom);
        $xpath->registerNamespace('w', 'http://schemas.openxmlformats.org/wordprocessingml/2006/main');
        $nodes = $xpath->query("//w:t[contains(., 'Mã đề')]");
        foreach ($nodes as $node) {
            $node->nodeValue = preg_replace('/(Mã đề\s*[:\-])\s*.*/u', "$1 {$newCode}", $node->nodeValue);
        }
    }

    private function updateExamCodeInHeadersAndFooters(string $workspace, string $newCode): void 
    {
        $targetFiles = array_merge(glob($workspace . '/word/header*.xml') ?: [], glob($workspace . '/word/footer*.xml') ?: []);
        foreach ($targetFiles as $file) {
            $dom = new DOMDocument();
            libxml_use_internal_errors(true);
            $dom->load($file);
            libxml_clear_errors();
            $xpath = new DOMXPath($dom);
            $xpath->registerNamespace('w', 'http://schemas.openxmlformats.org/wordprocessingml/2006/main');
            
            $nodes = $xpath->query("//w:t[contains(., 'Mã đề')]");
            $isModified = false;
            foreach ($nodes as $node) {
                $node->nodeValue = preg_replace('/(Mã đề\s*[:\-])\s*.*/u', "$1 {$newCode}", $node->nodeValue);
                $isModified = true;
            }
            if ($isModified) $dom->save($file);
        }
    }

    // ================================================================
    // BƯỚC 1: ĐỌC VÀ NHẬN DIỆN (PARSER V3 + SOII GẠCH CHÂN)
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

        $sections = [];
        $currentSection = 'default';
        $currentQuestion = null;
        $nodesToRemove = [];

        $nodes = [];
        foreach ($body->childNodes as $node) { $nodes[] = $node; }

        foreach ($nodes as $node) {
            $text = $node->textContent;
            
            // Xóa khoảng trắng thừa giữa các phần
            if (trim($text) === '') {
                if ($node->nodeName === 'w:p') {
                    if ($currentQuestion !== null) {
                        $sections[$currentSection]['questions'][$currentQuestion]['question_nodes'][] = $node;
                        $nodesToRemove[] = $node;
                    } elseif ($currentSection !== 'default') {
                        $sections[$currentSection]['header_nodes'][] = $node;
                        $nodesToRemove[] = $node;
                    }
                }
                continue;
            }

            if (preg_match('/^\s*Phần\s+([I,V,X,1-9]+)/i', $text, $matches)) {
                $currentSection = trim($matches[0]);
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
                    'question_nodes' => [$node],
                    'answers'        => [],
                    'table_answers'  => [],
                    'tf_answers'     => [],
                    'correct_key'    => null,
                    'is_lower_case'  => false,
                    'tf_key_nodes'   => [],
                ];
                $nodesToRemove[] = $node;
                continue;
            }

            if ($currentQuestion !== null) {
                // Tiêu diệt dòng Đáp án: A cũ
                if (preg_match('/^\s*Đáp\s+án\s*:/iu', $text)) {
                    $nodesToRemove[] = $node;
                    continue;
                }

                if ($node->nodeName === 'w:tbl') {
                    $answerCells = [];
                    $tcs = $xpath->query('.//w:tc', $node);
                    $foundAns = false;

                    foreach ($tcs as $tc) {
                        $tcText = trim($tc->textContent);
                        if (preg_match('/^([A-Da-d])\s*([\.\\)])/u', $tcText, $m)) {
                            $ansKey = strtoupper($m[1]);
                            $foundAns = true;
                            $answerCells[$ansKey] = $tc;
                            $sections[$currentSection]['questions'][$currentQuestion]['is_lower_case'] = ctype_lower($m[1]);

                            // SOI GẠCH CHÂN TRONG BẢNG
                            $isCorrect = $this->isNodeUnderlined($tc, $dom);
                            if (str_contains($currentSection, 'Phần II')) {
                                $sections[$currentSection]['questions'][$currentQuestion]['tf_answers'][$ansKey] = $isCorrect ? 'Đ' : 'S';
                            } elseif ($isCorrect) {
                                $sections[$currentSection]['questions'][$currentQuestion]['correct_key'] = $ansKey;
                            }
                        }
                    }

                    if ($foundAns) {
                        $sections[$currentSection]['questions'][$currentQuestion]['table_answers'] = [
                            'table_node' => $node,
                            'cells'      => $answerCells,
                        ];
                    } else {
                        $sections[$currentSection]['questions'][$currentQuestion]['question_nodes'][] = $node;
                    }
                    $nodesToRemove[] = $node;
                } 
                elseif (preg_match('/^\s*([A-Da-d])\s*([\.\\)])/u', $text, $m)) {
                    $ansKey = strtoupper($m[1]);
                    
                    if (preg_match('/(Đúng|Sai)/iu', $text, $tfMatch)) {
                        $sections[$currentSection]['questions'][$currentQuestion]['tf_key_nodes'][$ansKey] = [
                            'node' => $node, 'value' => $tfMatch[1]
                        ];
                    } else {
                        $sections[$currentSection]['questions'][$currentQuestion]['answers'][$ansKey] = $node;
                    }

                    $sections[$currentSection]['questions'][$currentQuestion]['is_lower_case'] = ctype_lower($m[1]);

                    // SOI GẠCH CHÂN Ở DÒNG THƯỜNG
                    $isCorrect = $this->isNodeUnderlined($node, $dom);
                    if (str_contains($currentSection, 'Phần II')) {
                        $sections[$currentSection]['questions'][$currentQuestion]['tf_answers'][$ansKey] = $isCorrect ? 'Đ' : 'S';
                    } elseif ($isCorrect) {
                        $sections[$currentSection]['questions'][$currentQuestion]['correct_key'] = $ansKey;
                    }
                    $nodesToRemove[] = $node;
                } 
                else {
                    $sections[$currentSection]['questions'][$currentQuestion]['question_nodes'][] = $node;
                    $nodesToRemove[] = $node;
                }
            } elseif ($currentSection !== 'default') {
                $sections[$currentSection]['header_nodes'][] = $node;
                $nodesToRemove[] = $node;
            }
        }

        foreach ($nodesToRemove as $n) { if ($n->parentNode) $n->parentNode->removeChild($n); }
        return [$dom, $body, $sections];
    }

    // ================================================================
    // BƯỚC 2: TRÁO, RÁP, TẨY GẠCH CHÂN VÀ LƯU CSV
    // ================================================================

    private function shuffleAndRebuild(DOMDocument $dom, $body, array $sections, $maDe): void
    {
        $xpath = new DOMXPath($dom);
        $xpath->registerNamespace('w', 'http://schemas.openxmlformats.org/wordprocessingml/2006/main');
        
        $sectPrList = $xpath->query('./w:sectPr', $body);
        $sectPr = $sectPrList->length > 0 ? $sectPrList->item($sectPrList->length - 1) : null;

        $safeAppend = function($node) use ($body, $sectPr) {
            if ($sectPr) { $body->insertBefore($node, $sectPr); } 
            else { $body->appendChild($node); }
        };

        foreach ($sections as $sectionName => $sectionData) {
            foreach ($sectionData['header_nodes'] as $hn) { $safeAppend($hn); }

            $isPartII = str_contains($sectionName, 'Phần II');
            $questions = $sectionData['questions'];
            $questionKeys = array_keys($questions);
            shuffle($questionKeys);

            $qSecIdx = 1; // Số thứ tự câu bắt đầu lại từ 1 cho mỗi phần

            foreach ($questionKeys as $qKey) {
                $qData = $questions[$qKey];

                $firstQNode = $qData['question_nodes'][0];
                $this->replacePrefixInNode($firstQNode, '/^\s*Câu\s+\d+\s*([\.\\:])/iu', "Câu {$qSecIdx}$1");

                foreach ($qData['question_nodes'] as $qn) { $safeAppend($qn); }

                $shuffleResult = $this->shuffleAnswers($dom, $xpath, $body, $qData, $isPartII, $safeAppend);
                
                // Xử lý nhãn Đúng/Sai text (Tương thích ngược)
                if (!empty($qData['tf_key_nodes']) && !empty($shuffleResult['shuffledKeysMap'])) {
                    $visualKeys = $qData['is_lower_case'] ? range('a', 'z') : range('A', 'Z');
                    foreach ($shuffleResult['shuffledKeysMap'] as $newIndex => $oldKey) {
                        $newLetter = $visualKeys[$newIndex];
                        if (isset($qData['tf_key_nodes'][$oldKey])) {
                            $tfData = $qData['tf_key_nodes'][$oldKey];
                            $this->replacePrefixInNode($tfData['node'], '/^\s*[A-Da-d]\s*[\.\)]\s*(Đúng|Sai)/iu', "{$newLetter}. {$tfData['value']}");
                            $safeAppend($tfData['node']);
                        }
                    }
                }

                // Lưu vào Ma trận Đáp án đa tầng
                $finalAns = $isPartII ? implode('', $shuffleResult['tfResult']) : $shuffleResult['newCorrectKey'];
                $this->answerMap[$maDe][$sectionName][$qSecIdx] = $finalAns;

                $qSecIdx++;
            }
        }
    }

    private function shuffleAnswers(DOMDocument $dom, DOMXPath $xpath, $body, array $qData, bool $isPartII, callable $safeAppend): array
    {
        $answers = $qData['answers'];
        $tableAnswers = $qData['table_answers'];
        $visualKeys = $qData['is_lower_case'] ? range('a', 'z') : range('A', 'Z');
        $newCorrectKey = ''; 
        $tfResult = ['A'=>'S', 'B'=>'S', 'C'=>'S', 'D'=>'S']; 
        $shuffledKeysMap = []; 

        $canShuffle = (count($answers) >= 2 || (!empty($tableAnswers) && count($tableAnswers['cells']) >= 2));

        if ($canShuffle && !empty($answers)) {
            $ansKeys = array_keys($answers);
            shuffle($ansKeys);
            $shuffledKeysMap = $ansKeys; 
            
            foreach ($ansKeys as $idx => $oldKey) {
                $newLetter = $visualKeys[$idx];
                $upperNew = strtoupper($newLetter);
                $node = $answers[$oldKey];

                if ($isPartII && isset($qData['tf_answers'][$oldKey])) { $tfResult[$upperNew] = $qData['tf_answers'][$oldKey]; } 
                elseif ($oldKey === $qData['correct_key']) { $newCorrectKey = $upperNew; }

                $this->stripUnderlineFromNode($node);
                $this->replacePrefixInNode($node, '/^\s*[A-Da-d]\s*([\.\\)])/u', "{$newLetter}$1");
                $safeAppend($node);
            }
        } elseif ($canShuffle && !empty($tableAnswers)) {
            $cells = $tableAnswers['cells'];
            $ansKeys = array_keys($cells);
            $originalAnsKeys = $ansKeys;
            sort($originalAnsKeys); 
            
            shuffle($ansKeys);
            $shuffledKeysMap = $ansKeys;

            $cloned = [];
            foreach ($ansKeys as $key) {
                $cloned[$key] = [];
                foreach ($cells[$key]->childNodes as $child) { $cloned[$key][] = $child->cloneNode(true); }
            }

            foreach ($originalAnsKeys as $idx => $origKey) {
                $targetCell = $cells[$origKey];
                $shuffledKey = $ansKeys[$idx];
                $newLetter = $visualKeys[$idx];
                $upperNew = strtoupper($newLetter);

                while ($targetCell->hasChildNodes()) { $targetCell->removeChild($targetCell->firstChild); }
                foreach ($cloned[$shuffledKey] as $node) { $targetCell->appendChild($node); }

                $firstP = $xpath->query('.//w:p', $targetCell)->item(0);
                if ($firstP) { $this->replacePrefixInNode($firstP, '/^\s*[A-Da-d]\s*([\.\\)])/u', "{$newLetter}$1"); }

                if ($isPartII && isset($qData['tf_answers'][$shuffledKey])) { $tfResult[$upperNew] = $qData['tf_answers'][$shuffledKey]; } 
                elseif ($shuffledKey === $qData['correct_key']) { $newCorrectKey = $upperNew; }
                
                $this->stripUnderlineFromNode($targetCell);
            }
            $safeAppend($tableAnswers['table_node']);
        } else {
            foreach ($answers as $key => $node) {
                $upper = strtoupper($key);
                if ($isPartII) $tfResult[$upper] = $qData['tf_answers'][$key] ?? 'S';
                elseif ($key === $qData['correct_key']) $newCorrectKey = $upper;
                
                $this->stripUnderlineFromNode($node);
                $safeAppend($node);
            }
            if (!empty($tableAnswers)) { $safeAppend($tableAnswers['table_node']); }
        }

        return [
            'newCorrectKey' => $newCorrectKey, 'tfResult' => $tfResult, 'shuffledKeysMap' => $shuffledKeysMap
        ];
    }

    // ================================================================
    // BƯỚC 3: XUẤT CSV MẪU CHUẨN
    // ================================================================

    private function generateAnswerKeyCSV(array $codes, string $originalFileName): string
    {
        $path = storage_path('app/temp_uploads/DapAn_'.time().'.csv');
        $file = fopen($path, 'w');
        fprintf($file, chr(0xEF).chr(0xBB).chr(0xBF)); // BOM UTF-8

        fputcsv($file, ['{thông tin trường}', $originalFileName, '', '', '']);
        fputcsv($file, ['{môn thi}', '', '', '', '']);
        fputcsv($file, ['Thời gian làm bài: 50 phút (Không kể thời gian giao đề)', '', '', '', '']);
        fputcsv($file, ['-------------------------', '', '', '', '']);
        fputcsv($file, ['Câu hỏi', 'Mã đề thi', '', '', '']);
        fputcsv($file, array_merge([''], $codes));

        $firstCode = reset($codes);
        if ($firstCode && isset($this->answerMap[$firstCode])) {
            foreach ($this->answerMap[$firstCode] as $secName => $questions) {
                $numQs = count($questions);
                for ($i = 1; $i <= $numQs; $i++) {
                    $row = [$i]; 
                    foreach ($codes as $code) {
                        $row[] = $this->answerMap[$code][$secName][$i] ?? '';
                    }
                    fputcsv($file, $row);
                }
            }
        }
        fclose($file);
        return $path;
    }

    // ================================================================
    // CÁC HÀM HELPER HỖ TRỢ (GIỮ NGUYÊN)
    // ================================================================

    private function prepareWorkspace(string $filePath): array {
        $workspace = storage_path('app/temp_workspace_' . uniqid());
        mkdir($workspace);
        $zip = new ZipArchive();
        if ($zip->open($filePath) === true) {
            $zip->extractTo($workspace);
            $zip->close();
        } else {
            throw new RuntimeException("Không thể mở file .docx");
        }
        $xmlBackupPath = $workspace . '/word/document_backup.xml';
        copy($workspace . '/word/document.xml', $xmlBackupPath);
        $outputZipPath = storage_path('app/temp_uploads/result_' . time() . '.zip');
        $finalZip = new ZipArchive();
        $finalZip->open($outputZipPath, ZipArchive::CREATE);
        return [$workspace, $xmlBackupPath, $outputZipPath, $finalZip];
    }

    private function packExamCopy(DOMDocument $dom, string $xmlOriginalPath, string $workspace, string $maDe): string {
        $dom->save($xmlOriginalPath);
        $tempDocx = storage_path("app/temp_uploads/De_Thi_Ma_{$maDe}.docx");
        $this->zipDirectory($workspace, $tempDocx);
        return $tempDocx;
    }

    private function cleanup(string $workspace, string $xmlBackupPath, array $generatedFiles): void {
        if (file_exists($xmlBackupPath)) unlink($xmlBackupPath);
        $this->deleteDirectory($workspace);
        foreach ($generatedFiles as $file) {
            if (file_exists($file)) unlink($file);
        }
    }

    private function replacePrefixInNode(\DOMNode $node, string $pattern, string $replacement): void {
        $xpath = new DOMXPath($node->ownerDocument);
        $xpath->registerNamespace('w', 'http://schemas.openxmlformats.org/wordprocessingml/2006/main');
        $texts = $xpath->query('.//w:t', $node);
        $fullText = '';
        foreach ($texts as $t) { $fullText .= $t->nodeValue; }
        
        if (preg_match($pattern, $fullText, $matches)) {
            // [FIX LỖI $1]: Tự động thay $1 bằng dấu (., :, )) đã bắt được
            if (str_contains($replacement, '$1') && isset($matches[1])) {
                $replacement = str_replace('$1', $matches[1], $replacement);
            }

            $originalPrefix = $matches[0];
            $charsToRemove = mb_strlen($originalPrefix, 'UTF-8');
            $first = true;
            foreach ($texts as $t) {
                if ($charsToRemove <= 0) break;
                $tText = $t->nodeValue;
                $tLen = mb_strlen($tText, 'UTF-8');
                if ($first) {
                    $t->nodeValue = $replacement . mb_substr($tText, $charsToRemove, null, 'UTF-8');
                    $charsToRemove -= $tLen;
                    $first = false;
                } else {
                    if ($tLen <= $charsToRemove) {
                        $t->nodeValue = '';
                        $charsToRemove -= $tLen;
                    } else {
                        $t->nodeValue = mb_substr($tText, $charsToRemove, null, 'UTF-8');
                        $charsToRemove = 0;
                    }
                }
            }
        }
    }

    private function zipDirectory(string $sourcePath, string $outZipPath): void {
        $zip = new ZipArchive();
        if ($zip->open($outZipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) return;
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($sourcePath), \RecursiveIteratorIterator::LEAVES_ONLY);
        foreach ($files as $name => $file) {
            if (!$file->isDir()) {
                $filePath = $file->getRealPath();
                $relativePath = substr($filePath, strlen($sourcePath) + 1);
                $zip->addFile($filePath, str_replace('\\', '/', $relativePath));
            }
        }
        $zip->close();
    }

    private function deleteDirectory(string $dirPath): void {
        if (!is_dir($dirPath)) return;
        $files = array_diff(scandir($dirPath), ['.', '..']);
        foreach ($files as $file) {
            $path = "$dirPath/$file";
            is_dir($path) ? $this->deleteDirectory($path) : unlink($path);
        }
        rmdir($dirPath);
    }
}