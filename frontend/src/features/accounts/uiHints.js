/**
 * DICAS DE INTERFACE apenas — mostram/ocultam menu e botões.
 * A autorização real é do backend (ContaPolicy); qualquer ação negada retorna 403 e é tratada pela tela.
 */
export function canAccessAccounts(perfilSlug) {
  return ['pastor', 'administrador', 'tesoureiro', 'auxiliar_financeiro'].includes(perfilSlug)
}

export function canWriteAccounts(perfilSlug) {
  return ['pastor', 'administrador'].includes(perfilSlug)
}

export function canDeleteAccounts(perfilSlug) {
  return perfilSlug === 'pastor'
}
