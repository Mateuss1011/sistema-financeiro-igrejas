import { callApi } from '../../shared/apiCall'

export const AUDIT_PER_PAGE = 20

/** Opções de ordenação da tela (valores aceitos pelo parâmetro `ordenar` da API). */
export const AUDIT_SORT_OPTIONS = [
  { value: '-created_at,-id', label: 'Mais recentes primeiro' },
  { value: 'created_at,id', label: 'Mais antigos primeiro' },
  { value: 'modulo,acao', label: 'Módulo e ação (A–Z)' },
  { value: '-modulo,-acao', label: 'Módulo e ação (Z–A)' },
]

/** Valor do filtro de usuário para eventos sem responsável (ex.: login com e-mail inexistente). */
export const NO_USER_FILTER = 'sem_usuario'

/** Somente consulta: não existe nenhuma chamada de escrita para a auditoria (nem na API). */
export function listAuditLogs({ modulo = '', acao = '', usuario = '', registro_id = '', data_de = '', data_ate = '', ordenar = '', page = 1 } = {}) {
  const params = new URLSearchParams({ page: String(page), por_pagina: String(AUDIT_PER_PAGE) })
  const filters = { modulo, acao, registro_id, data_de, data_ate, ordenar }

  for (const [name, value] of Object.entries(filters)) {
    if (value !== '') params.set(name, value)
  }

  if (usuario === NO_USER_FILTER) params.set('sem_usuario', 'true')
  else if (usuario !== '') params.set('user_id', usuario)

  return callApi(`/auditoria?${params.toString()}`)
}

/** Módulos/ações (com rótulos) e usuários que aparecem nos logs, para montar os filtros. */
export function getAuditCatalog() {
  return callApi('/auditoria/catalogo')
}
