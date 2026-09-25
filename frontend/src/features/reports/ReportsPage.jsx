import { useEffect, useState } from 'react'
import { formatIsoDate, todayLocalIso } from '../../shared/utils/dates'
import { formatMoney, isNegativeMoney } from '../../shared/utils/money'
import { formatDateTime } from '../audit/uiFormat'
import { formatAnoMes } from '../periods/uiFormat'
import { buildSortOptions, downloadReport, getReport, getReportCatalog, saveBlob } from './reportsService'

const EMPTY_DRAFT = { report: '', ano_mes: '', conta_id: '', categoria_id: '', status: '', ordenar: '' }

function renderCell(value, type) {
  if (value === null || value === undefined || value === '') return <span className="text-body-secondary">—</span>
  if (type === 'dinheiro') return <span className={isNegativeMoney(value) ? 'text-danger' : ''}>{formatMoney(value)}</span>
  if (type === 'data') return formatIsoDate(value)
  if (type === 'datahora') return formatDateTime(value)

  return String(value)
}

function ReportsPage({ onSessionExpired }) {
  const [catalog, setCatalog] = useState(null)
  const [catalogStatus, setCatalogStatus] = useState('loading')
  const [catalogError, setCatalogError] = useState(null)
  const [draft, setDraft] = useState(EMPTY_DRAFT)
  const [applied, setApplied] = useState(null)
  const [page, setPage] = useState(1)
  const [refreshKey, setRefreshKey] = useState(0)
  const [result, setResult] = useState(null)
  const [status, setStatus] = useState('loading')
  const [loadError, setLoadError] = useState(null)
  const [exporting, setExporting] = useState(null)
  const [exportError, setExportError] = useState(null)
  const [flash, setFlash] = useState(null)

  const maxMonth = todayLocalIso().slice(0, 7)
  const canExport = catalog?.meta.permissoes.exportar === true
  const reports = catalog?.reports ?? []
  const draftReport = reports.find((report) => report.codigo === draft.report)
  const appliedReport = applied ? reports.find((report) => report.codigo === applied.report) : null
  const dirty = applied !== null && JSON.stringify(draft) !== JSON.stringify(applied)

  // Catálogo (relatórios permitidos + opções de filtro + se pode exportar): vem do backend.
  useEffect(() => {
    let cancelled = false

    getReportCatalog().then((response) => {
      if (cancelled) return

      if (response.ok) {
        const data = { reports: response.data.data, meta: response.data.meta }
        setCatalog(data)
        setCatalogError(null)
        setCatalogStatus('ready')
        const first = { ...EMPTY_DRAFT, report: data.reports[0]?.codigo ?? '' }
        setDraft(first)
        setApplied(first)
        return
      }

      if (response.error.kind === 'unauthenticated') return onSessionExpired()

      setCatalogError(response.error)
      setCatalogStatus('error')
    })

    return () => {
      cancelled = true
    }
  }, [onSessionExpired])

  useEffect(() => {
    if (!applied || !appliedReport) return undefined

    let cancelled = false

    getReport({ report: applied.report, filters: applied, allowedFilters: appliedReport.filtros, paginated: appliedReport.paginado, page }).then((response) => {
      if (cancelled) return

      if (response.ok) {
        setResult({ rows: response.data.data, meta: response.data.meta })
        setLoadError(null)
        setStatus('ready')
        return
      }

      if (response.error.kind === 'unauthenticated') return onSessionExpired()

      setLoadError(
        response.error.kind === 'validation'
          ? { ...response.error, message: 'Filtros inválidos. Confira o mês (não pode ser futuro) e os demais campos.' }
          : response.error,
      )
      setStatus('error')
    })

    return () => {
      cancelled = true
    }
  }, [applied, appliedReport, page, refreshKey, onSessionExpired])

  function changeDraft(name, value) {
    setDraft((current) => ({ ...current, [name]: value }))
  }

  function changeReport(code) {
    // Trocar de relatório descarta os filtros específicos (cada relatório aceita filtros diferentes); o mês permanece.
    setDraft((current) => ({ ...EMPTY_DRAFT, report: code, ano_mes: current.ano_mes }))
  }

  function consult(event) {
    event.preventDefault()
    setFlash(null)
    setExportError(null)
    setStatus('loading')
    setPage(1)
    setApplied({ ...draft })
    setRefreshKey((key) => key + 1)
  }

  function refresh() {
    setStatus((current) => (current === 'ready' ? 'refreshing' : current))
    setRefreshKey((key) => key + 1)
  }

  function retry() {
    setStatus('loading')
    setRefreshKey((key) => key + 1)
  }

  function changePage(target) {
    setStatus('refreshing')
    setPage(target)
  }

  async function exportFile(format) {
    setExporting(format)
    setExportError(null)
    setFlash(null)

    const response = await downloadReport({ report: applied.report, format, filters: applied, allowedFilters: appliedReport.filtros })

    setExporting(null)

    if (response.ok) {
      saveBlob(response.blob, response.filename)
      setFlash(`Arquivo ${response.filename} gerado. A exportação foi registrada na auditoria.`)
      return
    }

    if (response.error.kind === 'unauthenticated') return onSessionExpired()

    setExportError(response.error.message)
  }

  const isBusy = status === 'loading' || status === 'refreshing'
  const hasFilters = applied && (applied.conta_id !== '' || applied.categoria_id !== '' || applied.status !== '')
  const filterNames = draftReport?.filtros ?? []
  const categoryType = draft.report === 'despesas' ? 'despesa' : 'entrada'
  const meta = result?.meta
  const columns = meta?.colunas ?? []
  const pagination = meta?.paginacao

  return (
    <section aria-labelledby="reports-title">
      <div className="mb-3">
        <h1 className="h4 mb-0" id="reports-title">
          Relatórios
        </h1>
        <p className="text-body-secondary mb-0 small">
          Os números são os mesmos do Dashboard para o mesmo mês. Entradas pela data de competência e despesas pagas pela data de pagamento. A exportação
          traz todo o conjunto filtrado (não só a página) e é sempre registrada na auditoria.
        </p>
      </div>

      {catalogStatus === 'loading' && (
        <div className="text-center py-5" role="status">
          <span className="spinner-border text-primary" aria-hidden="true"></span>
          <div className="text-body-secondary mt-2">Carregando relatórios...</div>
        </div>
      )}

      {catalogStatus === 'error' && (
        <div className="alert alert-danger d-flex align-items-center justify-content-between" role="alert">
          <span>{catalogError.message}</span>
          {catalogError.kind !== 'forbidden' && (
            <button type="button" className="btn btn-sm btn-outline-danger" onClick={() => window.location.reload()}>
              Recarregar
            </button>
          )}
        </div>
      )}

      {catalogStatus === 'ready' && draftReport && (
        <>
          <form className="row g-2 mb-3" onSubmit={consult}>
            <div className="col-12 col-md-4 col-lg-3">
              <label htmlFor="report-select" className="form-label small mb-1">
                Relatório
              </label>
              <select id="report-select" className="form-select form-select-sm" value={draft.report} onChange={(event) => changeReport(event.target.value)}>
                {reports.map((report) => (
                  <option key={report.codigo} value={report.codigo}>
                    {report.nome}
                  </option>
                ))}
              </select>
            </div>
            <div className="col-6 col-md-3 col-lg-2">
              <label htmlFor="report-month" className="form-label small mb-1">
                Mês
              </label>
              <input
                id="report-month"
                type="month"
                className="form-control form-control-sm"
                value={draft.ano_mes}
                max={maxMonth}
                onChange={(event) => changeDraft('ano_mes', event.target.value <= maxMonth ? event.target.value : draft.ano_mes)}
              />
            </div>
            {filterNames.includes('conta_id') && (
              <div className="col-6 col-md-3 col-lg-2">
                <label htmlFor="report-account" className="form-label small mb-1">
                  Conta
                </label>
                <select id="report-account" className="form-select form-select-sm" value={draft.conta_id} onChange={(event) => changeDraft('conta_id', event.target.value)}>
                  <option value="">Todas</option>
                  {catalog.meta.opcoes.contas.map((account) => (
                    <option key={account.id} value={account.id}>
                      {account.nome}
                      {account.ativa ? '' : ' (inativa)'}
                    </option>
                  ))}
                </select>
              </div>
            )}
            {filterNames.includes('categoria_id') && (
              <div className="col-6 col-md-3 col-lg-2">
                <label htmlFor="report-category" className="form-label small mb-1">
                  Categoria
                </label>
                <select id="report-category" className="form-select form-select-sm" value={draft.categoria_id} onChange={(event) => changeDraft('categoria_id', event.target.value)}>
                  <option value="">Todas</option>
                  {catalog.meta.opcoes.categorias
                    .filter((category) => category.tipo === categoryType)
                    .map((category) => (
                      <option key={category.id} value={category.id}>
                        {category.nome}
                      </option>
                    ))}
                </select>
              </div>
            )}
            {filterNames.includes('status') && (
              <div className="col-6 col-md-3 col-lg-2">
                <label htmlFor="report-status" className="form-label small mb-1">
                  Situação
                </label>
                <select id="report-status" className="form-select form-select-sm" value={draft.status} onChange={(event) => changeDraft('status', event.target.value)}>
                  <option value="">Todas</option>
                  {catalog.meta.status_despesa.map((option) => (
                    <option key={option.valor} value={option.valor}>
                      {option.rotulo}
                    </option>
                  ))}
                </select>
              </div>
            )}
            {draftReport.ordenacao.length > 0 && (
              <div className="col-6 col-md-3 col-lg-2">
                <label htmlFor="report-sort" className="form-label small mb-1">
                  Ordenar por
                </label>
                <select id="report-sort" className="form-select form-select-sm" value={draft.ordenar} onChange={(event) => changeDraft('ordenar', event.target.value)}>
                  {buildSortOptions(draftReport.ordenacao).map((option) => (
                    <option key={option.value} value={option.value}>
                      {option.label}
                    </option>
                  ))}
                </select>
              </div>
            )}
            <div className="col-12 col-md-auto d-flex align-items-end gap-2 flex-wrap">
              <button type="submit" className="btn btn-sm btn-primary" disabled={isBusy}>
                Consultar
              </button>
              {canExport && (
                <>
                  <button
                    type="button"
                    className="btn btn-sm btn-outline-success"
                    onClick={() => exportFile('csv')}
                    disabled={exporting !== null || isBusy || dirty || status === 'error'}
                    title={dirty ? 'Clique em Consultar para aplicar os filtros antes de exportar' : undefined}
                  >
                    {exporting === 'csv' && <span className="spinner-border spinner-border-sm me-2" aria-hidden="true"></span>}
                    Exportar CSV
                  </button>
                  <button
                    type="button"
                    className="btn btn-sm btn-outline-success"
                    onClick={() => exportFile('xlsx')}
                    disabled={exporting !== null || isBusy || dirty || status === 'error'}
                    title={dirty ? 'Clique em Consultar para aplicar os filtros antes de exportar' : undefined}
                  >
                    {exporting === 'xlsx' && <span className="spinner-border spinner-border-sm me-2" aria-hidden="true"></span>}
                    Exportar Excel
                  </button>
                </>
              )}
              <button type="button" className="btn btn-sm btn-outline-secondary" onClick={refresh} disabled={isBusy || dirty}>
                Atualizar
              </button>
            </div>
          </form>

          <p className="small text-body-secondary mb-2">{draftReport.descricao}</p>
          {dirty && (
            <div className="alert alert-warning py-2 small" role="note">
              Você alterou os filtros. Clique em <strong>Consultar</strong> para atualizar a tela{canExport ? ' antes de exportar' : ''}.
            </div>
          )}
        </>
      )}

      {flash && (
        <div className="alert alert-success alert-dismissible py-2" role="status">
          {flash}
          <button type="button" className="btn-close" aria-label="Fechar aviso" onClick={() => setFlash(null)}></button>
        </div>
      )}

      {exportError && (
        <div className="alert alert-danger alert-dismissible py-2" role="alert">
          {exportError}
          <button type="button" className="btn-close" aria-label="Fechar aviso" onClick={() => setExportError(null)}></button>
        </div>
      )}

      {catalogStatus === 'ready' && status === 'loading' && (
        <div className="text-center py-5" role="status">
          <span className="spinner-border text-primary" aria-hidden="true"></span>
          <div className="text-body-secondary mt-2">Carregando relatório...</div>
        </div>
      )}

      {catalogStatus === 'ready' && status === 'error' && (
        <div className="alert alert-danger d-flex align-items-center justify-content-between" role="alert">
          <span>{loadError.message}</span>
          {loadError.kind !== 'forbidden' && (
            <button type="button" className="btn btn-sm btn-outline-danger" onClick={retry}>
              Tentar novamente
            </button>
          )}
        </div>
      )}

      {catalogStatus === 'ready' && (status === 'ready' || status === 'refreshing') && result && (
        <div className={status === 'refreshing' ? 'opacity-50' : ''} aria-busy={status === 'refreshing'}>
          <h2 className="h6 text-body-secondary mb-3" data-testid="report-heading">
            {meta.titulo} · <span className="text-capitalize">{formatAnoMes(meta.ano_mes)}</span>
          </h2>

          {meta.escopo === 'proprios' && (
            <div className="alert alert-info py-2" role="note">
              Você está vendo apenas os lançamentos que você mesmo registrou.
            </div>
          )}

          {meta.totais.length > 0 && (
            <div className="row g-2 mb-3">
              {meta.totais.map((total) => (
                <div className="col-6 col-md-4 col-xl-3" key={total.chave}>
                  <div className="card shadow-sm h-100" data-testid={`total-${total.chave}`}>
                    <div className="card-body py-2">
                      <div className="text-body-secondary small">{total.rotulo}</div>
                      <div className="fs-5 fw-semibold text-nowrap">{renderCell(total.valor, total.tipo)}</div>
                    </div>
                  </div>
                </div>
              ))}
            </div>
          )}

          <div className="card shadow-sm">
            {result.rows.length === 0 ? (
              <div className="card-body text-center text-body-secondary py-5">
                {hasFilters ? 'Nenhum registro encontrado com os filtros selecionados.' : 'Nenhum registro neste mês.'}
              </div>
            ) : (
              <div className="table-responsive">
                <table className="table table-hover align-middle mb-0" data-testid="report-table">
                  <thead className="table-light">
                    <tr>
                      {columns.map((column) => (
                        <th key={column.chave} scope="col" className={column.tipo === 'dinheiro' ? 'text-end' : ''}>
                          {column.rotulo}
                        </th>
                      ))}
                    </tr>
                  </thead>
                  <tbody>
                    {result.rows.map((row, index) => (
                      <tr key={`${index}-${row.id ?? row.referencia ?? row.indicador ?? ''}`}>
                        {columns.map((column) => {
                          const type = row._tipos?.[column.chave] ?? column.tipo

                          return (
                            <td key={column.chave} className={type === 'dinheiro' ? 'text-end text-nowrap' : ''}>
                              {renderCell(row[column.chave], type)}
                            </td>
                          )
                        })}
                      </tr>
                    ))}
                  </tbody>
                </table>
              </div>
            )}

            {pagination && pagination.last_page > 1 && (
              <div className="card-footer d-flex flex-wrap align-items-center justify-content-between gap-2">
                <span className="text-body-secondary small">
                  Página {pagination.current_page} de {pagination.last_page} · {pagination.total} registros
                </span>
                <div className="btn-group" role="group" aria-label="Paginação">
                  <button type="button" className="btn btn-sm btn-outline-secondary" onClick={() => changePage(pagination.current_page - 1)} disabled={pagination.current_page <= 1 || isBusy}>
                    Anterior
                  </button>
                  <button
                    type="button"
                    className="btn btn-sm btn-outline-secondary"
                    onClick={() => changePage(pagination.current_page + 1)}
                    disabled={pagination.current_page >= pagination.last_page || isBusy}
                  >
                    Próxima
                  </button>
                </div>
              </div>
            )}
          </div>
        </div>
      )}
    </section>
  )
}

export default ReportsPage
