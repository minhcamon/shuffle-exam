# 🔀 Shuffle Exam — Trộn Đề Thi Trắc Nghiệm

> **Dự án mã nguồn mở** · © 2026 [minhcamon](https://github.com/minhcamon) · [MIT License](#-license)

Ứng dụng web **miễn phí, stateless** giúp giáo viên trộn ngẫu nhiên câu hỏi & đáp án trong đề thi trắc nghiệm (.docx) để tạo nhiều mã đề khác nhau — **không lưu file, không đăng ký tài khoản**.

**Stack:** React + Vite (Frontend) · Laravel 11 API-only (Backend)  
**Deploy:** Vercel (Frontend) · Render Free Tier (Backend)

---

## ✨ Tính Năng

| Tính năng | Mô tả |
|---|---|
| 📂 **Upload & Validate** | Drag & Drop hoặc click chọn file `.docx`, kiểm tra định dạng và dung lượng (≤ 20MB) |
| 👁 **Preview nội dung** | Xem trước nội dung đề thi gốc ngay trên trình duyệt (dùng mammoth.js) trước khi trộn |
| 🔀 **Trộn thông minh** | Xáo ngẫu nhiên vị trí câu hỏi và đáp án, tự cập nhật số thứ tự & đáp án đúng |
| 📦 **Xuất file ZIP** | Tải về một file `.zip` chứa N file `.docx` mã đề riêng biệt |
| 🌙 **Dark / Light Mode** | Chuyển đổi giao diện, lưu lựa chọn vào `localStorage` |
| ⏳ **Wake-up Banner** | Thông báo thân thiện khi Render free-tier đang khởi động lại (cold start) |
| 🔒 **Bảo mật** | File xử lý hoàn toàn trên RAM, tự xóa ngay sau khi gửi về client |

---

## 🗂️ Cấu Trúc Thư Mục

```
shuffle-exam/
│
├── frontend/                          ← React + Vite (deploy → Vercel)
│   └── src/
│       ├── api/
│       │   └── quizApi.js             ← Axios: gọi API backend
│       ├── components/
│       │   ├── FileUploader.jsx/css   ← Drag & Drop upload zone
│       │   ├── PreviewModal.jsx/css   ← Modal xem trước .docx (mammoth.js)
│       │   └── WakeUpBanner.jsx/css   ← Banner "server đang thức dậy"
│       ├── pages/
│       │   ├── HomePage.jsx/css       ← Trang upload + cấu hình
│       │   ├── ResultPage.jsx/css     ← Trang download kết quả
│       │   └── NotFoundPage.jsx       ← 404
│       ├── App.jsx                    ← Router + Dark/Light theme
│       └── index.css                  ← Design system / Global CSS tokens
│
└── backend/                           ← Laravel 11 API (deploy → Render)
    └── app/
        ├── Http/
        │   ├── Controllers/Api/
        │   │   ├── QuizController.php     ← Thin controller
        │   │   └── HealthController.php   ← GET /api/health
        │   ├── Middleware/
        │   │   └── CorsMiddleware.php     ← CORS whitelist
        │   └── Requests/
        │       └── ShuffleQuizRequest.php ← Validation form request
        └── Services/
            └── QuizShufflerService.php    ← Parser XML + Fisher-Yates shuffle
```

---

## ⚡ Chạy Localhost

### Yêu cầu hệ thống

| Tool | Phiên bản tối thiểu |
|------|---------------------|
| Node.js | 20+ |
| PHP | 8.2+ |
| Composer | 2.x |

### Terminal 1 — Backend Laravel

```bash
cd backend

composer install

cp .env.render .env.example
cp .env.example .env

php artisan key:generate

php artisan serve --port=8000
```

> API health check: **http://localhost:8000/api/health**

### Terminal 2 — Frontend React (Vite)

```bash
cd frontend

npm install

npm run dev
```

> App chạy tại: **http://localhost:5173**  
> Vite tự động **proxy** `/api/*` → `localhost:8000` qua `vite.config.js`.

### Kiểm tra nhanh bằng cURL

```bash
# Health check
curl http://localhost:8000/api/health

# Test shuffle
curl -X POST http://localhost:8000/api/quiz/shuffle \
  -F "file=@/path/to/de-thi.docx" \
  -F "copies=4" \
  --output result.zip
```

---

## 🚀 Deploy lên Production

### Frontend → Vercel

```bash
cd frontend
npm install -g vercel
vercel --prod
```

**Biến môi trường cần đặt trong Vercel Dashboard:**

| Key | Value |
|-----|-------|
| `VITE_API_BASE_URL` | `https://<your-render-app>.onrender.com` |

### Backend → Render

1. Push repo lên GitHub
2. Vào [render.com](https://render.com) → **New** → **Web Service**
3. Connect GitHub repo (thư mục `backend/`)
4. Render tự đọc `render.yaml` và cấu hình
5. Đặt biến môi trường trong tab **Environment**:
   - `FRONTEND_URL` = URL Vercel của bạn
   - `APP_URL` = URL Render của bạn

> ⏱️ **Render Free Tier**: Service sẽ "ngủ" sau 15 phút không có request.  
> Request đầu tiên sau cold start mất **~30–60 giây** — UI sẽ hiển thị banner thông báo tự động.

---

## 🏗️ Kiến Trúc & Luồng Dữ Liệu

```
User Browser
    │
    │ 1. Chọn file .docx + số mã đề
    │    (Preview tùy chọn bằng mammoth.js — không gửi server)
    ▼
React App (Vercel)
    │
    │ 2. POST /api/quiz/shuffle (multipart/form-data)
    │    <CORS: Vercel → Render>
    ▼
Laravel API (Render)
    │
    ├── ShuffleQuizRequest.php   → Validate file
    ├── QuizController.php       → Điều phối
    └── QuizShufflerService.php  → Parse XML + Fisher-Yates shuffle
        ├── prepareWorkspace()   → Giải nén .docx vào workspace tạm
        ├── parseDocumentSections() → Bóc tách câu hỏi/đáp án từ DOM
        ├── shuffleAndRebuild()  → Xáo & ráp lại DOM
        └── packExamCopy()       → Đóng gói .docx → ZIP
    │
    │ 3. Trả về file .zip (binary stream)
    │    Workspace và file tạm tự động xóa
    ▼
React App
    │
    │ 4. createObjectURL(blob) → Chuyển sang ResultPage → Trigger download
    ▼
User nhận file .zip chứa N mã đề .docx
```

---

## 🧩 Thuật Toán Trộn Đề

`QuizShufflerService` xử lý theo 5 bước tách biệt, dễ debug:

| Bước | Hàm | Mô tả |
|------|-----|-------|
| 1 | `prepareWorkspace()` | Giải nén `.docx` (thực chất là ZIP), backup XML gốc |
| 2a | `parseDocumentSections()` | Parse `document.xml` → cấu trúc `sections → questions → answers` |
| 2b | `shuffleAndRebuild()` | Fisher-Yates xáo câu hỏi; delegate đáp án → `shuffleAnswers()` |
| 2c | `packExamCopy()` | Lưu DOM → zip thành `.docx` tạm |
| 3 | `cleanup()` | Xóa workspace và file tạm |

**Tính năng đặc biệt:**
- ✅ Hỗ trợ đáp án dạng **đoạn văn** (A. B. C. D.) và **bảng** (table layout)
- ✅ Chốt chặn layout ngang: không xáo nếu nhiều đáp án cùng dòng
- ✅ Hỗ trợ câu **Đúng/Sai** (Phần II) — cập nhật nhãn theo vị trí sau xáo
- ✅ Giữ nguyên dòng trắng và khoảng cách gốc tránh vỡ layout Word

---

## 📦 Dependencies Chính

### Frontend
| Package | Mục đích |
|---------|---------|
| `react` + `react-router-dom` | SPA routing |
| `axios` | HTTP client |
| `mammoth` | Parse `.docx` → HTML (preview trên browser) |
| `vite` | Build tool + dev proxy |

### Backend
| Package | Mục đích |
|---------|---------|
| `laravel/framework` | API framework |
| `ext-zip` | Đọc/ghi `.docx` (ZIP container) |
| `ext-xml` / `DOMDocument` | Parse `word/document.xml` |
| `ext-mbstring` | Xử lý chuỗi UTF-8 tiếng Việt |

---

## 📝 Ghi Chú Kỹ Thuật

- File `.docx` thực chất là một **ZIP** chứa XML. Backend giải nén, chỉnh sửa `word/document.xml` trực tiếp qua `DOMDocument`, rồi đóng gói lại — không dùng thư viện PHPWord.
- Trên **Render Free**, `ext-zip`, `ext-xml`, `ext-mbstring` đã có sẵn trong Ubuntu 22.04.
- Kiểm tra PHP extensions trên Windows localhost:
  ```bash
  php -m | grep -E "zip|xml|mbstring"
  ```

---

## 📄 License

Dự án được phát hành dưới giấy phép **MIT License**.

```
MIT License

Copyright (c) 2026 minhcamon (https://github.com/minhcamon)

Permission is hereby granted, free of charge, to any person obtaining a copy
of this software and associated documentation files (the "Software"), to deal
in the Software without restriction, including without limitation the rights
to use, copy, modify, merge, publish, distribute, sublicense, and/or sell
copies of the Software, and to permit persons to whom the Software is
furnished to do so, subject to the following conditions:

The above copyright notice and this permission notice shall be included in all
copies or substantial portions of the Software.

THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS OR
IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF MERCHANTABILITY,
FITNESS FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT. IN NO EVENT SHALL THE
AUTHORS OR COPYRIGHT HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER
LIABILITY, WHETHER IN AN ACTION OF CONTRACT, TORT OR OTHERWISE, ARISING FROM,
OUT OF OR IN CONNECTION WITH THE SOFTWARE OR THE USE OR OTHER DEALINGS IN THE
SOFTWARE.
```

---

<div align="center">

Made with ❤️ in Vietnam by [minhcamon](https://github.com/minhcamon)

</div>
