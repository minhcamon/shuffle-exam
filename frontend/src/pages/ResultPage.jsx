import { useEffect } from 'react'
import { useLocation, useNavigate, Link } from 'react-router-dom'
import './ResultPage.css'

function ResultPage() {
  const { state }  = useLocation()
  const navigate   = useNavigate()

  // Guard: nếu vào thẳng URL /result thì redirect về trang chủ
  useEffect(() => {
    if (!state?.downloadUrl) navigate('/', { replace: true })
  }, [state, navigate])

  if (!state?.downloadUrl) return null

  const { downloadUrl, fileName, copies } = state

  const handleDownload = () => {
    const a = document.createElement('a')
    a.href  = downloadUrl
    a.download = fileName
    a.click()

    // Giải phóng object URL sau khi tải
    setTimeout(() => URL.revokeObjectURL(downloadUrl), 60_000)
  }

  return (
    <div className="result-page container">
      <div className="result-card card fade-in-up">
        {/* Success icon */}
        <div className="success-badge" aria-hidden="true">
          <span className="check-icon">✓</span>
        </div>

        <h1 className="result-title">Đề thi đã sẵn sàng!</h1>
        <p className="result-subtitle">
          Đã tạo thành công <strong>{copies}</strong> mã đề được trộn ngẫu nhiên.
          File sẽ được tải xuống ở định dạng <code>.docx</code>.
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
    </div>
  )
}

export default ResultPage
