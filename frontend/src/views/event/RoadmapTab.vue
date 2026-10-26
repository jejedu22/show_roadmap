<script setup>
import { inject, reactive, ref, watch } from 'vue'
import { post, put, del, toast } from '../../api.js'
import { fdate } from '../../format.js'
import Icon from '../../components/Icon.vue'

const { ev, apply } = inject('event')
const slots = reactive({})
const sections = reactive({})
const newSlot = ref({})
const newSection = ref({})
const editing = ref(null)
const TITLES = ['ATELIERS', 'REPAS', 'TRANSFERTS', 'ACCÈS AU THÉÂTRE', 'WIFI', 'CONTACTS SUR PLACE']
watch(ev, (e) => {
  for (const s of e.schedule) slots[s.id] = { ...s }
  for (const s of e.sections) sections[s.id] = { ...s }
  newSlot.value = { day: newSlot.value.day || e.firstDate, time: '', label: '' }
  newSection.value = { title: '', body: '', position: (e.sections.length + 1) * 10 }
}, { immediate: true })

const url = (kind, id = '') => `/events/${ev.value.id}/items/${kind}${id ? '/' + id : ''}`
const saved = async (p, msg = 'Feuille de route mise à jour') => { if (await apply(p)) { toast(msg); return true } }
async function saveSection(s) { if (await saved(put(url('section', s.id), sections[s.id]))) editing.value = null }
</script>

<template>
  <div class="two-cols">
    <div class="main-col">
      <section class="card">
        <h2 style="margin-bottom:12px">Planning sur place</h2>
        <form v-for="s in ev.schedule" :key="s.id" class="actions" style="margin-bottom:8px" @submit.prevent="saved(put(url('schedule', s.id), slots[s.id]))">
          <label class="field" style="flex:1 1 140px">Jour<input v-model="slots[s.id].day" type="date" required></label>
          <label class="field" style="flex:1 1 100px">Heure<input v-model="slots[s.id].time"></label>
          <label class="field" style="flex:3 1 220px">Intitulé<input v-model="slots[s.id].label" required></label>
          <span class="actions" style="align-self:flex-end"><button class="btn btn-sm">OK</button>
            <button type="button" class="btn btn-sm btn-icon btn-danger" :aria-label="`Supprimer ${s.label}`" @click="saved(del(url('schedule', s.id)))"><Icon name="trash" :size="16" /></button></span>
        </form>
        <form class="actions" @submit.prevent="saved(post(url('schedule'), newSlot))">
          <label class="field" style="flex:1 1 140px">Jour<input v-model="newSlot.day" type="date" required></label>
          <label class="field" style="flex:1 1 100px">Heure<input v-model="newSlot.time" placeholder="10h–13h"></label>
          <label class="field" style="flex:3 1 220px">Intitulé<input v-model="newSlot.label" required placeholder="Montage, répétition, représentation…"></label>
          <button class="btn btn-sm" style="align-self:flex-end"><Icon name="plus" :size="16" />Ajouter</button>
        </form>
      </section>

      <section class="card">
        <h2 style="margin-bottom:4px">Infos pratiques</h2>
        <p class="muted small" style="margin:0 0 12px">Ateliers, repas, transferts, accès, wifi… dans l'ordre de leur position.</p>
        <article v-for="s in ev.sections" :key="s.id" style="border-top:1px solid var(--line);padding:12px 0">
          <form v-if="editing === s.id" style="display:flex;flex-direction:column;gap:10px" @submit.prevent="saveSection(s)">
            <div class="form-grid"><label class="field">Titre<input v-model="sections[s.id].title" required></label><label class="field">Position<input v-model="sections[s.id].position" type="number"></label></div>
            <label class="field">Contenu<textarea v-model="sections[s.id].body" rows="5" /></label>
            <div class="actions"><button class="btn btn-primary">Enregistrer</button><button type="button" class="btn btn-ghost" @click="editing = null">Annuler</button>
              <button type="button" class="btn btn-danger" style="margin-left:auto" @click="saved(del(url('section', s.id)))">Supprimer</button></div>
          </form>
          <template v-else>
            <div class="card-head" style="margin-bottom:2px"><h3>{{ s.title }}</h3>
              <button class="btn btn-sm btn-icon" :aria-label="`Modifier ${s.title}`" @click="editing = s.id"><Icon name="edit" :size="16" /></button></div>
            <p class="small" style="margin:0;white-space:pre-wrap">{{ s.body }}</p>
          </template>
        </article>
        <form style="border-top:1px solid var(--line);padding-top:12px;display:flex;flex-direction:column;gap:10px" @submit.prevent="saved(post(url('section'), newSection))">
          <datalist id="sec-titles"><option v-for="t in TITLES" :key="t" :value="t" /></datalist>
          <div class="form-grid"><label class="field">Nouveau bloc<input v-model="newSection.title" list="sec-titles" required></label><label class="field">Position<input v-model="newSection.position" type="number"></label></div>
          <label class="field">Contenu<textarea v-model="newSection.body" rows="3" /></label>
          <button class="btn" style="align-self:flex-start"><Icon name="plus" :size="16" />Ajouter le bloc</button>
        </form>
      </section>
    </div>

    <aside class="side-col">
      <section class="card dark">
        <span class="eyebrow" style="color:var(--sun)">Feuille de route</span>
        <h2 style="color:#fff;margin:6px 0 8px">{{ ev.share.sharedAt ? `Partagée le ${fdate(ev.share.sharedAt.slice(0, 10), true)}` : 'Prête à partager ?' }}</h2>
        <p class="small" style="color:var(--muted-on-dark);margin:0 0 14px">Voyages aller, hébergement, planning, infos pratiques, puis voyages retour — avec les boutons « Billet ».</p>
        <div class="actions">
          <RouterLink class="btn btn-sun" :to="`/events/${ev.id}/print/roadmap`" target="_blank"><Icon name="file" :size="16" />Voir le PDF</RouterLink>
          <a class="btn" :href="`/api/events/${ev.id}/pdf/roadmap?download=1`"><Icon name="download" :size="16" />Télécharger</a>
        </div>
      </section>
      <section class="card">
        <h3 style="margin-bottom:8px">Autres documents</h3>
        <div style="display:flex;flex-direction:column;gap:6px">
          <RouterLink v-for="[doc, label] in [['contacts', 'Coordonnées de l\'équipe'], ['rooming', 'Rooming-list'], ['perdiem', 'Défraiements'], ['travelcost', 'Suivi des frais de voyage'], ['quote', 'Devis']]"
            :key="doc" :to="`/events/${ev.id}/print/${doc}`" target="_blank">{{ label }} (PDF)</RouterLink>
        </div>
      </section>
    </aside>
  </div>
</template>
