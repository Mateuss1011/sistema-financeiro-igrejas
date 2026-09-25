import { useEffect, useRef, useState } from 'react'
import ConfirmDialog from '../../shared/components/ConfirmDialog'
import Modal from '../../shared/components/Modal'
import { todayLocalIso } from '../../shared/utils/dates'
import { newIdempotencyKey } from '../../shared/utils/idempotency'
import { formatMoney, isNegativeMoney, isZeroMoney, parseMoneyInput } from '../../shared/utils/money'
import { ACCOUNT_TYPE_LABELS } from '../accounts/accountsService'
import { createTransfer, listAccountOptions } from './transfersService'

function validate({ origem, destino, valor, data, descricao }) {
  const errors = {}

  if (!origem) errors.conta_origem_id = 'Selecione a conta de origem.'
  if (!destino) errors.conta_destino_id = 'Selecione a conta de destino.'
  else if (destino === origem) errors.conta_destino_id = 'A conta de destino deve ser diferente da conta de origem.'

  const parsed = parseMoneyInput(valor)
  if (valor.trim() === '') errors.valor = 'Informe o valor.'
  else if (parsed === null) errors.valor = 'Informe um valor válido, com até 2 casas decimais (ex.: 1.500,00).'
  else if (isZeroMoney(parsed) || isNegativeMoney(parsed)) errors.valor = 'O valor deve ser maior que zero.'

  if (!data) errors.data_transferencia = 'Informe a data da transferência.'
  else if (data > todayLocalIso()) errors.data_transferencia = 'A data da transferência não pode ser futura.'

  if (descricao.length > 255) errors.descricao = 'A descrição pode ter no máximo 255 caracteres.'

  return errors
}

/**
 * Nova transferência entre contas. Uma transferência é uma operação única: subtrai da origem e soma no
 * destino ao mesmo tempo. Caixa de origem sem saldo é bloqueado pelo backend; conta bancária que ficaria
 * negativa pede confirmação explícita. O saldo mostrado é o da última consulta (informativo).
 */
function TransferFormModal({ onClose, onSaved, onSessionExpired }) {
  const [accounts, setAccounts] = useState([])
  const [optionsStatus, setOptionsStatus] = useState('loading')
  const [optionsError, setOptionsError] = useState(null)
  const [optionsKey, setOptionsKey] = useState(0)

  const [origem, setOrigem] = useState('')
  const [destino, setDestino] = useState('')
  const [valor, setValor] = useState('')
  const [data, setData] = useState(todayLocalIso)
  const [descricao, setDescricao] = useState('')
  const [fieldErrors, setFieldErrors] = useState({})
  const [formError, setFormError] = useState(null)
  const [submitting, setSubmitting] = useState(false)
  const [confirmNegative, setConfirmNegative] = useState(false)

  // Mesma chave para reenvios do MESMO conteúdo; muda quando o conteúdo muda.
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
  const selectedOrigin = accounts.find((account) => String(account.id) === origem)
  const selectedDestination = accounts.find((account) => String(account.id) === destino)

  async function submit(confirmarSaldoNegativo) {
    setFormError(null)

    const payload = {
      conta_origem_id: Number(origem),
      conta_destino_id: Number(destino),
      valor: parsedValue,
      data_transferencia: data,
      descricao: descricao.trim() === '' ? null : descricao.trim(),
    }
    if (confirmarSaldoNegativo) payload.confirmar_saldo_negativo = true

    const signature = JSON.stringify({ ...payload, confirmar_saldo_negativo: undefined })
    if (attemptRef.current.signature !== signature) {
      attemptRef.current = { signature, key: newIdempotencyKey() }
    }

    setSubmitting(true)
    const result = await createTransfer(payload, attemptRef.current.key)
    setSubmitting(false)

    if (result.ok) {
      setConfirmNegative(false)
      onSaved('Transferência registrada. O saldo das duas contas foi atualizado.')
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

    // Conta inativada por outra pessoa: recarrega as opções para refletir a realidade.
    if (error.kind === 'conflict' && error.code === 'CONTA_INATIVA') retryOptions()

    setFormError(error.message)
  }

  function handleSubmit(event) {
    event.preventDefault()
    if (submitting) return

    const errors = validate({ origem, destino, valor, data, descricao })
    setFieldErrors(errors)
    if (Object.keys(errors).length > 0) return

    submit(false)
  }

  const canSubmit = optionsStatus === 'ready' && accounts.length >= 2

  return (
    <>
      <Modal
        title="Nova transferência"
        onClose={onClose}
        busy={submitting}
        footer={
          <>
            <button type="button" className="btn btn-outline-secondary" onClick={onClose} disabled={submitting}>
              Cancelar
            </button>
            <button type="submit" form="transfer-form" className="btn btn-primary" disabled={submitting || !canSubmit}>
              {submitting && <span className="spinner-border spinner-border-sm me-2" aria-hidden="true"></span>}
              Transferir
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

        {optionsStatus === 'ready' && accounts.length < 2 && (
          <div className="alert alert-warning py-2" role="alert">
            É preciso ter pelo menos duas contas ativas para transferir.
          </div>
        )}

        {optionsStatus === 'ready' && (
          <form id="transfer-form" onSubmit={handleSubmit} noValidate>
            <div className="row g-3">
              <div className="col-md-6">
                <label htmlFor="transfer-origin" className="form-label">
                  Conta de origem
                </label>
                <select
                  id="transfer-origin"
                  className={`form-select ${fieldErrors.conta_origem_id ? 'is-invalid' : ''}`}
                  value={origem}
                  onChange={(event) => setOrigem(event.target.value)}
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
                {fieldErrors.conta_origem_id && <div className="invalid-feedback">{fieldErrors.conta_origem_id}</div>}
                {selectedOrigin?.tipo === 'caixa' && <div className="form-text">Caixa físico: não pode ficar com saldo negativo.</div>}
                {selectedOrigin?.tipo === 'banco' && <div className="form-text">Conta bancária: se o saldo ficar negativo, será pedida uma confirmação.</div>}
              </div>

              <div className="col-md-6">
                <label htmlFor="transfer-destination" className="form-label">
                  Conta de destino
                </label>
                <select
                  id="transfer-destination"
                  className={`form-select ${fieldErrors.conta_destino_id ? 'is-invalid' : ''}`}
                  value={destino}
                  onChange={(event) => setDestino(event.target.value)}
                  disabled={submitting}
                >
                  <option value="">Selecione...</option>
                  {accounts
                    .filter((account) => String(account.id) !== origem)
                    .map((account) => (
                      <option key={account.id} value={account.id}>
                        {account.nome} ({ACCOUNT_TYPE_LABELS[account.tipo]}) — saldo {formatMoney(account.saldo_atual)}
                      </option>
                    ))}
                </select>
                {fieldErrors.conta_destino_id && <div className="invalid-feedback">{fieldErrors.conta_destino_id}</div>}
              </div>

              <div className="col-md-6">
                <label htmlFor="transfer-value" className="form-label">
                  Valor
                </label>
                <div className="input-group has-validation">
                  <span className="input-group-text">R$</span>
                  <input
                    id="transfer-value"
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
                {parsedValue !== null && !isZeroMoney(parsedValue) && !isNegativeMoney(parsedValue) && (
                  <div className="form-text">Será transferido {formatMoney(parsedValue)}.</div>
                )}
              </div>

              <div className="col-md-6">
                <label htmlFor="transfer-date" className="form-label">
                  Data da transferência
                </label>
                <input
                  id="transfer-date"
                  type="date"
                  className={`form-control ${fieldErrors.data_transferencia ? 'is-invalid' : ''}`}
                  value={data}
                  max={todayLocalIso()}
                  onChange={(event) => setData(event.target.value)}
                  disabled={submitting}
                />
                {fieldErrors.data_transferencia && <div className="invalid-feedback">{fieldErrors.data_transferencia}</div>}
                <div className="form-text">Não pode ser futura. Datas passadas são aceitas se o período estiver aberto.</div>
              </div>

              <div className="col-12">
                <label htmlFor="transfer-description" className="form-label">
                  Descrição <span className="text-body-secondary">(opcional)</span>
                </label>
                <input
                  id="transfer-description"
                  className={`form-control ${fieldErrors.descricao ? 'is-invalid' : ''}`}
                  value={descricao}
                  onChange={(event) => setDescricao(event.target.value)}
                  disabled={submitting}
                  maxLength={255}
                  placeholder="Ex.: reforço do caixa"
                  autoComplete="off"
                />
                {fieldErrors.descricao && <div className="invalid-feedback">{fieldErrors.descricao}</div>}
              </div>
            </div>

            <div className="form-text mt-3">
              A transferência move o valor entre as duas contas e não é receita nem despesa. Uma vez registrada, não pode ser editada nem excluída;
              para desfazer, faça um estorno.
            </div>
          </form>
        )}
      </Modal>

      {confirmNegative && (
        <ConfirmDialog
          title="Saldo ficará negativo"
          message={`Esta transferência deixará o saldo da conta "${selectedOrigin?.nome ?? ''}" negativo${selectedDestination ? ` (destino: ${selectedDestination.nome})` : ''}. Deseja transferir mesmo assim?`}
          confirmLabel="Transferir mesmo assim"
          confirmVariant="warning"
          busy={submitting}
          onConfirm={() => submit(true)}
          onCancel={() => setConfirmNegative(false)}
        />
      )}
    </>
  )
}

export default TransferFormModal
