import { callApi } from '../../shared/apiCall'

export const ENTRIES_PER_PAGE = 10

export const ENTRY_STATUS_LABELS = { confirmada: 'Confirmada', estornada: 'Estornada' }

/** Opções de ordenação da tela (valores aceitos pelo parâmetro `ordenar` da API). */
export const ENTRY_SORT_OPTIONS = [
  { value: '-data_competencia,-id', label: 'Data (mais recente)' },
  { value: 'data_competencia,id', label: 'Data (mais antiga)' },
  { value: '-valor,-id', label: 'Maior valor' },
  { value: 'valor,id', label: 'Menor valor' },
]

/** Toda chamada à API de entradas passa por aqui. */
export function listEntries({ data_de = '', data_ate = '', categoria_id = '', conta_id = '', status = '', estorno = '', ordenar = '', page = 1 } = {}) {
  const params = new URLSearchParams({ page: String(page), por_pagina: String(ENTRIES_PER_PAGE) })
  const filters = { data_de, data_ate, categoria_id, conta_id, status, estorno, ordenar }

  for (const [name, value] of Object.entries(filters)) {
    if (value !== '') params.set(name, value)
  }

  return callApi(`/entradas?${params.toString()}`)
}

/**
 * `payload.valor` é uma string decimal ("1500.00"), nunca um número.
 * `idempotencyKey` protege contra duplicidade em duplo clique ou reenvio após falha de rede.
 */
export function createEntry(payload, idempotencyKey) {
  return callApi('/entradas', { method: 'POST', body: payload, headers: { 'Idempotency-Key': idempotencyKey } })
}

/** Estorna uma entrada (nunca há edição ou exclusão). `confirmar_saldo_negativo` só é enviado após confirmação explícita. */
export function reverseEntry(id, { justificativa, confirmarSaldoNegativo = false }) {
  const body = { justificativa }
  if (confirmarSaldoNegativo) body.confirmar_saldo_negativo = true

  return callApi(`/entradas/${id}/estornar`, { method: 'POST', body })
}

/** Categorias de entrada. `onlyActive` para o formulário; sem filtro para os filtros da listagem (registros antigos). */
export function listEntryCategories({ onlyActive = false } = {}) {
  const params = new URLSearchParams({ tipo: 'entrada', por_pagina: '100' })
  if (onlyActive) params.set('ativa', 'true')

  return callApi(`/categorias?${params.toString()}`)
}

export function listAccountOptions({ onlyActive = false } = {}) {
  const params = new URLSearchParams({ por_pagina: '100' })
  if (onlyActive) params.set('ativa', 'true')

  return callApi(`/contas?${params.toString()}`)
}

// Utilitários compartilhados (movidos para shared/utils); reexportados para não quebrar consumidores.
export { newIdempotencyKey } from '../../shared/utils/idempotency'
export { formatIsoDate, todayLocalIso } from '../../shared/utils/dates'
