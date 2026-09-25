import { useState } from 'react'
import ConfirmDialog from '../../shared/components/ConfirmDialog'
import Modal from '../../shared/components/Modal'
import { formatIsoDate } from '../../shared/utils/dates'
import { formatMoney } from '../../shared/utils/money'
import { reverseTransfer } from './transfersService'

/**
 * Estorno de transferência: cria uma NOVA transferência inversa (destino → origem) na MESMA data da
 * original. Justificativa obrigatória. Se a conta que devolve o dinheiro é um banco e ficaria negativa,
 * pede confirmação explícita; se for um caixa sem saldo, o backend bloqueia.
 */
function ReverseTransferModal({ transfer, onClose, onDone, onStale, onSessionExpired }) {
  const [justificativa, setJustificativa] = useState('')
  const [fieldError, setFieldError] = useState(null)
  const [formError, setFormError] = useState(null)
  const [submitting, setSubmitting] = useState(false)
  const [confirmNegative, setConfirmNegative] = useState(false)

  async function submit(confirmarSaldoNegativo) {
    setFormError(null)
    setSubmitting(true)
    const result = await reverseTransfer(transfer.id, { justificativa: justificativa.trim(), confirmarSaldoNegativo })
    setSubmitting(false)

    if (result.ok) {
      setConfirmNegative(false)
      onDone('Transferência estornada. Uma transferência inversa foi registrada e os saldos foram ajustados.')
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

    // A transferência mudou de estado por outra via (já estornada / não estornável / removida): atualiza a lista.
    if (error.kind === 'notFound' || (error.kind === 'conflict' && ['TRANSFERENCIA_JA_ESTORNADA', 'TRANSFERENCIA_NAO_ESTORNAVEL'].includes(error.code))) {
      onStale()
    }

    setFormError(error.message)
  }

  function handleSubmit(event) {
    event.preventDefault()
    if (submitting) return

    if (justificativa.trim().length < 3) {
      setFieldError('Informe a justificativa do estorno (mínimo de 3 caracteres).')
      return
    }

    setFieldError(null)
    submit(false)
  }

  return (
    <>
      <Modal
        title="Estornar transferência"
        onClose={onClose}
        busy={submitting}
        footer={
          <>
            <button type="button" className="btn btn-outline-secondary" onClick={onClose} disabled={submitting}>
              Voltar
            </button>
            <button type="submit" form="reverse-transfer-form" className="btn btn-danger" disabled={submitting}>
              {submitting && <span className="spinner-border spinner-border-sm me-2" aria-hidden="true"></span>}
              Estornar transferência
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
          <dd className="col-8">{formatIsoDate(transfer.data_transferencia)}</dd>
          <dt className="col-4">Origem</dt>
          <dd className="col-8">{transfer.conta_origem?.nome ?? '—'}</dd>
          <dt className="col-4">Destino</dt>
          <dd className="col-8">{transfer.conta_destino?.nome ?? '—'}</dd>
          <dt className="col-4">Valor</dt>
          <dd className="col-8 mb-0 fw-semibold">{formatMoney(transfer.valor)}</dd>
        </dl>

        <form id="reverse-transfer-form" onSubmit={handleSubmit} noValidate>
          <label htmlFor="reverse-transfer-reason" className="form-label">
            Justificativa do estorno
          </label>
          <textarea
            id="reverse-transfer-reason"
            rows="3"
            className={`form-control ${fieldError ? 'is-invalid' : ''}`}
            value={justificativa}
            onChange={(event) => setJustificativa(event.target.value)}
            disabled={submitting}
            maxLength={500}
            placeholder="Ex.: transferência lançada por engano..."
            autoFocus
          ></textarea>
          {fieldError && <div className="invalid-feedback">{fieldError}</div>}
          <div className="form-text">
            O estorno registra uma nova transferência de {transfer.conta_destino?.nome ?? 'destino'} para {transfer.conta_origem?.nome ?? 'origem'}, na mesma data da original.
            O histórico é preservado e o estorno não pode ser desfeito.
          </div>
        </form>
      </Modal>

      {confirmNegative && (
        <ConfirmDialog
          title="Saldo ficará negativo"
          message={`Este estorno deixará o saldo da conta "${transfer.conta_destino?.nome ?? ''}" negativo. Deseja estornar mesmo assim?`}
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

export default ReverseTransferModal
