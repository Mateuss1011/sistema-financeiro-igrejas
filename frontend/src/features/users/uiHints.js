/**
 * DICAS DE INTERFACE apenas — mostram/ocultam botões e links.
 * A autorização real é sempre do backend (Policies); qualquer ação negada
 * lá retorna 403 e é tratada normalmente pela tela.
 */
export function canAccessUsers(perfilSlug) {
  return ['pastor', 'administrador', 'secretario'].includes(perfilSlug)
}

export function canWriteUsers(perfilSlug) {
  return ['pastor', 'administrador'].includes(perfilSlug)
}
