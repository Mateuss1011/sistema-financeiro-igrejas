const API_URL = import.meta.env.VITE_API_URL || 'http://localhost:8000/api/v1'
const API_ORIGIN = new URL(API_URL).origin

function readCookie(name) {
  const entry = document.cookie
    .split('; ')
    .find((cookie) => cookie.startsWith(`${name}=`))

  return entry ? decodeURIComponent(entry.slice(name.length + 1)) : null
}

export async function fetchCsrfCookie() {
  await fetch(`${API_ORIGIN}/sanctum/csrf-cookie`, {
    credentials: 'include',
    headers: { Accept: 'application/json' },
  })
}

/**
 * Wrapper mínimo sobre fetch para a API do SFG (sessão por cookie via Sanctum).
 * Sempre envia credentials: 'include'. `headers` adiciona cabeçalhos extras (ex.: Idempotency-Key). Erros de rede lançam exceção (TypeError);
 * respostas HTTP (inclusive 4xx/5xx) retornam { ok, status, data }.
 */
export async function apiRequest(path, { method = 'GET', body, headers: extraHeaders, retryOnCsrfExpired = true } = {}) {
  const headers = { Accept: 'application/json', ...extraHeaders }

  if (body !== undefined) {
    headers['Content-Type'] = 'application/json'
  }

  if (method !== 'GET') {
    const xsrfToken = readCookie('XSRF-TOKEN')
    if (xsrfToken) {
      headers['X-XSRF-TOKEN'] = xsrfToken
    }
  }

  const response = await fetch(`${API_URL}${path}`, {
    method,
    headers,
    credentials: 'include',
    body: body !== undefined ? JSON.stringify(body) : undefined,
  })

  if (response.status === 419 && retryOnCsrfExpired) {
    await fetchCsrfCookie()
    return apiRequest(path, { method, body, headers: extraHeaders, retryOnCsrfExpired: false })
  }

  let data = null
  try {
    data = await response.json()
  } catch {
    data = null
  }

  return { ok: response.ok, status: response.status, data }
}

/**
 * Download de arquivo (Fase 12): função SEPARADA de `apiRequest`, que só lê JSON. Faz GET com credenciais (sessão por
 * cookie), devolve o corpo como Blob e nunca tenta interpretar o arquivo como JSON. Em resposta de erro (4xx/5xx) a API
 * sempre devolve JSON no formato padrão, que é lido em `data`. Erros de rede lançam exceção (TypeError).
 */
export async function apiDownload(path) {
  const response = await fetch(`${API_URL}${path}`, {
    method: 'GET',
    headers: { Accept: '*/*' },
    credentials: 'include',
  })

  if (!response.ok) {
    let data = null
    try {
      data = await response.json()
    } catch {
      data = null
    }

    return { ok: false, status: response.status, data }
  }

  return {
    ok: true,
    status: response.status,
    blob: await response.blob(),
    contentType: response.headers.get('Content-Type'),
    disposition: response.headers.get('Content-Disposition'),
  }
}
