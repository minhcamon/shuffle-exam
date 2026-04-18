/**
 * components/WakeUpBanner.jsx
 * ─────────────────────────────────────────────────────────────────
 * Hiển thị banner thông báo "Server đang thức dậy" khi Render free
 * tier cần thời gian khởi động lại sau khi sleep.
 * Tự đếm giây đã chờ và hiển thị trạng thái theo giai đoạn.
 * ─────────────────────────────────────────────────────────────────
 */

import { useState, useEffect } from 'react'
import './WakeUpBanner.css'

// Các giai đoạn hiển thị theo thời gian chờ (giây)
const PHASES = [
  { till: 8,   icon: '🌙', label: 'Server đang ngủ...', desc: 'Đang kết nối đến máy chủ' },
  { till: 20,  icon: '⏳', label: 'Server đang thức dậy...', desc: 'Hệ thống free-tier cần ~30s để khởi động' },
  { till: 40,  icon: '☕', label: 'Xin chờ thêm một chút...', desc: 'Server sắp sẵn sàng rồi!' },
  { till: 999, icon: '🔄', label: 'Vẫn đang khởi động...', desc: 'Nếu quá lâu, thử tải lại trang' },
]

function WakeUpBanner({ startTime }) {
  const [elapsed, setElapsed] = useState(0)

  useEffect(() => {
    const interval = setInterval(() => {
      setElapsed(Math.floor((Date.now() - startTime) / 1000))
    }, 1000)
    return () => clearInterval(interval)
  }, [startTime])

  const phase = PHASES.find(p => elapsed < p.till) ?? PHASES[PHASES.length - 1]

  return (
    <div className="wakeup-banner" role="status" aria-live="polite">
      {/* Left: animated icon + text */}
      <div className="wakeup-banner__left">
        <span className="wakeup-banner__icon" key={phase.icon}>
          {phase.icon}
        </span>
        <div>
          <p className="wakeup-banner__label">{phase.label}</p>
          <p className="wakeup-banner__desc">{phase.desc}</p>
        </div>
      </div>

      {/* Right: elapsed timer */}
      <div className="wakeup-banner__timer">
        <div className="wakeup-dots">
          <span /><span /><span />
        </div>
        <p className="wakeup-banner__elapsed">{elapsed}s</p>
      </div>

      {/* Progress bar (pseudo-indeterminate) */}
      <div className="wakeup-progress">
        <div className="wakeup-progress__fill" />
      </div>
    </div>
  )
}

export default WakeUpBanner
