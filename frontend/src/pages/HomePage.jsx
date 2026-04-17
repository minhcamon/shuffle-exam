import { useState } from 'react'
import { useNavigate } from 'react-router-dom'
import FileUploader from '../components/FileUploader.jsx'
import { shuffleQuiz } from '../api/quizApi.js'
import './HomePage.css'

function HomePage() {
  const navigate = useNavigate()
  const [file, setFile] = useState(null)
  const [copies, setCopies] = useState(4)
  const [isLoading, setIsLoading] = useState(false)
  const [progress, setProgress] = useState(0)
  const [apiError, setApiError] = useState(null)

  const handleSubmit = async (e) => {
    e.preventDefault()
    if (!file) return

    setIsLoading(true)
    setApiError(null)
    setProgress(0)

    try {
      const blob = await shuffleQuiz(file, copies, setProgress)

      // Tạo link tải về
      const url = URL.createObjectURL(blob)
      const fileName = `de-thi-tron-${copies}-ma_${Date.now()}.docx`

      // Chuyển sang trang Result với dữ liệu download
      navigate('/result', { state: { downloadUrl: url, fileName, copies } })
    } catch (err) {
      if (err.response?.data instanceof Blob) {
        const errorText = await err.response.data.text();
        try {
          const errorJson = JSON.parse(errorText);
          console.error("🔥 LỖI TỪ LARAVEL:", errorJson);
          setApiError(errorJson.message || "Lỗi xử lý file.");
        } catch (parseError) {
          // Nếu parse JSON thất bại (nghĩa là Laravel vẫn cứng đầu trả HTML)
          console.error("🔥 LỖI CHƯA BIẾT (HTML/Text):", errorText);
          setApiError("Lỗi hệ thống từ Backend. Vui lòng kiểm tra log.");
        }
      } else {
        setApiError('Không thể kết nối đến máy chủ.');
      }
    } finally {
      setIsLoading(false)
    }
  }

  return (
    <div className="home-page">
      {/* Hero Header */}
      <header className="hero fade-in-up">
        <div className="hero__badge">✨ Miễn phí &amp; Không lưu dữ liệu</div>
        <h1 className="hero__title">
          Trộn Đề Thi<br />
          <span className="hero__title--accent">Trắc Nghiệm</span>
        </h1>
        <p className="hero__subtitle">
          Upload file Word (.docx) — nhận ngay nhiều mã đề đã được trộn ngẫu nhiên.<br />
          Xử lý hoàn toàn trên server, không lưu file của bạn.
        </p>
      </header>

      {/* Upload Form */}
      <main className="container">
        <section className="card upload-card fade-in-up" style={{ '--delay': '0.1s' }}>
          <form onSubmit={handleSubmit} noValidate>
            {/* Step 1: Upload */}
            <div className="form-section">
              <label className="form-label">
                <span className="step-badge">1</span>
                Chọn file đề thi gốc
              </label>
              <FileUploader onFileSelect={setFile} disabled={isLoading} />
            </div>

            {/* Step 2: Copies */}
            <div className="form-section">
              <label className="form-label" htmlFor="copies-input">
                <span className="step-badge">2</span>
                Số lượng mã đề cần tạo
              </label>
              <div className="copies-control">
                <input
                  id="copies-input"
                  type="number"
                  min={2}
                  max={26}
                  value={copies}
                  onChange={(e) => setCopies(Number(e.target.value))}
                  disabled={isLoading}
                  className="copies-input"
                />
                <span className="copies-hint">mã đề (tối đa 26 — từ A đến Z)</span>
              </div>
            </div>

            {/* Error */}
            {apiError && (
              <div className="api-error" role="alert">
                ❌ {apiError}
              </div>
            )}

            {/* Progress */}
            {isLoading && (
              <div className="progress-wrapper" aria-live="polite">
                <div className="progress-bar">
                  <div className="progress-fill" style={{ width: `${progress}%` }} />
                </div>
                <p className="progress-text">
                  {progress < 100
                    ? `Đang tải lên... ${progress}%`
                    : 'Đang xử lý trên server...'}
                </p>
              </div>
            )}

            {/* Submit */}
            <button
              id="shuffle-btn"
              type="submit"
              className="btn btn-primary submit-btn"
              disabled={!file || isLoading}
            >
              {isLoading ? (
                <><span className="spinner" />Đang xử lý...</>
              ) : (
                <>🔀 Trộn đề ngay</>
              )}
            </button>
          </form>
        </section>

        {/* Feature bullets */}
        <section className="features fade-in-up" aria-label="Tính năng nổi bật">
          {[
            { icon: '⚡', title: 'Xử lý nhanh', desc: 'Thuật toán Fisher-Yates O(n) trên RAM' },
            { icon: '🔒', title: 'Bảo mật', desc: 'File không được lưu lại trên server' },
            { icon: '📦', title: 'Miễn phí', desc: 'Không cần đăng ký tài khoản' },
          ].map(({ icon, title, desc }) => (
            <div key={title} className="feature-card card">
              <span className="feature-icon">{icon}</span>
              <h3 className="feature-title">{title}</h3>
              <p className="feature-desc">{desc}</p>
            </div>
          ))}
        </section>
      </main>
    </div>
  )
}

export default HomePage
