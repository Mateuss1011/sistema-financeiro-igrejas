import { apiRequest, fetchCsrfCookie } from '../../shared/apiClient'

const MSG_CONNECTION = 'Não foi possível conectar ao servidor. Verifique sua conexão e tente novamente.'
const MSG_UNEXPECTED = 'Ocorreu um erro inesperado. Tente novamente em instantes.'
const MSG_RATE_LIMITED = 'Muitas tentativas de login. Aguarde alguns instantes e tente novamente.'

/**
 * Retorna o usuário autenticado, ou null se não houver sessão (401).
 * Lança exceção em erro de rede ou resposta inesperada.
 */
export async function getCurrentUser() {
  const response = await apiRequest('/auth/me')

  if (response.status === 401) {
    return null
  }

  if (!response.ok) {
    throw new Error('unexpected_response')
  }

  return response.data.data
}

/**
 * Fluxo: CSRF → login → /auth/me.
 * Retorna { ok: true, user } ou { ok: false, kind, message }.
 */
export async function login(email, password) {
  try {
    await fetchCsrfCookie()

    const loginResponse = await apiRequest('/auth/login', {
      method: 'POST',
      body: { email, password },
    })

    if (loginResponse.status === 422) {
      const message = loginResponse.data?.errors?.email?.[0] ?? 'Credenciais inválidas.'
      const kind = message.toLowerCase().includes('inativo') ? 'inactive' : 'credentials'
      return { ok: false, kind, message }
    }

    // Limite de tentativas (Fase 13): o backend recusa antes de conferir a senha. Mensagem fixa, sem detalhes.
    if (loginResponse.status === 429) {
      return { ok: false, kind: 'rateLimited', message: MSG_RATE_LIMITED }
    }

    if (!loginResponse.ok) {
      return { ok: false, kind: 'unexpected', message: MSG_UNEXPECTED }
    }

    const user = await getCurrentUser()

    if (!user) {
      return { ok: false, kind: 'unexpected', message: MSG_UNEXPECTED }
    }

    return { ok: true, user }
  } catch {
    return { ok: false, kind: 'connection', message: MSG_CONNECTION }
  }
}

/**
 * Retorna { ok: true } quando a sessão foi encerrada (ou já não existia),
 * ou { ok: false, message } em falha de rede/servidor.
 */
export async function logout() {
  try {
    const response = await apiRequest('/auth/logout', { method: 'POST' })

    if (response.ok || response.status === 401) {
      return { ok: true }
    }

    return { ok: false, message: MSG_UNEXPECTED }
  } catch {
    return { ok: false, message: MSG_CONNECTION }
  }
}
