const DAYS = ['dim.', 'lun.', 'mar.', 'mer.', 'jeu.', 'ven.', 'sam.']
const MONTHS = ['janv.', 'févr.', 'mars', 'avr.', 'mai', 'juin', 'juil.', 'août', 'sept.', 'oct.', 'nov.', 'déc.']

const parse = (iso) => { const [y, m, d] = iso.split('-').map(Number); return new Date(y, m - 1, d) }

export function fdate(iso, short = false) {
  if (!iso) return ''
  const d = parse(iso)
  const s = `${DAYS[d.getDay()]} ${d.getDate()} ${MONTHS[d.getMonth()]}`
  return short ? s : `${s} ${d.getFullYear()}`
}

export function fperiod(a, b) {
  if (!a) return ''
  if (!b || a === b) return fdate(a)
  const x = parse(a), y = parse(b)
  if (x.getFullYear() === y.getFullYear() && x.getMonth() === y.getMonth()) return `${x.getDate()} au ${y.getDate()} ${MONTHS[y.getMonth()]} ${y.getFullYear()}`
  return `${x.getDate()} ${MONTHS[x.getMonth()]} au ${y.getDate()} ${MONTHS[y.getMonth()]} ${y.getFullYear()}`
}

export const money = (x) => (x ?? 0).toLocaleString('fr-FR', { style: 'currency', currency: 'EUR' })
export const ddmm = (iso) => `${iso.slice(8, 10)}/${iso.slice(5, 7)}`
export const sum = (arr, f) => arr.reduce((s, x) => s + (typeof f === 'function' ? f(x) : x[f]), 0)
