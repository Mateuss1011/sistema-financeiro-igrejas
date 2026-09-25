const MESES = [
  'janeiro', 'fevereiro', 'março', 'abril', 'maio', 'junho',
  'julho', 'agosto', 'setembro', 'outubro', 'novembro', 'dezembro',
]

/** "2026-03" -> "março/2026" (só manipulação de texto, sem Date). */
export function formatAnoMes(anoMes) {
  const match = /^(\d{4})-(\d{2})$/.exec(String(anoMes ?? ''))
  if (!match) return String(anoMes ?? '—')

  const mes = MESES[Number(match[2]) - 1]
  return mes ? `${mes}/${match[1]}` : String(anoMes)
}
