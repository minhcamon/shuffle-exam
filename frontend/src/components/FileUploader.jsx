/**
 * components/FileUploader.jsx
 * ─────────────────────────────────────────────────────────────────────────────
 * Component upload file với:
 *   - Drag & Drop zone
 *   - Validation định dạng .docx
 *   - Hiển thị tên file đã chọn
 *   - Prop callback onFileSelect(file)
 * ─────────────────────────────────────────────────────────────────────────────
 */

import { useState, useRef, useCallback } from 'react'
import './FileUploader.css'

const ACCEPTED_TYPES = [
  'application/vnd.openxmlformats-officedocument.wordprocessingml.document', // .docx
]
const MAX_SIZE_MB = 20

function FileUploader({ onFileSelect, disabled = false }) {
  const [isDragging, setIsDragging]   = useState(false)
  const [selectedFile, setSelectedFile] = useState(null)
  const [error, setError]             = useState(null)
  const inputRef                      = useRef(null)

  const validate = (file) => {
    if (!ACCEPTED_TYPES.includes(file.type)) {
      return 'Chỉ chấp nhận file .docx (Microsoft Word)'
    }
    if (file.size > MAX_SIZE_MB * 1024 * 1024) {
      return `Kích thước file vượt quá ${MAX_SIZE_MB}MB`
    }
    return null
  }

  const handleFile = useCallback((file) => {
    setError(null)
    const err = validate(file)
    if (err) { setError(err); return }
    setSelectedFile(file)
    onFileSelect(file)
  }, [onFileSelect])

  // ── Drag & Drop handlers ──
  const onDragOver  = (e) => { e.preventDefault(); if (!disabled) setIsDragging(true) }
  const onDragLeave = ()  => setIsDragging(false)
  const onDrop      = (e) => {
    e.preventDefault()
    setIsDragging(false)
    if (disabled) return
    const file = e.dataTransfer.files[0]
    if (file) handleFile(file)
  }

  const onInputChange = (e) => {
    const file = e.target.files[0]
    if (file) handleFile(file)
    // Reset để cho phép chọn lại cùng file
    e.target.value = ''
  }

  const formatSize = (bytes) => {
    if (bytes < 1024)       return `${bytes} B`
    if (bytes < 1024 * 1024) return `${(bytes / 1024).toFixed(1)} KB`
    return `${(bytes / (1024 * 1024)).toFixed(2)} MB`
  }

  return (
    <div className="file-uploader-wrapper">
      <div
        id="drop-zone"
        className={`drop-zone ${isDragging ? 'dragging' : ''} ${disabled ? 'disabled' : ''}`}
        onDragOver={onDragOver}
        onDragLeave={onDragLeave}
        onDrop={onDrop}
        onClick={() => !disabled && inputRef.current?.click()}
        role="button"
        tabIndex={0}
        aria-label="Khu vực kéo thả hoặc nhấn để chọn file"
        onKeyDown={(e) => e.key === 'Enter' && inputRef.current?.click()}
      >
        <input
          ref={inputRef}
          id="file-input"
          type="file"
          accept=".docx"
          style={{ display: 'none' }}
          onChange={onInputChange}
          disabled={disabled}
        />

        {/* Icon */}
        <div className="drop-zone__icon" aria-hidden="true">
          {selectedFile ? '📄' : '📂'}
        </div>

        {selectedFile ? (
          <div className="drop-zone__file-info">
            <p className="file-name">{selectedFile.name}</p>
            <p className="file-size">{formatSize(selectedFile.size)}</p>
            <p className="file-hint">Nhấn để chọn file khác</p>
          </div>
        ) : (
          <div className="drop-zone__placeholder">
            <p className="drop-title">Kéo & thả file vào đây</p>
            <p className="drop-subtitle">hoặc <span className="link-text">nhấn để duyệt</span></p>
            <p className="drop-hint">Chỉ hỗ trợ .docx · Tối đa {MAX_SIZE_MB}MB</p>
          </div>
        )}
      </div>

      {error && (
        <p className="upload-error" role="alert" aria-live="polite">
          ⚠️ {error}
        </p>
      )}
    </div>
  )
}

export default FileUploader
