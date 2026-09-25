import { useEffect, useState } from 'react'
import { formatMoney } from '../../shared/utils/money'
import EntryFormModal from './EntryFormModal'
import ReverseEntryModal from './ReverseEntryModal'
import { ENTRY_SORT_OPTIONS, ENTRY_STATUS_LABELS, formatIsoDate, listAccountOptions, listEntries, listEntryCategories } from './entriesService'
import { seesOnlyOwnEntries } from './uiHints'

const EMPTY_FILTERS = { data_de: '', data_ate: '', categoria_id: '', conta_id: '', status: '', estorno: '', ordenar: ENTRY_SORT_OPTIONS[0].value }

function EntriesPage({ currentUser, onSessionExpired }) {
  const onlyOwn = seesOnlyOwnEntries(currentUser.perfil?.slug)

  const [filters, setFilters] = useState(EMPTY_FILTERS)
  const [page, setPage] = useState(1)
  const [refreshKey, setRefreshKey] = useState(0)
  const [entries, setEntries] = useState([])
  const [meta, setMeta] = useState(null)
  const [status, setStatus] = useState('loading')
  const [loadError, setLoadError] = useState(null)
  const [flash, setFlash] = useState(null)
  const [showForm, setShowForm] = useState(false)
  const [reversing, setReversing] = useState(null)
  const [categoryOptions, setCategoryOptions] = useState([])
  const [accountOptions, setAccountOptions] = useState([])

  const invalidRange = filters.data_de !== '' && filters.data_ate !== '' && filters.data_ate < filters.data_de
  const permissions = meta?.permissoes ?? { criar: false, estornar: false }

  // Opções dos filtros: inclui categorias/contas inativas, pois entradas antigas podem usá-las.
  useEffect(() => {
    let cancelled = false

    Promise.all([listEntryCategories(), listAccountOptions()]).then(([categoriesResult, accountsResult]) => {
      if (cancelled) return

      if (categoriesResult.ok) setCategoryOptions(categoriesResult.data.data)
      if (accountsResult.ok) setAccountOptions(accountsResult.data.data)
    })

    return () => {
      cancelled = true
    }
  }, [refreshKey])

  useEffect(() => {
    if (invalidRange) return undefined

    let cancelled = false

    listEntries({ ...filters, page }).then((result) => {
      if (cancelled) return

      if (result.ok) {
        setEntries(result.data.data)
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
    <section aria-labelledby="entries-title">
      <div className="d-flex flex-wrap align-items-center justify-content-between gap-2 mb-3">
        <div>
          <h1 className="h4 mb-0" id="entries-title">
            Entradas
          </h1>
          <p className="text-body-secondary mb-0 small">
            {onlyOwn ? 'Você vê apenas as entradas registradas por você.' : 'Receitas da igreja: dízimos, ofertas, doações e outras entradas.'}{' '}
            Entradas não são editadas nem excluídas; correções são feitas por estorno.
          </p>
        </div>
        {permissions.criar && (
          <button type="button" className="btn btn-primary" onClick={() => setShowForm(true)}>
            Nova entrada
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
          <label htmlFor="filter-category" className="form-label small mb-1">
            Categoria
          </label>
          <select id="filter-category" className="form-select form-select-sm" value={filters.categoria_id} onChange={(event) => changeFilter('categoria_id', event.target.value)}>
            <option value="">Todas</option>
            {categoryOptions.map((category) => (
              <option key={category.id} value={category.id}>
                {category.nome}
              </option>
            ))}
          </select>
        </div>
        <div className="col-6 col-md-3 col-lg-2">
          <label htmlFor="filter-account" className="form-label small mb-1">
            Conta
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
            {Object.entries(ENTRY_STATUS_LABELS).map(([value, label]) => (
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
            <option value="false">Entradas</option>
            <option value="true">Estornos</option>
          </select>
        </div>
        <div className="col-6 col-md-3 col-lg-3">
          <label htmlFor="filter-sort" className="form-label small mb-1">
            Ordenar por
          </label>
          <select id="filter-sort" className="form-select form-select-sm" value={filters.ordenar} onChange={(event) => changeFilter('ordenar', event.target.value)}>
            {ENTRY_SORT_OPTIONS.map((option) => (
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
          <div className="text-body-secondary mt-2">Carregando entradas...</div>
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
          {entries.length === 0 ? (
            <div className="card-body text-center text-body-secondary py-5">
              {hasFilters ? 'Nenhuma entrada encontrada com os filtros selecionados.' : 'Nenhuma entrada registrada.'}
            </div>
          ) : (
            <div className="table-responsive">
              <table className="table table-hover align-middle mb-0">
                <thead className="table-light">
                  <tr>
                    <th scope="col">Data</th>
                    <th scope="col">Categoria</th>
                    <th scope="col">Conta</th>
                    <th scope="col" className="text-end">
                      Valor
                    </th>
                    <th scope="col">Contribuinte / Descrição</th>
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
                  {entries.map((entry) => (
                    <tr key={entry.id} className={entry.eh_estorno ? 'table-warning' : ''}>
                      <td className="text-nowrap">{formatIsoDate(entry.data_competencia)}</td>
                      <td>{entry.categoria?.nome ?? '—'}</td>
                      <td>{entry.conta?.nome ?? '—'}</td>
                      {/* O sinal do estorno é só de exibição: o valor da API é sempre positivo. */}
                      <td className={`text-end text-nowrap ${entry.eh_estorno ? 'text-danger fw-semibold' : ''}`}>
                        {entry.eh_estorno ? formatMoney(`-${entry.valor}`) : formatMoney(entry.valor)}
                      </td>
                      <td>
                        {entry.eh_estorno ? (
                          <>
                            <div className="fw-semibold">Estorno da entrada #{entry.entrada_estornada_id}</div>
                            <div className="small text-body-secondary">{entry.motivo_estorno}</div>
                          </>
                        ) : (
                          <>
                            {entry.contribuinte_nome && <div>{entry.contribuinte_nome}</div>}
                            {entry.descricao && <div className="small text-body-secondary">{entry.descricao}</div>}
                            {!entry.contribuinte_nome && !entry.descricao && <span className="text-body-secondary">—</span>}
                          </>
                        )}
                      </td>
                      <td>
                        {entry.eh_estorno ? (
                          <span className="badge text-bg-warning">Estorno</span>
                        ) : (
                          <>
                            <span className={`badge ${entry.status === 'confirmada' ? 'text-bg-success' : 'text-bg-secondary'}`}>{ENTRY_STATUS_LABELS[entry.status]}</span>
                            {entry.estorno_id && <div className="small text-body-secondary">Estorno #{entry.estorno_id}</div>}
                          </>
                        )}
                      </td>
                      <td>{entry.criado_por?.name ?? '—'}</td>
                      {showActions && (
                        <td className="text-end text-nowrap">
                          {entry.estornavel && (
                            <button type="button" className="btn btn-sm btn-outline-danger" onClick={() => setReversing(entry)}>
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

      {showForm && <EntryFormModal onClose={() => setShowForm(false)} onSaved={handleCreated} onSessionExpired={onSessionExpired} />}

      {reversing && (
        <ReverseEntryModal entry={reversing} onClose={() => setReversing(null)} onDone={handleReversed} onStale={reload} onSessionExpired={onSessionExpired} />
      )}
    </section>
  )
}

export default EntriesPage
