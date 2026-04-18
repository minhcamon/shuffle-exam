import { useEffect } from 'react'
import { useLocation, useNavigate, Link } from 'react-router-dom'
import './ResultPage.css'

function ResultPage() {
  const { state } = useLocation()
  const navigate = useNavigate()

  useEffect(() => {
    if (!state?.downloadUrl) navigate('/', { replace: true })
  }, [state, navigate])

  if (!state?.downloadUrl) return null

  const { downloadUrl, fileName, copies } = state

  const handleDownload = () => {
    const a = document.createElement('a')
    a.href = downloadUrl
    a.download = fileName
    a.click()
    setTimeout(() => URL.revokeObjectURL(downloadUrl), 60_000)
  }

  return (
    <div className="result-page">
      <div className="result-card card fade-in-up container">
        {/* Success icon */}
        <div className="success-badge" aria-hidden="true">
          <span className="check-icon">✓</span>
        </div>

        <h1 className="result-title">Đề thi đã sẵn sàng!</h1>
        <p className="result-subtitle">
          Đã tạo thành công <strong>{copies}</strong> mã đề được trộn ngẫu nhiên.
          File sẽ được tải xuống ở định dạng <code>.zip</code> chứa các file <code>.docx</code>.
        </p>

        <div className="result-meta">
          <div className="meta-item">
            <span className="meta-label">Tên file</span>
            <span className="meta-value">{fileName}</span>
          </div>
          <div className="meta-item">
            <span className="meta-label">Số mã đề</span>
            <span className="meta-value">{copies}</span>
          </div>
        </div>

        <div className="result-actions">
          <button
            id="download-btn"
            className="btn btn-primary"
            onClick={handleDownload}
          >
            ⬇️ Tải xuống ngay
          </button>
          <Link to="/" className="btn btn-outline" id="back-btn">
            ↩ Trộn đề khác
          </Link>
        </div>

        <p className="result-notice">
          🔒 File của bạn <strong>không được lưu</strong> trên server. Link tải sẽ hết hạn sau khi bạn rời trang này.
        </p>
      </div>

      {/* ── Footer ── */}
      <footer className="site-footer">
        <p>
          © {new Date().getFullYear()}&nbsp;
          <a
            href="https://github.com/minhcamon"
            target="_blank"
            rel="noopener noreferrer"
            aria-label="GitHub của minhcamon"
          >
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
  )
}

export default ResultPage
