import { reactive } from 'vue'

/** État global minimal : utilisateur connecté et options du serveur. */
export const session = reactive({ user: null, google: false, sncf: false, sncfCoverage: null, loaded: false, error: '' })

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

export const get = (p) => api(p)
export const post = (p, body = {}) => api(p, { method: 'POST', body })
export const put = (p, body = {}) => api(p, { method: 'PUT', body })
export const del = (p) => api(p, { method: 'DELETE', body: {} })

export async function loadConfig() {
  const c = await get('/config')
  Object.assign(session, { user: c.user, google: c.google, sncf: c.sncf, sncfCoverage: c.sncfCoverage, loaded: true })
}

/** Affiche brièvement une erreur (utilisé par les écrans pour les échecs d'enregistrement). */
export function showError(e) {
  session.error = e?.message || String(e)
  clearTimeout(showError.t)
  showError.t = setTimeout(() => { session.error = '' }, 5000)
}
