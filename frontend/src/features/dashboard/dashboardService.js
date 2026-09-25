import { callApi } from '../../shared/apiCall'

export const ACCOUNT_TYPE_LABELS = { banco: 'Banco', caixa: 'Caixa' }

export const PERIOD_STATUS_LABELS = { aberto: 'Aberto', fechado: 'Fechado' }

/**
 * Somente consulta (um único GET). `anoMes` vazio = mês corrente (o backend decide o "corrente" no fuso da
 * igreja). Não existe filtro de intervalo de datas: só o mês.
 */
export function getDashboard({ anoMes = '' } = {}) {
  const params = new URLSearchParams()
  if (anoMes !== '') params.set('ano_mes', anoMes)

  const query = params.toString()

  return callApi(query === '' ? '/dashboard' : `/dashboard?${query}`)
}
