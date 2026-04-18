/**
 * api/quizApi.js
 * ─────────────────────────────────────────────────────────────────────────────
 * Centralized API layer. Tất cả HTTP calls đến Laravel backend được
 * định nghĩa tại đây. Khi deploy, thay VITE_API_BASE_URL trong .env
 * thành URL của service Render.
 *
 * Endpoint dự kiến:
 *   POST /api/quiz/shuffle   — Upload file Word, nhận file đã trộn
 *   POST /api/quiz/preview   — (Tùy chọn) Trả về JSON để preview trên UI
 * ─────────────────────────────────────────────────────────────────────────────
 */

import axios from 'axios'

// Đọc base URL từ biến môi trường Vite. Trong lúc dev, proxy vite.config.js
// sẽ forward /api/* → localhost:8000, nên để rỗng cũng được.
const BASE_URL = import.meta.env.VITE_API_BASE_URL ?? ''

const apiClient = axios.create({
  baseURL: BASE_URL,
  timeout: 60_000, // 60s — cho phép file lớn xử lý trên Render Free
  headers: {
    Accept: 'application/json',
  },
})

// ─── Response interceptor: log lỗi toàn cục ───
apiClient.interceptors.response.use(
  (response) => response,
  (error) => {
    const message =
      error.response?.data?.message ?? error.message ?? 'Đã xảy ra lỗi không xác định.'
    console.error('[API Error]', message, error)
    return Promise.reject(error)
  },
)

// ─── API Functions ───

/**
 * Gửi file Word lên backend để trộn đề thi.
 *
 * @param {File}       file        - File .docx từ input[type=file]
 * @param {number}     copies      - Số lượng mã đề cần tạo (mặc định 4)
 * @param {Function}   onProgress  - Callback nhận phần trăm upload (0–100)
 * @param {string[]}   codes       - Mảng mã đề tùy chọn (VD: ['101','202','303','404'])
 *                                   Nếu rỗng, backend tự sinh ngẫu nhiên.
 * @returns {Promise<Blob>}         - File ZIP chứa các .docx đã trộn
 */
export async function shuffleQuiz(file, copies = 4, onProgress = null, codes = []) {
  const formData = new FormData()
  formData.append('file', file)
  formData.append('copies', copies)

  // Gửi từng mã đề riêng lẻ → Laravel nhận dưới dạng codes[] (array)
  codes.forEach((code) => formData.append('codes[]', code.trim()))

  const response = await apiClient.post('/api/quiz/shuffle', formData, {
    responseType: 'blob',
    headers: { 'Content-Type': 'multipart/form-data' },
    onUploadProgress: (progressEvent) => {
      if (onProgress && progressEvent.total) {
        const percent = Math.round((progressEvent.loaded * 100) / progressEvent.total)
        onProgress(percent)
      }
    },
  })

  return response.data // Blob
}

/**
 * Gọi endpoint preview (tuỳ chọn): nhận JSON danh sách câu hỏi đã trộn.
 *
 * @param {File} file
 * @returns {Promise<Object>} - { questions: Array }
 */
export async function previewQuiz(file) {
  const formData = new FormData()
  formData.append('file', file)

  const response = await apiClient.post('/api/quiz/preview', formData, {
    headers: { 'Content-Type': 'multipart/form-data' },
  })

  return response.data
}
