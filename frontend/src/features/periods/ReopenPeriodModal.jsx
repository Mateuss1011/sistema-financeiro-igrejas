import { useState } from 'react'
import ConfirmDialog from '../../shared/components/ConfirmDialog'
import Modal from '../../shared/components/Modal'
import { formatAnoMes } from './uiFormat'
import { reopenPeriod } from './periodsService'

/**
 * Reabrir é restrito ao Pastor e exige justificativa (3–500 caracteres). Pede uma confirmação
 * extra, dado o impacto: libera de novo criação/edição/pagamento/cancelamento/exclusão/estorno de
 * lançamentos naquele mês, sem afetar nenhum outro período.
 */
function ReopenPeriodModal({ period, onClose, onDone, onSessionExpired }) {
  const [justificativa, setJustificativa] = useState('')
  const [fieldError, setFieldError] = useState(null)
  const [formError, setFormError] = useState(null)
  const [submitting, setSubmitting] = useState(false)
  const [confirming, setConfirming] = useState(false)

  async function enviar() {
    setFormError(null)
    setSubmitting(true)
    const result = await reopenPeriod(period.ano_mes, justificativa.trim())
    setSubmitting(false)

    if (result.ok) {
      setConfirming(false)
      onDone(`Período ${formatAnoMes(period.ano_mes)} reaberto. Lançamentos com data nesse mês voltam a ser aceitos.`)
      return
    }

    const { error } = result
    setConfirming(false)

    if (error.kind === 'unauthenticated') return onSessionExpired()
    if (error.kind === 'validation') setFieldError(error.fieldErrors.justificativa ?? null)
    if (error.kind === 'conflict' && error.code === 'PERIODO_JA_ABERTO') {
      onDone(`Período ${formatAnoMes(period.ano_mes)} já estava aberto.`, 'warning')
      return
    }

    setFormError(error.message)
  }

  function handleSubmit(event) {
    event.preventDefault()
    if (submitting) return

    if (justificativa.trim().length < 3) {
      setFieldError('Informe a justificativa da reabertura (mínimo de 3 caracteres).')
      return
    }

    setFieldError(null)
    setConfirming(true)
  }

  return (
    <>
      <Modal
        title="Reabrir período"
        onClose={onClose}
        busy={submitting}
        footer={
          <>
            <button type="button" className="btn btn-outline-secondary" onClick={onClose} disabled={submitting}>
              Voltar
            </button>
            <button type="submit" form="reopen-period-form" className="btn btn-danger" disabled={submitting}>
              {submitting && <span className="spinner-border spinner-border-sm me-2" aria-hidden="true"></span>}
              Reabrir período
            </button>
          </>
        }
      >
        {formError && (
          <div className="alert alert-danger py-2" role="alert">
            {formError}
          </div>
        )}

        <p>
          Reabrir <strong>{formatAnoMes(period.ano_mes)}</strong> libera de novo a criação, edição, pagamento, cancelamento, exclusão e estorno de
          lançamentos com data nesse mês. Nenhum outro período é afetado.
        </p>

        <form id="reopen-period-form" onSubmit={handleSubmit} noValidate>
          <label htmlFor="reopen-period-reason" className="form-label">
            Justificativa da reabertura
          </label>
          <textarea
            id="reopen-period-reason"
            rows="3"
            className={`form-control ${fieldError ? 'is-invalid' : ''}`}
            value={justificativa}
            onChange={(event) => setJustificativa(event.target.value)}
            disabled={submitting}
            maxLength={500}
            placeholder="Ex.: lançamento retroativo esquecido, correção de um estorno..."
            autoFocus
          ></textarea>
          {fieldError && <div className="invalid-feedback">{fieldError}</div>}
          <div className="form-text">{justificativa.trim().length}/500 caracteres (mínimo 3).</div>
        </form>
      </Modal>

      {confirming && (
        <ConfirmDialog
          title="Confirmar reabertura"
          message={`Tem certeza de que deseja reabrir ${formatAnoMes(period.ano_mes)}? Lançamentos com data nesse mês voltarão a ser aceitos até o período ser fechado de novo.`}
          confirmLabel="Reabrir mesmo assim"
          confirmVariant="danger"
          busy={submitting}
          onConfirm={enviar}
          onCancel={() => setConfirming(false)}
        />
      )}
    </>
  )
}

export default ReopenPeriodModal
