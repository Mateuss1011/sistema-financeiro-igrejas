import { useCallback, useEffect, useState } from 'react'
import AppShell from './app/AppShell'
import LoginPage from './features/auth/LoginPage'
import { getCurrentUser } from './features/auth/authService'

const MSG_SESSION_EXPIRED = 'Sua sessão expirou. Faça login novamente.'
const MSG_SESSION_CHECK_FAILED = 'Não foi possível verificar sua sessão. Verifique a conexão com o servidor.'

function App() {
  const [status, setStatus] = useState('loading')
  const [user, setUser] = useState(null)
  const [notice, setNotice] = useState(null)

  const handleSessionExpired = useCallback(() => {
    setUser(null)
    setNotice(MSG_SESSION_EXPIRED)
    setStatus('anonymous')
  }, [])

  useEffect(() => {
    let cancelled = false

    getCurrentUser()
      .then((currentUser) => {
        if (cancelled) return
        setUser(currentUser)
        setStatus(currentUser ? 'authenticated' : 'anonymous')
      })
      .catch(() => {
        if (cancelled) return
        setNotice(MSG_SESSION_CHECK_FAILED)
        setStatus('anonymous')
      })

    return () => {
      cancelled = true
    }
  }, [])

  if (status === 'loading') {
    return (
      <main className="container min-vh-100 d-flex align-items-center justify-content-center">
        <div className="spinner-border text-primary" role="status">
          <span className="visually-hidden">Carregando...</span>
        </div>
      </main>
    )
  }

  if (status === 'authenticated') {
    return (
      <AppShell
        user={user}
        onSessionExpired={handleSessionExpired}
        onLoggedOut={() => {
          setUser(null)
          setNotice(null)
          setStatus('anonymous')
        }}
      />
    )
  }

  return (
    <LoginPage
      notice={notice}
      onLoggedIn={(loggedUser) => {
        setUser(loggedUser)
        setNotice(null)
        setStatus('authenticated')
      }}
    />
  )
}

export default App
