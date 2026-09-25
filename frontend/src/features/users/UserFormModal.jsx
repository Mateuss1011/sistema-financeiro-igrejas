import { useEffect, useState } from 'react'
import ConfirmDialog from '../../shared/components/ConfirmDialog'
import Modal from '../../shared/components/Modal'
import ExceptionsPanel from './ExceptionsPanel'
import { createUser, listAssignableProfiles, updateUser } from './usersService'

const EMAIL_PATTERN = /^[^\s@]+@[^\s@]+\.[^\s@]+$/

function validate({ name, email, password, perfilId }, isCreate) {
  const errors = {}

  if (!name.trim()) errors.name = 'Informe o nome.'
  else if (name.trim().length > 150) errors.name = 'O nome pode ter no máximo 150 caracteres.'

  if (!email.trim()) errors.email = 'Informe o e-mail.'
  else if (!EMAIL_PATTERN.test(email.trim())) errors.email = 'Informe um e-mail válido.'

  if (isCreate && password.length < 8) errors.password = 'A senha deve ter pelo menos 8 caracteres.'

  if (!perfilId) errors.perfil_id = 'Selecione um perfil.'

  return errors
}

function UserFormModal({ mode, user, currentUser, onClose, onSaved, onExceptionsChanged, onSessionExpired }) {
  const isCreate = mode === 'create'

  const [name, setName] = useState(user?.name ?? '')
  const [email, setEmail] = useState(user?.email ?? '')
  const [password, setPassword] = useState('')
  const [perfilId, setPerfilId] = useState(user?.perfil ? String(user.perfil.id) : '')
  const [ativo, setAtivo] = useState(user?.ativo ?? true)

  const [profiles, setProfiles] = useState(null)
  const [profilesError, setProfilesError] = useState(null)
  const [fieldErrors, setFieldErrors] = useState({})
  const [formError, setFormError] = useState(null)
  const [submitting, setSubmitting] = useState(false)
  const [confirmDeactivate, setConfirmDeactivate] = useState(false)

  useEffect(() => {
    let cancelled = false

    listAssignableProfiles().then((result) => {
      if (cancelled) return
      if (result.ok) return setProfiles(result.data.data)
      if (result.error.kind === 'unauthenticated') return onSessionExpired()
      setProfilesError(result.error.message)
    })

    return () => {
      cancelled = true
    }
  }, [onSessionExpired])

  // O perfil atual do usuário editado sempre aparece, mesmo que o backend não permita atribuí-lo a outros.
  const profileOptions = profiles
    ? user?.perfil && !profiles.some((profile) => profile.id === user.perfil.id)
      ? [user.perfil, ...profiles]
      : profiles
    : []

  function buildChanges() {
    const changes = {}
    if (name.trim() !== user.name) changes.name = name.trim()
    if (email.trim() !== user.email) changes.email = email.trim()
    if (Number(perfilId) !== user.perfil?.id) changes.perfil_id = Number(perfilId)
    if (ativo !== user.ativo) changes.ativo = ativo
    return changes
  }

  async function submit() {
    setSubmitting(true)
    setFormError(null)

    const changes = isCreate ? null : buildChanges()
    const result = isCreate
      ? await createUser({ name: name.trim(), email: email.trim(), password, perfil_id: Number(perfilId) })
      : await updateUser(user.id, changes, changes.ativo === false ? 'deactivate' : 'update')

    setSubmitting(false)
    setConfirmDeactivate(false)
    setPassword('') // a senha nunca permanece no estado após a tentativa

    if (result.ok) {
      onSaved(isCreate ? 'Usuário criado com sucesso.' : 'Usuário atualizado com sucesso.')
      return
    }

    const { error } = result

    if (error.kind === 'unauthenticated') return onSessionExpired()
    if (error.kind === 'validation') setFieldErrors(error.fieldErrors)
    setFormError(error.message)
  }

  function handleSubmit(event) {
    event.preventDefault()
    setFormError(null)

    const errors = validate({ name, email, password, perfilId }, isCreate)
    setFieldErrors(errors)
    if (Object.keys(errors).length > 0) return

    if (!isCreate) {
      const changes = buildChanges()

      if (Object.keys(changes).length === 0) {
        setFormError('Nenhuma alteração foi feita.')
        return
      }

      if (changes.ativo === false) {
        setConfirmDeactivate(true)
        return
      }
    }

    submit()
  }

  const isSelf = !isCreate && user.id === currentUser.id
  const canSubmit = !submitting && profiles !== null

  return (
    <Modal
      title={isCreate ? 'Novo usuário' : `Editar usuário`}
      onClose={onClose}
      busy={submitting}
      footer={
        <>
          <button type="button" className="btn btn-outline-secondary" onClick={onClose} disabled={submitting}>
            Cancelar
          </button>
          <button type="submit" form="user-form" className="btn btn-primary" disabled={!canSubmit}>
            {submitting && <span className="spinner-border spinner-border-sm me-2" aria-hidden="true"></span>}
            {isCreate ? 'Criar usuário' : 'Salvar alterações'}
          </button>
        </>
      }
    >
      {formError && (
        <div className="alert alert-danger py-2" role="alert">
          {formError}
        </div>
      )}

      {profilesError && (
        <div className="alert alert-warning py-2" role="alert">
          Não foi possível carregar os perfis. {profilesError}
        </div>
      )}

      <form id="user-form" onSubmit={handleSubmit} noValidate>
        <div className="mb-3">
          <label htmlFor="user-name" className="form-label">
            Nome
          </label>
          <input
            id="user-name"
            className={`form-control ${fieldErrors.name ? 'is-invalid' : ''}`}
            value={name}
            onChange={(event) => setName(event.target.value)}
            disabled={submitting}
            autoFocus
          />
          {fieldErrors.name && <div className="invalid-feedback">{fieldErrors.name}</div>}
        </div>

        <div className="mb-3">
          <label htmlFor="user-email" className="form-label">
            E-mail
          </label>
          <input
            id="user-email"
            type="email"
            className={`form-control ${fieldErrors.email ? 'is-invalid' : ''}`}
            value={email}
            onChange={(event) => setEmail(event.target.value)}
            disabled={submitting}
            autoComplete="off"
          />
          {fieldErrors.email && <div className="invalid-feedback">{fieldErrors.email}</div>}
        </div>

        {isCreate && (
          <div className="mb-3">
            <label htmlFor="user-password" className="form-label">
              Senha
            </label>
            <input
              id="user-password"
              type="password"
              className={`form-control ${fieldErrors.password ? 'is-invalid' : ''}`}
              value={password}
              onChange={(event) => setPassword(event.target.value)}
              disabled={submitting}
              autoComplete="new-password"
            />
            {fieldErrors.password ? (
              <div className="invalid-feedback">{fieldErrors.password}</div>
            ) : (
              <div className="form-text">Mínimo de 8 caracteres.</div>
            )}
          </div>
        )}

        <div className="mb-3">
          <label htmlFor="user-profile" className="form-label">
            Perfil
          </label>
          <select
            id="user-profile"
            className={`form-select ${fieldErrors.perfil_id ? 'is-invalid' : ''}`}
            value={perfilId}
            onChange={(event) => setPerfilId(event.target.value)}
            disabled={submitting || profiles === null}
          >
            <option value="">{profiles === null && !profilesError ? 'Carregando...' : 'Selecione...'}</option>
            {profileOptions.map((profile) => (
              <option key={profile.id} value={profile.id}>
                {profile.nome_exibicao}
              </option>
            ))}
          </select>
          {fieldErrors.perfil_id && <div className="invalid-feedback">{fieldErrors.perfil_id}</div>}
        </div>

        {!isCreate && (
          <div className="form-check form-switch mb-1">
            <input
              id="user-active"
              type="checkbox"
              role="switch"
              className="form-check-input"
              checked={ativo}
              onChange={(event) => setAtivo(event.target.checked)}
              disabled={submitting}
            />
            <label htmlFor="user-active" className="form-check-label">
              Usuário ativo
            </label>
            {fieldErrors.ativo && <div className="text-danger small">{fieldErrors.ativo}</div>}
          </div>
        )}
      </form>

      {!isCreate && Array.isArray(user.permissoes_excecao) && (
        <ExceptionsPanel
          userId={user.id}
          initialGranted={user.permissoes_excecao}
          onChanged={onExceptionsChanged}
          onSessionExpired={onSessionExpired}
        />
      )}

      {confirmDeactivate && (
        <ConfirmDialog
          title="Desativar usuário"
          message={
            isSelf
              ? 'Você está desativando o seu próprio usuário. Tem certeza?'
              : `Desativar "${user.name}"? Ele não conseguirá mais entrar no sistema.`
          }
          confirmLabel="Desativar"
          busy={submitting}
          onConfirm={submit}
          onCancel={() => setConfirmDeactivate(false)}
        />
      )}
    </Modal>
  )
}

export default UserFormModal
