import { callApi } from '../../shared/apiCall'

export const PERIODS_PER_PAGE = 20

export const PERIOD_STATUS_LABELS = { aberto: 'Aberto', fechado: 'Fechado' }

export function listPeriods({ page = 1 } = {}) {
  const params = new URLSearchParams({ page: String(page), por_pagina: String(PERIODS_PER_PAGE) })

  return callApi(`/periodos-financeiros?${params.toString()}`)
}

/** Fechar não tem corpo: os dados (quem, quando) são sempre calculados no backend. */
export function closePeriod(anoMes) {
  return callApi(`/periodos-financeiros/${anoMes}/fechar`, { method: 'POST' }, { context: 'close-period' })
}

export function reopenPeriod(anoMes, justificativa) {
  return callApi(`/periodos-financeiros/${anoMes}/reabrir`, { method: 'POST', body: { justificativa } }, { context: 'reopen-period' })
}
