const CHURCH_TIME_ZONE = 'America/Sao_Paulo'

const dateTimeFormat = new Intl.DateTimeFormat('pt-BR', {
  timeZone: CHURCH_TIME_ZONE,
  day: '2-digit',
  month: '2-digit',
  year: 'numeric',
  hour: '2-digit',
  minute: '2-digit',
  second: '2-digit',
  hour12: false,
})

/** Instante ISO (UTC, como vem da API) -> "23/09/2026 14:05:09" no horário de Brasília (o mesmo do filtro de datas). */
export function formatDateTime(iso) {
  const date = new Date(iso)

  return Number.isNaN(date.getTime()) ? '—' : dateTimeFormat.format(date).replace(',', '')
}

/** Apenas rótulo de exibição do perfil congelado no log (não decide nenhuma permissão). */
const PROFILE_LABELS = {
  pastor: 'Pastor',
  administrador: 'Administrador',
  tesoureiro: 'Tesoureiro',
  auxiliar_financeiro: 'Auxiliar financeiro',
  secretario: 'Secretário',
}

export function formatProfile(slug) {
  return slug ? (PROFILE_LABELS[slug] ?? slug) : null
}

/** Valor de um campo de dados_anteriores/dados_novos para leitura humana. */
export function formatDataValue(value) {
  if (value === null || value === undefined) return '—'
  if (typeof value === 'boolean') return value ? 'Sim' : 'Não'
  if (typeof value === 'object') return JSON.stringify(value)

  return String(value)
}
