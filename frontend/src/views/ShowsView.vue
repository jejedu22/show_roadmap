<script setup>
import { reactive, ref, watch } from 'vue'
import { get, post, put, del, showError, toast, setCompany } from '../api.js'
import { daysFrom, fperiod } from '../format.js'
import Icon from '../components/Icon.vue'
import NewEventDialog from '../components/NewEventDialog.vue'

const props = defineProps({ id: String })
const company = ref(null)
const editing = ref(null)
const edit = reactive({ title: '', description: '' })
const fresh = reactive({ title: '', description: '' })
const newDateFor = ref(null)

watch(() => props.id, async (id) => { setCompany(Number(id)); company.value = await get(`/companies/${id}`) }, { immediate: true })

const eventsOf = (s) => company.value.events.filter((e) => e.showId === s.id)
const run = async (promise, msg) => { try { company.value = await promise; toast(msg); return true } catch (e) { showError(e) } }
function startEdit(s) { editing.value = s.id; Object.assign(edit, { title: s.title, description: s.description }) }
async function save(s) { if (await run(put(`/shows/${s.id}`, edit), 'Spectacle enregistré')) editing.value = null }
const remove = (s) => confirm(`Supprimer « ${s.title} » ?`) && run(del(`/shows/${s.id}`), 'Spectacle supprimé')
async function add() { if (await run(post(`/companies/${props.id}/shows`, fresh), `« ${fresh.title} » ajouté`)) Object.assign(fresh, { title: '', description: '' }) }
</script>

<template>
  <template v-if="company">
    <div class="page-head"><div><h1>Spectacles</h1><p>Le répertoire de {{ company.name }}.</p></div></div>
    <article v-for="s in company.shows" :key="s.id" class="card">
      <form v-if="editing === s.id" @submit.prevent="save(s)">
        <div class="form-grid">
          <label class="field">Titre<input v-model="edit.title" required></label>
          <label class="field">Description<input v-model="edit.description"></label>
        </div>
        <div class="actions" style="margin-top:12px"><button class="btn btn-primary">Enregistrer</button><button type="button" class="btn btn-ghost" @click="editing = null">Annuler</button></div>
      </form>
      <template v-else>
        <div class="card-head" style="margin-bottom:6px">
          <div><h2>{{ s.title }}</h2><p v-if="s.description" class="muted" style="margin:4px 0 0">{{ s.description }}</p></div>
          <div class="actions">
            <button class="btn btn-sm btn-primary" @click="newDateFor = s.id"><Icon name="plus" :size="16" />Nouvelle date</button>
            <button class="btn btn-sm btn-icon" :aria-label="`Modifier ${s.title}`" @click="startEdit(s)"><Icon name="edit" :size="16" /></button>
            <button v-if="!s.eventCount" class="btn btn-sm btn-icon btn-danger" :aria-label="`Supprimer ${s.title}`" @click="remove(s)"><Icon name="trash" :size="16" /></button>
          </div>
        </div>
        <ul v-if="eventsOf(s).length" style="list-style:none;padding:0;margin:8px 0 0;display:flex;flex-direction:column;gap:4px">
          <li v-for="e in eventsOf(s)" :key="e.id">
            <RouterLink :to="`/events/${e.id}`">{{ fperiod(e.firstDate, e.lastDate) }} — {{ e.venue }}<template v-if="e.city"> ({{ e.city }})</template></RouterLink>
            <span v-if="daysFrom(e.end) < 0" class="muted small"> · passée</span>
          </li>
        </ul>
        <p v-else class="muted small" style="margin:8px 0 0">Aucune date pour l'instant.</p>
      </template>
    </article>
    <form class="card" @submit.prevent="add">
      <h2 style="margin-bottom:14px">Nouveau spectacle</h2>
      <div class="form-grid">
        <label class="field">Titre<input v-model="fresh.title" required></label>
        <label class="field">Description<input v-model="fresh.description"></label>
      </div>
      <div class="actions" style="margin-top:12px"><button class="btn btn-primary">Ajouter le spectacle</button></div>
    </form>
    <NewEventDialog :open="!!newDateFor" :company="company" :show-id="newDateFor" @close="newDateFor = null" />
  </template>
</template>
