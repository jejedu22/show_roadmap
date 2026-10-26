<script setup>
import { reactive, ref, watch } from 'vue'
import { useRouter } from 'vue-router'
import { api, get, put, showError, toast, session, setCompany } from '../api.js'

const props = defineProps({ id: String })
const router = useRouter()
const company = ref(null)
const form = reactive({ name: '', notes: '' })
const confirmName = ref('')

watch(() => props.id, async (id) => {
  setCompany(Number(id))
  company.value = await get(`/companies/${id}`)
  Object.assign(form, { name: company.value.name, notes: company.value.notes })
}, { immediate: true })

async function save() {
  try {
    company.value = await put(`/companies/${props.id}`, form)
    window.dispatchEvent(new Event('companies-changed'))
    toast('Paramètres enregistrés')
  } catch (e) { showError(e) }
}
async function remove() {
  try {
    await api(`/companies/${props.id}`, { method: 'DELETE', body: { confirmName: confirmName.value } })
    window.dispatchEvent(new Event('companies-changed'))
    toast('Compagnie supprimée')
    router.push('/companies')
  } catch (e) { showError(e) }
}
</script>

<template>
  <template v-if="company">
    <div class="page-head"><div><h1>Paramètres</h1><p>{{ company.name }}</p></div></div>
    <form class="card" @submit.prevent="save">
      <div class="form-grid">
        <label class="field">Nom de la compagnie<input v-model="form.name" required></label>
        <label class="field wide">Notes<textarea v-model="form.notes" placeholder="Coordonnées administratives, licence, SIRET…" /></label>
      </div>
      <div class="actions" style="margin-top:16px"><button class="btn btn-primary">Enregistrer</button></div>
    </form>
    <section v-if="session.user.isAdmin" class="card" style="border:2px solid #f3b9bb">
      <h2 style="margin-bottom:6px">Supprimer la compagnie</h2>
      <p class="muted" style="margin:0 0 14px">Supprime définitivement <b>{{ company.name }}</b>, ses spectacles, dates, équipes et voyages. Pour confirmer, saisissez son nom.</p>
      <div class="actions">
        <label class="field" style="flex:1 1 260px"><span class="sr-only">Nom de la compagnie</span><input v-model="confirmName" :placeholder="company.name"></label>
        <button class="btn btn-danger" :disabled="confirmName !== company.name" @click="remove">Supprimer définitivement</button>
      </div>
    </section>
  </template>
</template>
