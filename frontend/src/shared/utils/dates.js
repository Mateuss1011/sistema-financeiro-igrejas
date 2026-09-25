/** Datas como texto "AAAA-MM-DD" (sem Date para cálculos financeiros; só manipulação de texto). */

/** Data local de hoje como "AAAA-MM-DD" (sem toISOString, que usa UTC e pode ser o dia seguinte à noite). */
export function todayLocalIso() {
  const now = new Date()
  const month = String(now.getMonth() + 1).padStart(2, '0')
  const day = String(now.getDate()).padStart(2, '0')

  return `${now.getFullYear()}-${month}-${day}`
}

/** "2026-05-01" -> "01/05/2026" (só manipulação de texto). */
export function formatIsoDate(iso) {
  const match = /^(\d{4})-(\d{2})-(\d{2})$/.exec(String(iso ?? ''))

  return match ? `${match[3]}/${match[2]}/${match[1]}` : '—'
}
