import { reactive } from 'vue'

/** État global minimal : utilisateur connecté et options du serveur. */
const storedCompany = () => { try { return Number(localStorage.getItem('companyId')) || null } catch { return null } }
export const session = reactive({ user: null, google: false, passwordReset: false, sncf: false, sncfCoverage: null, loaded: false, toast: null, companyId: storedCompany() })

export class ApiError extends Error {
  constructor(message, status) { super(message); this.status = status }
}

let onUnauthorized = () => {}
export function setUnauthorizedHandler(fn) { onUnauthorized = fn }

/** Appel JSON de l'API. L'en-tête X-Requested-With est exigé par le serveur pour toute écriture (CSRF). */
export async function api(path, { method = 'GET', body } = {}) {
  const res = await fetch(`/api${path}`, {
    method,
    credentials: 'same-origin',
    headers: { 'X-Requested-With': 'XMLHttpRequest', ...(body !== undefined ? { 'Content-Type': 'application/json' } : {}) },
    body: body !== undefined ? JSON.stringify(body) : undefined,
  })
  const data = await res.json().catch(() => ({}))
  if (res.status === 401 && path !== '/login') {
    session.user = null
    onUnauthorized()
  }
  if (!res.ok) throw new ApiError(data.error || `Erreur ${res.status}`, res.status)
  return data
}

/** Envoi de fichiers (multipart) : même en-tête anti-CSRF, sans Content-Type (le navigateur pose la frontière). */
export async function upload(path, field, files) {
  const form = new FormData()
  for (const f of files) form.append(`${field}[]`, f)
  const res = await fetch(`/api${path}`, { method: 'POST', credentials: 'same-origin', headers: { 'X-Requested-With': 'XMLHttpRequest' }, body: form })
  const data = await res.json().catch(() => ({}))
  if (res.status === 401) { session.user = null; onUnauthorized() }
  if (res.status === 413) throw new ApiError('Envoi trop volumineux : importez les fichiers en plusieurs fois.', 413)
  if (!res.ok) throw new ApiError(data.error || `Erreur ${res.status}`, res.status)
  return data
}

export const get = (p) => api(p)
export const post = (p, body = {}) => api(p, { method: 'POST', body })
export const put = (p, body = {}) => api(p, { method: 'PUT', body })
export const del = (p) => api(p, { method: 'DELETE', body: {} })

export async function loadConfig() {
  const c = await get('/config')
  Object.assign(session, { user: c.user, google: c.google, passwordReset: c.passwordReset, sncf: c.sncf, sncfCoverage: c.sncfCoverage, loaded: true })
}

/** Notification brève : succès (« Enregistré ») ou erreur. */
export function toast(text, kind = 'ok') {
  session.toast = { text, kind }
  clearTimeout(toast.t)
  toast.t = setTimeout(() => { session.toast = null }, kind === 'error' ? 6000 : 2500)
}

export const showError = (e) => toast(e?.message || String(e), 'error')

/**
 * Droits : globaux (session.user.permissions) ou sur une compagnie / une date (liste « permissions » renvoyée par l'API).
 * Le serveur vérifie toujours : côté écran, cela ne sert qu'à masquer ce qui serait refusé.
 */
export const can = (perm, perms = session.user?.permissions) => !!session.user?.isAdmin || (perms || []).includes(perm)

/** Compagnie affichée dans le menu : mémorisée pour revenir dessus à la prochaine visite. */
export function setCompany(id) {
  session.companyId = id
  try { localStorage.setItem('companyId', String(id)) } catch { /* stockage indisponible */ }
}
export function lastCompany() {
  try { return Number(localStorage.getItem('companyId')) || null } catch { return null }
}
