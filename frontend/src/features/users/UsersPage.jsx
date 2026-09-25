import { useEffect, useState } from 'react'
import ConfirmDialog from '../../shared/components/ConfirmDialog'
import UserFormModal from './UserFormModal'
import { canWriteUsers } from './uiHints'
import { listUsers, updateUser } from './usersService'

function formatDateTime(value) {
  if (!value) return 'Nunca'
  return new Date(value).toLocaleString('pt-BR', { dateStyle: 'short', timeStyle: 'short' })
}

function UsersPage({ currentUser, onSessionExpired }) {
  const canWrite = canWriteUsers(currentUser.perfil?.slug)

  const [page, setPage] = useState(1)
  const [users, setUsers] = useState([])
  const [meta, setMeta] = useState(null)
  const [status, setStatus] = useState('loading')
  const [loadError, setLoadError] = useState(null)
  const [flash, setFlash] = useState(null)
  const [form, setForm] = useState(null)
  const [confirm, setConfirm] = useState(null)
  const [busyUserId, setBusyUserId] = useState(null)

  const [refreshKey, setRefreshKey] = useState(0)

  // Busca a página atual; refreshKey força uma nova busca (após criar/editar/ativar etc.).
  useEffect(() => {
    let cancelled = false

    listUsers(page).then((result) => {
      if (cancelled) return

      if (result.ok) {
        setUsers(result.data.data)
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

  function showFlash(type, message) {
    setFlash({ type, message })
  }

  async function changeStatus(user, ativo) {
    setBusyUserId(user.id)
    const result = await updateUser(user.id, { ativo }, ativo ? 'activate' : 'deactivate')
    setBusyUserId(null)
    setConfirm(null)

    if (result.ok) {
      showFlash('success', ativo ? `Usuário "${user.name}" ativado.` : `Usuário "${user.name}" desativado.`)
      reload()
      return
    }

    if (result.error.kind === 'unauthenticated') return onSessionExpired()
    showFlash('danger', result.error.message)
  }

  function handleSaved(message) {
    setForm(null)
    showFlash('success', message)
    reload()
  }

  function handleExceptionsChanged(message) {
    showFlash('success', message)
    reload()
  }

  const isBusy = status === 'loading' || status === 'refreshing'

  return (
    <section aria-labelledby="users-title">
      <div className="d-flex flex-wrap align-items-center justify-content-between gap-2 mb-3">
        <div>
          <h1 className="h4 mb-0" id="users-title">
            Usuários
          </h1>
          <p className="text-body-secondary mb-0 small">
            {canWrite ? 'Gerencie quem acessa o sistema e seus perfis.' : 'Consulta de usuários (somente leitura).'}
          </p>
        </div>
        {canWrite && (
          <button type="button" className="btn btn-primary" onClick={() => setForm({ mode: 'create' })}>
            Novo usuário
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
        <div className="text-center py-5" role="status">
          <span className="spinner-border text-primary" aria-hidden="true"></span>
          <div className="text-body-secondary mt-2">Carregando usuários...</div>
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
          {users.length === 0 ? (
            <div className="card-body text-center text-body-secondary py-5">Nenhum usuário encontrado.</div>
          ) : (
            <div className="table-responsive">
              <table className="table table-hover align-middle mb-0">
                <thead className="table-light">
                  <tr>
                    <th scope="col">Nome</th>
                    <th scope="col">E-mail</th>
                    <th scope="col">Perfil</th>
                    <th scope="col">Status</th>
                    <th scope="col">Último login</th>
                    {canWrite && (
                      <th scope="col" className="text-end">
                        Ações
                      </th>
                    )}
                  </tr>
                </thead>
                <tbody>
                  {users.map((user) => (
                    <tr key={user.id}>
                      <td className="fw-semibold">
                        {user.name}
                        {user.id === currentUser.id && <span className="badge text-bg-light border ms-2">Você</span>}
                      </td>
                      <td>{user.email}</td>
                      <td>{user.perfil?.nome_exibicao ?? '—'}</td>
                      <td>
                        <span className={`badge ${user.ativo ? 'text-bg-success' : 'text-bg-secondary'}`}>
                          {user.ativo ? 'Ativo' : 'Inativo'}
                        </span>
                      </td>
                      <td className="text-nowrap">{formatDateTime(user.ultimo_login_em)}</td>
                      {canWrite && (
                        <td className="text-end text-nowrap">
                          <button
                            type="button"
                            className="btn btn-sm btn-outline-primary me-2"
                            onClick={() => setForm({ mode: 'edit', user })}
                            disabled={busyUserId === user.id}
                          >
                            Editar
                          </button>
                          {user.ativo ? (
                            <button
                              type="button"
                              className="btn btn-sm btn-outline-danger"
                              onClick={() => setConfirm(user)}
                              disabled={busyUserId === user.id}
                            >
                              Desativar
                            </button>
                          ) : (
                            <button
                              type="button"
                              className="btn btn-sm btn-outline-success"
                              onClick={() => changeStatus(user, true)}
                              disabled={busyUserId === user.id}
                            >
                              {busyUserId === user.id && (
                                <span className="spinner-border spinner-border-sm me-1" aria-hidden="true"></span>
                              )}
                              Ativar
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
                Página {meta.current_page} de {meta.last_page} · {meta.total} usuários
              </span>
              <div className="btn-group" role="group" aria-label="Paginação">
                <button
                  type="button"
                  className="btn btn-sm btn-outline-secondary"
                  onClick={() => changePage(meta.current_page - 1)}
                  disabled={meta.current_page <= 1 || isBusy}
                >
                  Anterior
                </button>
                <button
                  type="button"
                  className="btn btn-sm btn-outline-secondary"
                  onClick={() => changePage(meta.current_page + 1)}
                  disabled={meta.current_page >= meta.last_page || isBusy}
                >
                  Próxima
                </button>
              </div>
            </div>
          )}
        </div>
      )}

      {form && (
        <UserFormModal
          mode={form.mode}
          user={form.user}
          currentUser={currentUser}
          onClose={() => setForm(null)}
          onSaved={handleSaved}
          onExceptionsChanged={handleExceptionsChanged}
          onSessionExpired={onSessionExpired}
        />
      )}

      {confirm && (
        <ConfirmDialog
          title="Desativar usuário"
          message={
            confirm.id === currentUser.id
              ? 'Você está desativando o seu próprio usuário. Tem certeza?'
              : `Desativar "${confirm.name}"? Ele não conseguirá mais entrar no sistema.`
          }
          confirmLabel="Desativar"
          busy={busyUserId === confirm.id}
          onConfirm={() => changeStatus(confirm, false)}
          onCancel={() => setConfirm(null)}
        />
      )}
    </section>
  )
}

export default UsersPage
