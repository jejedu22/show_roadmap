<script setup>
import { ref, onMounted, nextTick } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { get, post, showError, lastCompany, toast, can } from '../api.js'

const props = defineProps({ stay: Boolean })
const route = useRoute()
const router = useRouter()
const companies = ref(null)
const name = ref('')
const nameInput = ref(null)

onMounted(async () => {
  companies.value = await get('/companies')
  if (!props.stay) {
    // Accueil : on reprend la dernière compagnie consultée (ou l'unique)
    const last = companies.value.find((c) => c.id === lastCompany()) || (companies.value.length === 1 ? companies.value[0] : null)
    if (last) return router.replace(`/companies/${last.id}`)
  }
  if (route.query.new) { await nextTick(); nameInput.value?.focus() }
})

async function create() {
  try {
    const c = await post('/companies', { name: name.value })
    window.dispatchEvent(new Event('companies-changed'))
    toast(`Compagnie « ${c.name} » créée`)
    router.push(`/companies/${c.id}`)
  } catch (e) { showError(e) }
}
</script>

<template>
  <div class="page-head">
    <div><h1>Compagnies</h1><p>Chaque compagnie a ses spectacles, son annuaire et ses dates de tournée.</p></div>
  </div>
  <div v-if="companies" class="tiles">
    <RouterLink v-for="c in companies" :key="c.id" class="tile calm" :to="`/companies/${c.id}`">
      <span class="eyebrow">{{ c.showCount }} spectacle{{ c.showCount > 1 ? 's' : '' }}</span>
      <span class="big">{{ c.name }}</span>
    </RouterLink>
  </div>
  <p v-if="companies && !companies.length" class="card empty">Vous n'avez encore accès à aucune compagnie. Demandez à un administrateur de vous ajouter à un groupe.</p>
  <form v-if="can('companies.create')" class="card" @submit.prevent="create">
    <h2 style="margin-bottom:14px">Nouvelle compagnie</h2>
    <div class="actions">
      <label class="field" style="flex:1 1 280px">Nom<input ref="nameInput" v-model="name" required placeholder="Nom de la compagnie"></label>
      <button class="btn btn-primary" style="align-self:flex-end">Créer la compagnie</button>
    </div>
    <p class="muted small" style="margin:10px 0 0">Vous y aurez accès automatiquement ; un administrateur peut ensuite y ajouter d'autres utilisateurs.</p>
  </form>
</template>
