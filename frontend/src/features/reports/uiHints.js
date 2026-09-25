/**
 * DICA DE INTERFACE apenas — mostra/oculta o item de menu. A autorização real é do backend (RelatorioPolicy):
 * Pastor, Administrador e Tesoureiro visualizam e exportam; Auxiliar financeiro só visualiza (e só o que ele criou,
 * sem saldos); Secretário não tem acesso. Quais relatórios existem, se dá para exportar e o escopo vêm da API
 * (`/relatorios`), nunca do perfil.
 */
export function canAccessReports(perfilSlug) {
  return ['pastor', 'administrador', 'tesoureiro', 'auxiliar_financeiro'].includes(perfilSlug)
}
