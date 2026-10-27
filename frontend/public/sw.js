// Service worker de Show Roadmap : l'interface s'ouvre hors connexion, les données (API) ne sont jamais mises en cache
// (données personnelles, droits vérifiés à chaque appel).
// Version et liste des fichiers compilés : inscrites par le build (vite.config.js)
const VERSION = '__VERSION__'
const PRECACHE = [/* __PRECACHE__ */]
const SHELL = 'shell'
const ASSETS = `assets-${VERSION}`
const STATIC = ['/', '/manifest.webmanifest', '/icon.svg', '/icon-192.png', '/icon-512.png']

self.addEventListener('install', (e) => {
  e.waitUntil(Promise.all([caches.open(SHELL).then((c) => c.addAll(STATIC)), caches.open(ASSETS).then((c) => c.addAll(PRECACHE))])
    .then(() => self.skipWaiting()))
})

self.addEventListener('activate', (e) => {
  e.waitUntil(caches.keys()
    .then((keys) => Promise.all(keys.filter((k) => ![SHELL, ASSETS].includes(k)).map((k) => caches.delete(k))))
    .then(() => self.clients.claim()))
})

self.addEventListener('fetch', (e) => {
  const req = e.request
  const url = new URL(req.url)
  if (req.method !== 'GET' || url.origin !== location.origin || url.pathname.startsWith('/api')) return

  // Pages : réseau d'abord (nouvelle version dès qu'elle est en ligne), sinon l'interface en cache
  if (req.mode === 'navigate') {
    e.respondWith(fetch(req).then((res) => {
      if (res.ok) { const copy = res.clone(); caches.open(SHELL).then((c) => c.put('/', copy)) }
      return res
    }).catch(() => caches.match('/', { cacheName: SHELL })))
    return
  }

  // Scripts, styles, polices : noms versionnés et mis en cache à l'installation, donc cache d'abord
  if (url.pathname.startsWith('/assets/')) {
    e.respondWith(caches.match(req, { cacheName: ASSETS }).then((hit) => hit || fetch(req)))
    return
  }

  // Icônes et manifeste : cache, rafraîchi en arrière-plan
  if (STATIC.includes(url.pathname)) {
    e.respondWith(caches.open(SHELL).then(async (c) => {
      const hit = await c.match(req)
      const net = fetch(req).then((res) => { if (res.ok) c.put(req, res.clone()); return res }).catch(() => hit)
      return hit || net
    }))
  }
})
