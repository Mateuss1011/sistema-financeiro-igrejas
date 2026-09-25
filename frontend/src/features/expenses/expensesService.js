import { callApi } from '../../shared/apiCall'

export const EXPENSES_PER_PAGE = 10

export const EXPENSE_STATUS_LABELS = { pendente: 'Pendente', paga: 'Paga', estornada: 'Estornada', cancelada: 'Cancelada' }

export const EXPENSE_STATUS_BADGES = { pendente: 'text-bg-warning', paga: 'text-bg-success', estornada: 'text-bg-secondary', cancelada: 'text-bg-dark' }

/** Opções de ordenação da tela (valores aceitos pelo parâmetro `ordenar` da API). */
export const EXPENSE_SORT_OPTIONS = [
  { value: '-data_competencia,-id', label: 'Competência (mais recente)' },
  { value: 'data_competencia,id', label: 'Competência (mais antiga)' },
  { value: '-data_pagamento,-id', label: 'Pagamento (mais recente)' },
  { value: '-valor,-id', label: 'Maior valor' },
  { value: 'valor,id', label: 'Menor valor' },
]

/** Toda chamada à API de despesas passa por aqui. */
export function listExpenses({ data_de = '', data_ate = '', categoria_id = '', conta_id = '', status = '', estorno = '', ordenar = '', page = 1 } = {}) {
  const params = new URLSearchParams({ page: String(page), por_pagina: String(EXPENSES_PER_PAGE) })
  const filters = { data_de, data_ate, categoria_id, conta_id, status, estorno, ordenar }

  for (const [name, value] of Object.entries(filters)) {
    if (value !== '') params.set(name, value)
  }

  return callApi(`/despesas?${params.toString()}`)
}

/**
 * Cria uma despesa (sempre nasce Pendente, sem conta). `payload.valor` é uma string decimal ("150.00").
 * `idempotencyKey` protege contra duplicidade em duplo clique ou reenvio após falha de rede.
 */
export function createExpense(payload, idempotencyKey) {
  return callApi('/despesas', { method: 'POST', body: payload, headers: { 'Idempotency-Key': idempotencyKey } })
}

/** Edita uma Pendente. `changes` traz apenas os campos alterados (categoria, valor, competência, descrição, fornecedor). */
export function updateExpense(id, changes) {
  return callApi(`/despesas/${id}`, { method: 'PUT', body: changes })
}

/** Paga uma Pendente. `confirmarSaldoNegativo` só é enviado após confirmação explícita (conta bancária). */
export function payExpense(id, { contaId, dataPagamento, confirmarSaldoNegativo = false }) {
  const body = { conta_id: contaId, data_pagamento: dataPagamento }
  if (confirmarSaldoNegativo) body.confirmar_saldo_negativo = true

  return callApi(`/despesas/${id}/pagar`, { method: 'POST', body }, { context: 'pay-expense' })
}

export function cancelExpense(id, justificativa) {
  return callApi(`/despesas/${id}/cancelar`, { method: 'POST', body: { justificativa } })
}

export function reverseExpense(id, justificativa) {
  return callApi(`/despesas/${id}/estornar`, { method: 'POST', body: { justificativa } })
}

export function deleteExpense(id) {
  return callApi(`/despesas/${id}`, { method: 'DELETE' })
}

/** Categorias de despesa. `onlyActive` para o formulário; sem filtro para os filtros da listagem (registros antigos). */
export function listExpenseCategories({ onlyActive = false } = {}) {
  const params = new URLSearchParams({ tipo: 'despesa', por_pagina: '100' })
  if (onlyActive) params.set('ativa', 'true')

  return callApi(`/categorias?${params.toString()}`)
}

export function listAccountOptions({ onlyActive = false } = {}) {
  const params = new URLSearchParams({ por_pagina: '100' })
  if (onlyActive) params.set('ativa', 'true')

  return callApi(`/contas?${params.toString()}`)
}
