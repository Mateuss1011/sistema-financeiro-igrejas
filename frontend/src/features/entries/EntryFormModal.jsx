import { useEffect, useRef, useState } from 'react'
import Modal from '../../shared/components/Modal'
import { formatMoney, isNegativeMoney, isZeroMoney, parseMoneyInput } from '../../shared/utils/money'
import { ACCOUNT_TYPE_LABELS } from '../accounts/accountsService'
import { createEntry, listAccountOptions, listEntryCategories, newIdempotencyKey, todayLocalIso } from './entriesService'

function validate({ categoriaId, contaId, valor, data, contribuinte, descricao }) {
  const errors = {}

  if (!categoriaId) errors.categoria_id = 'Selecione a categoria.'
  if (!contaId) errors.conta_id = 'Selecione a conta.'

  const parsed = parseMoneyInput(valor)
  if (valor.trim() === '') errors.valor = 'Informe o valor.'
  else if (parsed === null) errors.valor = 'Informe um valor válido, com até 2 casas decimais (ex.: 1.500,00).'
  else if (isZeroMoney(parsed) || isNegativeMoney(parsed)) errors.valor = 'O valor deve ser maior que zero.'

  if (!data) errors.data_competencia = 'Informe a data de competência.'
  else if (data > todayLocalIso()) errors.data_competencia = 'A data de competência não pode ser futura.'

  if (contribuinte.length > 150) errors.contribuinte_nome = 'O nome pode ter no máximo 150 caracteres.'
  if (descricao.length > 255) errors.descricao = 'A descrição pode ter no máximo 255 caracteres.'

  return errors
}

/** Registro de uma nova entrada. Entradas não são editáveis: correção é por estorno + nova entrada. */
function EntryFormModal({ onClose, onSaved, onSessionExpired }) {
  const [categories, setCategories] = useState([])
  const [accounts, setAccounts] = useState([])
  const [optionsStatus, setOptionsStatus] = useState('loading')
  const [optionsError, setOptionsError] = useState(null)
  const [optionsKey, setOptionsKey] = useState(0)

  const [categoriaId, setCategoriaId] = useState('')
  const [contaId, setContaId] = useState('')
  const [valor, setValor] = useState('')
  const [data, setData] = useState(todayLocalIso)
  const [contribuinte, setContribuinte] = useState('')
  const [descricao, setDescricao] = useState('')
  const [fieldErrors, setFieldErrors] = useState({})
  const [formError, setFormError] = useState(null)
  const [submitting, setSubmitting] = useState(false)

  // Mesma chave para reenvios do MESMO conteúdo; muda quando o conteúdo muda.
  const attemptRef = useRef({ signature: null, key: null })

  useEffect(() => {
    let cancelled = false

    Promise.all([listEntryCategories({ onlyActive: true }), listAccountOptions({ onlyActive: true })]).then(([categoriesResult, accountsResult]) => {
      if (cancelled) return

      const failed = [categoriesResult, accountsResult].find((result) => !result.ok)
      if (failed) {
        if (failed.error.kind === 'unauthenticated') return onSessionExpired()
        setOptionsError(failed.error)
        setOptionsStatus('error')
        return
      }

      setCategories(categoriesResult.data.data)
      setAccounts(accountsResult.data.data)
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
  const selectedAccount = accounts.find((account) => String(account.id) === contaId)

  async function handleSubmit(event) {
    event.preventDefault()
    if (submitting) return
    setFormError(null)

    const errors = validate({ categoriaId, contaId, valor, data, contribuinte, descricao })
    setFieldErrors(errors)
    if (Object.keys(errors).length > 0) return

    const payload = {
      categoria_id: Number(categoriaId),
      conta_id: Number(contaId),
      valor: parsedValue,
      data_competencia: data,
      descricao: descricao.trim() === '' ? null : descricao.trim(),
      contribuinte_nome: contribuinte.trim() === '' ? null : contribuinte.trim(),
    }

    const signature = JSON.stringify(payload)
    if (attemptRef.current.signature !== signature) {
      attemptRef.current = { signature, key: newIdempotencyKey() }
    }

    setSubmitting(true)
    const result = await createEntry(payload, attemptRef.current.key)
    setSubmitting(false)

    if (result.ok) {
      onSaved('Entrada registrada com sucesso.')
      return
    }

    const { error } = result

    if (error.kind === 'unauthenticated') return onSessionExpired()

    if (error.kind === 'validation') {
      setFieldErrors(error.fieldErrors)
    }

    // Conta/categoria inativadas por outra pessoa: recarrega as opções para refletir a realidade.
    if (error.kind === 'conflict' && (error.code === 'CONTA_INATIVA' || error.code === 'CATEGORIA_INATIVA')) {
      retryOptions()
    }

    setFormError(error.message)
  }

  const canSubmit = optionsStatus === 'ready' && accounts.length > 0 && categories.length > 0

  return (
    <Modal
      title="Nova entrada"
      onClose={onClose}
      busy={submitting}
      footer={
        <>
          <button type="button" className="btn btn-outline-secondary" onClick={onClose} disabled={submitting}>
            Cancelar
          </button>
          <button type="submit" form="entry-form" className="btn btn-primary" disabled={submitting || !canSubmit}>
            {submitting && <span className="spinner-border spinner-border-sm me-2" aria-hidden="true"></span>}
            Registrar entrada
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
          Carregando categorias e contas...
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

      {optionsStatus === 'ready' && (accounts.length === 0 || categories.length === 0) && (
        <div className="alert alert-warning py-2" role="alert">
          {accounts.length === 0 ? 'Não há contas ativas. Ative ou cadastre uma conta antes de registrar entradas.' : 'Não há categorias de entrada ativas.'}
        </div>
      )}

      {optionsStatus === 'ready' && (
        <form id="entry-form" onSubmit={handleSubmit} noValidate>
          <div className="row g-3">
            <div className="col-md-6">
              <label htmlFor="entry-category" className="form-label">
                Categoria
              </label>
              <select
                id="entry-category"
                className={`form-select ${fieldErrors.categoria_id ? 'is-invalid' : ''}`}
                value={categoriaId}
                onChange={(event) => setCategoriaId(event.target.value)}
                disabled={submitting}
                autoFocus
              >
                <option value="">Selecione...</option>
                {categories.map((category) => (
                  <option key={category.id} value={category.id}>
                    {category.nome}
                  </option>
                ))}
              </select>
              {fieldErrors.categoria_id && <div className="invalid-feedback">{fieldErrors.categoria_id}</div>}
            </div>

            <div className="col-md-6">
              <label htmlFor="entry-account" className="form-label">
                Conta
              </label>
              <select
                id="entry-account"
                className={`form-select ${fieldErrors.conta_id ? 'is-invalid' : ''}`}
                value={contaId}
                onChange={(event) => setContaId(event.target.value)}
                disabled={submitting}
              >
                <option value="">Selecione...</option>
                {accounts.map((account) => (
                  <option key={account.id} value={account.id}>
                    {account.nome} ({ACCOUNT_TYPE_LABELS[account.tipo]})
                  </option>
                ))}
              </select>
              {fieldErrors.conta_id && <div className="invalid-feedback">{fieldErrors.conta_id}</div>}
            </div>

            <div className="col-md-6">
              <label htmlFor="entry-value" className="form-label">
                Valor
              </label>
              <div className="input-group has-validation">
                <span className="input-group-text">R$</span>
                <input
                  id="entry-value"
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
                <div className="form-text">Será registrado como {formatMoney(parsedValue)}.</div>
              )}
            </div>

            <div className="col-md-6">
              <label htmlFor="entry-date" className="form-label">
                Data de competência
              </label>
              <input
                id="entry-date"
                type="date"
                className={`form-control ${fieldErrors.data_competencia ? 'is-invalid' : ''}`}
                value={data}
                max={todayLocalIso()}
                onChange={(event) => setData(event.target.value)}
                disabled={submitting}
              />
              {fieldErrors.data_competencia && <div className="invalid-feedback">{fieldErrors.data_competencia}</div>}
              <div className="form-text">Não pode ser futura. Datas passadas são aceitas se o período estiver aberto.</div>
            </div>

            <div className="col-12">
              <label htmlFor="entry-contributor" className="form-label">
                Contribuinte <span className="text-body-secondary">(opcional)</span>
              </label>
              <input
                id="entry-contributor"
                className={`form-control ${fieldErrors.contribuinte_nome ? 'is-invalid' : ''}`}
                value={contribuinte}
                onChange={(event) => setContribuinte(event.target.value)}
                disabled={submitting}
                maxLength={150}
                placeholder="Nome de quem contribuiu"
                autoComplete="off"
              />
              {fieldErrors.contribuinte_nome && <div className="invalid-feedback">{fieldErrors.contribuinte_nome}</div>}
            </div>

            <div className="col-12">
              <label htmlFor="entry-description" className="form-label">
                Descrição <span className="text-body-secondary">(opcional)</span>
              </label>
              <textarea
                id="entry-description"
                rows="2"
                className={`form-control ${fieldErrors.descricao ? 'is-invalid' : ''}`}
                value={descricao}
                onChange={(event) => setDescricao(event.target.value)}
                disabled={submitting}
                maxLength={255}
              ></textarea>
              {fieldErrors.descricao && <div className="invalid-feedback">{fieldErrors.descricao}</div>}
            </div>
          </div>

          <div className="form-text mt-3">
            {selectedAccount ? `A entrada será somada ao saldo de "${selectedAccount.nome}". ` : ''}
            Uma entrada registrada não pode ser editada nem excluída; para corrigir, estorne-a e registre uma nova.
          </div>
        </form>
      )}
    </Modal>
  )
}

export default EntryFormModal
