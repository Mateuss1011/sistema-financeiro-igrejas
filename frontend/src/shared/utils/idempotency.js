/** Chave única por tentativa de criação (header Idempotency-Key). */
export function newIdempotencyKey() {
  if (globalThis.crypto?.randomUUID) return globalThis.crypto.randomUUID()

  return `k-${Date.now().toString(36)}-${Math.random().toString(36).slice(2, 12)}`
}
