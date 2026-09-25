import { useEffect, useState } from 'react'
import ConfirmDialog from '../../shared/components/ConfirmDialog'
import CategoryFormModal from './CategoryFormModal'
import { TYPE_LABELS, deleteCategory, listCategories, updateCategory } from './categoriesService'
import { canWriteCategories } from './uiHints'

function CategoriesPage({ currentUser, onSessionExpired }) {
  const canWrite = canWriteCategories(currentUser.perfil?.slug)

  const [filters, setFilters] = useState({ tipo: '', ativa: '' })
  const [page, setPage] = useState(1)
  const [refreshKey, setRefreshKey] = useState(0)
  const [categories, setCategories] = useState([])
  const [meta, setMeta] = useState(null)
  const [status, setStatus] = useState('loading')
  const [loadError, setLoadError] = useState(null)
  const [flash, setFlash] = useState(null)
  const [form, setForm] = useState(null)
  const [confirm, setConfirm] = useState(null) // { action: 'deactivate' | 'delete', category }
  const [busyId, setBusyId] = useState(null)

  useEffect(() => {
    let cancelled = false

    listCategories({ ...filters, page }).then((result) => {
      if (cancelled) return

      if (result.ok) {
        setCategories(result.data.data)
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

  async function setActive(category, ativa) {
    setBusyId(category.id)
    const result = await updateCategory(category.id, { ativa })
    setBusyId(null)
    setConfirm(null)

    if (result.ok) {
      setFlash({ type: 'success', message: ativa ? `Categoria "${category.nome}" ativada.` : `Categoria "${category.nome}" inativada.` })
      reload()
      return
    }

    if (result.error.kind === 'unauthenticated') return onSessionExpired()
    setFlash({ type: 'danger', message: result.error.message })
  }

  async function remove(category) {
    setBusyId(category.id)
    const result = await deleteCategory(category.id)
    setBusyId(null)
    setConfirm(null)

    if (result.ok) {
      setFlash({ type: 'success', message: `Categoria "${category.nome}" excluída.` })
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

  const isBusy = status === 'loading' || status === 'refreshing'
  const hasFilters = filters.tipo !== '' || filters.ativa !== ''

  return (
    <section aria-labelledby="categories-title">
      <div className="d-flex flex-wrap align-items-center justify-content-between gap-2 mb-3">
        <div>
          <h1 className="h4 mb-0" id="categories-title">
            Categorias
          </h1>
          <p className="text-body-secondary mb-0 small">
            {canWrite
              ? 'Organize as categorias de entradas e despesas.'
              : 'Consulta das categorias de entradas e despesas (somente leitura).'}
          </p>
        </div>
        {canWrite && (
          <button type="button" className="btn btn-primary" onClick={() => setForm({ mode: 'create' })}>
            Nova categoria
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
            <option value="entrada">Entrada</option>
            <option value="despesa">Despesa</option>
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
          <div className="text-body-secondary mt-2">Carregando categorias...</div>
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
          {categories.length === 0 ? (
            <div className="card-body text-center text-body-secondary py-5">
              {hasFilters ? 'Nenhuma categoria encontrada com os filtros selecionados.' : 'Nenhuma categoria cadastrada.'}
            </div>
          ) : (
            <div className="table-responsive">
              <table className="table table-hover align-middle mb-0">
                <thead className="table-light">
                  <tr>
                    <th scope="col">Nome</th>
                    <th scope="col">Tipo</th>
                    <th scope="col">Status</th>
                    {canWrite && (
                      <th scope="col" className="text-end">
                        Ações
                      </th>
                    )}
                  </tr>
                </thead>
                <tbody>
                  {categories.map((category) => (
                    <tr key={category.id}>
                      <td className="fw-semibold">{category.nome}</td>
                      <td>
                        <span className={`badge ${category.tipo === 'entrada' ? 'text-bg-primary' : 'text-bg-warning'}`}>
                          {TYPE_LABELS[category.tipo]}
                        </span>
                      </td>
                      <td>
                        <span className={`badge ${category.ativa ? 'text-bg-success' : 'text-bg-secondary'}`}>
                          {category.ativa ? 'Ativa' : 'Inativa'}
                        </span>
                      </td>
                      {canWrite && (
                        <td className="text-end text-nowrap">
                          <button
                            type="button"
                            className="btn btn-sm btn-outline-primary me-2"
                            onClick={() => setForm({ mode: 'edit', category })}
                            disabled={busyId === category.id}
                          >
                            Editar
                          </button>
                          {category.ativa ? (
                            <button
                              type="button"
                              className="btn btn-sm btn-outline-warning me-2"
                              onClick={() => setConfirm({ action: 'deactivate', category })}
                              disabled={busyId === category.id}
                            >
                              Inativar
                            </button>
                          ) : (
                            <button
                              type="button"
                              className="btn btn-sm btn-outline-success me-2"
                              onClick={() => setActive(category, true)}
                              disabled={busyId === category.id}
                            >
                              Ativar
                            </button>
                          )}
                          <button
                            type="button"
                            className="btn btn-sm btn-outline-danger"
                            onClick={() => setConfirm({ action: 'delete', category })}
                            disabled={busyId === category.id}
                          >
                            Excluir
                          </button>
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
                Página {meta.current_page} de {meta.last_page} · {meta.total} categorias
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
        <CategoryFormModal
          mode={form.mode}
          category={form.category}
          onClose={() => setForm(null)}
          onSaved={handleSaved}
          onSessionExpired={onSessionExpired}
        />
      )}

      {confirm && (
        <ConfirmDialog
          title={confirm.action === 'delete' ? 'Excluir categoria' : 'Inativar categoria'}
          message={
            confirm.action === 'delete'
              ? `Excluir definitivamente "${confirm.category.nome}"? Categorias já em uso não podem ser excluídas — nesse caso, inative-a.`
              : `Inativar "${confirm.category.nome}"? Ela deixará de ser oferecida em novos lançamentos, mas o histórico é preservado.`
          }
          confirmLabel={confirm.action === 'delete' ? 'Excluir' : 'Inativar'}
          confirmVariant={confirm.action === 'delete' ? 'danger' : 'warning'}
          busy={busyId === confirm.category.id}
          onConfirm={() => (confirm.action === 'delete' ? remove(confirm.category) : setActive(confirm.category, false))}
          onCancel={() => setConfirm(null)}
        />
      )}
    </section>
  )
}

export default CategoriesPage
