import { callApi, callDownload } from '../../shared/apiCall'

export const REPORTS_PER_PAGE = 20

/** Catálogo dos relatórios que o usuário pode ver + permissões (exportar?) + opções dos filtros. Vem do backend. */
export function getReportCatalog() {
  return callApi('/relatorios')
}

/**
 * Filtros efetivamente enviados: só os que o relatório aceita e que têm valor. `ano_mes` vazio = mês corrente (o
 * backend decide qual é). Usado tanto na consulta quanto na exportação, para que arquivo e tela usem o MESMO filtro.
 */
function buildParams(filters, allowedFilters) {
  const params = new URLSearchParams()

  if (filters.ano_mes !== '') params.set('ano_mes', filters.ano_mes)
  for (const name of allowedFilters) {
    if (filters[name] !== undefined && filters[name] !== '') params.set(name, filters[name])
  }
  if (filters.ordenar) params.set('ordenar', filters.ordenar)

  return params
}

export function getReport({ report, filters, allowedFilters, paginated, page = 1 }) {
  const params = buildParams(filters, allowedFilters)
  if (paginated) {
    params.set('page', String(page))
    params.set('por_pagina', String(REPORTS_PER_PAGE))
  }

  return callApi(`/relatorios/${report}?${params.toString()}`)
}

/** Exporta TODO o conjunto filtrado (não só a página). `format`: 'csv' | 'xlsx'. Só Pastor/Administrador/Tesoureiro. */
export function downloadReport({ report, format, filters, allowedFilters }) {
  const params = buildParams(filters, allowedFilters)
  const query = params.toString()
  const anoMes = filters.ano_mes === '' ? 'mes-corrente' : filters.ano_mes

  return callDownload(`/relatorios/${report}/exportar/${format}${query === '' ? '' : `?${query}`}`, {
    fallbackName: `sfg-relatorio-${report}-${anoMes}.${format}`,
  })
}

/** Entrega o Blob ao navegador como download. */
export function saveBlob(blob, filename) {
  const url = URL.createObjectURL(blob)
  const link = document.createElement('a')

  link.href = url
  link.download = filename
  document.body.appendChild(link)
  link.click()
  link.remove()
  setTimeout(() => URL.revokeObjectURL(url), 1000)
}

const ORDER_FIELD_LABELS = {
  data_competencia: 'Data de competência',
  data_pagamento: 'Data de pagamento',
  data: 'Data',
  valor: 'Valor',
  created_at: 'Data de criação',
  referencia_id: 'Referência',
  id: 'ID',
  tipo: 'Tipo',
  nome: 'Conta',
  saldo: 'Saldo',
}

/** Opções do seletor de ordenação a partir dos campos aceitos pelo relatório (o primeiro item é "padrão"). */
export function buildSortOptions(orderFields) {
  const options = [{ value: '', label: 'Ordem padrão' }]

  for (const field of orderFields) {
    const label = ORDER_FIELD_LABELS[field] ?? field
    options.push({ value: field, label: `${label} (crescente)` }, { value: `-${field}`, label: `${label} (decrescente)` })
  }

  return options
}
