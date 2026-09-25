import { useEffect, useState } from 'react'
import ConfirmDialog from '../../shared/components/ConfirmDialog'
import Modal from '../../shared/components/Modal'
import { formatIsoDate, todayLocalIso } from '../../shared/utils/dates'
import { formatMoney } from '../../shared/utils/money'
import { ACCOUNT_TYPE_LABELS } from '../accounts/accountsService'
import { listAccountOptions, payExpense } from './expensesService'

/**
 * Pagamento de uma despesa Pendente: exige conta de origem e data de pagamento.
 * Conta bancária que ficaria negativa pede confirmação explícita (reenvio com o aceite);
 * caixa que ficaria negativo é bloqueado pelo backend, sem opção de confirmar.
 * O saldo mostrado é o da última consulta (informativo): quem decide é o backend.
 */
function PayExpenseModal({ expense, onClose, onDone, onStale, onSessionExpired }) {
  const [accounts, setAccounts] = useState([])
  const [optionsStatus, setOptionsStatus] = useState('loading')
  const [optionsError, setOptionsError] = useState(null)
  const [optionsKey, setOptionsKey] = useState(0)

  const [contaId, setContaId] = useState('')
  const [data, setData] = useState(todayLocalIso)
  const [fieldErrors, setFieldErrors] = useState({})
  const [formError, setFormError] = useState(null)
  const [submitting, setSubmitting] = useState(false)
  const [confirmNegative, setConfirmNegative] = useState(false)

  useEffect(() => {
    let cancelled = false

    listAccountOptions({ onlyActive: true }).then((result) => {
      if (cancelled) return

      if (!result.ok) {
        if (result.error.kind === 'unauthenticated') return onSessionExpired()
        setOptionsError(result.error)
        setOptionsStatus('error')
        return
      }

      setAccounts(result.data.data)
      setOptionsError(null)
      setOptionsStatus('ready')
    })

    return () => {
      cancelled = true
    }
  }, [optionsKey, onSessionExpired])

  function retryOptions() {
    setOptionsStatus('loading')
    setOptionsKey((key) => key + 1)
  }

  const selectedAccount = accounts.find((account) => String(account.id) === contaId)

  async function submit(confirmarSaldoNegativo) {
    setFormError(null)
    setSubmitting(true)
    const result = await payExpense(expense.id, { contaId: Number(contaId), dataPagamento: data, confirmarSaldoNegativo })
    setSubmitting(false)

    if (result.ok) {
      setConfirmNegative(false)
      onDone('Despesa paga. O valor foi debitado da conta selecionada.')
      return
    }

    const { error } = result

    if (error.kind === 'unauthenticated') return onSessionExpired()

    if (error.kind === 'conflict' && error.code === 'SALDO_NEGATIVO_REQUER_CONFIRMACAO') {
      setConfirmNegative(true)
      return
    }

    setConfirmNegative(false)

    if (error.kind === 'validation') setFieldErrors(error.fieldErrors)

    // Conta inativada por outra pessoa: recarrega as opções.
    if (error.kind === 'conflict' && error.code === 'CONTA_INATIVA') retryOptions()

    // A despesa mudou de estado por outra via (paga/cancelada/removida): atualiza a lista.
    if (error.kind === 'notFound' || (error.kind === 'conflict' && error.code === 'DESPESA_NAO_PENDENTE')) onStale()

    setFormError(error.message)
  }

  function handleSubmit(event) {
    event.preventDefault()
    if (submitting) return

    const errors = {}
    if (!contaId) errors.conta_id = 'Selecione a conta de origem.'
    if (!data) errors.data_pagamento = 'Informe a data de pagamento.'
    else if (data > todayLocalIso()) errors.data_pagamento = 'A data de pagamento não pode ser futura.'

    setFieldErrors(errors)
    if (Object.keys(errors).length > 0) return

    submit(false)
  }

  return (
    <>
      <Modal
        title="Pagar despesa"
        onClose={onClose}
        busy={submitting}
        footer={
          <>
            <button type="button" className="btn btn-outline-secondary" onClick={onClose} disabled={submitting}>
              Cancelar
            </button>
            <button type="submit" form="pay-form" className="btn btn-success" disabled={submitting || optionsStatus !== 'ready' || accounts.length === 0}>
              {submitting && <span className="spinner-border spinner-border-sm me-2" aria-hidden="true"></span>}
              Pagar despesa
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
          <dt className="col-4">Valor</dt>
          <dd className="col-8 mb-0 fw-semibold">{formatMoney(expense.valor)}</dd>
        </dl>

        {optionsStatus === 'loading' && (
          <div className="text-center py-3" role="status">
            <span className="spinner-border spinner-border-sm text-primary me-2" aria-hidden="true"></span>
            Carregando contas...
          </div>
        )}

        {optionsStatus === 'error' && (
          <div className="alert alert-danger d-flex align-items-center justify-content-between" role="alert">
            <span>{optionsError.message}</span>
            <button type="button" className="btn btn-sm btn-outline-danger" onClick={retryOptions}>
              Tentar novamente
            </button>
          </div>
        )}

        {optionsStatus === 'ready' && accounts.length === 0 && (
          <div className="alert alert-warning py-2" role="alert">
            Não há contas ativas. Ative ou cadastre uma conta antes de pagar despesas.
          </div>
        )}

        {optionsStatus === 'ready' && accounts.length > 0 && (
          <form id="pay-form" onSubmit={handleSubmit} noValidate>
            <div className="row g-3">
              <div className="col-12">
                <label htmlFor="pay-account" className="form-label">
                  Conta de origem
                </label>
                <select
                  id="pay-account"
                  className={`form-select ${fieldErrors.conta_id ? 'is-invalid' : ''}`}
                  value={contaId}
                  onChange={(event) => setContaId(event.target.value)}
                  disabled={submitting}
                  autoFocus
                >
                  <option value="">Selecione...</option>
                  {accounts.map((account) => (
                    <option key={account.id} value={account.id}>
                      {account.nome} ({ACCOUNT_TYPE_LABELS[account.tipo]}) — saldo {formatMoney(account.saldo_atual)}
                    </option>
                  ))}
                </select>
                {fieldErrors.conta_id && <div className="invalid-feedback">{fieldErrors.conta_id}</div>}
                {selectedAccount?.tipo === 'caixa' && <div className="form-text">Caixa físico: não pode ficar com saldo negativo.</div>}
                {selectedAccount?.tipo === 'banco' && <div className="form-text">Conta bancária: se o saldo ficar negativo, será pedida uma confirmação.</div>}
              </div>

              <div className="col-md-6">
                <label htmlFor="pay-date" className="form-label">
                  Data de pagamento
                </label>
                <input
                  id="pay-date"
                  type="date"
                  className={`form-control ${fieldErrors.data_pagamento ? 'is-invalid' : ''}`}
                  value={data}
                  max={todayLocalIso()}
                  onChange={(event) => setData(event.target.value)}
                  disabled={submitting}
                />
                {fieldErrors.data_pagamento && <div className="invalid-feedback">{fieldErrors.data_pagamento}</div>}
              </div>
            </div>
          </form>
        )}
      </Modal>

      {confirmNegative && (
        <ConfirmDialog
          title="Saldo ficará negativo"
          message={`Este pagamento deixará o saldo da conta "${selectedAccount?.nome ?? ''}" negativo. Deseja pagar mesmo assim?`}
          confirmLabel="Pagar mesmo assim"
          confirmVariant="warning"
          busy={submitting}
          onConfirm={() => submit(true)}
          onCancel={() => setConfirmNegative(false)}
        />
      )}
    </>
  )
}

export default PayExpenseModal
