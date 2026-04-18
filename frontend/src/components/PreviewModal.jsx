/**
 * components/PreviewModal.jsx
 * ─────────────────────────────────────────────────────────────────
 * Hiển thị nội dung file .docx dưới dạng HTML trong một modal overlay.
 * Dùng mammoth.js để parse .docx → HTML ngay trên browser.
 * ─────────────────────────────────────────────────────────────────
 */

import { useState, useEffect, useRef, useCallback } from 'react'
import mammoth from 'mammoth'
import './PreviewModal.css'

function PreviewModal({ file, onClose }) {
  const [html, setHtml]       = useState('')
  const [status, setStatus]   = useState('loading') // 'loading' | 'done' | 'error'
  const [errMsg, setErrMsg]   = useState('')
  const backdropRef           = useRef(null)

  // Parse docx → html khi file thay đổi
  useEffect(() => {
    if (!file) return
    setStatus('loading')

    const reader = new FileReader()

    reader.onload = async (e) => {
      try {
        const arrayBuffer = e.target.result
        const result = await mammoth.convertToHtml(
          { arrayBuffer },
          {
            // Giữ nguyên heading, list; bỏ inline style phức tạp
            styleMap: [
              "p[style-name='Heading 1'] => h2:fresh",
              "p[style-name='Heading 2'] => h3:fresh",
            ],
          }
        )
        setHtml(result.value)
        setStatus('done')
      } catch (err) {
        setErrMsg('Không thể đọc nội dung file. Vui lòng đảm bảo file đúng định dạng .docx.')
        setStatus('error')
      }
    }

    reader.onerror = () => {
      setErrMsg('Lỗi đọc file.')
      setStatus('error')
    }

    reader.readAsArrayBuffer(file)
  }, [file])

  // Đóng modal khi nhấn Escape
  useEffect(() => {
    const handleKey = (e) => { if (e.key === 'Escape') onClose() }
    window.addEventListener('keydown', handleKey)
    return () => window.removeEventListener('keydown', handleKey)
  }, [onClose])

  // Khoá scroll body khi modal mở
  useEffect(() => {
    document.body.style.overflow = 'hidden'
    return () => { document.body.style.overflow = '' }
  }, [])

  // Click backdrop để đóng
  const handleBackdropClick = useCallback((e) => {
    if (e.target === backdropRef.current) onClose()
  }, [onClose])

  return (
    <div
      className="preview-backdrop"
      ref={backdropRef}
      onClick={handleBackdropClick}
      role="dialog"
      aria-modal="true"
      aria-label="Xem trước nội dung đề thi"
    >
      <div className="preview-modal">
        {/* Header */}
        <div className="preview-modal__header">
          <div className="preview-modal__title">
            <span className="preview-modal__icon">👁</span>
            <div>
              <p className="preview-modal__label">Xem trước nội dung</p>
              <p className="preview-modal__filename" title={file?.name}>{file?.name}</p>
            </div>
          </div>
          <button
            id="preview-close-btn"
            className="preview-modal__close"
            onClick={onClose}
            aria-label="Đóng xem trước"
          >
            ✕
          </button>
        </div>

        {/* Notice bar */}
        <div className="preview-modal__notice">
          ℹ️ Đây là bản <strong>xem trước nội dung gốc</strong> trước khi trộn. Định dạng có thể khác một chút so với file Word.
        </div>

        {/* Body */}
        <div className="preview-modal__body">
          {status === 'loading' && (
            <div className="preview-state">
              <div className="preview-spinner" />
              <p>Đang đọc nội dung file...</p>
            </div>
          )}

          {status === 'error' && (
            <div className="preview-state preview-state--error">
              <span>⚠️</span>
              <p>{errMsg}</p>
            </div>
          )}

          {status === 'done' && (
            <div
              className="preview-content"
              dangerouslySetInnerHTML={{ __html: html }}
            />
          )}
        </div>

        {/* Footer */}
        <div className="preview-modal__footer">
          <button className="btn btn-outline" onClick={onClose}>
            ✕ Đóng
          </button>
        </div>
      </div>
    </div>
  )
}

export default PreviewModal
