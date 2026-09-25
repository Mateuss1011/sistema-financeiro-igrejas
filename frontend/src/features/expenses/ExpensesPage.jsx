import { useEffect, useState } from 'react'
import ConfirmDialog from '../../shared/components/ConfirmDialog'
import { formatIsoDate } from '../../shared/utils/dates'
import { formatMoney } from '../../shared/utils/money'
import ExpenseFormModal from './ExpenseFormModal'
import PayExpenseModal from './PayExpenseModal'
import ReasonModal from './ReasonModal'
import {
  EXPENSE_SORT_OPTIONS,
  EXPENSE_STATUS_BADGES,
  EXPENSE_STATUS_LABELS,
  deleteExpense,
  listAccountOptions,
  listExpenseCategories,
  listExpenses,
} from './expensesService'
import { seesOnlyOwnExpenses } from './uiHints'

const EMPTY_FILTERS = { data_de: '', data_ate: '', categoria_id: '', conta_id: '', status: '', estorno: '', ordenar: EXPENSE_SORT_OPTIONS[0].value }

function ExpensesPage({ currentUser, onSessionExpired }) {
  const onlyOwn = seesOnlyOwnExpenses(currentUser.perfil?.slug)

  const [filters, setFilters] = useState(EMPTY_FILTERS)
  const [page, setPage] = useState(1)
  const [refreshKey, setRefreshKey] = useState(0)
  const [expenses, setExpenses] = useState([])
  const [meta, setMeta] = useState(null)
  const [status, setStatus] = useState('loading')
  const [loadError, setLoadError] = useState(null)
  const [flash, setFlash] = useState(null)
  const [form, setForm] = useState(null) // { mode: 'create' | 'edit', expense? }
  const [paying, setPaying] = useState(null)
  const [reason, setReason] = useState(null) // { mode: 'cancel' | 'reverse', expense }
  const [deleting, setDeleting] = useState(null)
  const [busyDelete, setBusyDelete] = useState(false)
  const [categoryOptions, setCategoryOptions] = useState([])
  const [accountOptions, setAccountOptions] = useState([])

  const invalidRange = filters.data_de !== '' && filters.data_ate !== '' && filters.data_ate < filters.data_de
  const permissions = meta?.permissoes ?? { criar: false, editar: false, pagar: false, cancelar: false, estornar: false, excluir: false }
  const showActions = permissions.editar || permissions.pagar || permissions.cancelar || permissions.estornar || permissions.excluir

  // Opções dos filtros: inclui categorias/contas inativas, pois despesas antigas podem usá-las.
  useEffect(() => {
    let cancelled = false

    Promise.all([listExpenseCategories(), listAccountOptions()]).then(([categoriesResult, accountsResult]) => {
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

    listExpenses({ ...filters, page }).then((result) => {
      if (cancelled) return

      if (result.ok) {
        setExpenses(result.data.data)
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

  function finish(message) {
    setForm(null)
    setPaying(null)
    setReason(null)
    setFlash({ type: 'success', message })
    reload()
  }

  function handleCreated(message) {
    setPage(1)
    setFilters((current) => ({ ...current, ordenar: EMPTY_FILTERS.ordenar }))
    finish(message)
  }

  async function remove(expense) {
    setBusyDelete(true)
    const result = await deleteExpense(expense.id)
    setBusyDelete(false)
    setDeleting(null)

    if (result.ok) {
      setFlash({ type: 'success', message: 'Despesa excluída.' })
      reload()
      return
    }

    if (result.error.kind === 'unauthenticated') return onSessionExpired()
    setFlash({ type: 'danger', message: result.error.message })
    if (result.error.kind === 'notFound' || result.error.kind === 'conflict') reload()
  }

  const isBusy = status === 'loading' || status === 'refreshing'
  const hasFilters = Object.entries(filters).some(([name, value]) => name !== 'ordenar' && value !== '')

  return (
    <section aria-labelledby="expenses-title">
      <div className="d-flex flex-wrap align-items-center justify-content-between gap-2 mb-3">
        <div>
          <h1 className="h4 mb-0" id="expenses-title">
            Despesas
          </h1>
          <p className="text-body-secondary mb-0 small">
            {onlyOwn ? 'Você vê apenas as despesas registradas por você. ' : 'Saídas da igreja: nascem Pendentes e só afetam o saldo quando pagas. '}
            Despesas pagas não são editadas nem excluídas; correções são feitas por estorno.
          </p>
        </div>
        {permissions.criar && (
          <button type="button" className="btn btn-primary" onClick={() => setForm({ mode: 'create' })}>
            Nova despesa
          </button>
        )}
      </div>

      <div className="row g-2 mb-3">
        <div className="col-6 col-md-3 col-lg-2">
          <label htmlFor="filter-from" className="form-label small mb-1">
            Competência de
          </label>
          <input id="filter-from" type="date" className="form-control form-control-sm" value={filters.data_de} onChange={(event) => changeFilter('data_de', event.target.value)} />
        </div>
        <div className="col-6 col-md-3 col-lg-2">
          <label htmlFor="filter-to" className="form-label small mb-1">
            Competência até
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
            {Object.entries(EXPENSE_STATUS_LABELS).map(([value, label]) => (
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
            <option value="false">Despesas</option>
            <option value="true">Estornos</option>
          </select>
        </div>
        <div className="col-6 col-md-3 col-lg-3">
          <label htmlFor="filter-sort" className="form-label small mb-1">
            Ordenar por
          </label>
          <select id="filter-sort" className="form-select form-select-sm" value={filters.ordenar} onChange={(event) => changeFilter('ordenar', event.target.value)}>
            {EXPENSE_SORT_OPTIONS.map((option) => (
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
          <div className="text-body-secondary mt-2">Carregando despesas...</div>
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
          {expenses.length === 0 ? (
            <div className="card-body text-center text-body-secondary py-5">
              {hasFilters ? 'Nenhuma despesa encontrada com os filtros selecionados.' : 'Nenhuma despesa registrada.'}
            </div>
          ) : (
            <div className="table-responsive">
              <table className="table table-hover align-middle mb-0">
                <thead className="table-light">
                  <tr>
                    <th scope="col">Competência</th>
                    <th scope="col">Descrição / Fornecedor</th>
                    <th scope="col">Categoria</th>
                    <th scope="col" className="text-end">
                      Valor
                    </th>
                    <th scope="col">Situação</th>
                    <th scope="col">Conta / Pagamento</th>
                    <th scope="col">Registrado por</th>
                    {showActions && (
                      <th scope="col" className="text-end">
                        Ações
                      </th>
                    )}
                  </tr>
                </thead>
                <tbody>
                  {expenses.map((expense) => (
                    <tr key={expense.id} className={expense.eh_estorno ? 'table-warning' : ''}>
                      <td className="text-nowrap">{formatIsoDate(expense.data_competencia)}</td>
                      <td>
                        {expense.eh_estorno ? (
                          <>
                            <div className="fw-semibold">Estorno da despesa #{expense.despesa_estornada_id}</div>
                            <div className="small text-body-secondary">{expense.motivo_estorno}</div>
                          </>
                        ) : (
                          <>
                            <div>{expense.descricao}</div>
                            {expense.fornecedor_nome && <div className="small text-body-secondary">{expense.fornecedor_nome}</div>}
                            {expense.motivo_cancelamento && <div className="small text-body-secondary">Cancelada: {expense.motivo_cancelamento}</div>}
                          </>
                        )}
                      </td>
                      <td>{expense.categoria?.nome ?? '—'}</td>
                      {/* O sinal do estorno é só de exibição: o valor da API é sempre positivo. */}
                      <td className={`text-end text-nowrap ${expense.eh_estorno ? 'text-success fw-semibold' : ''}`}>
                        {expense.eh_estorno ? `+${formatMoney(expense.valor)}` : formatMoney(expense.valor)}
                      </td>
                      <td>
                        {expense.eh_estorno ? (
                          <span className="badge text-bg-info">Estorno</span>
                        ) : (
                          <>
                            <span className={`badge ${EXPENSE_STATUS_BADGES[expense.status]}`}>{EXPENSE_STATUS_LABELS[expense.status]}</span>
                            {expense.estorno_id && <div className="small text-body-secondary">Estorno #{expense.estorno_id}</div>}
                          </>
                        )}
                      </td>
                      <td>
                        {expense.conta ? (
                          <>
                            <div>{expense.conta.nome}</div>
                            <div className="small text-body-secondary">{formatIsoDate(expense.data_pagamento)}</div>
                          </>
                        ) : (
                          <span className="text-body-secondary">—</span>
                        )}
                      </td>
                      <td>{expense.criado_por?.name ?? '—'}</td>
                      {showActions && (
                        <td className="text-end">
                          <div className="d-inline-flex flex-wrap justify-content-end gap-1">
                            {expense.editavel && (
                              <button type="button" className="btn btn-sm btn-outline-primary" onClick={() => setForm({ mode: 'edit', expense })}>
                                Editar
                              </button>
                            )}
                            {expense.pagavel && (
                              <button type="button" className="btn btn-sm btn-outline-success" onClick={() => setPaying(expense)}>
                                Pagar
                              </button>
                            )}
                            {expense.cancelavel && (
                              <button type="button" className="btn btn-sm btn-outline-warning" onClick={() => setReason({ mode: 'cancel', expense })}>
                                Cancelar
                              </button>
                            )}
                            {expense.estornavel && (
                              <button type="button" className="btn btn-sm btn-outline-danger" onClick={() => setReason({ mode: 'reverse', expense })}>
                                Estornar
                              </button>
                            )}
                            {expense.excluivel && (
                              <button type="button" className="btn btn-sm btn-outline-secondary" onClick={() => setDeleting(expense)}>
                                Excluir
                              </button>
                            )}
                          </div>
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

      {form && (
        <ExpenseFormModal
          mode={form.mode}
          expense={form.expense}
          onClose={() => setForm(null)}
          onSaved={form.mode === 'create' ? handleCreated : finish}
          onSessionExpired={onSessionExpired}
        />
      )}

      {paying && <PayExpenseModal expense={paying} onClose={() => setPaying(null)} onDone={finish} onStale={reload} onSessionExpired={onSessionExpired} />}

      {reason && <ReasonModal mode={reason.mode} expense={reason.expense} onClose={() => setReason(null)} onDone={finish} onStale={reload} onSessionExpired={onSessionExpired} />}

      {deleting && (
        <ConfirmDialog
          title="Excluir despesa"
          message={`Excluir definitivamente "${deleting.descricao}"? Só despesas Pendentes podem ser excluídas (janela de 48 horas para quem a registrou). Esta ação não pode ser desfeita.`}
          confirmLabel="Excluir"
          confirmVariant="danger"
          busy={busyDelete}
          onConfirm={() => remove(deleting)}
          onCancel={() => setDeleting(null)}
        />
      )}
    </section>
  )
}

export default ExpensesPage
