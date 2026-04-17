<?php
// app/Http/Requests/ShuffleQuizRequest.php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Http\Response;

/**
 * ShuffleQuizRequest
 *
 * Validate file upload trước khi chạm vào Controller.
 * Trả lỗi dạng JSON (không redirect như web form).
 */
class ShuffleQuizRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // API public, không cần auth
    }

    public function rules(): array
    {
        return [
            'file'   => [
                'required',
                'file',
                'mimes:docx',                     // chỉ chấp nhận .docx
                'max:20480',                       // 20 MB
            ],
            'copies' => [
                'sometimes',
                'integer',
                'min:2',
                'max:26',                          // tối đa 26 mã đề (A–Z)
            ],
        ];
    }

    public function messages(): array
    {
        return [
            'file.required' => 'Bạn chưa chọn file.',
            'file.mimes'    => 'Chỉ chấp nhận file định dạng .docx.',
            'file.max'      => 'File không được vượt quá 20MB.',
            'copies.min'    => 'Cần ít nhất 2 mã đề.',
            'copies.max'    => 'Tối đa 26 mã đề.',
        ];
    }

    // Override để trả về JSON thay vì redirect
    protected function failedValidation(Validator $validator): never
    {
        throw new HttpResponseException(
            response()->json([
                'message' => 'Dữ liệu không hợp lệ.',
                'errors'  => $validator->errors(),
            ], Response::HTTP_UNPROCESSABLE_ENTITY)
        );
    }
}
