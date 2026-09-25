import { callApi } from '../../shared/apiCall'

export const USERS_PER_PAGE = 10

/** Toda chamada à API do módulo de usuários passa por aqui. */
export function listUsers(page = 1) {
  return callApi(`/usuarios?page=${page}&por_pagina=${USERS_PER_PAGE}`)
}

export function createUser({ name, email, password, perfil_id }) {
  return callApi('/usuarios', { method: 'POST', body: { name, email, password, perfil_id } })
}

/** `changes` pode conter name, email, perfil_id e/ou ativo (true/false). */
export function updateUser(id, changes, context) {
  return callApi(`/usuarios/${id}`, { method: 'PUT', body: changes }, { context })
}

export function listAssignableProfiles() {
  return callApi('/perfis')
}

export function listExceptionCatalog() {
  return callApi('/permissoes-excecao')
}

export function grantException(userId, permissao) {
  return callApi(`/usuarios/${userId}/permissoes-excecao`, { method: 'POST', body: { permissao } })
}

export function revokeException(userId, permissao) {
  return callApi(`/usuarios/${userId}/permissoes-excecao/${encodeURIComponent(permissao)}`, { method: 'DELETE' })
}
