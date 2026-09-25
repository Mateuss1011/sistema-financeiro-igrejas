/**
 * Utilitários de dinheiro. Valores trafegam SEMPRE como string decimal da API ("1234.50").
 * Este módulo só converte formato (entrada pt-BR -> string da API, string da API -> BRL)
 * usando manipulação de texto. NÃO faz cálculos e nunca usa Number para valores financeiros.
 */

/**
 * Converte a digitação do usuário para a string decimal da API.
 * Aceita "1.500,00", "1500,5", "1500.50", "-250,30", "R$ 10". Retorna null se inválido
 * (mais de 2 casas, mais de 12 dígitos inteiros, caracteres inválidos).
 */
export function parseMoneyInput(raw) {
  let text = String(raw ?? '').replace(/\s|R\$/g, '')
  if (text === '') return null

  let negative = false
  if (text.startsWith('-')) {
    negative = true
    text = text.slice(1)
  }

  if (!/^[\d.,]+$/.test(text)) return null

  // Parte inteira: só dígitos, ou dígitos agrupados por ponto de milhar (1.234.567).
  const isValidInteger = (part) => part === '' || /^\d+$/.test(part) || /^\d{1,3}(\.\d{3})+$/.test(part)

  let integerPart
  let decimalPart = ''

  if (text.includes(',')) {
    const parts = text.split(',')
    if (parts.length !== 2 || !isValidInteger(parts[0])) return null
    integerPart = parts[0].replace(/\./g, '')
    decimalPart = parts[1]
  } else if (/^\d+\.\d{1,2}$/.test(text)) {
    ;[integerPart, decimalPart] = text.split('.')
  } else {
    if (!isValidInteger(text)) return null
    integerPart = text.replace(/\./g, '')
  }

  if (integerPart === '') integerPart = '0'
  if (!/^\d{1,12}$/.test(integerPart)) return null
  if (decimalPart !== '' && !/^\d{1,2}$/.test(decimalPart)) return null

  const normalized = `${integerPart.replace(/^0+(?=\d)/, '')}.${decimalPart.padEnd(2, '0')}`

  return negative && normalized !== '0.00' ? `-${normalized}` : normalized
}

/** "1234.50" -> "R$ 1.234,50"; "-1234.5" -> "-R$ 1.234,50". */
export function formatMoney(value) {
  if (value === null || value === undefined || value === '') return '—'

  const match = /^(-?)(\d+)(?:\.(\d{1,2}))?$/.exec(String(value))
  if (!match) return String(value)

  const [, sign, integerPart, decimalPart = ''] = match
  const grouped = integerPart.replace(/\B(?=(\d{3})+(?!\d))/g, '.')

  return `${sign}R$ ${grouped},${decimalPart.padEnd(2, '0')}`
}

export function isNegativeMoney(value) {
  return String(value ?? '').startsWith('-') && !isZeroMoney(value)
}

export function isZeroMoney(value) {
  return /^-?0+(\.0+)?$/.test(String(value ?? ''))
}
