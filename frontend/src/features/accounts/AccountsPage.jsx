import { useEffect, useState } from 'react'
import ConfirmDialog from '../../shared/components/ConfirmDialog'
import { formatMoney, isNegativeMoney, isZeroMoney } from '../../shared/utils/money'
import AdjustmentsPanel from '../adjustments/AdjustmentsPanel'
import { canAccessAdjustments } from '../adjustments/uiHints'
import AccountFormModal from './AccountFormModal'
import { ACCOUNT_TYPE_LABELS, deleteAccount, listAccounts, updateAccount } from './accountsService'
import { canDeleteAccounts, canWriteAccounts } from './uiHints'

function AccountsPage({ currentUser, onSessionExpired }) {
  const perfilSlug = currentUser.perfil?.slug
  const canWrite = canWriteAccounts(perfilSlug)
  const canDelete = canDeleteAccounts(perfilSlug)

  const [filters, setFilters] = useState({ tipo: '', ativa: '' })
  const [page, setPage] = useState(1)
  const [refreshKey, setRefreshKey] = useState(0)
  const [accounts, setAccounts] = useState([])
  const [meta, setMeta] = useState(null)
  const [status, setStatus] = useState('loading')
  const [loadError, setLoadError] = useState(null)
  const [flash, setFlash] = useState(null)
  const [form, setForm] = useState(null)
  const [confirm, setConfirm] = useState(null) // { action: 'deactivate' | 'delete', account }
  const [busyId, setBusyId] = useState(null)

  useEffect(() => {
    let cancelled = false

    listAccounts({ ...filters, page }).then((result) => {
      if (cancelled) return

      if (result.ok) {
        setAccounts(result.data.data)
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
  }, [filters, page, refreshKey, onSessionExpired])

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

  function changePage(targetPage) {
    setStatus('refreshing')
    setPage(targetPage)
  }

  async function setActive(account, ativa) {
    setBusyId(account.id)
    const result = await updateAccount(account.id, { ativa })
    setBusyId(null)
    setConfirm(null)

    if (result.ok) {
      setFlash({ type: 'success', message: ativa ? `Conta "${account.nome}" ativada.` : `Conta "${account.nome}" inativada.` })
      reload()
      return
    }

    if (result.error.kind === 'unauthenticated') return onSessionExpired()
    setFlash({ type: 'danger', message: result.error.message })
  }

  async function remove(account) {
    setBusyId(account.id)
    const result = await deleteAccount(account.id)
    setBusyId(null)
    setConfirm(null)

    if (result.ok) {
      setFlash({ type: 'success', message: `Conta "${account.nome}" excluída.` })
      reload()
      return
    }

    if (result.error.kind === 'unauthenticated') return onSessionExpired()
    setFlash({ type: 'danger', message: result.error.message })
    if (result.error.kind === 'notFound') reload()
  }

  function handleSaved(message) {
    setForm(null)
    setFlash({ type: 'success', message })
    reload()
  }

  function deactivateMessage(account) {
    const base = `Inativar "${account.nome}"? Ela deixará de receber lançamentos e transferências, mas continua consultável e o histórico é preservado.`

    return isZeroMoney(account.saldo_atual)
      ? base
      : `${base} Atenção: esta conta possui saldo de ${formatMoney(account.saldo_atual)}; inativar não move nem apaga esse valor.`
  }

  const isBusy = status === 'loading' || status === 'refreshing'
  const hasFilters = filters.tipo !== '' || filters.ativa !== ''
  const showActions = canWrite || canDelete

  return (
    <section aria-labelledby="accounts-title">
      <div className="d-flex flex-wrap align-items-center justify-content-between gap-2 mb-3">
        <div>
          <h1 className="h4 mb-0" id="accounts-title">
            Contas
          </h1>
          <p className="text-body-secondary mb-0 small">
            {canWrite ? 'Contas bancárias e caixas onde o dinheiro da igreja é mantido.' : 'Consulta de contas e saldos (somente leitura).'}
          </p>
        </div>
        {canWrite && (
          <button type="button" className="btn btn-primary" onClick={() => setForm({ mode: 'create' })}>
            Nova conta
          </button>
        )}
      </div>

      <div className="row g-2 mb-3">
        <div className="col-6 col-md-3">
          <label htmlFor="filter-type" className="form-label small mb-1">
            Tipo
          </label>
          <select id="filter-type" className="form-select form-select-sm" value={filters.tipo} onChange={(event) => changeFilter('tipo', event.target.value)}>
            <option value="">Todos</option>
            <option value="banco">Conta bancária</option>
            <option value="caixa">Caixa</option>
          </select>
        </div>
        <div className="col-6 col-md-3">
          <label htmlFor="filter-status" className="form-label small mb-1">
            Status
          </label>
          <select id="filter-status" className="form-select form-select-sm" value={filters.ativa} onChange={(event) => changeFilter('ativa', event.target.value)}>
            <option value="">Todos</option>
            <option value="true">Ativas</option>
            <option value="false">Inativas</option>
          </select>
        </div>
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
          <div className="text-body-secondary mt-2">Carregando contas...</div>
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
          {accounts.length === 0 ? (
            <div className="card-body text-center text-body-secondary py-5">
              {hasFilters ? 'Nenhuma conta encontrada com os filtros selecionados.' : 'Nenhuma conta cadastrada.'}
            </div>
          ) : (
            <div className="table-responsive">
              <table className="table table-hover align-middle mb-0">
                <thead className="table-light">
                  <tr>
                    <th scope="col">Nome</th>
                    <th scope="col">Tipo</th>
                    <th scope="col" className="text-end">
                      Saldo atual
                    </th>
                    <th scope="col">Status</th>
                    {showActions && (
                      <th scope="col" className="text-end">
                        Ações
                      </th>
                    )}
                  </tr>
                </thead>
                <tbody>
                  {accounts.map((account) => (
                    <tr key={account.id}>
                      <td className="fw-semibold">{account.nome}</td>
                      <td>
                        <span className={`badge ${account.tipo === 'banco' ? 'text-bg-primary' : 'text-bg-info'}`}>
                          {ACCOUNT_TYPE_LABELS[account.tipo]}
                        </span>
                      </td>
                      <td className={`text-end text-nowrap ${isNegativeMoney(account.saldo_atual) ? 'text-danger fw-semibold' : ''}`}>
                        {formatMoney(account.saldo_atual)}
                      </td>
                      <td>
                        <span className={`badge ${account.ativa ? 'text-bg-success' : 'text-bg-secondary'}`}>
                          {account.ativa ? 'Ativa' : 'Inativa'}
                        </span>
                      </td>
                      {showActions && (
                        <td className="text-end text-nowrap">
                          {canWrite && (
                            <>
                              <button
                                type="button"
                                className="btn btn-sm btn-outline-primary me-2"
                                onClick={() => setForm({ mode: 'edit', account })}
                                disabled={busyId === account.id}
                              >
                                Editar
                              </button>
                              {account.ativa ? (
                                <button
                                  type="button"
                                  className="btn btn-sm btn-outline-warning me-2"
                                  onClick={() => setConfirm({ action: 'deactivate', account })}
                                  disabled={busyId === account.id}
                                >
                                  Inativar
                                </button>
                              ) : (
                                <button
                                  type="button"
                                  className="btn btn-sm btn-outline-success me-2"
                                  onClick={() => setActive(account, true)}
                                  disabled={busyId === account.id}
                                >
                                  Ativar
                                </button>
                              )}
                            </>
                          )}
                          {canDelete && (
                            <button
                              type="button"
                              className="btn btn-sm btn-outline-danger"
                              onClick={() => setConfirm({ action: 'delete', account })}
                              disabled={busyId === account.id}
                            >
                              Excluir
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
                Página {meta.current_page} de {meta.last_page} · {meta.total} contas
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

      {canAccessAdjustments(perfilSlug) && <AdjustmentsPanel onChanged={reload} onSessionExpired={onSessionExpired} />}

      {form && (
        <AccountFormModal
          mode={form.mode}
          account={form.account}
          onClose={() => setForm(null)}
          onSaved={handleSaved}
          onSessionExpired={onSessionExpired}
        />
      )}

      {confirm && (
        <ConfirmDialog
          title={confirm.action === 'delete' ? 'Excluir conta' : 'Inativar conta'}
          message={
            confirm.action === 'delete'
              ? `Excluir "${confirm.account.nome}"? Contas com movimentações não podem ser excluídas — nesse caso, inative-a.`
              : deactivateMessage(confirm.account)
          }
          confirmLabel={confirm.action === 'delete' ? 'Excluir' : 'Inativar'}
          confirmVariant={confirm.action === 'delete' ? 'danger' : 'warning'}
          busy={busyId === confirm.account.id}
          onConfirm={() => (confirm.action === 'delete' ? remove(confirm.account) : setActive(confirm.account, false))}
          onCancel={() => setConfirm(null)}
        />
      )}
    </section>
  )
}

export default AccountsPage
