/**
 * DICAS DE INTERFACE apenas — mostram/ocultam o painel. O botão de novo ajuste segue `meta.permissoes`
 * da API (AjusteSaldoPolicy, inclusive a exceção `ajustes.operar` do Administrador). A autorização real é do backend.
 */
export function canAccessAdjustments(perfilSlug) {
  return ['pastor', 'administrador', 'tesoureiro'].includes(perfilSlug)
}
