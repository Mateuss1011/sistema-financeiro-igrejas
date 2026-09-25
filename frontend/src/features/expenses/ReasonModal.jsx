import { useState } from 'react'
import Modal from '../../shared/components/Modal'
import { formatIsoDate } from '../../shared/utils/dates'
import { formatMoney } from '../../shared/utils/money'
import { cancelExpense, reverseExpense } from './expensesService'

const MODES = {
  cancel: {
    title: 'Cancelar despesa',
    label: 'Justificativa do cancelamento',
    placeholder: 'Ex.: fornecedor cancelou o serviço...',
    confirm: 'Cancelar despesa',
    variant: 'danger',
    help: 'O cancelamento é definitivo: a despesa não poderá mais ser paga nem editada. Não altera nenhum saldo.',
    done: 'Despesa cancelada.',
    run: cancelExpense,
  },
  reverse: {
    title: 'Estornar despesa paga',
    label: 'Justificativa do estorno',
    placeholder: 'Ex.: pagamento em duplicidade, valor errado...',
    confirm: 'Estornar despesa',
    variant: 'danger',
    help: 'O estorno cria um novo registro vinculado a esta despesa e devolve o valor à conta. O histórico é preservado e o estorno não pode ser desfeito.',
    done: 'Despesa estornada. O valor foi devolvido à conta.',
    run: reverseExpense,
  },
}

/** Cancelamento (Pendente) e estorno (Paga): ambos exigem justificativa de 3 a 500 caracteres. */
function ReasonModal({ mode, expense, onClose, onDone, onStale, onSessionExpired }) {
  const config = MODES[mode]

  const [justificativa, setJustificativa] = useState('')
  const [fieldError, setFieldError] = useState(null)
  const [formError, setFormError] = useState(null)
  const [submitting, setSubmitting] = useState(false)

  async function handleSubmit(event) {
    event.preventDefault()
    if (submitting) return

    const text = justificativa.trim()
    if (text.length < 3) {
      setFieldError('Informe a justificativa (mínimo de 3 caracteres).')
      return
    }

    setFieldError(null)
    setFormError(null)
    setSubmitting(true)
    const result = await config.run(expense.id, text)
    setSubmitting(false)

    if (result.ok) {
      onDone(config.done)
      return
    }

    const { error } = result

    if (error.kind === 'unauthenticated') return onSessionExpired()

    if (error.kind === 'validation') setFieldError(error.fieldErrors.justificativa ?? null)

    // A despesa mudou de estado por outra via: atualiza a lista.
    const stale = ['DESPESA_NAO_PENDENTE', 'DESPESA_NAO_PAGA', 'DESPESA_JA_ESTORNADA', 'ESTORNO_NAO_ESTORNAVEL']
    if (error.kind === 'notFound' || (error.kind === 'conflict' && stale.includes(error.code))) onStale()

    setFormError(error.message)
  }

  return (
    <Modal
      title={config.title}
      onClose={onClose}
      busy={submitting}
      footer={
        <>
          <button type="button" className="btn btn-outline-secondary" onClick={onClose} disabled={submitting}>
            Voltar
          </button>
          <button type="submit" form="reason-form" className={`btn btn-${config.variant}`} disabled={submitting}>
            {submitting && <span className="spinner-border spinner-border-sm me-2" aria-hidden="true"></span>}
            {config.confirm}
          </button>
        </>
      }
    >
      {formError && (
        <div className="alert alert-danger py-2" role="alert">
          {formError}
        </div>
      )}

      <dl className="row mb-3 small">
        <dt className="col-4">Competência</dt>
        <dd className="col-8">{formatIsoDate(expense.data_competencia)}</dd>
        <dt className="col-4">Categoria</dt>
        <dd className="col-8">{expense.categoria?.nome ?? '—'}</dd>
        <dt className="col-4">Descrição</dt>
        <dd className="col-8">{expense.descricao}</dd>
        {expense.conta && (
          <>
            <dt className="col-4">Conta</dt>
            <dd className="col-8">{expense.conta.nome}</dd>
          </>
        )}
        <dt className="col-4">Valor</dt>
        <dd className="col-8 mb-0 fw-semibold">{formatMoney(expense.valor)}</dd>
      </dl>

      <form id="reason-form" onSubmit={handleSubmit} noValidate>
        <label htmlFor="reason-text" className="form-label">
          {config.label}
        </label>
        <textarea
          id="reason-text"
          rows="3"
          className={`form-control ${fieldError ? 'is-invalid' : ''}`}
          value={justificativa}
          onChange={(event) => setJustificativa(event.target.value)}
          disabled={submitting}
          maxLength={500}
          placeholder={config.placeholder}
          autoFocus
        ></textarea>
        {fieldError && <div className="invalid-feedback">{fieldError}</div>}
        <div className="form-text">{config.help}</div>
      </form>
    </Modal>
  )
}

export default ReasonModal
