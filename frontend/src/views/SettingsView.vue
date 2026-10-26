<script setup>
import { reactive, ref, watch } from 'vue'
import { useRouter } from 'vue-router'
import { api, can, get, put, del, showError, toast, setCompany } from '../api.js'

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
// Orfeo : la clé est envoyée une fois, vérifiée par le serveur puis stockée chiffrée ; elle n'est jamais réaffichée
const orfeoKey = ref('')
const orfeoBusy = ref(false)
async function connectOrfeo() {
  orfeoBusy.value = true
  try {
    company.value = await put(`/companies/${props.id}/orfeo`, { token: orfeoKey.value.trim() })
    orfeoKey.value = ''
    toast(`Relié à Orfeo (${company.value.orfeo.organization})`)
  } catch (e) { showError(e) } finally { orfeoBusy.value = false }
}
async function disconnectOrfeo() {
  if (!confirm('Délier la compagnie d\'Orfeo ? Les dates déjà importées sont conservées.')) return
  try { company.value = await del(`/companies/${props.id}/orfeo`); toast('Compagnie déliée d\'Orfeo') } catch (e) { showError(e) }
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
    <form class="card" @submit.prevent="save"><fieldset :disabled="!can('company.manage', company.permissions)" style="border:0;padding:0;margin:0">
      <div class="form-grid">
        <label class="field">Nom de la compagnie<input v-model="form.name" required></label>
        <label class="field wide">Notes<textarea v-model="form.notes" placeholder="Coordonnées administratives, licence, SIRET…" /></label>
      </div>
      <div class="actions" style="margin-top:16px"><button class="btn btn-primary">Enregistrer</button></div>
    </fieldset></form>
    <section v-if="can('company.manage', company.permissions)" class="card">
      <div class="card-head" style="margin-bottom:6px">
        <h2>Orfeo</h2>
        <span v-if="company.orfeo.connected" class="chip ok">Relié à {{ company.orfeo.organization }}</span>
        <span v-else class="chip dark">Non relié</span>
      </div>
      <p class="muted" style="margin:0 0 12px">Reliez la compagnie à son compte <a href="https://orfeo.pro/" target="_blank" rel="noopener">Orfeo</a> pour récupérer ses dates, lieux, équipes et plannings depuis « Importer des devis ».
        La clé se crée dans Orfeo : <b>Paramètres → API &amp; webhooks</b>. Elle est vérifiée puis stockée chiffrée, et n'est jamais réaffichée.</p>
      <form class="actions" @submit.prevent="connectOrfeo">
        <label class="field" style="flex:1 1 280px">{{ company.orfeo.connected ? 'Nouvelle clé d\'API (pour la remplacer)' : 'Clé d\'API Orfeo' }}
          <input v-model="orfeoKey" type="password" autocomplete="off" spellcheck="false" required minlength="8" placeholder="Collez la clé ici"></label>
        <button class="btn btn-primary" style="align-self:flex-end" :disabled="orfeoBusy">{{ orfeoBusy ? 'Vérification…' : (company.orfeo.connected ? 'Remplacer la clé' : 'Relier à Orfeo') }}</button>
        <button v-if="company.orfeo.connected" type="button" class="btn btn-danger" style="align-self:flex-end" @click="disconnectOrfeo">Délier</button>
      </form>
    </section>
    <section v-if="can('companies.delete')" class="card" style="border:2px solid #f3b9bb">
      <h2 style="margin-bottom:6px">Supprimer la compagnie</h2>
      <p class="muted" style="margin:0 0 14px">Supprime définitivement <b>{{ company.name }}</b>, ses spectacles, dates, équipes et voyages. Pour confirmer, saisissez son nom.</p>
      <div class="actions">
        <label class="field" style="flex:1 1 260px"><span class="sr-only">Nom de la compagnie</span><input v-model="confirmName" :placeholder="company.name"></label>
        <button class="btn btn-danger" :disabled="confirmName !== company.name" @click="remove">Supprimer définitivement</button>
      </div>
    </section>
  </template>
</template>
