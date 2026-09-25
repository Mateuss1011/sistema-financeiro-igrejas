/**
 * DICA DE INTERFACE apenas — mostra/oculta o item de menu. A autorização real é do backend (DashboardPolicy:
 * Pastor, Administrador e Tesoureiro = completo; Auxiliar financeiro = parcial; Secretário = sem acesso).
 * O que a tela exibe (saldos, situação do período, escopo) vem sempre do que a API devolve, nunca do perfil.
 */
export function canAccessDashboard(perfilSlug) {
  return ['pastor', 'administrador', 'tesoureiro', 'auxiliar_financeiro'].includes(perfilSlug)
}
