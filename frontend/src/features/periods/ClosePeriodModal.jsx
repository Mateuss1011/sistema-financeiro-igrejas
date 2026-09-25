import { useState } from 'react'
import Modal from '../../shared/components/Modal'
import { formatAnoMes } from './uiFormat'
import { closePeriod } from './periodsService'

/**
 * Fechar não pede justificativa (decisão de negócio da Fase 9: só a reabertura exige). É uma
 * confirmação simples, porque bloqueia todo lançamento novo com data naquele mês.
 */
function ClosePeriodModal({ period, onClose, onDone, onSessionExpired }) {
  const [busy, setBusy] = useState(false)
  const [error, setError] = useState(null)

  async function confirm() {
    setBusy(true)
    setError(null)
    const result = await closePeriod(period.ano_mes)
    setBusy(false)

    if (result.ok) {
      onDone(`Período ${formatAnoMes(period.ano_mes)} fechado. Novos lançamentos com data nesse mês passam a ser bloqueados.`)
      return
    }

    const { error: err } = result
    if (err.kind === 'unauthenticated') return onSessionExpired()

    if (err.kind === 'conflict' && err.code === 'PERIODO_JA_FECHADO') {
      onDone(`Período ${formatAnoMes(period.ano_mes)} já estava fechado.`, 'warning')
      return
    }

    setError(err.message)
  }

  return (
    <Modal
      title="Fechar período"
      onClose={onClose}
      busy={busy}
      footer={
        <>
          <button type="button" className="btn btn-outline-secondary" onClick={onClose} disabled={busy}>
            Cancelar
          </button>
          <button type="button" className="btn btn-warning" onClick={confirm} disabled={busy}>
            {busy && <span className="spinner-border spinner-border-sm me-2" aria-hidden="true"></span>}
            Fechar período
          </button>
        </>
      }
    >
      {error && (
        <div className="alert alert-danger py-2" role="alert">
          {error}
        </div>
      )}
      <p className="mb-0">
        Fechar {formatAnoMes(period.ano_mes)}? A partir de agora não será possível criar, alterar, pagar, cancelar, excluir nem estornar lançamentos com
        data nesse mês. O histórico já registrado é preservado e pode ser consultado normalmente.
      </p>
    </Modal>
  )
}

export default ClosePeriodModal
