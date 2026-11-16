/** Devis initial : passage des valeurs (API ou lecture du PDF) au formulaire, et lecture des nombres saisis (« 1 100,50 »). */
const txt = (x) => (x ?? '') === '' ? '' : String(x).replace('.', ',')

export function toForm(s = {}, keep = {}) {
  s = s || {}
  return {
    number: s.number || '', date: s.date || '', signedOn: s.signedOn ?? keep.signedOn ?? '',
    show: s.show || '', organizer: s.organizer || '', period: s.period || '',
    performances: txt(s.performances), cession: txt(s.cession),
    lunches: txt(s.lunches), dinners: txt(s.dinners), meals: txt(s.meals), nights: txt(s.nights), allowances: txt(s.allowances),
    lines: (s.lines || s.teamLines || []).map((l) => ({ label: l.label || '', detail: l.detail || '', amount: txt(l.amount) })),
    teamTravel: txt(s.teamTravel), decor: txt(s.decor), totalHt: txt(s.totalHt), totalTtc: txt(s.totalTtc),
  }
}

/** Nombre saisi, ou null si vide / illisible */
export function num(v) {
  const s = String(v ?? '').replace(/[\s  ]/g, '').replace(',', '.')
  return s === '' || isNaN(Number(s)) ? null : Number(s)
}
