/**
 * DICAS DE INTERFACE apenas — mostram/ocultam o menu. Os botões de criar/estornar seguem
 * `meta.permissoes` devolvido pela API (calculado pela EntradaPolicy, inclusive a exceção
 * `entradas.operar` do Administrador). A autorização real é sempre do backend.
 */
export function canAccessEntries(perfilSlug) {
  return ['pastor', 'administrador', 'tesoureiro', 'auxiliar_financeiro'].includes(perfilSlug)
}

/** O backend já restringe o Auxiliar às próprias entradas; isto só ajusta o texto da tela. */
export function seesOnlyOwnEntries(perfilSlug) {
  return perfilSlug === 'auxiliar_financeiro'
}
