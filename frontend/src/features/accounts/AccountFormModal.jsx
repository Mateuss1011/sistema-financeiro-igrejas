import { useState } from 'react'
import Modal from '../../shared/components/Modal'
import { formatMoney, isNegativeMoney, parseMoneyInput } from '../../shared/utils/money'
import { ACCOUNT_TYPE_LABELS, createAccount, updateAccount } from './accountsService'

function validate({ nome, tipo, saldo }) {
  const errors = {}

  if (!nome.trim()) errors.nome = 'Informe o nome.'
  else if (nome.trim().length > 100) errors.nome = 'O nome pode ter no máximo 100 caracteres.'

  if (!tipo) errors.tipo = 'Selecione o tipo.'

  if (saldo.trim() !== '') {
    const parsed = parseMoneyInput(saldo)
    if (parsed === null) errors.saldo_inicial = 'Informe um valor válido, com até 2 casas decimais (ex.: 1.500,00).'
    else if (tipo === 'caixa' && isNegativeMoney(parsed)) errors.saldo_inicial = 'O saldo inicial de um caixa não pode ser negativo.'
  }

  return errors
}

/** Criação (nome, tipo, saldo inicial) e edição (somente nome — tipo e saldo inicial são imutáveis). */
function AccountFormModal({ mode, account, onClose, onSaved, onSessionExpired }) {
  const isCreate = mode === 'create'

  const [nome, setNome] = useState(account?.nome ?? '')
  const [tipo, setTipo] = useState(account?.tipo ?? '')
  const [saldo, setSaldo] = useState('')
  const [fieldErrors, setFieldErrors] = useState({})
  const [formError, setFormError] = useState(null)
  const [submitting, setSubmitting] = useState(false)

  const parsedBalance = saldo.trim() === '' ? null : parseMoneyInput(saldo)

  async function handleSubmit(event) {
    event.preventDefault()
    setFormError(null)

    if (!isCreate) {
      const errors = validate({ nome, tipo: account.tipo, saldo: '' })
      setFieldErrors(errors)
      if (Object.keys(errors).length > 0) return

      if (nome.trim() === account.nome) {
        setFormError('Nenhuma alteração foi feita.')
        return
      }
    } else {
      const errors = validate({ nome, tipo, saldo })
      setFieldErrors(errors)
      if (Object.keys(errors).length > 0) return
    }

    setSubmitting(true)
    const result = isCreate
      ? await createAccount({ nome: nome.trim(), tipo, saldo_inicial: parsedBalance ?? '0.00' })
      : await updateAccount(account.id, { nome: nome.trim() })
    setSubmitting(false)

    if (result.ok) {
      onSaved(isCreate ? 'Conta criada com sucesso.' : 'Conta atualizada com sucesso.')
      return
    }

    const { error } = result

    if (error.kind === 'unauthenticated') return onSessionExpired()

    if (error.kind === 'validation') {
      const fields = { ...error.fieldErrors }
      if (fields.nome === 'Este valor já está em uso.') fields.nome = 'Já existe uma conta com este nome.'
      setFieldErrors(fields)
    }

    setFormError(error.message)
  }

  return (
    <Modal
      title={isCreate ? 'Nova conta' : 'Editar conta'}
      onClose={onClose}
      busy={submitting}
      footer={
        <>
          <button type="button" className="btn btn-outline-secondary" onClick={onClose} disabled={submitting}>
            Cancelar
          </button>
          <button type="submit" form="account-form" className="btn btn-primary" disabled={submitting}>
            {submitting && <span className="spinner-border spinner-border-sm me-2" aria-hidden="true"></span>}
            {isCreate ? 'Criar conta' : 'Salvar alterações'}
          </button>
        </>
      }
    >
      {formError && (
        <div className="alert alert-danger py-2" role="alert">
          {formError}
        </div>
      )}

      <form id="account-form" onSubmit={handleSubmit} noValidate>
        <div className="mb-3">
          <label htmlFor="account-name" className="form-label">
            Nome
          </label>
          <input
            id="account-name"
            className={`form-control ${fieldErrors.nome ? 'is-invalid' : ''}`}
            value={nome}
            onChange={(event) => setNome(event.target.value)}
            disabled={submitting}
            placeholder="Ex.: Banco do Brasil - Conta Corrente"
            autoFocus
          />
          {fieldErrors.nome && <div className="invalid-feedback">{fieldErrors.nome}</div>}
        </div>

        <div className="mb-3">
          <label htmlFor="account-type" className="form-label">
            Tipo
          </label>
          {isCreate ? (
            <select
              id="account-type"
              className={`form-select ${fieldErrors.tipo ? 'is-invalid' : ''}`}
              value={tipo}
              onChange={(event) => setTipo(event.target.value)}
              disabled={submitting}
            >
              <option value="">Selecione...</option>
              {Object.entries(ACCOUNT_TYPE_LABELS).map(([value, label]) => (
                <option key={value} value={value}>
                  {label}
                </option>
              ))}
            </select>
          ) : (
            <input id="account-type" className="form-control" value={ACCOUNT_TYPE_LABELS[account.tipo]} disabled readOnly />
          )}
          {fieldErrors.tipo && <div className="invalid-feedback d-block">{fieldErrors.tipo}</div>}
          <div className="form-text">
            {isCreate ? 'O tipo não poderá ser alterado depois.' : 'O tipo é definido na criação e não pode ser alterado.'}
          </div>
        </div>

        <div className="mb-1">
          <label htmlFor="account-balance" className="form-label">
            Saldo inicial
          </label>
          {isCreate ? (
            <>
              <div className="input-group">
                <span className="input-group-text">R$</span>
                <input
                  id="account-balance"
                  inputMode="decimal"
                  className={`form-control ${fieldErrors.saldo_inicial ? 'is-invalid' : ''}`}
                  value={saldo}
                  onChange={(event) => setSaldo(event.target.value)}
                  disabled={submitting}
                  placeholder="0,00"
                  autoComplete="off"
                />
                {fieldErrors.saldo_inicial && <div className="invalid-feedback">{fieldErrors.saldo_inicial}</div>}
              </div>
              <div className="form-text">
                {parsedBalance !== null ? `Será registrado como ${formatMoney(parsedBalance)}. ` : ''}
                Definido apenas na criação e não pode ser alterado depois. Pode ser negativo somente em conta bancária.
              </div>
            </>
          ) : (
            <>
              <input id="account-balance" className="form-control" value={formatMoney(account.saldo_inicial)} disabled readOnly />
              <div className="form-text">O saldo inicial não pode ser alterado. Correções futuras serão feitas por ajuste de saldo.</div>
            </>
          )}
        </div>
      </form>
    </Modal>
  )
}

export default AccountFormModal
