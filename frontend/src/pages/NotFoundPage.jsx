import { Link } from 'react-router-dom'

function NotFoundPage() {
  return (
    <div style={{ textAlign: 'center', padding: '6rem 1.5rem' }}>
      <div style={{ fontSize: '5rem', marginBottom: '1rem' }}>🔍</div>
      <h1 style={{ fontSize: '2rem', fontWeight: 800, marginBottom: '0.75rem' }}>404 – Không tìm thấy trang</h1>
      <p style={{ color: 'var(--color-text-muted)', marginBottom: '2rem' }}>Trang bạn đang tìm kiếm không tồn tại.</p>
      <Link to="/" className="btn btn-primary">↩ Về trang chủ</Link>
    </div>
  )
}

export default NotFoundPage
