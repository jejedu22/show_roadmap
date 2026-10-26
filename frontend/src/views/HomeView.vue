<script setup>
import { ref, onMounted } from 'vue'
import { useRouter } from 'vue-router'
import { get, post, showError } from '../api.js'

const props = defineProps({ stay: Boolean })
const router = useRouter()
const companies = ref(null)
const name = ref('')

onMounted(async () => {
  companies.value = await get('/companies')
  // Accès direct à l'unique compagnie depuis l'accueil ; la page « Compagnies » reste accessible par le menu
  if (!props.stay && companies.value.length === 1) router.replace(`/companies/${companies.value[0].id}`)
})

async function create() {
  try {
    const c = await post('/companies', { name: name.value })
    window.dispatchEvent(new Event('companies-changed'))
    router.push(`/companies/${c.id}`)
  } catch (e) { showError(e) }
}
</script>

<template>
  <h1>Mes compagnies</h1>
  <div v-if="companies" class="grid g3">
    <RouterLink v-for="c in companies" :key="c.id" class="card" :to="`/companies/${c.id}`" style="text-decoration:none;color:inherit">
      <b>{{ c.name }}</b><div class="muted small">{{ c.showCount }} spectacle(s)</div>
    </RouterLink>
    <p v-if="!companies.length" class="muted">Aucune compagnie. Créez-en une ci-dessous.</p>
  </div>
  <form class="card grid g2" @submit.prevent="create">
    <div><label>Nouvelle compagnie</label><input v-model="name" required placeholder="Nom de la compagnie"></div>
    <div style="align-self:end"><button>Créer</button></div>
  </form>
</template>
