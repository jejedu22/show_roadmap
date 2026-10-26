import { reactive } from 'vue'
import { get } from './api.js'

/** Informations légales de l'instance (éditeur, hébergeur, contact RGPD, code source), publiques. */
export const legal = reactive({ loaded: false, configured: false, publisher: {}, host: {}, privacyEmail: '', sourceUrl: '' })

let pending = null
export function loadLegal() {
  if (legal.loaded) return Promise.resolve(legal)
  pending ??= get('/legal').then((d) => Object.assign(legal, d, { loaded: true })).catch(() => {}).finally(() => { pending = null })
  return pending
}
