import { useState } from 'react'
import Modal from '../../shared/components/Modal'
import { TYPE_LABELS, createCategory, updateCategory } from './categoriesService'

function validate({ nome, tipo }, isCreate) {
  const errors = {}

  if (!nome.trim()) errors.nome = 'Informe o nome.'
  else if (nome.trim().length > 100) errors.nome = 'O nome pode ter no máximo 100 caracteres.'

  if (isCreate && !tipo) errors.tipo = 'Selecione o tipo.'

  return errors
}

/** Criação (nome + tipo) e edição (somente nome — o tipo é imutável depois de criada). */
function CategoryFormModal({ mode, category, onClose, onSaved, onSessionExpired }) {
  const isCreate = mode === 'create'

  const [nome, setNome] = useState(category?.nome ?? '')
  const [tipo, setTipo] = useState(category?.tipo ?? '')
  const [fieldErrors, setFieldErrors] = useState({})
  const [formError, setFormError] = useState(null)
  const [submitting, setSubmitting] = useState(false)

  async function handleSubmit(event) {
    event.preventDefault()
    setFormError(null)

    const errors = validate({ nome, tipo }, isCreate)
    setFieldErrors(errors)
    if (Object.keys(errors).length > 0) return

    if (!isCreate && nome.trim() === category.nome) {
      setFormError('Nenhuma alteração foi feita.')
      return
    }

    setSubmitting(true)
    const result = isCreate
      ? await createCategory({ nome: nome.trim(), tipo })
      : await updateCategory(category.id, { nome: nome.trim() })
    setSubmitting(false)

    if (result.ok) {
      onSaved(isCreate ? 'Categoria criada com sucesso.' : 'Categoria atualizada com sucesso.')
      return
    }

    const { error } = result

    if (error.kind === 'unauthenticated') return onSessionExpired()

    if (error.kind === 'validation') {
      const fields = { ...error.fieldErrors }
      if (fields.nome === 'Este valor já está em uso.') fields.nome = 'Já existe uma categoria com este nome neste tipo.'
      if (fields.tipo && !isCreate) fields.tipo = 'O tipo não pode ser alterado.'
      setFieldErrors(fields)
    }

    setFormError(error.message)
  }

  return (
    <Modal
      title={isCreate ? 'Nova categoria' : 'Editar categoria'}
      onClose={onClose}
      busy={submitting}
      footer={
        <>
          <button type="button" className="btn btn-outline-secondary" onClick={onClose} disabled={submitting}>
            Cancelar
          </button>
          <button type="submit" form="category-form" className="btn btn-primary" disabled={submitting}>
            {submitting && <span className="spinner-border spinner-border-sm me-2" aria-hidden="true"></span>}
            {isCreate ? 'Criar categoria' : 'Salvar alterações'}
          </button>
        </>
      }
    >
      {formError && (
        <div className="alert alert-danger py-2" role="alert">
          {formError}
        </div>
      )}

      <form id="category-form" onSubmit={handleSubmit} noValidate>
        <div className="mb-3">
          <label htmlFor="category-name" className="form-label">
            Nome
          </label>
          <input
            id="category-name"
            className={`form-control ${fieldErrors.nome ? 'is-invalid' : ''}`}
            value={nome}
            onChange={(event) => setNome(event.target.value)}
            disabled={submitting}
            autoFocus
          />
          {fieldErrors.nome && <div className="invalid-feedback">{fieldErrors.nome}</div>}
        </div>

        <div className="mb-1">
          <label htmlFor="category-type" className="form-label">
            Tipo
          </label>
          {isCreate ? (
            <select
              id="category-type"
              className={`form-select ${fieldErrors.tipo ? 'is-invalid' : ''}`}
              value={tipo}
              onChange={(event) => setTipo(event.target.value)}
              disabled={submitting}
            >
              <option value="">Selecione...</option>
              {Object.entries(TYPE_LABELS).map(([value, label]) => (
                <option key={value} value={value}>
                  {label}
                </option>
              ))}
            </select>
          ) : (
            <input id="category-type" className="form-control" value={TYPE_LABELS[category.tipo]} disabled readOnly />
          )}
          {fieldErrors.tipo && <div className="invalid-feedback d-block">{fieldErrors.tipo}</div>}
          <div className="form-text">
            {isCreate ? 'O tipo não poderá ser alterado depois.' : 'O tipo é definido na criação e não pode ser alterado.'}
          </div>
        </div>
      </form>
    </Modal>
  )
}

export default CategoryFormModal
