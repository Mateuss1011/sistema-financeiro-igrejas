import { useEffect, useRef, useState } from 'react'
import AuditDetailModal from './AuditDetailModal'
import { AUDIT_SORT_OPTIONS, NO_USER_FILTER, getAuditCatalog, listAuditLogs } from './auditService'
import { formatDateTime, formatProfile } from './uiFormat'

const EMPTY_FILTERS = { modulo: '', acao: '', usuario: '', registro_id: '', data_de: '', data_ate: '', ordenar: AUDIT_SORT_OPTIONS[0].value }
const REGISTRO_DEBOUNCE_MS = 350

function AuditPage({ onSessionExpired }) {
  const [filters, setFilters] = useState(EMPTY_FILTERS)
  const [registroInput, setRegistroInput] = useState('')
  const [page, setPage] = useState(1)
  const [refreshKey, setRefreshKey] = useState(0)
  const [logs, setLogs] = useState([])
  const [meta, setMeta] = useState(null)
  const [status, setStatus] = useState('loading')
  const [loadError, setLoadError] = useState(null)
  const [catalog, setCatalog] = useState({ modulos: [], usuarios: [] })
  const [viewing, setViewing] = useState(null)
  const registroTimer = useRef(null)

  const invalidRange = filters.data_de !== '' && filters.data_ate !== '' && filters.data_ate < filters.data_de
  const invalidRegistro = registroInput !== '' && !/^[1-9]\d*$/.test(registroInput)

  const selectedModule = catalog.modulos.find((module) => module.valor === filters.modulo)
  const actionOptions = selectedModule
    ? selectedModule.acoes
    : [...new Map(catalog.modulos.flatMap((module) => module.acoes).map((action) => [action.valor, action])).values()]

  useEffect(() => {
    let cancelled = false

    getAuditCatalog().then((result) => {
      if (cancelled) return
      if (result.ok) setCatalog(result.data.data)
      else if (result.error.kind === 'unauthenticated') onSessionExpired()
    })

    return () => {
      cancelled = true
    }
  }, [refreshKey, onSessionExpired])

  useEffect(() => {
    if (invalidRange) return undefined

    let cancelled = false

    listAuditLogs({ ...filters, page }).then((result) => {
      if (cancelled) return

      if (result.ok) {
        setLogs(result.data.data)
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

  useEffect(() => () => clearTimeout(registroTimer.current), [])

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
    setFilters((current) => {
      const next = { ...current, [name]: value }
      // Ao trocar o módulo, uma ação que ele não possui deixa de valer.
      if (name === 'modulo') {
        const module = catalog.modulos.find((item) => item.valor === value)
        if (module && !module.acoes.some((action) => action.valor === current.acao)) next.acao = ''
      }
      return next
    })
  }

  function changeRegistro(value) {
    setRegistroInput(value)
    clearTimeout(registroTimer.current)

    if (value !== '' && !/^[1-9]\d*$/.test(value)) return

    registroTimer.current = setTimeout(() => changeFilter('registro_id', value), REGISTRO_DEBOUNCE_MS)
  }

  function clearFilters() {
    clearTimeout(registroTimer.current)
    setRegistroInput('')
    setStatus('loading')
    setPage(1)
    setFilters(EMPTY_FILTERS)
  }

  function changePage(targetPage) {
    setStatus('refreshing')
    setPage(targetPage)
  }

  const isBusy = status === 'loading' || status === 'refreshing'
  const hasFilters = Object.entries(filters).some(([name, value]) => name !== 'ordenar' && value !== '') || registroInput !== ''

  return (
    <section aria-labelledby="audit-title">
      <div className="d-flex flex-wrap align-items-center justify-content-between gap-2 mb-3">
        <div>
          <h1 className="h4 mb-0" id="audit-title">
            Auditoria
          </h1>
          <p className="text-body-secondary mb-0 small">
            Histórico de tudo o que foi feito no sistema, com quem fez e quando. É somente para consulta: nenhum registro pode ser alterado ou excluído.
            Horários no fuso de Brasília.
          </p>
        </div>
        <button type="button" className="btn btn-outline-secondary btn-sm" onClick={reload} disabled={isBusy}>
          Atualizar
        </button>
      </div>

      <div className="row g-2 mb-3">
        <div className="col-6 col-md-3 col-lg-2">
          <label htmlFor="filter-module" className="form-label small mb-1">
            Módulo
          </label>
          <select id="filter-module" className="form-select form-select-sm" value={filters.modulo} onChange={(event) => changeFilter('modulo', event.target.value)}>
            <option value="">Todos</option>
            {catalog.modulos.map((module) => (
              <option key={module.valor} value={module.valor}>
                {module.rotulo}
              </option>
            ))}
          </select>
        </div>
        <div className="col-6 col-md-3 col-lg-2">
          <label htmlFor="filter-action" className="form-label small mb-1">
            Ação
          </label>
          <select id="filter-action" className="form-select form-select-sm" value={filters.acao} onChange={(event) => changeFilter('acao', event.target.value)}>
            <option value="">Todas</option>
            {actionOptions.map((action) => (
              <option key={action.valor} value={action.valor}>
                {action.rotulo}
              </option>
            ))}
          </select>
        </div>
        <div className="col-6 col-md-3 col-lg-2">
          <label htmlFor="filter-user" className="form-label small mb-1">
            Responsável
          </label>
          <select id="filter-user" className="form-select form-select-sm" value={filters.usuario} onChange={(event) => changeFilter('usuario', event.target.value)}>
            <option value="">Todos</option>
            <option value={NO_USER_FILTER}>Sem usuário vinculado (inexistente ou removido)</option>
            {catalog.usuarios.map((user) => (
              <option key={user.id} value={user.id}>
                {user.nome}
              </option>
            ))}
          </select>
        </div>
        <div className="col-6 col-md-3 col-lg-2">
          <label htmlFor="filter-record" className="form-label small mb-1">
            Nº do registro afetado
          </label>
          <input
            id="filter-record"
            type="text"
            inputMode="numeric"
            className={`form-control form-control-sm ${invalidRegistro ? 'is-invalid' : ''}`}
            value={registroInput}
            onChange={(event) => changeRegistro(event.target.value.trim())}
            placeholder="Ex.: 42"
            aria-describedby={invalidRegistro ? 'filter-record-error' : undefined}
          />
          {invalidRegistro && (
            <div className="invalid-feedback" id="filter-record-error">
              Informe apenas números.
            </div>
          )}
        </div>
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
          <label htmlFor="filter-sort" className="form-label small mb-1">
            Ordenar por
          </label>
          <select id="filter-sort" className="form-select form-select-sm" value={filters.ordenar} onChange={(event) => changeFilter('ordenar', event.target.value)}>
            {AUDIT_SORT_OPTIONS.map((option) => (
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

      {invalidRange && (
        <div className="alert alert-warning py-2" role="alert">
          A data final não pode ser anterior à data inicial.
        </div>
      )}

      {!invalidRange && status === 'loading' && (
        <div className="text-center py-5" role="status">
          <span className="spinner-border text-primary" aria-hidden="true"></span>
          <div className="text-body-secondary mt-2">Carregando registros...</div>
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
          {logs.length === 0 ? (
            <div className="card-body text-center text-body-secondary py-5">
              {hasFilters ? 'Nenhum registro encontrado com os filtros selecionados.' : 'Nenhum registro de auditoria.'}
            </div>
          ) : (
            <div className="table-responsive">
              <table className="table table-hover align-middle mb-0">
                <thead className="table-light">
                  <tr>
                    <th scope="col">Data e hora</th>
                    <th scope="col">Módulo</th>
                    <th scope="col">Ação</th>
                    <th scope="col">Registro</th>
                    <th scope="col">Responsável</th>
                    <th scope="col">Justificativa</th>
                    <th scope="col" className="text-end">
                      Detalhes
                    </th>
                  </tr>
                </thead>
                <tbody>
                  {logs.map((log) => (
                    <tr key={log.id}>
                      <td className="text-nowrap">{formatDateTime(log.created_at)}</td>
                      <td>{log.modulo_rotulo}</td>
                      <td>{log.acao_rotulo}</td>
                      <td>{log.registro_id ? `#${log.registro_id}` : <span className="text-body-secondary">—</span>}</td>
                      <td>
                        {log.usuario?.nome ? (
                          <>
                            {log.usuario.nome}
                            {log.usuario.perfil && <div className="small text-body-secondary">{formatProfile(log.usuario.perfil)}</div>}
                          </>
                        ) : (
                          <span className="text-body-secondary">Sem usuário identificado</span>
                        )}
                      </td>
                      <td className="text-break" style={{ maxWidth: '20rem' }}>
                        {log.justificativa ?? <span className="text-body-secondary">—</span>}
                      </td>
                      <td className="text-end text-nowrap">
                        <button type="button" className="btn btn-sm btn-outline-primary" onClick={() => setViewing(log)}>
                          Ver detalhes
                        </button>
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
                Página {meta.current_page} de {meta.last_page} · {meta.total} registros
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

      {viewing && <AuditDetailModal log={viewing} onClose={() => setViewing(null)} />}
    </section>
  )
}

export default AuditPage
