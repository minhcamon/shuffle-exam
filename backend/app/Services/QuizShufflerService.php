<?php
// app/Services/QuizShufflerService.php

namespace App\Services;

use PhpOffice\PhpWord\IOFactory;
use PhpOffice\PhpWord\PhpWord;
use PhpOffice\PhpWord\Element\AbstractElement;
use PhpOffice\PhpWord\Element\Section;
use PhpOffice\PhpWord\Element\TextRun;
use PhpOffice\PhpWord\Element\Table;
use RuntimeException;

/**
 * QuizShufflerService
 * ─────────────────────────────────────────────────────────────────────────────
 * Toàn bộ logic nghiệp vụ "trộn đề thi". Hoạt động hoàn toàn trên RAM,
 * không lưu dữ liệu nào lâu dài.
 *
 * Workflow:
 *   1. Đọc file .docx gốc bằng PHPWord
 *   2. Parse các câu hỏi (mỗi câu = 1 khối paragraph bắt đầu bằng số thứ tự)
 *   3. Với mỗi "mã đề":
 *      a. Shuffle mảng câu hỏi (Fisher-Yates)
 *      b. Với mỗi câu: shuffle lại thứ tự các đáp án (A, B, C, D)
 *      c. Đánh lại số thứ tự và đáp án
 *   4. Gộp tất cả mã đề vào 1 file .docx duy nhất (ngăn cách bằng page break)
 *   5. Lưu vào file tạm, trả về đường dẫn cho Controller
 * ─────────────────────────────────────────────────────────────────────────────
 *
 * CẤU TRÚC FILE ĐẦU VÀO (quy ước):
 * ─────────────────────────────────────────────────────────────────────────────
 * Câu 1: [nội dung câu hỏi]
 * A. [đáp án A]
 * B. [đáp án B]
 * C. [đáp án C]
 * D. [đáp án D]
 *
 * Câu 2: ...
 * ─────────────────────────────────────────────────────────────────────────────
 *
 * NOTE: Đây là Service SKELETON — bạn sẽ implement dần các method
 *       trong quá trình "Learning by doing". Mỗi method có doc-block
 *       mô tả rõ input/output và gợi ý thuật toán.
 */
class QuizShufflerService
{
    // Label đáp án chuẩn
    private const ANSWER_LABELS = ['A', 'B', 'C', 'D'];

    // Prefix nhận biết câu hỏi trong văn bản (RegEx)
    private const QUESTION_PATTERN = '/^(Câu\s+\d+[:.]?\s*)/iu';

    // Prefix nhận biết đáp án
    private const ANSWER_PATTERN = '/^([A-D][.)]\s*)/u';

    // ──────────────────────────────────────────────────────────────────────
    // PUBLIC API
    // ──────────────────────────────────────────────────────────────────────

    /**
     * Đọc file gốc, tạo $copies mã đề trộn, xuất ra file .docx tạm.
     *
     * @param  string $filePath  Đường dẫn tuyệt đối đến file .docx đầu vào
     * @param  int    $copies    Số lượng mã đề cần tạo (2–26)
     * @return string            Đường dẫn file .docx output (trong sys_get_temp_dir())
     *
     * @throws RuntimeException nếu file không đọc được
     */
    public function shuffle(string $filePath, int $copies = 4): string
    {
        // Bước 1: Parse câu hỏi từ file gốc
        $questions = $this->parseQuestions($filePath);

        if (empty($questions)) {
            throw new RuntimeException('Không tìm thấy câu hỏi nào trong file. Hãy kiểm tra định dạng.');
        }

        // Bước 2: Tạo PhpWord mới để ghi output
        $phpWord = new PhpWord();
        $phpWord->getSettings()->setUpdateFields(true);

        // Bước 3: Với mỗi mã đề, tạo 1 section
        $maeDe = range('A', 'Z');

        for ($i = 0; $i < $copies; $i++) {
            $sectionLabel = $maeDe[$i]; // Mã đề A, B, C, ...

            // Fisher-Yates shuffle trên bản sao của mảng câu hỏi
            $shuffled = $this->fisherYatesShuffle($questions);

            // Tạo section mới trong docx
            $section = $phpWord->addSection();

            // Viết tiêu đề mã đề
            $this->writeExamHeader($section, $sectionLabel);

            // Viết từng câu hỏi (đã trộn)
            foreach ($shuffled as $idx => $question) {
                $this->writeQuestion($section, $question, $idx + 1);
            }

            // Ngắt trang giữa các mã đề (trừ mã đề cuối)
            if ($i < $copies - 1) {
                $section->addPageBreak();
            }
        }

        // Bước 4: Lưu ra file tạm và trả về đường dẫn
        return $this->saveToTemp($phpWord);
    }

    /**
     * Đọc file .docx và trả về mảng câu hỏi dạng cấu trúc.
     *
     * Mỗi phần tử trong mảng có dạng:
     * [
     *   'question' => 'Nội dung câu hỏi...',
     *   'answers'  => ['A. Đáp án A', 'B. Đáp án B', 'C. Đáp án C', 'D. Đáp án D'],
     * ]
     *
     * @param  string $filePath
     * @return array<int, array{question: string, answers: string[]}>
     *
     * @throws RuntimeException nếu file không đọc được
     */
    public function parseQuestions(string $filePath): array
    {
        try {
            $phpWord   = IOFactory::load($filePath);
            $questions = [];
            $current   = null; // câu hỏi đang parse

            // Duyệt qua tất cả sections và elements
            foreach ($phpWord->getSections() as $section) {
                foreach ($section->getElements() as $element) {
                    $text = $this->extractText($element);
                    if (empty(trim($text))) continue;

                    if (preg_match(self::QUESTION_PATTERN, $text)) {
                        // Bắt đầu câu hỏi mới — lưu câu trước nếu có
                        if ($current !== null) {
                            $questions[] = $current;
                        }
                        $current = [
                            'question' => trim($text),
                            'answers'  => [],
                        ];
                    } elseif ($current !== null && preg_match(self::ANSWER_PATTERN, $text)) {
                        // Đây là một đáp án thuộc câu hỏi hiện tại
                        $current['answers'][] = trim($text);
                    } elseif ($current !== null && empty($current['answers'])) {
                        // Nội dung câu hỏi có thể kéo dài nhiều dòng
                        $current['question'] .= ' ' . trim($text);
                    }
                }
            }

            // Lưu câu hỏi cuối cùng
            if ($current !== null) {
                $questions[] = $current;
            }

            return $questions;

        } catch (\Throwable $e) {
            throw new RuntimeException('Không thể đọc file .docx: ' . $e->getMessage(), 0, $e);
        }
    }

    // ──────────────────────────────────────────────────────────────────────
    // PRIVATE HELPERS
    // ──────────────────────────────────────────────────────────────────────

    /**
     * Fisher-Yates shuffle — O(n), không bias
     * Áp dụng lên cả câu hỏi và đáp án của từng câu.
     *
     * @param  array $questions
     * @return array Bản sao đã được xáo trộn
     */
    private function fisherYatesShuffle(array $questions): array
    {
        $arr = $questions; // clone để không làm thay đổi mảng gốc
        $n   = count($arr);

        for ($i = $n - 1; $i > 0; $i--) {
            $j = random_int(0, $i);
            [$arr[$i], $arr[$j]] = [$arr[$j], $arr[$i]];
        }

        // Trộn đáp án trong từng câu
        foreach ($arr as &$q) {
            if (!empty($q['answers'])) {
                $answers = $q['answers'];
                $n2 = count($answers);
                for ($i = $n2 - 1; $i > 0; $i--) {
                    $j = random_int(0, $i);
                    [$answers[$i], $answers[$j]] = [$answers[$j], $answers[$i]];
                }
                // Đánh lại nhãn A/B/C/D sau khi trộn
                $q['answers'] = array_map(
                    fn ($label, $ans) => $label . '. ' . preg_replace(self::ANSWER_PATTERN, '', $ans),
                    self::ANSWER_LABELS,
                    $answers,
                );
            }
        }
        unset($q);

        return $arr;
    }

    /**
     * Trích xuất plain-text từ bất kỳ element PHPWord nào.
     * Xử lý: TextRun, Table, Text thông thường, v.v.
     *
     * @param  AbstractElement $element
     * @return string
     */
    private function extractText(AbstractElement $element): string
    {
        // TextRun chứa nhiều Text elements con
        if ($element instanceof TextRun) {
            $text = '';
            foreach ($element->getElements() as $child) {
                if (method_exists($child, 'getText')) {
                    $text .= $child->getText();
                }
            }
            return $text;
        }

        // Text đơn thuần
        if (method_exists($element, 'getText')) {
            return (string) $element->getText();
        }

        // Table — đọc text từ tất cả cells
        if ($element instanceof Table) {
            $text = '';
            foreach ($element->getRows() as $row) {
                foreach ($row->getCells() as $cell) {
                    foreach ($cell->getElements() as $cellEl) {
                        $text .= $this->extractText($cellEl) . ' ';
                    }
                }
            }
            return trim($text);
        }

        return '';
    }

    /**
     * Ghi tiêu đề mã đề vào Section.
     *
     * @param Section $section
     * @param string  $label    Ví dụ: 'A', 'B', 'C'
     */
    private function writeExamHeader(Section $section, string $label): void
    {
        $style = [
            'bold'      => true,
            'size'      => 14,
            'alignment' => \PhpOffice\PhpWord\SimpleType\Jc::CENTER,
        ];

        $section->addText("MÃ ĐỀ: {$label}", $style, ['alignment' => \PhpOffice\PhpWord\SimpleType\Jc::CENTER]);
        $section->addTextBreak(1);
    }

    /**
     * Ghi một câu hỏi (đã trộn) vào Section.
     *
     * @param Section $section
     * @param array   $question  Cấu trúc { question, answers }
     * @param int     $index     Số thứ tự câu (1-based)
     */
    private function writeQuestion(Section $section, array $question, int $index): void
    {
        // Câu hỏi — đánh lại số thứ tự
        $questionText = preg_replace(self::QUESTION_PATTERN, "Câu {$index}: ", $question['question']);
        $section->addText($questionText, ['bold' => true]);

        // Các đáp án
        foreach ($question['answers'] as $answer) {
            $section->addText($answer);
        }

        $section->addTextBreak(1); // dòng trống giữa các câu
    }

    /**
     * Lưu PhpWord document ra file tạm và trả về đường dẫn.
     *
     * @param  PhpWord $phpWord
     * @return string  Đường dẫn file .docx tạm
     *
     * @throws RuntimeException nếu không ghi được
     */
    // private function saveToTemp(PhpWord $phpWord): string
    // {
    //     $tempPath = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'quiz_' . uniqid('', true) . '.docx';

    //     $writer = IOFactory::createWriter($phpWord, 'Word2007');
    //     $writer->save($tempPath);

    //     if (!file_exists($tempPath)) {
    //         throw new RuntimeException('Không thể tạo file output.');
    //     }

    //     return $tempPath;
    // }

    private function saveToTemp(PhpWord $phpWord): string
    {
        // 1. TẠO NHÀ CHO PHPWORD: Lấy thư mục storage của Laravel
        $laravelStorageTemp = storage_path('app/temp_uploads');
        
        // Nếu thư mục chưa có thì tự động tạo
        if (!is_dir($laravelStorageTemp)) {
            mkdir($laravelStorageTemp, 0777, true);
        }

        // 2. ÉP BUỘC PHPWORD: Chỉ được dùng thư mục này để nén/giải nén file nháp
        \PhpOffice\PhpWord\Settings::setTempDir($laravelStorageTemp);

        // 3. ĐẶT ĐƯỜNG DẪN ĐẦU RA: File docx mới cũng sẽ nằm gọn trong nhà của Laravel
        $tempPath = $laravelStorageTemp . DIRECTORY_SEPARATOR . 'quiz_' . uniqid('', true) . '.docx';

        // 4. Bắt đầu ghi file
        $writer = IOFactory::createWriter($phpWord, 'Word2007');
        $writer->save($tempPath);

        if (!file_exists($tempPath)) {
            throw new RuntimeException('Không thể tạo file output tại: ' . $tempPath);
        }

        return $tempPath;
    }
}