<script setup>
import { ref, reactive, watch } from 'vue'
import { get, post, put, del, showError } from '../api.js'

const props = defineProps({ id: String })
const company = ref(null)
const rows = reactive({})
const blank = () => ({ lastName: '', firstName: '', function: '', phone: '', email: '', diet: '', homeCity: '' })
const fresh = reactive(blank())
const FIELDS = [['lastName', 'NOM'], ['firstName', 'Prénom'], ['function', 'Fonction'], ['phone', 'Téléphone'], ['email', 'E-mail'], ['diet', 'Régime'], ['homeCity', 'Ville de départ']]

function load(c) {
  company.value = c
  for (const p of c.people) rows[p.id] = { ...p }
}
watch(() => props.id, async (id) => load(await get(`/companies/${id}`)), { immediate: true })

const run = async (p) => { try { load(await p); return true } catch (e) { showError(e) } }
const save = (p) => run(put(`/people/${p.id}`, rows[p.id]))
const remove = (p) => confirm(`Supprimer ${p.fullName} ? Elle sera retirée de toutes les dates.`) && run(del(`/people/${p.id}`))
async function add() { if (await run(post(`/companies/${props.id}/people`, fresh))) Object.assign(fresh, blank()) }
</script>

<template>
  <template v-if="company">
    <h1>{{ company.name }}</h1>
    <div class="tabs">
      <RouterLink :to="`/companies/${company.id}`">Planning</RouterLink>
      <RouterLink class="on" :to="`/companies/${company.id}/people`">Équipe de la compagnie</RouterLink>
    </div>
    <p class="muted">Annuaire commun à tous les spectacles de la compagnie. Une personne peut être affectée à plusieurs dates, y compris simultanées (un avertissement est alors affiché).</p>
    <div class="card scroll">
      <table>
        <thead><tr><th v-for="[, label] in FIELDS" :key="label">{{ label }}</th><th></th></tr></thead>
        <tbody>
          <tr v-for="p in company.people" :key="p.id">
            <td v-for="[f] in FIELDS" :key="f"><input v-model="rows[p.id][f]" :required="f === 'lastName'" @keyup.enter="save(p)"></td>
            <td style="white-space:nowrap"><button @click="save(p)">OK</button> <button class="danger" @click="remove(p)">✕</button></td>
          </tr>
          <tr>
            <td v-for="[f, label] in FIELDS" :key="f"><input v-model="fresh[f]" :placeholder="label" @keyup.enter="add"></td>
            <td><button :disabled="!fresh.lastName" @click="add">Ajouter</button></td>
          </tr>
        </tbody>
      </table>
    </div>
  </template>
</template>
