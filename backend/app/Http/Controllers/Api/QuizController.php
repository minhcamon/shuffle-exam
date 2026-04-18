<?php
// app/Http/Controllers/Api/QuizController.php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\ShuffleQuizRequest;
use App\Services\QuizShufflerService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Illuminate\Support\Facades\Log; // THÊM DÒNG NÀY (Nếu chưa có)
use Illuminate\Support\Facades\Storage;
use Exception;
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
    // public function shuffle(ShuffleQuizRequest $request): BinaryFileResponse|JsonResponse
    // {
    //     try {
    //         $uploadedFile = $request->file('file');
    //         $copies       = (int) $request->input('copies', 4);

    //         // 1. CHIẾN THUẬT MỚI: Cất vào kho và ÉP BẮT BUỘC PHẢI CÓ ĐUÔI .DOCX
    //         $tempFileName = 'upload_' . uniqid() . '.docx';
    //         $savedPath = $uploadedFile->storeAs('temp_uploads', $tempFileName); 
            
    //         // 2. Trích xuất đường dẫn tuyệt đối (Lúc này nó sẽ là: .../temp_uploads/upload_12345.docx)
    //         $absolutePath = Storage::path($savedPath); 

    //         // Giao cho Service xử lý
    //         $outputPath = $this->shufflerService->shuffle(
    //             filePath: $absolutePath,
    //             copies:   $copies,
    //         );

    //         // Dọn rác
    //         if (file_exists($absolutePath)) {
    //             unlink($absolutePath);
    //         }

    //         // 4. Trả file kết quả về và tự xóa file kết quả
    //         return response()->download(
    //             file:    $outputPath,
    //             name:    $fileName,
    //             headers: [
    //                 'Content-Type'        => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
    //                 'Content-Disposition' => "attachment; filename=\"{$fileName}\"",
    //                 'Cache-Control'       => 'no-store',
    //             ],
    //         )->deleteFileAfterSend(true);

    //     } catch (Throwable $e) {
    //         report($e);
    //         return response()->json([
    //             'message' => 'Không thể xử lý file. Hãy đảm bảo file đúng định dạng .docx.',
    //             'error'   => config('app.debug') ? $e->getMessage() : null,
    //         ], Response::HTTP_UNPROCESSABLE_ENTITY);
    //     }
    // }

    public function shuffle(ShuffleQuizRequest $request): BinaryFileResponse|JsonResponse
    {
        Log::info('========== BẮT ĐẦU REQUEST TRỘN ĐỀ ==========');
        
        try {
            $uploadedFile = $request->file('file');
            $copies       = (int) $request->input('copies', 4);

            // 1. Log thông tin file gốc ngay khi nhận được từ React
            Log::info('[1] THÔNG TIN FILE GỐC TỪ FRONTEND:', [
                'Tên file gốc' => $uploadedFile->getClientOriginalName(),
                'Đuôi file' => $uploadedFile->getClientOriginalExtension(),
                'Dung lượng' => $uploadedFile->getSize() . ' bytes',
                'MIME type (Client báo)' => $uploadedFile->getClientMimeType(),
            ]);

            // 2. LƯU FILE BẰNG HÀM MOVE() GỐC (Bỏ qua Storage ảo của Laravel)
            $destinationPath = storage_path('app/temp_uploads'); // Đường dẫn thư mục vật lý
            $tempFileName = 'upload_' . time() . '_' . uniqid() . '.docx';
            
            // Lệnh này di chuyển thẳng file vào thư mục đích
            $uploadedFile->move($destinationPath, $tempFileName); 
            
            // Đường dẫn tuyệt đối giờ đây rất rõ ràng
            $absolutePath = $destinationPath . DIRECTORY_SEPARATOR . $tempFileName;

            // 3. Log thông tin file SAU KHI LƯU VÀO KHO
            Log::info('[2] THÔNG TIN FILE SAU KHI LƯU VÀO KHO:', [
                'Đường dẫn tuyệt đối' => $absolutePath,
                'File có tồn tại không?' => file_exists($absolutePath) ? 'CÓ TỒN TẠI' : 'KHÔNG THẤY FILE',
            ]);

            // Chỉ lấy filesize và mime type nếu file thực sự tồn tại
            if (file_exists($absolutePath)) {
                Log::info('[3] KIỂM TRA CHẤT LƯỢNG FILE VẬT LÝ:', [
                    'Dung lượng thực tế trên đĩa' => filesize($absolutePath) . ' bytes',
                    'MIME type thực tế của OS' => mime_content_type($absolutePath),
                    'Quyền đọc/ghi (Permissions)' => substr(sprintf('%o', fileperms($absolutePath)), -4),
                ]);
            } else {
                throw new \Exception("Chưa kịp đọc thì file đã bốc hơi khỏi: " . $absolutePath);
            }

            Log::info('[4] CHUẨN BỊ GIAO CHO PHPWORD ĐỌC...');

            // 4. Giao cho Service xử lý
            $outputPath = $this->shufflerService->shuffle(
                filePath: $absolutePath,
                copies:   $copies,
            );

            Log::info('[5] PHPWORD ĐÃ TRỘN XONG! Đường dẫn file kết quả: ' . $outputPath);

            // Dọn rác
            if (file_exists($absolutePath)) {
                unlink($absolutePath);
            }

           $fileName = 'Bo_De_Thi_Tron_' . $copies . '_Ma_' . time() . '.zip'; // Sửa chữ .docx thành .zip

            return response()->download(
                file:    $outputPath,
                name:    $fileName,
                headers: [
                    'Content-Type'        => 'application/zip', // Sửa content type thành zip
                    'Content-Disposition' => "attachment; filename=\"{$fileName}\"",
                    'Cache-Control'       => 'no-store',
                ],
            )->deleteFileAfterSend(true);

        } catch (\Throwable $e) {
            // Ghi nhận toàn bộ lỗi chi tiết vào file log
            Log::error('========== LỖI CRASH HỆ THỐNG ==========');
            Log::error('Thông báo lỗi: ' . $e->getMessage());
            Log::error('Dòng gây lỗi: ' . $e->getFile() . ' (Line ' . $e->getLine() . ')');
            
            return response()->json([
                'message' => 'Không thể xử lý file. Hãy đảm bảo file đúng định dạng .docx.',
                'error'   => config('app.debug') ? $e->getMessage() : null,
            ], \Illuminate\Http\Response::HTTP_UNPROCESSABLE_ENTITY); // Thay bằng 422
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