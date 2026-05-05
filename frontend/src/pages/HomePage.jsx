import { useState, useRef, useEffect, useCallback } from 'react'
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
  const [codes, setCodes] = useState(['', '', '', '']) // khởi tạo theo copies mặc định
  const [useCustomCodes, setUseCustomCodes] = useState(false)
  const [codesError, setCodesError] = useState(null)
  const [isLoading, setIsLoading] = useState(false)
  const [progress, setProgress] = useState(0)
  const [apiError, setApiError] = useState(null)
  const [showPreview, setShowPreview] = useState(false)
  const [wakeUpStartTime, setWakeUpStartTime] = useState(null)
  const wakeUpTimerRef = useRef(null)

  // Sync độ dài mảng codes theo copies (thêm ô trống / bớt ô thừa)
  useEffect(() => {
    setCodes((prev) => {
      if (prev.length === copies) return prev
      if (prev.length < copies) return [...prev, ...Array(copies - prev.length).fill('')]
      return prev.slice(0, copies)
    })
    setCodesError(null)
  }, [copies])

  const handleFileSelect = (selectedFile) => {
    setFile(selectedFile)
    setShowPreview(false)
  }

  const handleCodeChange = useCallback((index, value) => {
    setCodes((prev) => {
      const next = [...prev]
      next[index] = value
      return next
    })
    setCodesError(null)
  }, [])

  // Validate codes: phải đủ số lượng, không trùng, không rỗng
  const validateCodes = () => {
    if (!useCustomCodes) return true

    const trimmed = codes.map((c) => c.trim())
    const empty = trimmed.filter((c) => c === '')

    if (empty.length > 0) {
      setCodesError(`Còn ${empty.length} ô mã đề chưa được điền.`)
      return false
    }

    const unique = new Set(trimmed)
    if (unique.size !== trimmed.length) {
      setCodesError('Các mã đề không được trùng nhau.')
      return false
    }

    setCodesError(null)
    return true
  }

  const handleSubmit = async (e) => {
    e.preventDefault()
    if (!file) return
    if (!validateCodes()) return

    setIsLoading(true)
    setApiError(null)
    setProgress(0)
    setWakeUpStartTime(null)

    wakeUpTimerRef.current = setTimeout(() => {
      setWakeUpStartTime(Date.now())
    }, 5000)

    try {
      const activeCodes = useCustomCodes ? codes.map((c) => c.trim()) : []
      const blob = await shuffleQuiz(file, copies, setProgress, activeCodes)
      const url = URL.createObjectURL(blob)
      const baseName = file.name.replace(/\.docx$/i, '')
      const fileName = `${baseName}_${copies}-ma-de.zip`
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
      clearTimeout(wakeUpTimerRef.current)
      setWakeUpStartTime(null)
      setIsLoading(false)
    }
  }

  return (
    <>
      <div className="home-page">
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

          {/* Main Layout Area */}
          <main className="main-container">
            <div className="main-content-layout fade-in-up" style={{ animationDelay: '0.1s' }}>

              {/* Upload Card - Bên trái (70%) */}
              <section className="card upload-card">
                <form onSubmit={handleSubmit} noValidate>

                  {/* ── Step 1: Chọn file ── */}
                  <div className="form-section">
                    <label className="form-label">
                      <span className="step-badge">1</span>
                      Chọn file đề thi gốc
                    </label>
                    <FileUploader onFileSelect={handleFileSelect} disabled={isLoading} />

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

                  {/* ── Step 2: Số lượng mã đề ── */}
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
                        onChange={(e) => setCopies(Math.min(26, Math.max(2, Number(e.target.value))))}
                        disabled={isLoading}
                        className="copies-input"
                      />
                      <span className="copies-hint">mã đề (tối đa 26 — A → Z)</span>
                    </div>
                  </div>

                  {/* ── Step 3: Mã đề tùy chọn ── */}
                  <div className="form-section">
                    <div className="custom-codes-header">
                      <label className="form-label" style={{ margin: 0 }}>
                        <span className="step-badge">3</span>
                        Mã đề tùy chỉnh
                        <span
                          className="step-tooltip"
                          title="Nhập mã số riêng cho từng đề (VD: 101, 202…). Nếu bỏ qua, hệ thống tự sinh mã ngẫu nhiên."
                        >
                          ℹ️
                        </span>
                      </label>
                      {/* Toggle bật/tắt */}
                      <button
                        type="button"
                        className={`toggle-btn ${useCustomCodes ? 'toggle-btn--on' : ''}`}
                        onClick={() => { setUseCustomCodes((v) => !v); setCodesError(null) }}
                        disabled={isLoading}
                        aria-pressed={useCustomCodes}
                      >
                        <span className="toggle-btn__track">
                          <span className="toggle-btn__thumb" />
                        </span>
                        <span>{useCustomCodes ? 'Bật' : 'Tắt'}</span>
                      </button>
                    </div>

                    {!useCustomCodes && (
                      <p className="codes-off-hint">
                        Hệ thống sẽ tự sinh mã ngẫu nhiên 3 chữ số cho mỗi đề.
                      </p>
                    )}

                    {useCustomCodes && (
                      <div className="codes-grid" style={{ '--cols': Math.min(copies, 4) }}>
                        {codes.map((code, idx) => (
                          <div key={idx} className="code-input-wrapper">
                            <label className="code-input-label" htmlFor={`code-${idx}`}>
                              Đề {idx + 1}
                            </label>
                            <input
                              id={`code-${idx}`}
                              type="text"
                              inputMode="numeric"
                              maxLength={10}
                              className={`code-input ${codesError && code.trim() === '' ? 'code-input--error' : ''}`}
                              placeholder={`VD: ${101 + idx * 101}`}
                              value={code}
                              onChange={(e) => handleCodeChange(idx, e.target.value)}
                              disabled={isLoading}
                              autoComplete="off"
                            />
                          </div>
                        ))}
                      </div>
                    )}

                    {/* Lỗi mã đề */}
                    {codesError && (
                      <div className="codes-error" role="alert">
                        ⚠️ {codesError}
                      </div>
                    )}
                  </div>

                  {/* ── Error API (Báo lỗi Validation) ── */}
                  {apiError && (
                    <div className="validation-error-banner">
                      <svg fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z" />
                      </svg>
                      <div className="validation-error-content">
                        <h3>Hệ thống phát hiện lỗi định dạng trong file Word!</h3>
                        <p dangerouslySetInnerHTML={{ __html: apiError }}></p>
                        <p className="validation-error-hint">
                          💡 Hãy sửa lại file Word theo đúng quy chuẩn bên trái và tải lên lại nhé.
                        </p>
                      </div>
                    </div>
                  )}

                  {/* ── Progress upload (0–100%) ── */}
                  {isLoading && progress < 100 && (
                    <div className="progress-wrapper" aria-live="polite">
                      <div className="progress-bar">
                        <div className="progress-fill" style={{ width: `${progress}%` }} />
                      </div>
                      <p className="progress-text">Đang tải lên... {progress}%</p>
                    </div>
                  )}

                  {/* ── WakeUp Banner ── */}
                  {isLoading && progress >= 100 && wakeUpStartTime && (
                    <WakeUpBanner startTime={wakeUpStartTime} />
                  )}

                  {/* ── Đang xử lý thông thường ── */}
                  {isLoading && progress >= 100 && !wakeUpStartTime && (
                    <div className="progress-wrapper" aria-live="polite">
                      <div className="progress-bar">
                        <div className="progress-fill" style={{ width: '100%' }} />
                      </div>
                      <p className="progress-text">Đang xử lý trên server...</p>
                    </div>
                  )}

                  {/* ── Submit ── */}
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

              {/* Bảng Quy chuẩn định dạng (Guideline) - Bên phải (30%) */}
              <aside className="guideline-section">
                <h3>
                  📌 Quy chuẩn định dạng
                </h3>

                <div className="guideline-content">
                  <div className="guideline-item">
                    <span className="guideline-item-title">1. CÂU TRẮC NGHIỆM ĐƠN</span>
                    Bắt buộc dùng chữ <span className="guideline-badge">IN HOA</span> cho đáp án: <code>A.</code> <code>B.</code> <code>C.</code> <code>D.</code><br />
                    <span className="guideline-success">✔ Đáp án đúng:</span> Phải <u>gạch chân</u>.
                  </div>

                  <div className="guideline-item">
                    <span className="guideline-item-title">2. CÂU TRẮC NGHIỆM ĐÚNG/SAI</span>
                    Bắt buộc dùng chữ <span className="guideline-badge">thường</span> cho mệnh đề: <code>a)</code> <code>b)</code> <code>c)</code> <code>d)</code><br />
                    <span className="guideline-success">✔ Mệnh đề ĐÚNG:</span> Phải <u>gạch chân</u>.
                  </div>

                  {/* <div className="guideline-tip">
                    <span>💡</span>
                    <div>
                      <strong>Mẹo nhỏ:</strong> Nếu copy đề từ PDF/Web, hãy dán vào Word trắng, bôi đen toàn bộ và nhấn <b>Ctrl + U</b> 2 lần để xóa sạch lỗi gạch chân ẩn nhé!
                    </div>
                  </div> */}
                </div>
              </aside>
            </div>

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

      {/* Preview Modal */}
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
