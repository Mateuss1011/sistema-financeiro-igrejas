import { useEffect, useState } from 'react'
import { formatIsoDate } from '../../shared/utils/dates'
import { formatMoney } from '../../shared/utils/money'
import AdjustmentFormModal from './AdjustmentFormModal'
import { listAdjustments } from './adjustmentsService'

/** Histórico de ajustes de saldo (somente leitura) + botão de novo ajuste conforme `meta.permissoes`. */
function AdjustmentsPanel({ onChanged, onSessionExpired }) {
  const [page, setPage] = useState(1)
  const [refreshKey, setRefreshKey] = useState(0)
  const [adjustments, setAdjustments] = useState([])
  const [meta, setMeta] = useState(null)
  const [status, setStatus] = useState('loading')
  const [loadError, setLoadError] = useState(null)
  const [flash, setFlash] = useState(null)
  const [showForm, setShowForm] = useState(false)

  const permissions = meta?.permissoes ?? { criar: false }

  useEffect(() => {
    let cancelled = false

    listAdjustments({ page }).then((result) => {
      if (cancelled) return

      if (result.ok) {
        setAdjustments(result.data.data)
        setMeta(result.data.meta)
        setLoadError(null)
        setStatus('ready')
        return
      }

      if (result.error.kind === 'unauthenticated') return onSessionExpired()

      setLoadError(result.error)
      setStatus('error')
    })

    return () => {
      cancelled = true
    }
  }, [page, refreshKey, onSessionExpired])

  function retry() {
    setStatus('loading')
    setRefreshKey((key) => key + 1)
  }

  function handleSaved(message) {
    setShowForm(false)
    setFlash({ type: 'success', message })
    setPage(1)
    setRefreshKey((key) => key + 1)
    onChanged()
  }

  const isBusy = status === 'loading'

  return (
    <section className="mt-4" aria-labelledby="adjustments-title">
      <div className="d-flex flex-wrap align-items-center justify-content-between gap-2 mb-2">
        <div>
          <h2 className="h5 mb-0" id="adjustments-title">
            Ajustes de saldo
          </h2>
          <p className="text-body-secondary mb-0 small">Correções excepcionais e imutáveis do saldo de uma conta. Para corrigir um ajuste, registre um ajuste oposto.</p>
        </div>
        {permissions.criar && (
          <button type="button" className="btn btn-outline-primary" onClick={() => setShowForm(true)}>
            Novo ajuste
          </button>
        )}
      </div>

      {flash && (
        <div className={`alert alert-${flash.type} alert-dismissible py-2`} role="status">
          {flash.message}
          <button type="button" className="btn-close" aria-label="Fechar aviso" onClick={() => setFlash(null)}></button>
        </div>
      )}

      {status === 'loading' && (
        <div className="text-center py-3" role="status">
          <span className="spinner-border spinner-border-sm text-primary me-2" aria-hidden="true"></span>
          Carregando ajustes...
        </div>
      )}

      {status === 'error' && (
        <div className="alert alert-danger d-flex align-items-center justify-content-between" role="alert">
          <span>{loadError.message}</span>
          {loadError.kind !== 'forbidden' && (
            <button type="button" className="btn btn-sm btn-outline-danger" onClick={retry}>
              Tentar novamente
            </button>
          )}
        </div>
      )}

      {status === 'ready' && (
        <div className="card shadow-sm">
          {adjustments.length === 0 ? (
            <div className="card-body text-center text-body-secondary py-4">Nenhum ajuste de saldo registrado.</div>
          ) : (
            <div className="table-responsive">
              <table className="table table-hover align-middle mb-0">
                <thead className="table-light">
                  <tr>
                    <th scope="col">Data</th>
                    <th scope="col">Conta</th>
                    <th scope="col">Sentido</th>
                    <th scope="col" className="text-end">
                      Valor
                    </th>
                    <th scope="col">Justificativa</th>
                    <th scope="col">Registrado por</th>
                  </tr>
                </thead>
                <tbody>
                  {adjustments.map((adjustment) => (
                    <tr key={adjustment.id}>
                      <td className="text-nowrap">{formatIsoDate(adjustment.data_ajuste)}</td>
                      <td>{adjustment.conta?.nome ?? '—'}</td>
                      <td>
                        <span className={`badge ${adjustment.sentido === 'credito' ? 'text-bg-success' : 'text-bg-danger'}`}>
                          {adjustment.sentido === 'credito' ? 'Crédito' : 'Débito'}
                        </span>
                      </td>
                      {/* O sinal é só de exibição: o valor da API é sempre positivo. */}
                      <td className={`text-end text-nowrap ${adjustment.sentido === 'debito' ? 'text-danger fw-semibold' : ''}`}>
                        {adjustment.sentido === 'debito' ? formatMoney(`-${adjustment.valor}`) : formatMoney(adjustment.valor)}
                      </td>
                      <td>{adjustment.justificativa}</td>
                      <td>{adjustment.criado_por?.name ?? '—'}</td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
          )}

          {meta && meta.last_page > 1 && (
            <div className="card-footer d-flex flex-wrap align-items-center justify-content-between gap-2">
              <span className="text-body-secondary small">
                Página {meta.current_page} de {meta.last_page} · {meta.total} ajustes
              </span>
              <div className="btn-group" role="group" aria-label="Paginação dos ajustes">
                <button type="button" className="btn btn-sm btn-outline-secondary" onClick={() => setPage(meta.current_page - 1)} disabled={meta.current_page <= 1 || isBusy}>
                  Anterior
                </button>
                <button type="button" className="btn btn-sm btn-outline-secondary" onClick={() => setPage(meta.current_page + 1)} disabled={meta.current_page >= meta.last_page || isBusy}>
                  Próxima
                </button>
              </div>
            </div>
          )}
        </div>
      )}

      {showForm && <AdjustmentFormModal onClose={() => setShowForm(false)} onSaved={handleSaved} onSessionExpired={onSessionExpired} />}
    </section>
  )
}

export default AdjustmentsPanel
