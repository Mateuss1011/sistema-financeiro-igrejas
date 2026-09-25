/**
 * DICAS DE INTERFACE apenas — mostram/ocultam o menu. Fechar/reabrir seguem `meta.permissoes` da
 * API (PeriodoFinanceiroPolicy: Pastor e Tesoureiro fecham; só Pastor reabre; Administrador nunca,
 * mesmo com exceção — não existe exceção para este módulo). A autorização real é do backend.
 */
export function canAccessPeriods(perfilSlug) {
  return ['pastor', 'administrador', 'tesoureiro'].includes(perfilSlug)
}
