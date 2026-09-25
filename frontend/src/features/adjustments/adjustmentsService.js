import { callApi } from '../../shared/apiCall'

export const ADJUSTMENTS_PER_PAGE = 5

export const ADJUSTMENT_DIRECTION_LABELS = { credito: 'Crédito (entrada no saldo)', debito: 'Débito (saída do saldo)' }

export function listAdjustments({ page = 1 } = {}) {
  const params = new URLSearchParams({ page: String(page), por_pagina: String(ADJUSTMENTS_PER_PAGE) })

  return callApi(`/ajustes?${params.toString()}`)
}

/**
 * `payload.valor` é sempre positivo (string decimal); o efeito no saldo vem de `sentido`.
 * Ajustes são imutáveis: não há edição, exclusão nem estorno — correção é um novo ajuste oposto.
 */
export function createAdjustment(payload, idempotencyKey) {
  return callApi('/ajustes', { method: 'POST', body: payload, headers: { 'Idempotency-Key': idempotencyKey } }, { context: 'adjust' })
}
