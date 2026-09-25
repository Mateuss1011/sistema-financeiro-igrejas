import { useEffect, useState } from 'react'
import { formatIsoDate, todayLocalIso } from '../../shared/utils/dates'
import { formatMoney, isNegativeMoney } from '../../shared/utils/money'
import { formatAnoMes } from '../periods/uiFormat'
import { ACCOUNT_TYPE_LABELS, PERIOD_STATUS_LABELS, getDashboard } from './dashboardService'

function Indicator({ id, title, value, hint, tone = '' }) {
  return (
    <div className="col-12 col-md-6 col-xl-4">
      <div className="card shadow-sm h-100" data-testid={id}>
        <div className="card-body">
          <div className="text-body-secondary small">{title}</div>
          <div className={`fs-4 fw-semibold text-nowrap ${tone}`}>{value}</div>
          {hint && <div className="small text-body-secondary mt-1">{hint}</div>}
        </div>
      </div>
    </div>
  )
}

function DashboardPage({ onSessionExpired }) {
  // '' = mês corrente (o backend decide qual é). O <input type="month"> nunca permite meses futuros.
  const [month, setMonth] = useState('')
  const [refreshKey, setRefreshKey] = useState(0)
  const [data, setData] = useState(null)
  const [status, setStatus] = useState('loading')
  const [loadError, setLoadError] = useState(null)

  const maxMonth = todayLocalIso().slice(0, 7)

  useEffect(() => {
    let cancelled = false

    getDashboard({ anoMes: month }).then((result) => {
      if (cancelled) return

      if (result.ok) {
        setData(result.data.data)
        setLoadError(null)
        setStatus('ready')
        return
      }

      if (result.error.kind === 'unauthenticated') return onSessionExpired()

      setLoadError(
        result.error.kind === 'validation' ? { ...result.error, message: 'Mês inválido. Escolha o mês corrente ou um mês anterior.' } : result.error,
      )
      setStatus('error')
    })

    return () => {
      cancelled = true
    }
  }, [month, refreshKey, onSessionExpired])

  function changeMonth(value) {
    // Só meses até o corrente; campo vazio volta ao mês corrente.
    if (value > maxMonth) return

    setStatus('loading')
    setMonth(value)
  }

  function reload() {
    setStatus((current) => (current === 'ready' ? 'refreshing' : current))
    setRefreshKey((key) => key + 1)
  }

  function retry() {
    setStatus('loading')
    setRefreshKey((key) => key + 1)
  }

  const isBusy = status === 'loading' || status === 'refreshing'
  const full = Boolean(data?.saldo)
  const empty =
    data && data.entradas.total === '0.00' && data.despesas_pagas.total === '0.00' && data.despesas_pendentes.quantidade === 0

  return (
    <section aria-labelledby="dashboard-title">
      <div className="d-flex flex-wrap align-items-end justify-content-between gap-2 mb-3">
        <div>
          <h1 className="h4 mb-0" id="dashboard-title">
            Dashboard
          </h1>
          <p className="text-body-secondary mb-0 small">Indicadores do mês selecionado. Entradas pela data de competência e despesas pagas pela data de pagamento.</p>
        </div>
        <div className="d-flex align-items-end gap-2">
          <div>
            <label htmlFor="dashboard-month" className="form-label small mb-1">
              Mês
            </label>
            <input
              id="dashboard-month"
              type="month"
              className="form-control form-control-sm"
              value={month}
              max={maxMonth}
              onChange={(event) => changeMonth(event.target.value)}
            />
          </div>
          {month !== '' && (
            <button type="button" className="btn btn-sm btn-outline-secondary" onClick={() => changeMonth('')}>
              Mês corrente
            </button>
          )}
          <button type="button" className="btn btn-sm btn-outline-secondary" onClick={reload} disabled={isBusy}>
            Atualizar
          </button>
        </div>
      </div>

      {status === 'loading' && (
        <div className="text-center py-5" role="status">
          <span className="spinner-border text-primary" aria-hidden="true"></span>
          <div className="text-body-secondary mt-2">Carregando indicadores...</div>
        </div>
      )}

      {status === 'error' && (
        <div className="alert alert-danger d-flex align-items-center justify-content-between" role="alert">
          <span>{loadError.message}</span>
          {loadError.kind !== 'forbidden' && (
            <button type="button" className="btn btn-sm btn-outline-danger" onClick={retry}>
              Tentar novamente
            </button>
          )}
        </div>
      )}

      {(status === 'ready' || status === 'refreshing') && data && (
        <div className={status === 'refreshing' ? 'opacity-50' : ''} aria-busy={status === 'refreshing'}>
          <h2 className="h6 text-body-secondary text-capitalize mb-3" data-testid="dashboard-month-label">
            {formatAnoMes(data.ano_mes)}
          </h2>

          {data.escopo === 'proprios' && (
            <div className="alert alert-info py-2" role="note">
              Você está vendo apenas os lançamentos que você mesmo registrou.
            </div>
          )}

          {empty && <div className="alert alert-light border py-2">Nenhum lançamento encontrado neste mês.</div>}

          <div className="row g-3 mb-4">
            <Indicator id="ind-entradas" title="Entradas do mês" value={formatMoney(data.entradas.total)} />
            <Indicator id="ind-pagas" title="Despesas pagas no mês" value={formatMoney(data.despesas_pagas.total)} />
            <Indicator
              id="ind-pendentes"
              title="Despesas pendentes"
              value={formatMoney(data.despesas_pendentes.valor)}
              hint={`${data.despesas_pendentes.quantidade} ${data.despesas_pendentes.quantidade === 1 ? 'despesa' : 'despesas'} com competência neste mês`}
            />
            {full && (
              <>
                <Indicator
                  id="ind-saldo"
                  title="Saldo total"
                  value={formatMoney(data.saldo.total)}
                  tone={isNegativeMoney(data.saldo.total) ? 'text-danger' : ''}
                  hint={data.saldo.referencia === 'atual' ? 'Saldo atual de todas as contas' : `Saldo de todas as contas em ${formatIsoDate(data.saldo.referencia)}`}
                />
                <div className="col-12 col-md-6 col-xl-4">
                  <div className="card shadow-sm h-100" data-testid="ind-periodo">
                    <div className="card-body">
                      <div className="text-body-secondary small">Situação do período</div>
                      <div className="fs-4 fw-semibold">
                        <span className={`badge ${data.periodo.status === 'fechado' ? 'text-bg-secondary' : 'text-bg-success'}`}>
                          {PERIOD_STATUS_LABELS[data.periodo.status] ?? data.periodo.status}
                        </span>
                      </div>
                      <div className="small text-body-secondary mt-1 text-capitalize">{formatAnoMes(data.periodo.ano_mes)}</div>
                    </div>
                  </div>
                </div>
              </>
            )}
          </div>

          {full && (
            <div className="card shadow-sm" data-testid="saldo-contas">
              <div className="card-header bg-body">
                <h2 className="h6 mb-0">Saldo por conta</h2>
              </div>
              {data.saldo.contas.length === 0 ? (
                <div className="card-body text-center text-body-secondary py-4">Nenhuma conta cadastrada.</div>
              ) : (
                <div className="table-responsive">
                  <table className="table table-hover align-middle mb-0">
                    <thead className="table-light">
                      <tr>
                        <th scope="col">Conta</th>
                        <th scope="col">Tipo</th>
                        <th scope="col">Situação</th>
                        <th scope="col" className="text-end">
                          Saldo
                        </th>
                      </tr>
                    </thead>
                    <tbody>
                      {data.saldo.contas.map((account) => (
                        <tr key={account.id}>
                          <td className="fw-semibold">{account.nome}</td>
                          <td>{ACCOUNT_TYPE_LABELS[account.tipo] ?? account.tipo}</td>
                          <td>
                            <span className={`badge ${account.ativa ? 'text-bg-success' : 'text-bg-secondary'}`}>{account.ativa ? 'Ativa' : 'Inativa'}</span>
                          </td>
                          <td className={`text-end text-nowrap ${isNegativeMoney(account.saldo) ? 'text-danger' : ''}`}>{formatMoney(account.saldo)}</td>
                        </tr>
                      ))}
                    </tbody>
                  </table>
                </div>
              )}
            </div>
          )}
        </div>
      )}
    </section>
  )
}

export default DashboardPage
