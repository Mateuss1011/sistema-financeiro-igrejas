/**
 * DICAS DE INTERFACE apenas — mostram/ocultam o menu. Os botões de criar/estornar seguem
 * `meta.permissoes` da API (calculado pela TransferenciaPolicy, inclusive as exceções
 * `transferencias.operar` e `transferencias.estornar` do Administrador). A autorização real é do backend.
 */
export function canAccessTransfers(perfilSlug) {
  return ['pastor', 'administrador', 'tesoureiro'].includes(perfilSlug)
}
