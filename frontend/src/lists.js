import { reactive } from 'vue'
import { get } from './api.js'

/** Listes paramétrées (Administration → Listes) : fonctions des personnes, intitulés de planning, modes de transport, types de dépense. Chargées une fois, rechargées après modification. */
export const lists = reactive({ function: [], schedule: [], mode: [], expense: [], link: [], modeClass: [], linkUrls: {}, defaults: {}, loaded: false })

let pending = null
export function loadLists(force = false) {
  if (lists.loaded && !force) return Promise.resolve(lists)
  pending = (!force && pending) || get('/lists').then((data) => { Object.assign(lists, data, { loaded: true }); return lists }).finally(() => { pending = null })
  return pending
}
export const setLists = (data) => Object.assign(lists, data, { loaded: true })
