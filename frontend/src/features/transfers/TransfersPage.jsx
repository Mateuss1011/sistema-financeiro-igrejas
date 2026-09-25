import { useEffect, useState } from 'react'
import { formatIsoDate } from '../../shared/utils/dates'
import { formatMoney } from '../../shared/utils/money'
import ReverseTransferModal from './ReverseTransferModal'
import TransferFormModal from './TransferFormModal'
import { TRANSFER_SORT_OPTIONS, TRANSFER_STATUS_LABELS, listAccountOptions, listTransfers } from './transfersService'

const EMPTY_FILTERS = { data_de: '', data_ate: '', conta_id: '', status: '', estorno: '', ordenar: TRANSFER_SORT_OPTIONS[0].value }

function TransfersPage({ onSessionExpired }) {
  const [filters, setFilters] = useState(EMPTY_FILTERS)
  const [page, setPage] = useState(1)
  const [refreshKey, setRefreshKey] = useState(0)
  const [transfers, setTransfers] = useState([])
  const [meta, setMeta] = useState(null)
  const [status, setStatus] = useState('loading')
  const [loadError, setLoadError] = useState(null)
  const [flash, setFlash] = useState(null)
  const [showForm, setShowForm] = useState(false)
  const [reversing, setReversing] = useState(null)
  const [accountOptions, setAccountOptions] = useState([])

  const invalidRange = filters.data_de !== '' && filters.data_ate !== '' && filters.data_ate < filters.data_de
  const permissions = meta?.permissoes ?? { criar: false, estornar: false }

  // Filtro de conta: inclui inativas, pois transferências antigas podem usá-las.
  useEffect(() => {
    let cancelled = false

    listAccountOptions().then((result) => {
      if (!cancelled && result.ok) setAccountOptions(result.data.data)
    })

    return () => {
      cancelled = true
    }
  }, [refreshKey])

  useEffect(() => {
    if (invalidRange) return undefined

    let cancelled = false

    listTransfers({ ...filters, page }).then((result) => {
      if (cancelled) return

      if (result.ok) {
        setTransfers(result.data.data)
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
  }, [filters, page, refreshKey, invalidRange, onSessionExpired])

  function reload() {
    setStatus((current) => (current === 'ready' ? 'refreshing' : current))
    setRefreshKey((key) => key + 1)
  }

  function retry() {
    setStatus('loading')
    setRefreshKey((key) => key + 1)
  }

  function changeFilter(name, value) {
    setStatus('loading')
    setPage(1)
    setFilters((current) => ({ ...current, [name]: value }))
  }

  function clearFilters() {
    setStatus('loading')
    setPage(1)
    setFilters(EMPTY_FILTERS)
  }

  function changePage(targetPage) {
    setStatus('refreshing')
    setPage(targetPage)
  }

  function handleCreated(message) {
    setShowForm(false)
    setFlash({ type: 'success', message })
    setPage(1)
    setFilters((current) => ({ ...current, ordenar: EMPTY_FILTERS.ordenar }))
    reload()
  }

  function handleReversed(message) {
    setReversing(null)
    setFlash({ type: 'success', message })
    reload()
  }

  const isBusy = status === 'loading' || status === 'refreshing'
  const hasFilters = Object.entries(filters).some(([name, value]) => name !== 'ordenar' && value !== '')
  const showActions = permissions.estornar

  return (
    <section aria-labelledby="transfers-title">
      <div className="d-flex flex-wrap align-items-center justify-content-between gap-2 mb-3">
        <div>
          <h1 className="h4 mb-0" id="transfers-title">
            Transferências
          </h1>
          <p className="text-body-secondary mb-0 small">
            Movimentação de dinheiro entre contas da igreja (não é receita nem despesa). Transferências não são editadas nem excluídas; correções são feitas por estorno.
          </p>
        </div>
        {permissions.criar && (
          <button type="button" className="btn btn-primary" onClick={() => setShowForm(true)}>
            Nova transferência
          </button>
        )}
      </div>

      <div className="row g-2 mb-3">
        <div className="col-6 col-md-3 col-lg-2">
          <label htmlFor="filter-from" className="form-label small mb-1">
            De
          </label>
          <input id="filter-from" type="date" className="form-control form-control-sm" value={filters.data_de} onChange={(event) => changeFilter('data_de', event.target.value)} />
        </div>
        <div className="col-6 col-md-3 col-lg-2">
          <label htmlFor="filter-to" className="form-label small mb-1">
            Até
          </label>
          <input id="filter-to" type="date" className="form-control form-control-sm" value={filters.data_ate} onChange={(event) => changeFilter('data_ate', event.target.value)} />
        </div>
        <div className="col-6 col-md-3 col-lg-2">
          <label htmlFor="filter-account" className="form-label small mb-1">
            Conta (origem ou destino)
          </label>
          <select id="filter-account" className="form-select form-select-sm" value={filters.conta_id} onChange={(event) => changeFilter('conta_id', event.target.value)}>
            <option value="">Todas</option>
            {accountOptions.map((account) => (
              <option key={account.id} value={account.id}>
                {account.nome}
              </option>
            ))}
          </select>
        </div>
        <div className="col-6 col-md-3 col-lg-2">
          <label htmlFor="filter-status" className="form-label small mb-1">
            Situação
          </label>
          <select id="filter-status" className="form-select form-select-sm" value={filters.status} onChange={(event) => changeFilter('status', event.target.value)}>
            <option value="">Todas</option>
            {Object.entries(TRANSFER_STATUS_LABELS).map(([value, label]) => (
              <option key={value} value={value}>
                {label}
              </option>
            ))}
          </select>
        </div>
        <div className="col-6 col-md-3 col-lg-2">
          <label htmlFor="filter-kind" className="form-label small mb-1">
            Tipo de lançamento
          </label>
          <select id="filter-kind" className="form-select form-select-sm" value={filters.estorno} onChange={(event) => changeFilter('estorno', event.target.value)}>
            <option value="">Todos</option>
            <option value="false">Transferências</option>
            <option value="true">Estornos</option>
          </select>
        </div>
        <div className="col-6 col-md-3 col-lg-2">
          <label htmlFor="filter-sort" className="form-label small mb-1">
            Ordenar por
          </label>
          <select id="filter-sort" className="form-select form-select-sm" value={filters.ordenar} onChange={(event) => changeFilter('ordenar', event.target.value)}>
            {TRANSFER_SORT_OPTIONS.map((option) => (
              <option key={option.value} value={option.value}>
                {option.label}
              </option>
            ))}
          </select>
        </div>
        {hasFilters && (
          <div className="col-6 col-md-3 d-flex align-items-end">
            <button type="button" className="btn btn-sm btn-outline-secondary" onClick={clearFilters}>
              Limpar filtros
            </button>
          </div>
        )}
      </div>

      {flash && (
        <div className={`alert alert-${flash.type} alert-dismissible py-2`} role="status">
          {flash.message}
          <button type="button" className="btn-close" aria-label="Fechar aviso" onClick={() => setFlash(null)}></button>
        </div>
      )}

      {invalidRange && (
        <div className="alert alert-warning py-2" role="alert">
          A data final não pode ser anterior à data inicial.
        </div>
      )}

      {!invalidRange && status === 'loading' && (
        <div className="text-center py-5" role="status">
          <span className="spinner-border text-primary" aria-hidden="true"></span>
          <div className="text-body-secondary mt-2">Carregando transferências...</div>
        </div>
      )}

      {!invalidRange && status === 'error' && (
        <div className="alert alert-danger d-flex align-items-center justify-content-between" role="alert">
          <span>{loadError.message}</span>
          {loadError.kind !== 'forbidden' && (
            <button type="button" className="btn btn-sm btn-outline-danger" onClick={retry}>
              Tentar novamente
            </button>
          )}
        </div>
      )}

      {!invalidRange && (status === 'ready' || status === 'refreshing') && (
        <div className="card shadow-sm">
          {transfers.length === 0 ? (
            <div className="card-body text-center text-body-secondary py-5">
              {hasFilters ? 'Nenhuma transferência encontrada com os filtros selecionados.' : 'Nenhuma transferência registrada.'}
            </div>
          ) : (
            <div className="table-responsive">
              <table className="table table-hover align-middle mb-0">
                <thead className="table-light">
                  <tr>
                    <th scope="col">Data</th>
                    <th scope="col">Origem</th>
                    <th scope="col">Destino</th>
                    <th scope="col" className="text-end">
                      Valor
                    </th>
                    <th scope="col">Descrição</th>
                    <th scope="col">Situação</th>
                    <th scope="col">Registrado por</th>
                    {showActions && (
                      <th scope="col" className="text-end">
                        Ações
                      </th>
                    )}
                  </tr>
                </thead>
                <tbody>
                  {transfers.map((transfer) => (
                    <tr key={transfer.id} className={transfer.eh_estorno ? 'table-warning' : ''}>
                      <td className="text-nowrap">{formatIsoDate(transfer.data_transferencia)}</td>
                      <td>{transfer.conta_origem?.nome ?? '—'}</td>
                      <td>{transfer.conta_destino?.nome ?? '—'}</td>
                      <td className="text-end text-nowrap">{formatMoney(transfer.valor)}</td>
                      <td>
                        {transfer.eh_estorno ? (
                          <>
                            <div className="fw-semibold">Estorno da transferência #{transfer.transferencia_estornada_id}</div>
                            <div className="small text-body-secondary">{transfer.motivo_estorno}</div>
                          </>
                        ) : transfer.descricao ? (
                          transfer.descricao
                        ) : (
                          <span className="text-body-secondary">—</span>
                        )}
                      </td>
                      <td>
                        {transfer.eh_estorno ? (
                          <span className="badge text-bg-warning">Estorno</span>
                        ) : (
                          <>
                            <span className={`badge ${transfer.status === 'confirmada' ? 'text-bg-success' : 'text-bg-secondary'}`}>{TRANSFER_STATUS_LABELS[transfer.status]}</span>
                            {transfer.estorno_id && <div className="small text-body-secondary">Estorno #{transfer.estorno_id}</div>}
                          </>
                        )}
                      </td>
                      <td>{transfer.criado_por?.name ?? '—'}</td>
                      {showActions && (
                        <td className="text-end text-nowrap">
                          {transfer.estornavel && (
                            <button type="button" className="btn btn-sm btn-outline-danger" onClick={() => setReversing(transfer)}>
                              Estornar
                            </button>
                          )}
                        </td>
                      )}
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
          )}

          {meta && meta.last_page > 1 && (
            <div className="card-footer d-flex flex-wrap align-items-center justify-content-between gap-2">
              <span className="text-body-secondary small">
                Página {meta.current_page} de {meta.last_page} · {meta.total} lançamentos
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

      {showForm && <TransferFormModal onClose={() => setShowForm(false)} onSaved={handleCreated} onSessionExpired={onSessionExpired} />}

      {reversing && (
        <ReverseTransferModal transfer={reversing} onClose={() => setReversing(null)} onDone={handleReversed} onStale={reload} onSessionExpired={onSessionExpired} />
      )}
    </section>
  )
}

export default TransfersPage
