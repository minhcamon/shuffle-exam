import { useState, useRef } from 'react'
import { useNavigate } from 'react-router-dom'
import FileUploader from '../components/FileUploader.jsx'
import PreviewModal from '../components/PreviewModal.jsx'
import WakeUpBanner from '../components/WakeUpBanner.jsx'
import { shuffleQuiz } from '../api/quizApi.js'
import './HomePage.css'

function HomePage() {
  const navigate = useNavigate()
  const [file, setFile] = useState(null)
  const [copies, setCopies] = useState(4)
  const [isLoading, setIsLoading] = useState(false)
  const [progress, setProgress] = useState(0)
  const [apiError, setApiError] = useState(null)
  const [showPreview, setShowPreview] = useState(false)
  const [wakeUpStartTime, setWakeUpStartTime] = useState(null) // null = ẩn banner
  const wakeUpTimerRef = useRef(null)

  const handleFileSelect = (selectedFile) => {
    setFile(selectedFile)
    setShowPreview(false) // reset khi đổi file
  }

  const handleSubmit = async (e) => {
    e.preventDefault()
    if (!file) return

    setIsLoading(true)
    setApiError(null)
    setProgress(0)
    setWakeUpStartTime(null)

    // Sau 5 giây chưa có phản hồi → hiện banner "server đang thức dậy"
    wakeUpTimerRef.current = setTimeout(() => {
      setWakeUpStartTime(Date.now())
    }, 500)

    try {
      const blob = await shuffleQuiz(file, copies, setProgress)
      const url = URL.createObjectURL(blob)
      const fileName = `de-thi-tron-${copies}-ma_${Date.now()}.zip`
      navigate('/result', { state: { downloadUrl: url, fileName, copies } })
    } catch (err) {
      if (err.response?.data instanceof Blob) {
        const errorText = await err.response.data.text()
        try {
          const errorJson = JSON.parse(errorText)
          console.error('🔥 LỖI TỪ LARAVEL:', errorJson)
          setApiError(errorJson.message || 'Lỗi xử lý file.')
        } catch {
          console.error('🔥 LỖI CHƯA BIẾT:', errorText)
          setApiError('Lỗi hệ thống từ Backend. Vui lòng kiểm tra log.')
        }
      } else {
        setApiError('Không thể kết nối đến máy chủ.')
      }
    } finally {
      // Dù thành công hay lỗi, đều reset wake-up banner
      clearTimeout(wakeUpTimerRef.current)
      setWakeUpStartTime(null)
      setIsLoading(false)
    }
  }

  return (
    <>
      <div className="home-page">
        {/* ── Centered content wrapper ── */}
        <div className="home-center">

          {/* Hero */}
          <header className="hero fade-in-up">
            <div className="hero__badge">✨ Miễn phí &amp; Không lưu dữ liệu</div>
            <h1 className="hero__title">
              Trộn Đề Thi&nbsp;
              <span className="hero__title--accent">Trắc Nghiệm</span>
            </h1>
            <p className="hero__subtitle">
              Upload file Word (.docx) — nhận ngay nhiều mã đề đã trộn ngẫu nhiên.
              Xử lý hoàn toàn trên server, không lưu file của bạn.
            </p>
          </header>

          {/* Upload Card */}
          <main>
            <section className="card upload-card fade-in-up" style={{ animationDelay: '0.1s' }}>
              <form onSubmit={handleSubmit} noValidate>
                {/* Step 1 */}
                <div className="form-section">
                  <label className="form-label">
                    <span className="step-badge">1</span>
                    Chọn file đề thi gốc
                  </label>
                  <FileUploader onFileSelect={handleFileSelect} disabled={isLoading} />

                  {/* Nút Xem trước — chỉ hiện khi đã chọn file */}
                  {file && !isLoading && (
                    <button
                      id="preview-btn"
                      type="button"
                      className="btn-preview"
                      onClick={() => setShowPreview(true)}
                    >
                      <span>👁</span>
                      <span>Xem trước nội dung</span>
                    </button>
                  )}
                </div>

                {/* Step 2 */}
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
                    <span className="copies-hint">mã đề (tối đa 26 — A → Z)</span>
                  </div>
                </div>

                {/* Error */}
                {apiError && (
                  <div className="api-error" role="alert">❌ {apiError}</div>
                )}

                {/* Progress upload (0–100%) */}
                {isLoading && progress < 100 && (
                  <div className="progress-wrapper" aria-live="polite">
                    <div className="progress-bar">
                      <div className="progress-fill" style={{ width: `${progress}%` }} />
                    </div>
                    <p className="progress-text">Đang tải lên... {progress}%</p>
                  </div>
                )}

                {/* WakeUp Banner — hiện khi server sleep (sau 5s chưa phản hồi) */}
                {isLoading && progress >= 100 && wakeUpStartTime && (
                  <WakeUpBanner startTime={wakeUpStartTime} />
                )}

                {/* Trạng thái xử lý thông thường (chưa đủ 5s) */}
                {isLoading && progress >= 100 && !wakeUpStartTime && (
                  <div className="progress-wrapper" aria-live="polite">
                    <div className="progress-bar">
                      <div className="progress-fill" style={{ width: '100%' }} />
                    </div>
                    <p className="progress-text">Đang xử lý trên server...</p>
                  </div>
                )}


                {/* Submit */}
                <button
                  id="shuffle-btn"
                  type="submit"
                  className="btn btn-primary submit-btn"
                  disabled={!file || isLoading}
                >
                  {isLoading
                    ? <><span className="spinner" />Đang xử lý...</>
                    : <>🔀 Trộn đề ngay</>}
                </button>
              </form>
            </section>

            {/* Feature pills */}
            <ul className="features fade-in-up" aria-label="Tính năng" style={{ animationDelay: '0.2s' }}>
              {[
                { icon: '⚡', label: 'Xử lý nhanh' },
                { icon: '🔒', label: 'Bảo mật' },
                { icon: '📦', label: 'Miễn phí' },
                { icon: '📝', label: 'Giữ nguyên định dạng' },
              ].map(({ icon, label }) => (
                <li key={label} className="feature-pill">
                  <span>{icon}</span>
                  <span>{label}</span>
                </li>
              ))}
            </ul>
          </main>
        </div>

        {/* Footer */}
        <footer className="site-footer">
          <p>
            © {new Date().getFullYear()}&nbsp;
            <a href="https://github.com/minhcamon" target="_blank" rel="noopener noreferrer">
              <span className="site-footer__icon"></span>minhcamon
            </a>
            <br />
            Trộn đề thi trắc nghiệm miễn phí
          </p>
          <p style={{ marginTop: '0.25rem', fontSize: '0.78rem', opacity: 0.7 }}>
            Mã nguồn mở · Không lưu dữ liệu · Made with ❤️ in Vietnam
          </p>
        </footer>
      </div>

      {/* ── Preview Modal (portal-like, outside home-page div) ── */}
      {showPreview && (
        <PreviewModal
          file={file}
          onClose={() => setShowPreview(false)}
        />
      )}
    </>
  )
}

export default HomePage
