import { useState, useEffect } from 'react'
import { Routes, Route } from 'react-router-dom'
import HomePage from './pages/HomePage.jsx'
import ResultPage from './pages/ResultPage.jsx'
import NotFoundPage from './pages/NotFoundPage.jsx'
import './index.css'

function App() {
  const [theme, setTheme] = useState(() => {
    return localStorage.getItem('theme') || 'dark'
  })

  useEffect(() => {
    document.documentElement.setAttribute('data-theme', theme)
    localStorage.setItem('theme', theme)
  }, [theme])

  const toggleTheme = () => setTheme(t => t === 'dark' ? 'light' : 'dark')

  return (
    <>
      <button
        id="theme-toggle-btn"
        className="theme-toggle"
        onClick={toggleTheme}
        aria-label={theme === 'dark' ? 'Chuyển sang Light mode' : 'Chuyển sang Dark mode'}
      >
        <span className="theme-toggle__icon">{theme === 'dark' ? '☀️' : '🌙'}</span>
        <span className="theme-toggle__label">{theme === 'dark' ? 'Light Mode' : 'Dark Mode'}</span>
      </button>

      <Routes>
        <Route path="/"       element={<HomePage />} />
        <Route path="/result" element={<ResultPage />} />
        <Route path="*"       element={<NotFoundPage />} />
      </Routes>
    </>
  )
}

export default App
