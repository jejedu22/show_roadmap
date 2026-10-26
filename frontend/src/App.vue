<script setup>
import { computed, ref, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { session, get, post, can } from './api.js'
import Icon from './components/Icon.vue'

const route = useRoute()
const router = useRouter()
const companies = ref([])
const menuOpen = ref(false)
const switcherOpen = ref(false)

async function loadCompanies() { companies.value = session.user ? await get('/companies').catch(() => []) : [] }
watch(() => session.user?.id, loadCompanies, { immediate: true })
window.addEventListener('companies-changed', loadCompanies)
watch(() => route.fullPath, () => { menuOpen.value = false; switcherOpen.value = false })

const current = computed(() => companies.value.find((c) => c.id === session.companyId) || null)
const shell = computed(() => session.user && !route.meta.bare)
const cid = computed(() => current.value?.id)
const NAV = computed(() => [['', 'Tableau de bord', 'dashboard'], ['/calendar', 'Calendrier', 'calendar'], ['/shows', 'Spectacles', 'shows'],
  ['/people', 'Annuaire', 'people'],
  ...(can('events.edit', current.value?.permissions) && can('people.edit', current.value?.permissions) ? [['/import', 'Importer des devis', 'upload']] : []),
  ...(can('company.manage', current.value?.permissions) || can('companies.delete') ? [['/settings', 'Paramètres', 'settings']] : [])])
const ADMIN = computed(() => [['/admin/users', 'Utilisateurs', 'user', 'users.manage'], ['/admin/groups', 'Groupes et droits', 'people', 'groups.manage'],
  ['/admin/lists', 'Listes', 'menu', 'lists.manage'], ['/admin/audit', 'Journal', 'file', 'users.manage']].filter(([, , , perm]) => can(perm)))
const isActive = (suffix) => suffix === '' ? route.path === `/companies/${cid.value}` || route.path.startsWith('/events/') : route.path === `/companies/${cid.value}${suffix}`
const abbr = (name) => name.split(/\s+/).filter((w) => w.length > 2 || /^[A-Z]/.test(w)).slice(0, 2).map((w) => w[0]).join('').toUpperCase() || name.slice(0, 2).toUpperCase()

async function logout() {
  await post('/logout').catch(() => {})
  session.user = null
  router.push('/login')
}
</script>

<template>
  <div v-if="shell" class="shell">
    <div class="topbar">
      <RouterLink class="brand" to="/" style="display:flex"><span class="brand-mark"><Icon name="logo" :size="20" style="color:#1b1b3a" /></span>Show Roadmap</RouterLink>
      <button type="button" :aria-expanded="menuOpen" aria-controls="sidebar" aria-label="Menu" @click="menuOpen = !menuOpen"><Icon :name="menuOpen ? 'close' : 'menu'" :size="22" /></button>
    </div>
    <nav id="sidebar" class="sidebar" :class="{ open: menuOpen }" aria-label="Navigation principale">
      <RouterLink class="brand" to="/"><span class="brand-mark"><Icon name="logo" :size="20" style="color:#1b1b3a" /></span>Show Roadmap</RouterLink>

      <div style="display:flex;flex-direction:column;gap:6px">
        <span class="side-label">Compagnie</span>
        <div class="switcher">
          <button type="button" :aria-expanded="switcherOpen" @click="switcherOpen = !switcherOpen">
            <span style="display:flex;align-items:center;gap:10px;min-width:0">
              <span class="brand-mark" style="width:26px;height:26px;border-radius:8px;background:var(--coral);font-size:11px;font-weight:800;color:var(--ink)">{{ current ? abbr(current.name) : '?' }}</span>
              <span style="overflow:hidden;text-overflow:ellipsis;white-space:nowrap">{{ current?.name || 'Choisir une compagnie' }}</span>
            </span>
            <Icon name="chevron" :size="16" />
          </button>
          <div v-if="switcherOpen" class="switcher-menu">
            <RouterLink v-for="c in companies" :key="c.id" :to="`/companies/${c.id}`" :class="{ current: c.id === cid }">{{ c.name }}</RouterLink>
            <hr>
            <RouterLink to="/companies">Toutes les compagnies</RouterLink>
          </div>
        </div>
        <RouterLink v-if="can('companies.create')" class="side-add" to="/companies?new=1"><Icon name="plus" :size="16" />Nouvelle compagnie</RouterLink>
      </div>

      <div v-if="cid" class="side-nav">
        <RouterLink v-for="[suffix, label, icon] in NAV" :key="label" :to="`/companies/${cid}${suffix}`" :class="{ active: isActive(suffix) }"
          :aria-current="isActive(suffix) ? 'page' : null"><Icon :name="icon" />{{ label }}</RouterLink>
      </div>
      <div v-if="ADMIN.length" class="side-nav">
        <span class="side-label" style="margin-top:4px">Administration</span>
        <RouterLink v-for="[path, label, icon] in ADMIN" :key="path" :to="path" :class="{ active: route.path === path }"><Icon :name="icon" />{{ label }}</RouterLink>
      </div>

      <div class="side-user">
        <span class="avatar" style="background:var(--mint)">{{ (session.user.name || session.user.email).slice(0, 2).toUpperCase() }}</span>
        <span style="display:flex;flex-direction:column;min-width:0">
          <b style="overflow:hidden;text-overflow:ellipsis;white-space:nowrap">{{ session.user.name || session.user.email }}</b>
          <span style="display:flex;gap:10px"><RouterLink to="/account">Mon compte</RouterLink><button type="button" @click="logout">Déconnexion</button></span>
        </span>
      </div>
      <p class="side-legal"><RouterLink to="/mentions-legales">Mentions légales</RouterLink> · <RouterLink to="/confidentialite">Confidentialité</RouterLink></p>
    </nav>
    <main class="content"><div class="content-inner"><RouterView /></div></main>
  </div>
  <RouterView v-else />
  <div v-if="session.toast" class="toast" :class="session.toast.kind" role="status" aria-live="polite">{{ session.toast.text }}</div>
</template>
