import { useState } from 'react'
import { login } from './authService'

function LoginPage({ onLoggedIn, notice }) {
  const [email, setEmail] = useState('')
  const [password, setPassword] = useState('')
  const [submitting, setSubmitting] = useState(false)
  const [error, setError] = useState(null)

  async function handleSubmit(event) {
    event.preventDefault()
    setSubmitting(true)
    setError(null)

    const result = await login(email, password)

    setSubmitting(false)

    if (result.ok) {
      setPassword('')
      onLoggedIn(result.user)
      return
    }

    setPassword('')
    setError({ kind: result.kind, message: result.message })
  }

  const alertClass = error?.kind === 'inactive' || error?.kind === 'connection' || error?.kind === 'rateLimited' ? 'alert-warning' : 'alert-danger'

  return (
    <main className="container min-vh-100 d-flex align-items-center justify-content-center py-4">
      <div className="card shadow-sm w-100" style={{ maxWidth: '26rem' }}>
        <div className="card-body p-4">
          <h1 className="h4 text-center mb-1">SFG</h1>
          <p className="text-center text-body-secondary mb-4">Sistema Financeiro para Igrejas</p>

          {notice && !error && (
            <div className="alert alert-warning py-2" role="alert">
              {notice}
            </div>
          )}

          {error && (
            <div className={`alert ${alertClass} py-2`} role="alert">
              {error.message}
            </div>
          )}

          <form onSubmit={handleSubmit} noValidate>
            <div className="mb-3">
              <label htmlFor="email" className="form-label">
                E-mail
              </label>
              <input
                id="email"
                type="email"
                className="form-control"
                autoComplete="username"
                value={email}
                onChange={(event) => setEmail(event.target.value)}
                disabled={submitting}
                required
                autoFocus
              />
            </div>

            <div className="mb-4">
              <label htmlFor="password" className="form-label">
                Senha
              </label>
              <input
                id="password"
                type="password"
                className="form-control"
                autoComplete="current-password"
                value={password}
                onChange={(event) => setPassword(event.target.value)}
                disabled={submitting}
                required
              />
            </div>

            <button type="submit" className="btn btn-primary w-100" disabled={submitting || !email || !password}>
              {submitting ? (
                <>
                  <span className="spinner-border spinner-border-sm me-2" aria-hidden="true"></span>
                  Entrando...
                </>
              ) : (
                'Entrar'
              )}
            </button>
          </form>
        </div>
      </div>
    </main>
  )
}

export default LoginPage
