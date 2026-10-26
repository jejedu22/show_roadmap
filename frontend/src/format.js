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

/** « 9h05 », « 09:05 » → minutes depuis minuit ; heure absente ou illisible en dernier. */
export function timeKey(t) {
  const m = /^(\d{1,2})\s*[h:]\s*(\d{0,2})/i.exec(t || '')
  return m ? Number(m[1]) * 60 + Number(m[2] || 0) : 24 * 60
}

/** Ordre chronologique des trajets : date puis heure de départ. */
export const byDeparture = (a, b) => (a.day || '').localeCompare(b.day || '') || timeKey(a.depTime) - timeKey(b.depTime)

export const DIRECTIONS = [['outbound', 'Aller', '→'], ['return', 'Retour', '←']]

/** Date du jour au format AAAA-MM-JJ (heure locale). */
export function todayIso() {
  const d = new Date()
  return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`
}

/** Nombre de jours entre aujourd'hui et une date (négatif si passée). */
export const daysFrom = (iso) => Math.round((parse(iso) - parse(todayIso())) / 86400000)

export function relative(iso) {
  const n = daysFrom(iso)
  return n === 0 ? "aujourd'hui" : n === 1 ? 'demain' : n > 0 ? `dans ${n} jours` : n === -1 ? 'hier' : `il y a ${-n} jours`
}

const MONTHS_LONG = ['janvier', 'février', 'mars', 'avril', 'mai', 'juin', 'juillet', 'août', 'septembre', 'octobre', 'novembre', 'décembre']
export const monthLabel = (iso) => { const d = parse(iso); return `${MONTHS_LONG[d.getMonth()]} ${d.getFullYear()}` }

/** Bloc date : « 18–22 » + « janv. » (ou deux mois). */
export function dateBox(a, b) {
  const x = parse(a), y = parse(b || a)
  if (a === b || !b) return [String(x.getDate()), MONTHS[x.getMonth()]]
  if (x.getMonth() === y.getMonth()) return [`${x.getDate()}–${y.getDate()}`, MONTHS[x.getMonth()]]
  return [`${x.getDate()}–${y.getDate()}`, `${MONTHS[x.getMonth()]}/${MONTHS[y.getMonth()]}`]
}

const AVATAR_COLORS = ['#ffc93c', '#00c2a8', '#ffb3b5', '#cfc4ff', '#b8f0e7', '#ffe08a', '#a8d8ff']
export const avatarColor = (id) => AVATAR_COLORS[(Number(id) || 0) % AVATAR_COLORS.length]
export const initials = (first, last) => `${(first || '').charAt(0)}${(last || '').charAt(0)}`.toUpperCase() || '?'
