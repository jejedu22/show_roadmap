/**
 * Thèmes de couleurs : chaque compagnie peut avoir le sien (Paramètres de la compagnie), pour reconnaître d'un coup d'œil
 * sur quelle compagnie on travaille ; le thème par défaut (Administration → Apparence) vaut pour la connexion,
 * l'administration et les compagnies sans thème. Les couleurs arrivent avec /api/config et remplacent les variables CSS
 * de style.css. Le dernier thème affiché est gardé dans le navigateur pour s'afficher dès l'ouverture.
 */
import { reactive } from 'vue'

const VARS = {
  ink: '--ink', ink2: '--ink-2', ink3: '--ink-3',
  violet: '--violet', violetDark: '--violet-dark', violetSoft: '--violet-soft', violetMid: '--violet-mid',
  coral: '--coral', coralDark: '--coral-dark', coralSoft: '--coral-soft',
  sun: '--sun', sunSoft: '--sun-soft', sunDark: '--sun-dark',
  mint: '--mint', mintSoft: '--mint-soft', mintDark: '--mint-dark',
  bg: '--bg', line: '--line', line2: '--line-2', muted: '--muted', mutedOnDark: '--muted-on-dark',
}
const KEY = 'show-roadmap-theme'

/** Catalogue des thèmes (clé → nom, couleurs) et thème par défaut, reçus du serveur. */
export const themes = reactive({ catalog: {}, defaultKey: 'violet' })

export function setThemes(catalog, defaultKey) {
  if (catalog) themes.catalog = catalog
  if (defaultKey) themes.defaultKey = defaultKey
}

export function applyTheme(theme, remember = true) {
  if (!theme?.colors) return
  const root = document.documentElement.style
  for (const [k, v] of Object.entries(theme.colors)) if (VARS[k] && /^#[0-9a-f]{6}$/i.test(v)) root.setProperty(VARS[k], v)
  document.querySelector('meta[name="theme-color"]')?.setAttribute('content', theme.colors.violet)
  if (remember) try { localStorage.setItem(KEY, JSON.stringify(theme)) } catch { /* stockage indisponible : sans effet */ }
}

/** Applique un thème par sa clé ; clé vide ou inconnue : le thème par défaut. */
export function applyThemeKey(key) {
  const k = themes.catalog[key] ? key : themes.defaultKey
  if (themes.catalog[k]) applyTheme({ key: k, colors: themes.catalog[k].colors })
}

/** Couleur principale du thème d'une compagnie (repère dans les listes de compagnies). */
export const themeColor = (key) => (themes.catalog[key] || themes.catalog[themes.defaultKey])?.colors.violet

/** Au démarrage, avant la réponse du serveur : le dernier thème affiché. */
export function restoreTheme() {
  try { applyTheme(JSON.parse(localStorage.getItem(KEY) || 'null'), false) } catch { /* rien de mémorisé */ }
}
