<script setup>
import { ref, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { session, get, post } from './api.js'

const route = useRoute()
const router = useRouter()
const companies = ref([])

watch(() => session.user?.id, async (id) => {
  companies.value = id ? await get('/companies').catch(() => []) : []
}, { immediate: true })

async function logout() {
  await post('/logout').catch(() => {})
  session.user = null
  router.push('/login')
}
// Rafraîchit la liste de la barre de navigation après création d'une compagnie.
defineExpose({ companies })
window.addEventListener('companies-changed', async () => { companies.value = await get('/companies').catch(() => []) })
</script>

<template>
  <header v-if="!route.meta.bare" class="top">
    <RouterLink class="brand" to="/">🎭 Show Roadmap</RouterLink>
    <nav v-if="session.user">
      <RouterLink v-for="c in companies" :key="c.id" :to="`/companies/${c.id}`">{{ c.name }}</RouterLink>
      <RouterLink v-if="session.user.isAdmin" to="/admin/users">Utilisateurs</RouterLink>
    </nav>
    <template v-if="session.user">
      <span class="muted small">{{ session.user.name || session.user.email }}</span>
      <RouterLink to="/account" class="small">Compte</RouterLink>
      <button class="sec" @click="logout">Déconnexion</button>
    </template>
  </header>
  <main v-if="!route.meta.bare"><RouterView /></main>
  <RouterView v-else />
  <div v-if="session.error" class="toast" role="alert">{{ session.error }}</div>
</template>
