import { useEffect, useRef, useState } from 'react'
import ConfirmDialog from '../../shared/components/ConfirmDialog'
import Modal from '../../shared/components/Modal'
import { todayLocalIso } from '../../shared/utils/dates'
import { newIdempotencyKey } from '../../shared/utils/idempotency'
import { formatMoney, isNegativeMoney, isZeroMoney, parseMoneyInput } from '../../shared/utils/money'
import { ACCOUNT_TYPE_LABELS } from '../accounts/accountsService'
import { listAccountOptions } from '../transfers/transfersService'
import { createAdjustment } from './adjustmentsService'

function validate({ conta, sentido, valor, data, justificativa }) {
  const errors = {}

  if (!conta) errors.conta_id = 'Selecione a conta.'
  if (!sentido) errors.sentido = 'Informe se o ajuste é de crédito ou de débito.'

  const parsed = parseMoneyInput(valor)
  if (valor.trim() === '') errors.valor = 'Informe o valor.'
  else if (parsed === null) errors.valor = 'Informe um valor válido, com até 2 casas decimais (ex.: 1.500,00).'
  else if (isZeroMoney(parsed) || isNegativeMoney(parsed)) errors.valor = 'O valor deve ser maior que zero. O sentido (crédito ou débito) define se o saldo sobe ou desce.'

  if (!data) errors.data_ajuste = 'Informe a data do ajuste.'
  else if (data > todayLocalIso()) errors.data_ajuste = 'A data do ajuste não pode ser futura.'

  if (justificativa.trim().length < 3) errors.justificativa = 'Informe a justificativa do ajuste (mínimo de 3 caracteres).'

  return errors
}

/**
 * Ajuste manual de saldo (correção excepcional). Valor sempre positivo + sentido explícito. Débito em
 * caixa sem saldo é bloqueado pelo backend; débito que deixa conta bancária negativa pede confirmação.
 */
function AdjustmentFormModal({ onClose, onSaved, onSessionExpired }) {
  const [accounts, setAccounts] = useState([])
  const [optionsStatus, setOptionsStatus] = useState('loading')
  const [optionsError, setOptionsError] = useState(null)
  const [optionsKey, setOptionsKey] = useState(0)

  const [conta, setConta] = useState('')
  const [sentido, setSentido] = useState('')
  const [valor, setValor] = useState('')
  const [data, setData] = useState(todayLocalIso)
  const [justificativa, setJustificativa] = useState('')
  const [fieldErrors, setFieldErrors] = useState({})
  const [formError, setFormError] = useState(null)
  const [submitting, setSubmitting] = useState(false)
  const [confirmNegative, setConfirmNegative] = useState(false)

  const attemptRef = useRef({ signature: null, key: null })

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

  const parsedValue = valor.trim() === '' ? null : parseMoneyInput(valor)
  const selectedAccount = accounts.find((account) => String(account.id) === conta)

  async function submit(confirmarSaldoNegativo) {
    setFormError(null)

    const payload = {
      conta_id: Number(conta),
      valor: parsedValue,
      sentido,
      data_ajuste: data,
      justificativa: justificativa.trim(),
    }
    if (confirmarSaldoNegativo) payload.confirmar_saldo_negativo = true

    const signature = JSON.stringify(payload.conta_id) + JSON.stringify([payload.valor, payload.sentido, payload.data_ajuste, payload.justificativa])
    if (attemptRef.current.signature !== signature) {
      attemptRef.current = { signature, key: newIdempotencyKey() }
    }

    setSubmitting(true)
    const result = await createAdjustment(payload, attemptRef.current.key)
    setSubmitting(false)

    if (result.ok) {
      setConfirmNegative(false)
      onSaved('Ajuste de saldo registrado. Ajustes não podem ser editados nem excluídos; para corrigir, registre um ajuste oposto.')
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
    if (error.kind === 'conflict' && error.code === 'CONTA_INATIVA') retryOptions()

    setFormError(error.message)
  }

  function handleSubmit(event) {
    event.preventDefault()
    if (submitting) return

    const errors = validate({ conta, sentido, valor, data, justificativa })
    setFieldErrors(errors)
    if (Object.keys(errors).length > 0) return

    submit(false)
  }

  return (
    <>
      <Modal
        title="Novo ajuste de saldo"
        onClose={onClose}
        busy={submitting}
        footer={
          <>
            <button type="button" className="btn btn-outline-secondary" onClick={onClose} disabled={submitting}>
              Cancelar
            </button>
            <button type="submit" form="adjustment-form" className="btn btn-primary" disabled={submitting || optionsStatus !== 'ready' || accounts.length === 0}>
              {submitting && <span className="spinner-border spinner-border-sm me-2" aria-hidden="true"></span>}
              Registrar ajuste
            </button>
          </>
        }
      >
        {formError && (
          <div className="alert alert-danger py-2" role="alert">
            {formError}
          </div>
        )}

        {optionsStatus === 'loading' && (
          <div className="text-center py-4" role="status">
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
            Não há contas ativas para ajustar.
          </div>
        )}

        {optionsStatus === 'ready' && (
          <form id="adjustment-form" onSubmit={handleSubmit} noValidate>
            <div className="alert alert-warning py-2 small">
              Use o ajuste apenas para corrigir diferenças excepcionais de saldo (ex.: conciliação). Ele não é receita nem despesa e não pode ser desfeito;
              a correção é um novo ajuste no sentido oposto.
            </div>

            <div className="row g-3">
              <div className="col-md-6">
                <label htmlFor="adjustment-account" className="form-label">
                  Conta
                </label>
                <select
                  id="adjustment-account"
                  className={`form-select ${fieldErrors.conta_id ? 'is-invalid' : ''}`}
                  value={conta}
                  onChange={(event) => setConta(event.target.value)}
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
                {selectedAccount?.tipo === 'caixa' && <div className="form-text">Caixa físico: um débito não pode deixá-lo negativo.</div>}
              </div>

              <div className="col-md-6">
                <label htmlFor="adjustment-direction" className="form-label">
                  Sentido do ajuste
                </label>
                <select
                  id="adjustment-direction"
                  className={`form-select ${fieldErrors.sentido ? 'is-invalid' : ''}`}
                  value={sentido}
                  onChange={(event) => setSentido(event.target.value)}
                  disabled={submitting}
                >
                  <option value="">Selecione...</option>
                  <option value="credito">Crédito — entrada no saldo (aumenta)</option>
                  <option value="debito">Débito — saída do saldo (diminui)</option>
                </select>
                {fieldErrors.sentido && <div className="invalid-feedback">{fieldErrors.sentido}</div>}
              </div>

              <div className="col-md-6">
                <label htmlFor="adjustment-value" className="form-label">
                  Valor
                </label>
                <div className="input-group has-validation">
                  <span className="input-group-text">R$</span>
                  <input
                    id="adjustment-value"
                    inputMode="decimal"
                    className={`form-control ${fieldErrors.valor ? 'is-invalid' : ''}`}
                    value={valor}
                    onChange={(event) => setValor(event.target.value)}
                    disabled={submitting}
                    placeholder="0,00"
                    autoComplete="off"
                  />
                  {fieldErrors.valor && <div className="invalid-feedback">{fieldErrors.valor}</div>}
                </div>
                <div className="form-text">Sempre positivo; o sentido define se o saldo sobe ou desce.</div>
              </div>

              <div className="col-md-6">
                <label htmlFor="adjustment-date" className="form-label">
                  Data do ajuste
                </label>
                <input
                  id="adjustment-date"
                  type="date"
                  className={`form-control ${fieldErrors.data_ajuste ? 'is-invalid' : ''}`}
                  value={data}
                  max={todayLocalIso()}
                  onChange={(event) => setData(event.target.value)}
                  disabled={submitting}
                />
                {fieldErrors.data_ajuste && <div className="invalid-feedback">{fieldErrors.data_ajuste}</div>}
              </div>

              <div className="col-12">
                <label htmlFor="adjustment-reason" className="form-label">
                  Justificativa
                </label>
                <textarea
                  id="adjustment-reason"
                  rows="3"
                  className={`form-control ${fieldErrors.justificativa ? 'is-invalid' : ''}`}
                  value={justificativa}
                  onChange={(event) => setJustificativa(event.target.value)}
                  disabled={submitting}
                  maxLength={500}
                  placeholder="Ex.: diferença encontrada na conciliação bancária"
                ></textarea>
                {fieldErrors.justificativa && <div className="invalid-feedback">{fieldErrors.justificativa}</div>}
              </div>
            </div>
          </form>
        )}
      </Modal>

      {confirmNegative && (
        <ConfirmDialog
          title="Saldo ficará negativo"
          message={`Este débito deixará o saldo da conta "${selectedAccount?.nome ?? ''}" negativo. Deseja registrar o ajuste mesmo assim?`}
          confirmLabel="Registrar mesmo assim"
          confirmVariant="warning"
          busy={submitting}
          onConfirm={() => submit(true)}
          onCancel={() => setConfirmNegative(false)}
        />
      )}
    </>
  )
}

export default AdjustmentFormModal
