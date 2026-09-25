/**
 * DICAS DE INTERFACE apenas — mostram/ocultam o menu. Os botões de criar/editar/pagar/cancelar/
 * estornar/excluir seguem `meta.permissoes` e as flags por linha devolvidas pela API (calculadas pela
 * DespesaPolicy, inclusive as exceções `despesas.operar` e `despesas.estornar_paga`).
 * A autorização real é sempre do backend.
 */
export function canAccessExpenses(perfilSlug) {
  return ['pastor', 'administrador', 'tesoureiro', 'auxiliar_financeiro'].includes(perfilSlug)
}

/** O backend já restringe o Auxiliar às próprias despesas; isto só ajusta o texto da tela. */
export function seesOnlyOwnExpenses(perfilSlug) {
  return perfilSlug === 'auxiliar_financeiro'
}
