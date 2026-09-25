import { useEffect, useState } from 'react'
import { formatIsoDate } from '../../shared/utils/dates'
import ClosePeriodModal from './ClosePeriodModal'
import { PERIOD_STATUS_LABELS, listPeriods } from './periodsService'
import ReopenPeriodModal from './ReopenPeriodModal'
import { formatAnoMes } from './uiFormat'

function PeriodsPage({ onSessionExpired }) {
  const [page, setPage] = useState(1)
  const [refreshKey, setRefreshKey] = useState(0)
  const [periods, setPeriods] = useState([])
  const [meta, setMeta] = useState(null)
  const [status, setStatus] = useState('loading')
  const [loadError, setLoadError] = useState(null)
  const [flash, setFlash] = useState(null)
  const [closing, setClosing] = useState(null)
  const [reopening, setReopening] = useState(null)

  const permissions = meta?.permissoes ?? { fechar: false, reabrir: false }

  useEffect(() => {
    let cancelled = false

    listPeriods({ page }).then((result) => {
      if (cancelled) return

      if (result.ok) {
        setPeriods(result.data.data)
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

  function reload() {
    setStatus((current) => (current === 'ready' ? 'refreshing' : current))
    setRefreshKey((key) => key + 1)
  }

  function retry() {
    setStatus('loading')
    setRefreshKey((key) => key + 1)
  }

  function changePage(targetPage) {
    setStatus('refreshing')
    setPage(targetPage)
  }

  function handleClosed(message, type = 'success') {
    setClosing(null)
    setFlash({ type, message })
    reload()
  }

  function handleReopened(message, type = 'success') {
    setReopening(null)
    setFlash({ type, message })
    reload()
  }

  const isBusy = status === 'loading' || status === 'refreshing'

  return (
    <section aria-labelledby="periods-title">
      <div className="mb-3">
        <h1 className="h4 mb-0" id="periods-title">
          Fechamento
        </h1>
        <p className="text-body-secondary mb-0 small">
          Um período fechado bloqueia criação, edição, pagamento, cancelamento, exclusão e estorno de lançamentos com data naquele mês. O histórico já
          registrado nunca é alterado. Fechar e reabrir não afetam nenhum outro período.
        </p>
      </div>

      {flash && (
        <div className={`alert alert-${flash.type} alert-dismissible py-2`} role="status">
          {flash.message}
          <button type="button" className="btn-close" aria-label="Fechar aviso" onClick={() => setFlash(null)}></button>
        </div>
      )}

      {status === 'loading' && (
        <div className="text-center py-5" role="status">
          <span className="spinner-border text-primary" aria-hidden="true"></span>
          <div className="text-body-secondary mt-2">Carregando períodos...</div>
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

      {(status === 'ready' || status === 'refreshing') && (
        <div className="card shadow-sm">
          {periods.length === 0 ? (
            <div className="card-body text-center text-body-secondary py-5">Nenhum período encontrado.</div>
          ) : (
            <div className="table-responsive">
              <table className="table table-hover align-middle mb-0">
                <thead className="table-light">
                  <tr>
                    <th scope="col">Mês</th>
                    <th scope="col">Situação</th>
                    <th scope="col">Fechado por</th>
                    <th scope="col">Reaberto por</th>
                    <th scope="col">Justificativa da reabertura</th>
                    <th scope="col" className="text-end">
                      Ações
                    </th>
                  </tr>
                </thead>
                <tbody>
                  {periods.map((period) => (
                    <tr key={period.ano_mes}>
                      <td className="fw-semibold text-capitalize">{formatAnoMes(period.ano_mes)}</td>
                      <td>
                        <span className={`badge ${period.status === 'fechado' ? 'text-bg-secondary' : 'text-bg-success'}`}>
                          {PERIOD_STATUS_LABELS[period.status]}
                        </span>
                      </td>
                      <td>
                        {period.fechado_por ? (
                          <>
                            {period.fechado_por.name}
                            {period.fechado_em && <div className="small text-body-secondary">{formatIsoDate(period.fechado_em.slice(0, 10))}</div>}
                          </>
                        ) : (
                          <span className="text-body-secondary">—</span>
                        )}
                      </td>
                      <td>
                        {period.reaberto_por ? (
                          <>
                            {period.reaberto_por.name}
                            {period.reaberto_em && <div className="small text-body-secondary">{formatIsoDate(period.reaberto_em.slice(0, 10))}</div>}
                          </>
                        ) : (
                          <span className="text-body-secondary">—</span>
                        )}
                      </td>
                      <td>{period.justificativa_reabertura ?? <span className="text-body-secondary">—</span>}</td>
                      <td className="text-end text-nowrap">
                        {period.status === 'aberto' && permissions.fechar && (
                          <button type="button" className="btn btn-sm btn-outline-warning" onClick={() => setClosing(period)}>
                            Fechar
                          </button>
                        )}
                        {period.status === 'fechado' && permissions.reabrir && (
                          <button type="button" className="btn btn-sm btn-outline-danger" onClick={() => setReopening(period)}>
                            Reabrir
                          </button>
                        )}
                      </td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
          )}

          {meta && meta.last_page > 1 && (
            <div className="card-footer d-flex flex-wrap align-items-center justify-content-between gap-2">
              <span className="text-body-secondary small">
                Página {meta.current_page} de {meta.last_page} · {meta.total} períodos
              </span>
              <div className="btn-group" role="group" aria-label="Paginação">
                <button type="button" className="btn btn-sm btn-outline-secondary" onClick={() => changePage(meta.current_page - 1)} disabled={meta.current_page <= 1 || isBusy}>
                  Anterior
                </button>
                <button type="button" className="btn btn-sm btn-outline-secondary" onClick={() => changePage(meta.current_page + 1)} disabled={meta.current_page >= meta.last_page || isBusy}>
                  Próxima
                </button>
              </div>
            </div>
          )}
        </div>
      )}

      {closing && <ClosePeriodModal period={closing} onClose={() => setClosing(null)} onDone={handleClosed} onSessionExpired={onSessionExpired} />}

      {reopening && (
        <ReopenPeriodModal period={reopening} onClose={() => setReopening(null)} onDone={handleReopened} onSessionExpired={onSessionExpired} />
      )}
    </section>
  )
}

export default PeriodsPage
