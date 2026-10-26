<script setup>
import { computed, reactive, ref, watch } from 'vue'
import { can, get, post, put, del, showError, toast, setCompany } from '../api.js'
import Avatar from '../components/Avatar.vue'
import Icon from '../components/Icon.vue'
import ListSelect from '../components/ListSelect.vue'

const props = defineProps({ id: String })
const company = ref(null)
const query = ref('')
const editing = ref(null)
const adding = ref(false)
const blank = () => ({ lastName: '', firstName: '', function: '', phone: '', email: '', diet: '', homeCity: '' })
const form = reactive(blank())
const FIELDS = [['lastName', 'Nom', 'text'], ['firstName', 'Prénom', 'text'], ['function', 'Fonction', 'text'], ['phone', 'Téléphone', 'tel'],
  ['email', 'E-mail', 'email'], ['homeCity', 'Ville de départ', 'text'], ['diet', 'Régime alimentaire', 'text']]

watch(() => props.id, async (id) => { setCompany(Number(id)); company.value = await get(`/companies/${id}`) }, { immediate: true })

const people = computed(() => {
  const q = query.value.trim().toLowerCase()
  return company.value.people.filter((p) => !q || [p.fullName, p.function, p.homeCity, p.email].join(' ').toLowerCase().includes(q))
})
function open(p) { adding.value = false; editing.value = p.id; Object.assign(form, p) }
function openNew() { editing.value = null; adding.value = true; Object.assign(form, blank()) }
const close = () => { editing.value = null; adding.value = false }
async function run(promise, msg) { try { company.value = await promise; toast(msg); close() } catch (e) { showError(e) } }
const save = () => adding.value ? run(post(`/companies/${props.id}/people`, form), `${form.firstName || form.lastName} ajouté(e) à l'annuaire`)
  : run(put(`/people/${editing.value}`, form), 'Fiche enregistrée')
const remove = (p) => confirm(`Supprimer ${p.fullName} ? La personne sera retirée de toutes les dates.`) && run(del(`/people/${p.id}`), 'Personne supprimée')
</script>

<template>
  <template v-if="company">
    <div class="page-head">
      <div><h1>Annuaire</h1><p>{{ company.people.length }} personne{{ company.people.length > 1 ? 's' : '' }}, communes à tous les spectacles de {{ company.name }}.</p></div>
      <button v-if="can('people.edit', company.permissions)" class="btn btn-primary" @click="openNew"><Icon name="plus" />Nouvelle personne</button>
    </div>
    <p v-if="!can('people.view', company.permissions)" class="alert" style="margin:0">Les coordonnées (téléphone, e-mail, régime) ne sont visibles qu'avec le droit « Voir les coordonnées ».</p>
    <label class="field" style="max-width:420px"><span class="sr-only">Rechercher</span>
      <input v-model="query" type="search" placeholder="Rechercher un nom, une fonction, une ville…"></label>

    <form v-if="adding" class="card warn" @submit.prevent="save">
      <h2 style="margin-bottom:14px">Nouvelle personne</h2>
      <div class="form-grid"><label v-for="[f, label, type] in FIELDS" :key="f" class="field">{{ label }}<ListSelect v-if="f === 'function'" v-model="form.function" kind="function" /><input v-else v-model="form[f]" :type="type" :required="f === 'lastName'"></label></div>
      <div class="actions" style="margin-top:14px"><button class="btn btn-primary">Ajouter</button><button type="button" class="btn btn-ghost" @click="close">Annuler</button></div>
    </form>

    <div class="card" style="padding:8px 20px">
      <div v-for="p in people" :key="p.id" style="border-bottom:1px solid var(--line);padding:12px 0">
        <form v-if="editing === p.id" @submit.prevent="save">
          <div class="form-grid"><label v-for="[f, label, type] in FIELDS" :key="f" class="field">{{ label }}<ListSelect v-if="f === 'function'" v-model="form.function" kind="function" /><input v-else v-model="form[f]" :type="type" :required="f === 'lastName'"></label></div>
          <div class="actions" style="margin-top:12px"><button class="btn btn-primary">Enregistrer</button><button type="button" class="btn btn-ghost" @click="close">Annuler</button>
            <button type="button" class="btn btn-danger" style="margin-left:auto" @click="remove(p)"><Icon name="trash" :size="16" />Supprimer</button></div>
        </form>
        <div v-else class="person-head">
          <Avatar :id="p.id" :initials="(p.firstName.charAt(0) + p.lastName.charAt(0)).toUpperCase()" :name="p.fullName" />
          <span class="who"><b>{{ p.fullName }}</b><span class="muted small">{{ [p.function, p.homeCity].filter(Boolean).join(' · ') || '—' }}</span></span>
          <span class="muted small" style="flex:1 1 200px;min-width:0">
            <a v-if="p.phone" :href="`tel:${p.phone.replace(/\s/g, '')}`">{{ p.phone }}</a><template v-if="p.phone && p.email"> · </template>
            <a v-if="p.email" :href="`mailto:${p.email}`">{{ p.email }}</a><template v-if="p.diet"> · {{ p.diet }}</template>
          </span>
          <button v-if="can('people.edit', company.permissions)" class="btn btn-sm btn-icon" :aria-label="`Modifier ${p.fullName}`" @click="open(p)"><Icon name="edit" :size="16" /></button>
        </div>
      </div>
      <p v-if="!people.length" class="empty">{{ company.people.length ? 'Aucun résultat.' : 'Ajoutez les artistes, techniciens et chargés de production de la compagnie.' }}</p>
    </div>
  </template>
</template>
