<?php

namespace App\Services;

use Exception;
use RuntimeException;
use ZipArchive;
use DOMDocument;
use DOMXPath;
use Illuminate\Support\Facades\Log;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

class QuizShufflerService
{
    private array $answerMap = []; 
    private array $underlinedStyles = [];

    // ================================================================
    // NHẠC TRƯỞNG: PUBLIC ENTRY POINT
    // ================================================================

    public function shuffle(string $filePath, int $copies, array $customCodes = [], string $originalFileName = 'De_Thi'): string
    {
        Log::info("===== BẮT ĐẦU TẠO {$copies} MÃ ĐỀ (BẢN FINAL) =====");
        $this->answerMap = [];
        $generatedFiles = [];

        $baseName = pathinfo($originalFileName, PATHINFO_FILENAME);
        [$workspace, $xmlBackupPath, $outputZipPath, $finalZip] = $this->prepareWorkspace($filePath);
        
        $this->loadUnderlinedStyles($workspace);

        for ($i = 0; $i < $copies; $i++) {
            $maDe = !empty($customCodes[$i]) ? (string) $customCodes[$i] : str_pad(rand(100, 999), 3, '0', STR_PAD_LEFT);
            $customCodes[$i] = $maDe; 

            Log::info("--- Đang sinh Mã đề: {$maDe} ---");

            $xmlOriginalPath = $workspace . '/word/document.xml';
            copy($xmlBackupPath, $xmlOriginalPath);

            // Bước 1: Đọc và bóc tách
            [$dom, $body, $sections, $globalFooterNodes] = $this->parseDocumentSections($xmlOriginalPath);

            // Bước 2: Thay mã đề (Bản nâng cấp Nuclear Replace)
            $this->replaceCodeInDOM($dom, $maDe);
            $this->updateExamCodeInHeadersAndFooters($workspace, $maDe);

            // Bước 3: Tráo và Ráp (Sắp xếp chuẩn text phụ)
            $this->shuffleAndRebuild($dom, $body, $sections, $globalFooterNodes, $maDe);

            // Bước 4: Đóng gói
            $tempDocx = $this->packExamCopy($dom, $xmlOriginalPath, $workspace, $maDe);
            $finalZip->addFile($tempDocx, "De_Thi_Ma_{$maDe}.docx");
            $generatedFiles[] = $tempDocx;
        }

        // $csvPath = $this->generateAnswerKeyCSV($customCodes, $originalFileName);
        // $finalZip->addFile($csvPath, "DapAn_TongHop.csv");
        // $this->cleanup(..., array_merge($generatedFiles, [$csvPath]));

        $excelPath = $this->generateAnswerKeyExcel($customCodes, $originalFileName);
        $finalZip->addFile($excelPath, "DapAn_TongHop.xlsx");

        $finalZip->close();
        $this->cleanup($workspace, $xmlBackupPath, array_merge($generatedFiles, [$excelPath]));

        return $outputZipPath;
    }

    // ================================================================
    // TIỆN ÍCH: MẮT THẦN, CỤC TẨY VÀ THAY MÃ ĐỀ
    // ================================================================

    private function loadUnderlinedStyles(string $workspace): void {
        $this->underlinedStyles = [];
        $stylesPath = $workspace . '/word/styles.xml';
        if (!file_exists($stylesPath)) return;

        $dom = new DOMDocument();
        libxml_use_internal_errors(true);
        $dom->load($stylesPath);
        libxml_clear_errors();
        
        $xpath = new DOMXPath($dom);
        $xpath->registerNamespace('w', 'http://schemas.openxmlformats.org/wordprocessingml/2006/main');

        $styles = $xpath->query('//w:style[.//w:u[not(@w:val="none")]]');
        foreach ($styles as $style) {
            if ($styleId = $style->getAttribute('w:styleId')) {
                $this->underlinedStyles[] = $styleId;
            }
        }
    }

    private function isNodeUnderlined($node, $dom): bool 
    {
        if (!($node instanceof \DOMElement)) return false;
        $xpath = new DOMXPath($dom);
        $xpath->registerNamespace('w', 'http://schemas.openxmlformats.org/wordprocessingml/2006/main');
        
        if ($xpath->query('.//w:u[not(@w:val="none")]', $node)->length > 0) return true;
        if ($xpath->query('.//w:pBdr/w:bottom[not(@w:val="none")] | .//w:bdr[not(@w:val="none")]', $node)->length > 0) return true;
        if ($xpath->query('.//w:highlight[not(@w:val="none")]', $node)->length > 0) return true;

        $styles = $xpath->query('.//w:rStyle', $node);
        foreach ($styles as $style) {
            $val = $style->getAttribute('w:val');
            if (in_array($val, $this->underlinedStyles) || stripos($val, 'underline') !== false) {
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
        
        $query = './/w:u | .//w:pBdr/w:bottom | .//w:bdr | .//w:highlight';
        $elements = $xpath->query($query, $node);
        
        $remove = [];
        foreach ($elements as $el) { $remove[] = $el; }
        
        $styles = $xpath->query('.//w:rStyle', $node);
        foreach ($styles as $style) {
            $val = $style->getAttribute('w:val');
            if (in_array($val, $this->underlinedStyles) || stripos($val, 'underline') !== false) {
                $remove[] = $style;
            }
        }
        
        foreach ($remove as $el) { 
            if ($el->parentNode) $el->parentNode->removeChild($el); 
        }
    }

   // [BẢN NÂNG CẤP MỚI NHẤT]: Mổ nội soi đổi mã đề - Không làm vỡ Layout (Tabs, Spaces)
    private function replaceCodeInDOM(DOMDocument $dom, string $newCode): bool {
        $xpath = new DOMXPath($dom);
        $xpath->registerNamespace('w', 'http://schemas.openxmlformats.org/wordprocessingml/2006/main');
        $paragraphs = $xpath->query('//w:p');
        $isModified = false;

        foreach ($paragraphs as $p) {
            $tNodes = $xpath->query('.//w:t', $p);
            if ($tNodes->length === 0) continue;

            // 1. Gom toàn bộ text trong dòng để kiểm tra
            $fullText = '';
            foreach ($tNodes as $t) {
                $fullText .= $t->nodeValue;
            }

            // 2. Nếu phát hiện cụm "Mã đề: xxx"
            if (preg_match('/(Mã\s*đề(?:\s*thi)?\s*[:\-]?\s*)[\p{L}\p{N}]+/iu', $fullText, $matches)) {
                $targetStr = $matches[0]; // Ví dụ: "Mã đề: GỐC"
                $replacement = $matches[1] . $newCode; // Ví dụ: "Mã đề: 180"
                
                $startOffset = mb_strpos($fullText, $targetStr, 0, 'UTF-8');
                $charsToMatch = mb_strlen($targetStr, 'UTF-8');
                
                $currentOffset = 0;
                $replaced = false;

                // 3. Quét từng node chữ để thay thế từ từ
                foreach ($tNodes as $t) {
                    $tText = $t->nodeValue;
                    $tLen = mb_strlen($tText, 'UTF-8');

                    if (!$replaced) {
                        // Nếu điểm bắt đầu của "Mã đề" rơi vào Node này
                        if ($currentOffset + $tLen > $startOffset) {
                            $localStart = $startOffset - $currentOffset;
                            $localMatchLen = min($tLen - $localStart, $charsToMatch);

                            $before = mb_substr($tText, 0, $localStart, 'UTF-8');
                            $after = mb_substr($tText, $localStart + $localMatchLen, null, 'UTF-8');

                            // Ghi cụm mới vào Node này
                            $t->nodeValue = $before . $replacement . $after;
                            $charsToMatch -= $localMatchLen;
                            $replaced = true;
                        }
                    } elseif ($charsToMatch > 0) {
                        // Xóa các mẩu chữ thừa của mã đề cũ ở các Node tiếp theo (nếu có)
                        $localMatchLen = min($tLen, $charsToMatch);
                        $after = mb_substr($tText, $localMatchLen, null, 'UTF-8');
                        $t->nodeValue = $after;
                        $charsToMatch -= $localMatchLen;
                    }

                    $currentOffset += $tLen;
                }
                $isModified = true;
            }
        }
        return $isModified;
    }

    private function updateExamCodeInHeadersAndFooters(string $workspace, string $newCode): void 
    {
        $targetFiles = array_merge(glob($workspace . '/word/header*.xml') ?: [], glob($workspace . '/word/footer*.xml') ?: []);
        foreach ($targetFiles as $file) {
            $dom = new DOMDocument();
            libxml_use_internal_errors(true);
            $dom->load($file);
            libxml_clear_errors();
            
            if ($this->replaceCodeInDOM($dom, $newCode)) {
                $dom->save($file);
            }
        }
    }

    // ================================================================
    // BƯỚC 1: ĐỌC VÀ NHẬN DIỆN (PARSER)
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
        
        $parsingFooter = false;
        $globalFooterNodes = []; // Khoang chứa chân trang (HẾT)

        $nodes = [];
        foreach ($body->childNodes as $node) { $nodes[] = $node; }

        foreach ($nodes as $node) {
            $text = $node->textContent;
            
            // Xóa dòng trống
            if (trim($text) === '') {
                if ($node->nodeName === 'w:p') {
                    if ($currentQuestion !== null) {
                        if (!$sections[$currentSection]['questions'][$currentQuestion]['has_seen_answers']) {
                            $sections[$currentSection]['questions'][$currentQuestion]['question_nodes'][] = $node;
                        } else {
                            $sections[$currentSection]['questions'][$currentQuestion]['post_question_nodes'][] = $node;
                        }
                        $nodesToRemove[] = $node;
                    } elseif ($currentSection !== 'default') {
                        $sections[$currentSection]['header_nodes'][] = $node;
                        $nodesToRemove[] = $node;
                    }
                }
                continue;
            }

            // Nhận diện chân trang (Document Footer)
            if (preg_match('/^[\.\-\*_\s]*HẾT[\.\-\*_\s]*$/iu', trim($text)) || preg_match('/^\s*Ghi\s*chú\s*:/iu', trim($text))) {
                $parsingFooter = true;
            }

            if ($parsingFooter) {
                $globalFooterNodes[] = $node;
                $nodesToRemove[] = $node;
                continue;
            }

            // Nhận diện Phần
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

            // Nhận diện Câu hỏi
            if (preg_match('/^\s*Câu\s+(\d+)\s*[\.\\:]/i', $text, $matches)) {
                $currentQuestion = 'Câu ' . $matches[1];
                $sections[$currentSection]['questions'][$currentQuestion] = [
                    'question_nodes'      => [$node],
                    'post_question_nodes' => [], // Text nằm sau đáp án
                    'has_seen_answers'    => false, // Cảm biến thứ tự text
                    'answers'             => [],
                    'table_answers'       => [],
                    'tf_answers'          => [],
                    'correct_key'         => null,
                    'is_lower_case'       => false,
                    'tf_key_nodes'        => [],
                ];
                $nodesToRemove[] = $node;
                continue;
            }

            if ($currentQuestion !== null) {
                if (preg_match('/^\s*Đáp\s+án\s*:/iu', $text)) {
                    $nodesToRemove[] = $node;
                    continue;
                }

                // if ($node->nodeName === 'w:tbl') {
                //     $tcs = $xpath->query('.//w:tc', $node);
                //     $boxAnswers = [];
                //     $layoutCells = [];

                //     foreach ($tcs as $tc) {
                //         $tcText = trim($tc->textContent);
                //         $ps = $xpath->query('.//w:p', $tc);
                //         $ansInCell = [];
                //         foreach ($ps as $p) {
                //             if (preg_match('/^\s*([A-Da-d])\s*([\.\\)])/u', $p->textContent, $m)) {
                //                 $ansInCell[strtoupper($m[1])] = ['node' => $p, 'is_lower' => ctype_lower($m[1])];
                //             }
                //         }

                //         if (count($ansInCell) >= 2) {
                //             foreach ($ansInCell as $key => $ansData) { $boxAnswers[$key] = $ansData; }
                //         } elseif (preg_match('/^([A-Da-d])\s*([\.\\)])/u', $tcText, $m)) {
                //             $layoutCells[strtoupper($m[1])] = ['node' => $tc, 'is_lower' => ctype_lower($m[1])];
                //         }
                //     }

                //     if (count($boxAnswers) >= 2) {
                //         $sections[$currentSection]['questions'][$currentQuestion]['has_seen_answers'] = true;
                //         foreach ($boxAnswers as $key => $ansData) {
                //             $pNode = $ansData['node'];
                //             $sections[$currentSection]['questions'][$currentQuestion]['answers'][$key] = $pNode;
                //             $sections[$currentSection]['questions'][$currentQuestion]['is_lower_case'] = $ansData['is_lower'];

                //             $isCorrect = $this->isNodeUnderlined($pNode, $dom);
                //             if (str_contains($currentSection, 'Phần II')) {
                //                 $sections[$currentSection]['questions'][$currentQuestion]['tf_answers'][$key] = $isCorrect ? 'Đ' : 'S';
                //             } elseif ($isCorrect) {
                //                 $sections[$currentSection]['questions'][$currentQuestion]['correct_key'] = $key;
                //             }
                //         }
                //         $nodesToRemove[] = $node; 
                //     } elseif (count($layoutCells) >= 2) {
                //         $sections[$currentSection]['questions'][$currentQuestion]['has_seen_answers'] = true;
                //         $answerCells = [];
                //         foreach ($layoutCells as $key => $ansData) {
                //             $tcNode = $ansData['node'];
                //             $answerCells[$key] = $tcNode;
                //             $sections[$currentSection]['questions'][$currentQuestion]['is_lower_case'] = $ansData['is_lower'];

                //             $isCorrect = $this->isNodeUnderlined($tcNode, $dom);
                //             if (str_contains($currentSection, 'Phần II')) {
                //                 $sections[$currentSection]['questions'][$currentQuestion]['tf_answers'][$key] = $isCorrect ? 'Đ' : 'S';
                //             } elseif ($isCorrect) {
                //                 $sections[$currentSection]['questions'][$currentQuestion]['correct_key'] = $key;
                //             }
                //         }
                //         $sections[$currentSection]['questions'][$currentQuestion]['table_answers'] = [
                //             'table_node' => $node,
                //             'cells'      => $answerCells,
                //         ];
                //         $nodesToRemove[] = $node;
                //     } else {
                //         // Bảng là text phụ trước hoặc sau
                //         if (!$sections[$currentSection]['questions'][$currentQuestion]['has_seen_answers']) {
                //             $sections[$currentSection]['questions'][$currentQuestion]['question_nodes'][] = $node;
                //         } else {
                //             $sections[$currentSection]['questions'][$currentQuestion]['post_question_nodes'][] = $node;
                //         }
                //         $nodesToRemove[] = $node;
                //     }
                //     continue;
                // } 
                if ($node->nodeName === 'w:tbl') {
                    $tcs = $xpath->query('.//w:tc', $node);
                    $boxAnswers = [];
                    $layoutCells = [];
                    
                    // --- BỘ KHIÊN BẢO VỆ (CHỐNG NHẬN DIỆN NHẦM BẢNG DỮ LIỆU) ---
                    $isContentTable = false;
                    $firstTextInTable = '';
                    $hasNumberedList = false;

                    foreach ($tcs as $tc) {
                        $tcText = trim($tc->textContent);
                        $ps = $xpath->query('.//w:p', $tc);
                        $ansInCell = [];
                        $firstValidText = ''; 
                        
                        foreach ($ps as $p) {
                            $pText = trim($p->textContent);
                            if ($pText !== '') {
                                // Lấy đoạn text đầu tiên có chứa chữ của cả bảng
                                if ($firstTextInTable === '') {
                                    $firstTextInTable = $pText;
                                }

                                // Bắt đáp án a, b, c, d
                                if (preg_match('/^\s*([A-Da-d])\s*([\.\\)])/u', $pText, $m)) {
                                    $ansInCell[strtoupper($m[1])] = [
                                        'node' => $p,
                                        'is_lower' => ctype_lower($m[1])
                                    ];
                                }
                                
                                // Bắt danh sách 1., 2., 3. hoặc I., II., III.
                                if (empty($firstValidText)) {
                                    $firstValidText = $pText;
                                    if (preg_match('/^\s*(\d+|[IVX]+)\s*[\.\)]/iu', $firstValidText)) {
                                        $hasNumberedList = true;
                                    }
                                }
                            }
                        }

                        if (count($ansInCell) >= 2) {
                            foreach ($ansInCell as $key => $ansData) { 
                                $boxAnswers[$key] = $ansData; 
                            }
                        } elseif (preg_match('/^([A-Da-d])\s*([\.\\)])/u', $tcText, $m)) {
                            $layoutCells[strtoupper($m[1])] = ['node' => $tc, 'is_lower' => ctype_lower($m[1])];
                        }
                    }

                    // --- KÍCH HOẠT KHIÊN ---
                    // 1. Nếu ô đầu tiên của bảng là Tiêu đề (không phải A, B, C, D)
                    if ($firstTextInTable !== '' && !preg_match('/^\s*([A-Da-d])\s*([\.\\)])/u', $firstTextInTable)) {
                        $isContentTable = true;
                    }
                    // 2. Nếu trong bảng có chứa danh sách nối cột (1., 2., 3.)
                    if ($hasNumberedList) {
                        $isContentTable = true;
                    }

                    // --- TIẾN HÀNH XỬ LÝ DỰA TRÊN KẾT QUẢ KHIÊN ---
                    if (!$isContentTable && count($boxAnswers) >= 2) {
                        $sections[$currentSection]['questions'][$currentQuestion]['has_seen_answers'] = true;
                        foreach ($boxAnswers as $key => $ansData) {
                            $pNode = $ansData['node'];
                            $sections[$currentSection]['questions'][$currentQuestion]['answers'][$key] = $pNode;
                            $sections[$currentSection]['questions'][$currentQuestion]['is_lower_case'] = $ansData['is_lower'];

                            $isCorrect = $this->isNodeUnderlined($pNode, $dom);
                            if (str_contains($currentSection, 'Phần II')) {
                                $sections[$currentSection]['questions'][$currentQuestion]['tf_answers'][$key] = $isCorrect ? 'Đ' : 'S';
                            } elseif ($isCorrect) {
                                $sections[$currentSection]['questions'][$currentQuestion]['correct_key'] = $key;
                            }
                        }
                        $nodesToRemove[] = $node; 
                    } elseif (!$isContentTable && count($layoutCells) >= 2) {
                        $sections[$currentSection]['questions'][$currentQuestion]['has_seen_answers'] = true;
                        $answerCells = [];
                        foreach ($layoutCells as $key => $ansData) {
                            $tcNode = $ansData['node'];
                            $answerCells[$key] = $tcNode;
                            $sections[$currentSection]['questions'][$currentQuestion]['is_lower_case'] = $ansData['is_lower'];

                            $isCorrect = $this->isNodeUnderlined($tcNode, $dom);
                            if (str_contains($currentSection, 'Phần II')) {
                                $sections[$currentSection]['questions'][$currentQuestion]['tf_answers'][$key] = $isCorrect ? 'Đ' : 'S';
                            } elseif ($isCorrect) {
                                $sections[$currentSection]['questions'][$currentQuestion]['correct_key'] = $key;
                            }
                        }
                        $sections[$currentSection]['questions'][$currentQuestion]['table_answers'] = [
                            'table_node' => $node,
                            'cells'      => $answerCells,
                        ];
                        $nodesToRemove[] = $node;
                    } else {
                        // NẾU BỊ KHIÊN CHẶN (BẢNG NỘI DUNG) -> Gắn chặt vào đề bài, không được tráo
                        if (!$sections[$currentSection]['questions'][$currentQuestion]['has_seen_answers']) {
                            $sections[$currentSection]['questions'][$currentQuestion]['question_nodes'][] = $node;
                        } else {
                            $sections[$currentSection]['questions'][$currentQuestion]['post_question_nodes'][] = $node;
                        }
                        $nodesToRemove[] = $node;
                    }
                    continue;
                }
                elseif (preg_match('/^\s*([A-Da-d])\s*([\.\\)])/u', $text, $m)) {
                    $ansKey = strtoupper($m[1]);
                    $sections[$currentSection]['questions'][$currentQuestion]['has_seen_answers'] = true;
                    
                    if (preg_match('/(Đúng|Sai)/iu', $text, $tfMatch)) {
                        $sections[$currentSection]['questions'][$currentQuestion]['tf_key_nodes'][$ansKey] = [
                            'node' => $node, 'value' => $tfMatch[1]
                        ];
                    } else {
                        $sections[$currentSection]['questions'][$currentQuestion]['answers'][$ansKey] = $node;
                    }

                    $sections[$currentSection]['questions'][$currentQuestion]['is_lower_case'] = ctype_lower($m[1]);

                    $isCorrect = $this->isNodeUnderlined($node, $dom);
                    if (str_contains($currentSection, 'Phần II')) {
                        $sections[$currentSection]['questions'][$currentQuestion]['tf_answers'][$ansKey] = $isCorrect ? 'Đ' : 'S';
                    } elseif ($isCorrect) {
                        $sections[$currentSection]['questions'][$currentQuestion]['correct_key'] = $ansKey;
                    }
                    $nodesToRemove[] = $node;
                } 
                else {
                    // Xử lý text phụ (Mở bài hoặc Ghi chú câu)
                    if (!$sections[$currentSection]['questions'][$currentQuestion]['has_seen_answers']) {
                        $sections[$currentSection]['questions'][$currentQuestion]['question_nodes'][] = $node;
                    } else {
                        $sections[$currentSection]['questions'][$currentQuestion]['post_question_nodes'][] = $node;
                    }
                    $nodesToRemove[] = $node;
                }
            } elseif ($currentSection !== 'default') {
                $sections[$currentSection]['header_nodes'][] = $node;
                $nodesToRemove[] = $node;
            }
        }

        foreach ($nodesToRemove as $n) { if ($n->parentNode) $n->parentNode->removeChild($n); }
        return [$dom, $body, $sections, $globalFooterNodes];
    }

    // ================================================================
    // BƯỚC 2: TRÁO, RÁP, TẨY GẠCH CHÂN VÀ LƯU CSV
    // ================================================================

    private function shuffleAndRebuild(DOMDocument $dom, $body, array $sections, array $globalFooterNodes, string $maDe): void
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

            $qSecIdx = 1; 

            foreach ($questionKeys as $qKey) {
                $qData = $questions[$qKey];

                $firstQNode = $qData['question_nodes'][0];
                $this->replacePrefixInNode($firstQNode, '/^\s*Câu\s+\d+\s*([\.\\:])/iu', "Câu {$qSecIdx}$1");

                // In phần Mở bài của câu hỏi
                foreach ($qData['question_nodes'] as $qn) { $safeAppend($qn); }

                // In và tráo đáp án
                $shuffleResult = $this->shuffleAnswers($dom, $xpath, $body, $qData, $isPartII, $safeAppend);
                
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

                // In phần text phụ nằm phía DƯỚI đáp án (nếu có)
                if (!empty($qData['post_question_nodes'])) {
                    foreach ($qData['post_question_nodes'] as $pqn) { $safeAppend($pqn); }
                }

                $finalAns = $isPartII ? implode('', $shuffleResult['tfResult']) : $shuffleResult['newCorrectKey'];
                $this->answerMap[$maDe][$sectionName][$qSecIdx] = $finalAns;

                $qSecIdx++;
            }
        }

        // [QUAN TRỌNG]: In chữ HẾT và Ghi chú ở dưới cùng văn bản
        foreach ($globalFooterNodes as $fn) {
            $safeAppend($fn);
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
        fprintf($file, chr(0xEF).chr(0xBB).chr(0xBF)); 

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
    // BƯỚC 3: XUẤT EXCEL CHUẨN (.XLSX) - TƯƠNG THÍCH PHPSPREADSHEET 3.X
    // ================================================================

    private function generateAnswerKeyExcel(array $codes, string $originalFileName): string
    {
        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();

        // 1. In thông tin Header
        $sheet->setCellValue('A1', '{thông tin trường}');
        $sheet->setCellValue('B1', $originalFileName);
        $sheet->setCellValue('A2', '{môn thi}');
        $sheet->setCellValue('A3', 'Thời gian làm bài:');
        $sheet->setCellValue('A4', '------------');

        // 2. In nhãn bảng
        $sheet->setCellValue('A5', 'Câu hỏi');
        $sheet->setCellValue('B5', 'Mã đề thi');

        // 3. In danh sách mã đề (Hàng 6)
        $col = 2; // Cột B (tương đương index 2)
        foreach ($codes as $code) {
            // Dịch số thành chữ cái (Ví dụ: 2 -> 'B')
            $colLetter = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($col);
            $sheet->setCellValue($colLetter . '6', $code);
            $col++;
        }

        // 4. In dữ liệu đáp án đa tầng (Từ Hàng 7 trở đi)
        $firstCode = reset($codes);
        $row = 7;
        if ($firstCode && isset($this->answerMap[$firstCode])) {
            foreach ($this->answerMap[$firstCode] as $secName => $questions) {
                $numQs = count($questions);
                for ($i = 1; $i <= $numQs; $i++) {
                    $sheet->setCellValue('A' . $row, $i); // In số thứ tự ở Cột A
                    
                    $col = 2; 
                    foreach ($codes as $code) {
                        $colLetter = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($col);
                        $ans = $this->answerMap[$code][$secName][$i] ?? '';
                        $sheet->setCellValue($colLetter . $row, $ans);
                        $col++;
                    }
                    $row++;
                }
            }
        }

        // 5. Làm đẹp: Tự động căn chỉnh độ rộng cột
        $totalCols = count($codes) + 1;
        for ($c = 1; $c <= $totalCols; $c++) {
            $colLetter = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($c);
            $sheet->getColumnDimension($colLetter)->setAutoSize(true);
        }

        // 6. Lưu ra file .xlsx
        $path = storage_path('app/temp_uploads/DapAn_TongHop_'.time().'.xlsx');
        $writer = new Xlsx($spreadsheet);
        $writer->save($path);

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