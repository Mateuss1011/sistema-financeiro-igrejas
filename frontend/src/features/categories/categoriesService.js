import { callApi } from '../../shared/apiCall'

export const CATEGORIES_PER_PAGE = 50

export const TYPE_LABELS = { entrada: 'Entrada', despesa: 'Despesa' }

/** Toda chamada à API de categorias passa por aqui. Filtros: tipo ('entrada'|'despesa'), ativa ('true'|'false'). */
export function listCategories({ tipo = '', ativa = '', page = 1 } = {}) {
  const params = new URLSearchParams({ page: String(page), por_pagina: String(CATEGORIES_PER_PAGE) })
  if (tipo) params.set('tipo', tipo)
  if (ativa) params.set('ativa', ativa)

  return callApi(`/categorias?${params.toString()}`)
}

export function createCategory({ nome, tipo }) {
  return callApi('/categorias', { method: 'POST', body: { nome, tipo } })
}

/** `changes`: apenas nome e/ou ativa. O tipo é imutável e nunca é enviado. */
export function updateCategory(id, changes) {
  return callApi(`/categorias/${id}`, { method: 'PUT', body: changes })
}

export function deleteCategory(id) {
  return callApi(`/categorias/${id}`, { method: 'DELETE' })
}
