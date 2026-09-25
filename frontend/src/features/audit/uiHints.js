/**
 * DICA DE INTERFACE apenas — mostra/oculta o menu. A autorização real é da API (AuditLogPolicy: Pastor e
 * Administrador consultam; os demais perfis recebem 403, sem exceção pontual). Auditoria é somente consulta.
 */
export function canAccessAudit(perfilSlug) {
  return ['pastor', 'administrador'].includes(perfilSlug)
}
