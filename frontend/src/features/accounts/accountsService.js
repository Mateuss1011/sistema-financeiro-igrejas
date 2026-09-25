import { callApi } from '../../shared/apiCall'

export const ACCOUNTS_PER_PAGE = 10

export const ACCOUNT_TYPE_LABELS = { banco: 'Conta bancária', caixa: 'Caixa' }

/** Toda chamada à API de contas passa por aqui. Filtros: tipo ('banco'|'caixa'), ativa ('true'|'false'). */
export function listAccounts({ tipo = '', ativa = '', page = 1 } = {}) {
  const params = new URLSearchParams({ page: String(page), por_pagina: String(ACCOUNTS_PER_PAGE) })
  if (tipo) params.set('tipo', tipo)
  if (ativa) params.set('ativa', ativa)

  return callApi(`/contas?${params.toString()}`)
}

/** `saldo_inicial` é uma string decimal ("1500.00"), nunca um número. */
export function createAccount({ nome, tipo, saldo_inicial }) {
  return callApi('/contas', { method: 'POST', body: { nome, tipo, saldo_inicial } })
}

/** `changes`: apenas nome e/ou ativa. Tipo e saldo inicial são imutáveis e nunca são enviados. */
export function updateAccount(id, changes) {
  return callApi(`/contas/${id}`, { method: 'PUT', body: changes })
}

export function deleteAccount(id) {
  return callApi(`/contas/${id}`, { method: 'DELETE' })
}
