<?php
// app/Http/Controllers/Api/HealthController.php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;

/**
 * HealthController
 *
 * Endpoint GET /api/health — Render dùng để xác nhận service đang chạy.
 * Phải trả về HTTP 200 để Render đánh dấu deploy thành công.
 */
class HealthController extends Controller
{
    public function index(): JsonResponse
    {
        return response()->json([
            'status'  => 'ok',
            'service' => 'Quiz Shuffler API',
            'time'    => now()->toIso8601String(),
            'php'     => PHP_VERSION,
        ]);
    }
}
