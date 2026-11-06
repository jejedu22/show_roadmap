/**
 * Thème de couleurs choisi dans l'administration (Apparence) : les couleurs arrivent avec /api/config et remplacent
 * les variables CSS de style.css. Le dernier thème reçu est gardé dans le navigateur pour s'afficher dès l'ouverture.
 */
const VARS = {
  ink: '--ink', ink2: '--ink-2', ink3: '--ink-3',
  violet: '--violet', violetDark: '--violet-dark', violetSoft: '--violet-soft', violetMid: '--violet-mid',
  coral: '--coral', coralDark: '--coral-dark', coralSoft: '--coral-soft',
  sun: '--sun', sunSoft: '--sun-soft', sunDark: '--sun-dark',
  mint: '--mint', mintSoft: '--mint-soft', mintDark: '--mint-dark',
  bg: '--bg', line: '--line', line2: '--line-2', muted: '--muted', mutedOnDark: '--muted-on-dark',
}
const KEY = 'show-roadmap-theme'

export function applyTheme(theme, remember = true) {
  if (!theme?.colors) return
  const root = document.documentElement.style
  for (const [k, v] of Object.entries(theme.colors)) if (VARS[k] && /^#[0-9a-f]{6}$/i.test(v)) root.setProperty(VARS[k], v)
  document.querySelector('meta[name="theme-color"]')?.setAttribute('content', theme.colors.violet)
  if (remember) try { localStorage.setItem(KEY, JSON.stringify(theme)) } catch { /* stockage indisponible : sans effet */ }
}

/** Au démarrage, avant la réponse du serveur : le dernier thème connu. */
export function restoreTheme() {
  try { applyTheme(JSON.parse(localStorage.getItem(KEY) || 'null'), false) } catch { /* rien de mémorisé */ }
}
