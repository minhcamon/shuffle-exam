<?php
// app/Http/Controllers/Api/QuizController.php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\ShuffleQuizRequest;
use App\Services\QuizShufflerService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Throwable;

/**
 * QuizController
 *
 * Nhận file .docx từ Frontend, ủy nhiệm xử lý cho QuizShufflerService,
 * rồi stream kết quả về client dưới dạng file tải về.
 *
 * Pattern: Thin Controller — toàn bộ business logic nằm trong Service.
 */
class QuizController extends Controller
{
    public function __construct(
        private readonly QuizShufflerService $shufflerService
    ) {}

    // ──────────────────────────────────────────────────────────────────────
    // POST /api/quiz/shuffle
    // Trả về: file .docx (binary stream)
    // ──────────────────────────────────────────────────────────────────────
    public function shuffle(ShuffleQuizRequest $request): BinaryFileResponse|JsonResponse
    {
        try {
            $uploadedFile = $request->file('file');
            $copies       = (int) $request->input('copies', 4);

            // Xử lý trên RAM, trả về đường dẫn file tạm
            $outputPath = $this->shufflerService->shuffle(
                filePath: $uploadedFile->getRealPath(),
                copies:   $copies,
            );

            $fileName = 'de-thi-tron-' . $copies . 'ma_' . time() . '.docx';

            return response()->download(
                file:    $outputPath,
                name:    $fileName,
                headers: [
                    'Content-Type'        => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
                    'Content-Disposition' => "attachment; filename=\"{$fileName}\"",
                    'Cache-Control'       => 'no-store',
                ],
            )->deleteFileAfterSend(true); // Tự xóa file tạm sau khi gửi xong

        } catch (Throwable $e) {
            report($e);
            return response()->json([
                'message' => 'Không thể xử lý file. Hãy đảm bảo file đúng định dạng .docx.',
                'error'   => config('app.debug') ? $e->getMessage() : null,
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }
    }

    // ──────────────────────────────────────────────────────────────────────
    // POST /api/quiz/preview
    // Trả về: JSON { questions: [...] } — để frontend hiển thị trước
    // ──────────────────────────────────────────────────────────────────────
    public function preview(ShuffleQuizRequest $request): JsonResponse
    {
        try {
            $uploadedFile = $request->file('file');

            $questions = $this->shufflerService->parseQuestions(
                filePath: $uploadedFile->getRealPath(),
            );

            return response()->json([
                'total'     => count($questions),
                'questions' => $questions,
            ]);

        } catch (Throwable $e) {
            report($e);
            return response()->json([
                'message' => 'Không thể đọc file.',
                'error'   => config('app.debug') ? $e->getMessage() : null,
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }
    }
}
