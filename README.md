# 🔀 Quiz Shuffler — Trộn Đề Thi Trắc Nghiệm

**Stateless Web App** · **ReactJS (Vite) + Laravel 11 API-only**  
Deploy: **Vercel** (Frontend) · **Render Free** (Backend)

---

## 🗂️ Cấu Trúc Thư Mục

```
quiz-shuffler/
│
├── frontend/                         ← React + Vite (deploy → Vercel)
│   ├── public/
│   │   └── vite.svg
│   ├── src/
│   │   ├── api/
│   │   │   └── quizApi.js            ← Axios: gọi API backend
│   │   ├── components/
│   │   │   ├── FileUploader.jsx      ← Drag & Drop upload component
│   │   │   └── FileUploader.css
│   │   ├── pages/
│   │   │   ├── HomePage.jsx          ← Trang upload + cấu hình
│   │   │   ├── HomePage.css
│   │   │   ├── ResultPage.jsx        ← Trang download kết quả
│   │   │   ├── ResultPage.css
│   │   │   └── NotFoundPage.jsx      ← 404
│   │   ├── App.jsx                   ← Router
│   │   ├── main.jsx                  ← Entry point
│   │   └── index.css                 ← Design system / Global CSS
│   ├── index.html
│   ├── vite.config.js                ← Proxy /api → localhost:8000
│   ├── vercel.json                   ← SPA routing fallback
│   ├── package.json
│   ├── .env                          ← VITE_API_BASE_URL
│   └── .gitignore
│
└── backend/                          ← Laravel 11 API (deploy → Render)
    ├── app/
    │   ├── Http/
    │   │   ├── Controllers/
    │   │   │   └── Api/
    │   │   │       ├── QuizController.php    ← Thin controller
    │   │   │       └── HealthController.php  ← GET /api/health
    │   │   ├── Middleware/
    │   │   │   └── CorsMiddleware.php        ← CORS whitelist
    │   │   └── Requests/
    │   │       └── ShuffleQuizRequest.php    ← Validation form request
    │   └── Services/
    │       └── QuizShufflerService.php       ← Fisher-Yates + PHPWord
    ├── routes/
    │   └── api.php                   ← POST /api/quiz/shuffle, /preview
    ├── build.sh                      ← Script build cho Render
    ├── render.yaml                   ← IaC config cho Render
    ├── composer.json                 ← Laravel 11 + phpoffice/phpword
    ├── .env.render                   ← Template .env production
    └── .gitignore
```

---

## ⚡ Chạy Localhost (Song Song 2 Terminal)

### Yêu cầu hệ thống

| Tool | Phiên bản tối thiểu |
|------|---------------------|
| Node.js | 20+ |
| PHP | 8.2+ |
| Composer | 2.x |

---

### Terminal 1 — Backend Laravel

```bash
# 1. Vào thư mục backend
cd backend

# 2. Cài dependencies PHP
composer install

# 3. Tạo file .env từ template
cp .env.render .env.example
cp .env.example .env

# 4. Sinh APP_KEY
php artisan key:generate

# 5. Khởi động server (port 8000)
php artisan serve --port=8000
```

> API sẽ sẵn sàng tại: **http://localhost:8000/api/health**

---

### Terminal 2 — Frontend React (Vite)

```bash
# 1. Vào thư mục frontend
cd frontend

# 2. Cài dependencies Node
npm install

# 3. Chạy dev server (port 5173)
npm run dev
```

> App sẽ mở tại: **http://localhost:5173**

> 💡 Vite tự động **proxy** mọi request `/api/*` sang `localhost:8000`  
> (cấu hình trong `vite.config.js`) — bạn **không cần** sửa gì thêm.

---

### Kiểm tra nhanh bằng cURL

```bash
# Health check
curl http://localhost:8000/api/health

# Test upload file
curl -X POST http://localhost:8000/api/quiz/shuffle \
  -F "file=@/path/to/de-thi.docx" \
  -F "copies=4" \
  --output result.docx
```

---

## 🚀 Deploy lên Production

### Frontend → Vercel

```bash
cd frontend

# Cài Vercel CLI (1 lần duy nhất)
npm install -g vercel

# Deploy lần đầu
vercel

# Deploy lại (sau khi sửa code)
vercel --prod
```

**Đừng quên đặt biến môi trường trong Vercel Dashboard:**

| Key | Value |
|-----|-------|
| `VITE_API_BASE_URL` | `https://quiz-shuffler-api.onrender.com` |

---

### Backend → Render

1. Push thư mục `backend/` lên GitHub repository riêng
2. Vào [render.com](https://render.com) → **New** → **Web Service**
3. Connect GitHub repo
4. Render sẽ **tự đọc `render.yaml`** và cấu hình toàn bộ
5. Trong **Environment** tab:
   - Thay `FRONTEND_URL` = URL Vercel của bạn
   - Thay `APP_URL` = URL Render của bạn

> ⏱️ **Lưu ý Render Free**: Service sẽ "ngủ" sau 15 phút không có request.  
> Request đầu tiên sau khi ngủ sẽ mất ~30–60 giây để khởi động lại (cold start).

---

## 🏗️ Kiến Trúc & Luồng Dữ Liệu

```
User Browser
    │
    │ 1. Upload file .docx + chọn số mã đề
    ▼
React App (Vercel)
    │
    │ 2. POST /api/quiz/shuffle (multipart/form-data)
    │    <CORS: Vercel → Render>
    ▼
Laravel API (Render)
    │
    ├── ShuffleQuizRequest.php  → Validate file
    ├── QuizController.php      → Điều phối
    └── QuizShufflerService.php → Parse + Fisher-Yates + PHPWord
                                   (toàn bộ trên RAM)
    │
    │ 3. Trả về file .docx (binary stream)
    │    File tạm tự động xóa sau khi gửi
    ▼
React App
    │
    │ 4. createObjectURL(blob) → Trigger download
    ▼
User nhận file .docx đã trộn
```

---

## 📚 Các Bước Tiếp Theo (Learning Path)

- [ ] **Bước 1**: Khởi tạo Laravel project thực sự (`laravel new backend --no-interaction`)
- [ ] **Bước 2**: Đăng ký `CorsMiddleware` trong `bootstrap/app.php`
- [ ] **Bước 3**: Test `QuizShufflerService` với file `.docx` thực tế
- [ ] **Bước 4**: Viết Unit Test cho `fisherYatesShuffle()`
- [ ] **Bước 5**: Thêm tính năng "preview câu hỏi" trên UI trước khi download
- [ ] **Bước 6**: Deploy và test end-to-end

---

## 📝 Ghi chú về PHPWord

PHPWord dùng `ext-zip` để đọc/ghi `.docx` (vì .docx thực chất là file ZIP).  
`ext-xml` dùng để parse XML bên trong.

Trên **Render Free**, cả 2 extension này **đã có sẵn** trong Ubuntu 22.04.  
`build.sh` vẫn cài lại để đảm bảo chắc chắn.

Trên **Windows localhost**, kiểm tra bằng:
```bash
php -m | grep -E "zip|xml|mbstring"
```
