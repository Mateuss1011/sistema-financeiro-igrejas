import { useEffect, useState } from 'react'
import ConfirmDialog from '../../shared/components/ConfirmDialog'
import { grantException, listExceptionCatalog, revokeException } from './usersService'

/**
 * Exibe o catálogo de permissões excepcionais e permite conceder/revogar.
 * Só é renderizado quando a API devolveu `permissoes_excecao` para o usuário
 * (ou seja, o usuário logado pode gerenciá-las); se o catálogo retornar 403, some.
 */
function ExceptionsPanel({ userId, initialGranted, onChanged, onSessionExpired }) {
  const [catalog, setCatalog] = useState(null)
  const [granted, setGranted] = useState(initialGranted)
  const [hidden, setHidden] = useState(false)
  const [error, setError] = useState(null)
  const [pending, setPending] = useState(null)
  const [busy, setBusy] = useState(false)

  useEffect(() => {
    let cancelled = false

    listExceptionCatalog().then((result) => {
      if (cancelled) return
      if (result.ok) return setCatalog(result.data.data)
      if (result.error.kind === 'unauthenticated') return onSessionExpired()
      if (result.error.kind === 'forbidden') return setHidden(true)
      setError(result.error.message)
    })

    return () => {
      cancelled = true
    }
  }, [onSessionExpired])

  async function confirmChange() {
    setBusy(true)
    setError(null)

    const { item, action } = pending
    const result =
      action === 'grant' ? await grantException(userId, item.chave) : await revokeException(userId, item.chave)

    setBusy(false)
    setPending(null)

    if (result.ok) {
      setGranted((current) =>
        action === 'grant' ? [...new Set([...current, item.chave])] : current.filter((key) => key !== item.chave),
      )
      onChanged(action === 'grant' ? 'Permissão excepcional concedida.' : 'Permissão excepcional revogada.')
      return
    }

    if (result.error.kind === 'unauthenticated') return onSessionExpired()
    setError(result.error.message)
  }

  if (hidden) return null

  return (
    <section className="mt-4" aria-labelledby="exceptions-title">
      <h3 className="h6" id="exceptions-title">
        Permissões excepcionais
      </h3>
      <p className="text-body-secondary small">Concedidas somente por Pastores; toda alteração é registrada na auditoria.</p>

      {error && (
        <div className="alert alert-danger py-2" role="alert">
          {error}
        </div>
      )}

      {!catalog && !error && (
        <div className="text-body-secondary small">
          <span className="spinner-border spinner-border-sm me-2" aria-hidden="true"></span>
          Carregando permissões...
        </div>
      )}

      {catalog?.length === 0 && <p className="text-body-secondary small mb-0">Nenhuma permissão excepcional disponível.</p>}

      <ul className="list-group">
        {catalog?.map((item) => {
          const isGranted = granted.includes(item.chave)

          return (
            <li key={item.chave} className="list-group-item d-flex align-items-start justify-content-between gap-3">
              <div>
                <div className="fw-semibold">
                  <code>{item.chave}</code>{' '}
                  <span className={`badge ${isGranted ? 'text-bg-success' : 'text-bg-secondary'}`}>
                    {isGranted ? 'Concedida' : 'Não concedida'}
                  </span>
                </div>
                <div className="small text-body-secondary">{item.descricao}</div>
              </div>
              <button
                type="button"
                className={`btn btn-sm ${isGranted ? 'btn-outline-danger' : 'btn-outline-primary'} flex-shrink-0`}
                onClick={() => setPending({ item, action: isGranted ? 'revoke' : 'grant' })}
                disabled={busy}
              >
                {isGranted ? 'Revogar' : 'Conceder'}
              </button>
            </li>
          )
        })}
      </ul>

      {pending && (
        <ConfirmDialog
          title={pending.action === 'grant' ? 'Conceder permissão excepcional' : 'Revogar permissão excepcional'}
          message={
            pending.action === 'grant'
              ? `Conceder "${pending.item.chave}" a este usuário? ${pending.item.descricao}`
              : `Revogar "${pending.item.chave}" deste usuário?`
          }
          confirmLabel={pending.action === 'grant' ? 'Conceder' : 'Revogar'}
          confirmVariant={pending.action === 'grant' ? 'primary' : 'danger'}
          busy={busy}
          onConfirm={confirmChange}
          onCancel={() => setPending(null)}
        />
      )}
    </section>
  )
}

export default ExceptionsPanel
