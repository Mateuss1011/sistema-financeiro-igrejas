import { useEffect, useRef, useState } from 'react'
import Modal from '../../shared/components/Modal'
import { todayLocalIso } from '../../shared/utils/dates'
import { newIdempotencyKey } from '../../shared/utils/idempotency'
import { formatMoney, isNegativeMoney, isZeroMoney, parseMoneyInput } from '../../shared/utils/money'
import { createExpense, listExpenseCategories, updateExpense } from './expensesService'

function validate({ categoriaId, valor, data, descricao, fornecedor }) {
  const errors = {}

  if (!categoriaId) errors.categoria_id = 'Selecione a categoria.'

  const parsed = parseMoneyInput(valor)
  if (valor.trim() === '') errors.valor = 'Informe o valor.'
  else if (parsed === null) errors.valor = 'Informe um valor válido, com até 2 casas decimais (ex.: 1.500,00).'
  else if (isZeroMoney(parsed) || isNegativeMoney(parsed)) errors.valor = 'O valor deve ser maior que zero.'

  if (!data) errors.data_competencia = 'Informe a data de competência.'
  else if (data > todayLocalIso()) errors.data_competencia = 'A data de competência não pode ser futura.'

  if (descricao.trim() === '') errors.descricao = 'Informe a descrição da despesa.'
  else if (descricao.trim().length > 255) errors.descricao = 'A descrição pode ter no máximo 255 caracteres.'

  if (fornecedor.trim().length > 150) errors.fornecedor_nome = 'O nome do fornecedor pode ter no máximo 150 caracteres.'

  return errors
}

/** "1234.50" -> "1234,50" para preencher o campo de edição (só texto, sem aritmética). */
function toInputMoney(apiValue) {
  return String(apiValue ?? '').replace('.', ',')
}

/**
 * Criação (sempre Pendente, sem conta) e edição de uma despesa Pendente.
 * Pagamento, cancelamento e estorno são operações próprias, com modais próprios.
 */
function ExpenseFormModal({ mode, expense, onClose, onSaved, onSessionExpired }) {
  const isCreate = mode === 'create'

  const [categories, setCategories] = useState([])
  const [optionsStatus, setOptionsStatus] = useState('loading')
  const [optionsError, setOptionsError] = useState(null)
  const [optionsKey, setOptionsKey] = useState(0)

  const [categoriaId, setCategoriaId] = useState(expense ? String(expense.categoria_id) : '')
  const [valor, setValor] = useState(expense ? toInputMoney(expense.valor) : '')
  const [data, setData] = useState(expense ? expense.data_competencia : todayLocalIso)
  const [descricao, setDescricao] = useState(expense?.descricao ?? '')
  const [fornecedor, setFornecedor] = useState(expense?.fornecedor_nome ?? '')
  const [fieldErrors, setFieldErrors] = useState({})
  const [formError, setFormError] = useState(null)
  const [submitting, setSubmitting] = useState(false)

  // Mesma chave para reenvios do MESMO conteúdo; muda quando o conteúdo muda.
  const attemptRef = useRef({ signature: null, key: null })

  useEffect(() => {
    let cancelled = false

    listExpenseCategories({ onlyActive: true }).then((result) => {
      if (cancelled) return

      if (!result.ok) {
        if (result.error.kind === 'unauthenticated') return onSessionExpired()
        setOptionsError(result.error)
        setOptionsStatus('error')
        return
      }

      setCategories(result.data.data)
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

  // Na edição, a categoria atual pode estar inativa: continua listada (mantê-la é permitido).
  const options =
    !isCreate && expense.categoria && !categories.some((category) => category.id === expense.categoria_id)
      ? [{ id: expense.categoria_id, nome: `${expense.categoria.nome} (inativa)` }, ...categories]
      : categories

  const parsedValue = valor.trim() === '' ? null : parseMoneyInput(valor)

  async function handleSubmit(event) {
    event.preventDefault()
    if (submitting) return
    setFormError(null)

    const errors = validate({ categoriaId, valor, data, descricao, fornecedor })
    setFieldErrors(errors)
    if (Object.keys(errors).length > 0) return

    const payload = {
      categoria_id: Number(categoriaId),
      valor: parsedValue,
      data_competencia: data,
      descricao: descricao.trim(),
      fornecedor_nome: fornecedor.trim() === '' ? null : fornecedor.trim(),
    }

    let result

    if (isCreate) {
      const signature = JSON.stringify(payload)
      if (attemptRef.current.signature !== signature) {
        attemptRef.current = { signature, key: newIdempotencyKey() }
      }

      setSubmitting(true)
      result = await createExpense(payload, attemptRef.current.key)
    } else {
      // Envia só o que mudou (o backend também ignora PUT sem mudança).
      const changes = {}
      if (payload.categoria_id !== expense.categoria_id) changes.categoria_id = payload.categoria_id
      if (payload.valor !== expense.valor) changes.valor = payload.valor
      if (payload.data_competencia !== expense.data_competencia) changes.data_competencia = payload.data_competencia
      if (payload.descricao !== (expense.descricao ?? '')) changes.descricao = payload.descricao
      if (payload.fornecedor_nome !== (expense.fornecedor_nome ?? null)) changes.fornecedor_nome = payload.fornecedor_nome

      if (Object.keys(changes).length === 0) {
        setFormError('Nenhuma alteração foi feita.')
        return
      }

      setSubmitting(true)
      result = await updateExpense(expense.id, changes)
    }

    setSubmitting(false)

    if (result.ok) {
      onSaved(isCreate ? 'Despesa registrada como Pendente.' : 'Despesa atualizada com sucesso.')
      return
    }

    const { error } = result

    if (error.kind === 'unauthenticated') return onSessionExpired()

    if (error.kind === 'validation') setFieldErrors(error.fieldErrors)

    // Categoria inativada por outra pessoa: recarrega as opções para refletir a realidade.
    if (error.kind === 'conflict' && error.code === 'CATEGORIA_INATIVA') retryOptions()

    setFormError(error.message)
  }

  const canSubmit = optionsStatus === 'ready' && options.length > 0

  return (
    <Modal
      title={isCreate ? 'Nova despesa' : 'Editar despesa'}
      onClose={onClose}
      busy={submitting}
      footer={
        <>
          <button type="button" className="btn btn-outline-secondary" onClick={onClose} disabled={submitting}>
            Cancelar
          </button>
          <button type="submit" form="expense-form" className="btn btn-primary" disabled={submitting || !canSubmit}>
            {submitting && <span className="spinner-border spinner-border-sm me-2" aria-hidden="true"></span>}
            {isCreate ? 'Registrar despesa' : 'Salvar alterações'}
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
          Carregando categorias...
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

      {optionsStatus === 'ready' && options.length === 0 && (
        <div className="alert alert-warning py-2" role="alert">
          Não há categorias de despesa ativas.
        </div>
      )}

      {optionsStatus === 'ready' && (
        <form id="expense-form" onSubmit={handleSubmit} noValidate>
          <div className="row g-3">
            <div className="col-md-6">
              <label htmlFor="expense-category" className="form-label">
                Categoria
              </label>
              <select
                id="expense-category"
                className={`form-select ${fieldErrors.categoria_id ? 'is-invalid' : ''}`}
                value={categoriaId}
                onChange={(event) => setCategoriaId(event.target.value)}
                disabled={submitting}
                autoFocus
              >
                <option value="">Selecione...</option>
                {options.map((category) => (
                  <option key={category.id} value={category.id}>
                    {category.nome}
                  </option>
                ))}
              </select>
              {fieldErrors.categoria_id && <div className="invalid-feedback">{fieldErrors.categoria_id}</div>}
            </div>

            <div className="col-md-6">
              <label htmlFor="expense-value" className="form-label">
                Valor
              </label>
              <div className="input-group has-validation">
                <span className="input-group-text">R$</span>
                <input
                  id="expense-value"
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
              <label htmlFor="expense-date" className="form-label">
                Data de competência
              </label>
              <input
                id="expense-date"
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

            <div className="col-md-6">
              <label htmlFor="expense-supplier" className="form-label">
                Fornecedor <span className="text-body-secondary">(opcional)</span>
              </label>
              <input
                id="expense-supplier"
                className={`form-control ${fieldErrors.fornecedor_nome ? 'is-invalid' : ''}`}
                value={fornecedor}
                onChange={(event) => setFornecedor(event.target.value)}
                disabled={submitting}
                maxLength={150}
                placeholder="Nome do fornecedor"
                autoComplete="off"
              />
              {fieldErrors.fornecedor_nome && <div className="invalid-feedback">{fieldErrors.fornecedor_nome}</div>}
            </div>

            <div className="col-12">
              <label htmlFor="expense-description" className="form-label">
                Descrição
              </label>
              <textarea
                id="expense-description"
                rows="2"
                className={`form-control ${fieldErrors.descricao ? 'is-invalid' : ''}`}
                value={descricao}
                onChange={(event) => setDescricao(event.target.value)}
                disabled={submitting}
                maxLength={255}
                placeholder="Ex.: Conta de energia — setembro"
              ></textarea>
              {fieldErrors.descricao && <div className="invalid-feedback">{fieldErrors.descricao}</div>}
            </div>
          </div>

          <div className="form-text mt-3">
            {isCreate
              ? 'A despesa nasce Pendente e não altera nenhum saldo. A conta de origem e a data de pagamento são informadas ao pagar.'
              : 'Só despesas Pendentes podem ser editadas. Pagamento, cancelamento e estorno são operações separadas.'}
          </div>
        </form>
      )}
    </Modal>
  )
}

export default ExpenseFormModal
