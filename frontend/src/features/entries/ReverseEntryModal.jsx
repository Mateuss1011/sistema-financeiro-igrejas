import { useState } from 'react'
import ConfirmDialog from '../../shared/components/ConfirmDialog'
import Modal from '../../shared/components/Modal'
import { formatMoney } from '../../shared/utils/money'
import { formatIsoDate, reverseEntry } from './entriesService'

/**
 * Estorno de uma entrada: justificativa obrigatória. Se o backend informar que o saldo de uma
 * conta bancária ficaria negativo, pede confirmação explícita e reenvia com o aceite.
 */
function ReverseEntryModal({ entry, onClose, onDone, onStale, onSessionExpired }) {
  const [justificativa, setJustificativa] = useState('')
  const [fieldError, setFieldError] = useState(null)
  const [formError, setFormError] = useState(null)
  const [submitting, setSubmitting] = useState(false)
  const [confirmNegative, setConfirmNegative] = useState(false)

  async function submit(confirmarSaldoNegativo) {
    setFormError(null)
    setSubmitting(true)
    const result = await reverseEntry(entry.id, { justificativa: justificativa.trim(), confirmarSaldoNegativo })
    setSubmitting(false)

    if (result.ok) {
      setConfirmNegative(false)
      onDone('Entrada estornada. O estorno foi registrado e o saldo da conta foi ajustado.')
      return
    }

    const { error } = result

    if (error.kind === 'unauthenticated') return onSessionExpired()

    if (error.kind === 'conflict' && error.code === 'SALDO_NEGATIVO_REQUER_CONFIRMACAO') {
      setConfirmNegative(true)
      return
    }

    setConfirmNegative(false)

    if (error.kind === 'validation') setFieldError(error.fieldErrors.justificativa ?? null)

    // A entrada mudou de estado por outra via (já estornada / não estornável / não existe mais): atualiza a lista.
    if (error.kind === 'notFound' || (error.kind === 'conflict' && ['ENTRADA_JA_ESTORNADA', 'ESTORNO_NAO_ESTORNAVEL'].includes(error.code))) {
      onStale()
    }

    setFormError(error.message)
  }

  function handleSubmit(event) {
    event.preventDefault()
    if (submitting) return

    const text = justificativa.trim()
    if (text.length < 3) {
      setFieldError('Informe a justificativa do estorno (mínimo de 3 caracteres).')
      return
    }

    setFieldError(null)
    submit(false)
  }

  return (
    <>
      <Modal
        title="Estornar entrada"
        onClose={onClose}
        busy={submitting}
        footer={
          <>
            <button type="button" className="btn btn-outline-secondary" onClick={onClose} disabled={submitting}>
              Cancelar
            </button>
            <button type="submit" form="reverse-form" className="btn btn-danger" disabled={submitting}>
              {submitting && <span className="spinner-border spinner-border-sm me-2" aria-hidden="true"></span>}
              Estornar entrada
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
          <dt className="col-4">Data</dt>
          <dd className="col-8">{formatIsoDate(entry.data_competencia)}</dd>
          <dt className="col-4">Categoria</dt>
          <dd className="col-8">{entry.categoria?.nome ?? '—'}</dd>
          <dt className="col-4">Conta</dt>
          <dd className="col-8">{entry.conta?.nome ?? '—'}</dd>
          <dt className="col-4">Valor</dt>
          <dd className="col-8 mb-0 fw-semibold">{formatMoney(entry.valor)}</dd>
        </dl>

        <form id="reverse-form" onSubmit={handleSubmit} noValidate>
          <label htmlFor="reverse-reason" className="form-label">
            Justificativa
          </label>
          <textarea
            id="reverse-reason"
            rows="3"
            className={`form-control ${fieldError ? 'is-invalid' : ''}`}
            value={justificativa}
            onChange={(event) => setJustificativa(event.target.value)}
            disabled={submitting}
            maxLength={500}
            placeholder="Ex.: valor digitado errado, lançamento em duplicidade..."
            autoFocus
          ></textarea>
          {fieldError && <div className="invalid-feedback">{fieldError}</div>}
          <div className="form-text">
            O estorno cria um novo registro vinculado a esta entrada e anula o seu efeito no saldo. O histórico é preservado e o estorno não pode ser desfeito.
          </div>
        </form>
      </Modal>

      {confirmNegative && (
        <ConfirmDialog
          title="Saldo ficará negativo"
          message={`Este estorno deixará o saldo da conta "${entry.conta?.nome}" negativo. Deseja estornar mesmo assim?`}
          confirmLabel="Estornar mesmo assim"
          confirmVariant="warning"
          busy={submitting}
          onConfirm={() => submit(true)}
          onCancel={() => setConfirmNegative(false)}
        />
      )}
    </>
  )
}

export default ReverseEntryModal
