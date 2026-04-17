<?php
// routes/api.php
// ─────────────────────────────────────────────────────────────────────────────
// Laravel 11 API routes (không dùng web.php, session, CSRF)
// ─────────────────────────────────────────────────────────────────────────────

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\QuizController;
use App\Http\Controllers\Api\HealthController;

/*
|--------------------------------------------------------------------------
| Health Check — Render dùng để kiểm tra service còn sống không
|--------------------------------------------------------------------------
*/
Route::get('/health', [HealthController::class, 'index']);

/*
|--------------------------------------------------------------------------
| Quiz Endpoints
|--------------------------------------------------------------------------
*/
Route::prefix('quiz')->group(function () {
    // POST /api/quiz/shuffle
    // Body: multipart/form-data { file: File, copies: int }
    // Response: application/octet-stream (.docx Blob)
    Route::post('/shuffle', [QuizController::class, 'shuffle']);

    // POST /api/quiz/preview
    // Body: multipart/form-data { file: File }
    // Response: JSON { questions: [...] }
    Route::post('/preview', [QuizController::class, 'preview']);
});
