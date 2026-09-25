/**
 * DICAS DE INTERFACE apenas — mostram/ocultam botões.
 * A autorização real é do backend; qualquer ação negada retorna 403 e é tratada pela tela.
 */
export function canWriteCategories(perfilSlug) {
  return ['pastor', 'administrador'].includes(perfilSlug)
}
