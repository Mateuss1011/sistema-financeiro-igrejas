import { callApi } from '../../shared/apiCall'

export const TRANSFERS_PER_PAGE = 10

export const TRANSFER_STATUS_LABELS = { confirmada: 'Confirmada', estornada: 'Estornada' }

/** Opções de ordenação da tela (valores aceitos pelo parâmetro `ordenar` da API). */
export const TRANSFER_SORT_OPTIONS = [
  { value: '-data_transferencia,-id', label: 'Data (mais recente)' },
  { value: 'data_transferencia,id', label: 'Data (mais antiga)' },
  { value: '-valor,-id', label: 'Maior valor' },
  { value: 'valor,id', label: 'Menor valor' },
]

/** Toda chamada à API de transferências passa por aqui. `conta_id` casa com origem OU destino. */
export function listTransfers({ data_de = '', data_ate = '', conta_id = '', status = '', estorno = '', ordenar = '', page = 1 } = {}) {
  const params = new URLSearchParams({ page: String(page), por_pagina: String(TRANSFERS_PER_PAGE) })
  const filters = { data_de, data_ate, conta_id, status, estorno, ordenar }

  for (const [name, value] of Object.entries(filters)) {
    if (value !== '') params.set(name, value)
  }

  return callApi(`/transferencias?${params.toString()}`)
}

/**
 * `payload.valor` é uma string decimal ("150.00"). `confirmar_saldo_negativo` só é enviado após
 * confirmação explícita (conta bancária de origem). `idempotencyKey` protege duplo clique/reenvio.
 */
export function createTransfer(payload, idempotencyKey) {
  return callApi('/transferencias', { method: 'POST', body: payload, headers: { 'Idempotency-Key': idempotencyKey } }, { context: 'transfer' })
}

/** Estorno = nova transferência inversa vinculada à original (mesma data). Justificativa obrigatória. */
export function reverseTransfer(id, { justificativa, confirmarSaldoNegativo = false }) {
  const body = { justificativa }
  if (confirmarSaldoNegativo) body.confirmar_saldo_negativo = true

  return callApi(`/transferencias/${id}/estornar`, { method: 'POST', body }, { context: 'reverse-transfer' })
}

/** Contas para os seletores (`onlyActive` no formulário; todas nos filtros, pois há registros antigos). */
export function listAccountOptions({ onlyActive = false } = {}) {
  const params = new URLSearchParams({ por_pagina: '100' })
  if (onlyActive) params.set('ativa', 'true')

  return callApi(`/contas?${params.toString()}`)
}
