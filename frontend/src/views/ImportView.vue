<script setup>
import { computed, ref, watch } from 'vue'
import { can, get, post, upload, showError, toast, setCompany } from '../api.js'
import { fperiod, money } from '../format.js'
import Icon from '../components/Icon.vue'

const props = defineProps({ id: String })
const company = ref(null)
const step = ref('drop')        // drop → review → done
const busy = ref(false)
const dragging = ref(false)
const batch = ref(null)         // { id, files: [...] } renvoyé par l'analyse
const forms = ref({})           // corrections saisies, par index de fichier
const result = ref(null)
const input = ref(null)

watch(() => props.id, async (id) => {
  setCompany(Number(id))
  company.value = await get(`/companies/${id}`)
  reset()
}, { immediate: true })

const allowed = computed(() => company.value && can('events.edit', company.value.permissions) && can('people.edit', company.value.permissions))
const canCreateShow = computed(() => can('shows.edit', company.value?.permissions))
const files = computed(() => batch.value?.files || [])
const chosen = computed(() => files.value.filter((f) => !f.error && forms.value[f.index].include))
const LEVEL = { danger: 'bad', warning: 'warn', info: 'info' }

function reset() {
  step.value = 'drop'
  batch.value = null
  forms.value = {}
  result.value = null
}

async function send(list) {
  const xlsx = [...list].filter((f) => /\.xlsx$/i.test(f.name))
  if (!xlsx.length) return showError('Déposez des classeurs Excel (.xlsx).')
  busy.value = true
  try {
    batch.value = await upload(`/companies/${props.id}/import`, 'files', xlsx)
    forms.value = Object.fromEntries(batch.value.files.filter((f) => !f.error).map((f) => [f.index, {
      include: f.defaults.include, includeRoadmap: f.defaults.includeRoadmap,
      showId: f.show.id || '', showTitle: f.show.title,
      ...Object.fromEntries(['venue', 'city', 'address', 'firstDate', 'lastDate', 'techArrival', 'artisticArrival', 'departure', 'performances', 'status']
        .map((k) => [k, f.event[k] ?? ''])),
    }]))
    step.value = 'review'
  } catch (e) { showError(e) } finally {
    busy.value = false
    if (input.value) input.value.value = ''
  }
}

function drop(e) {
  dragging.value = false
  send(e.dataTransfer.files)
}

async function commit() {
  busy.value = true
  try {
    const payload = Object.fromEntries(chosen.value.map((f) => {
      const v = forms.value[f.index]
      return [f.index, { ...v, showId: v.showId || null, techArrival: v.techArrival || null, artisticArrival: v.artisticArrival || null, departure: v.departure || null }]
    }))
    result.value = await post(`/companies/${props.id}/import/${batch.value.id}`, { files: payload })
    step.value = 'done'
    toast(`${result.value.created.length} date${result.value.created.length > 1 ? 's' : ''} importée${result.value.created.length > 1 ? 's' : ''}`)
  } catch (e) {
    showError(e)
    if (e.status === 404) reset()
  } finally { busy.value = false }
}

const plural = (n, word) => `${n} ${word}${n > 1 ? 's' : ''}`
function counts(c) {
  return [
    `${plural(c.team, 'personne')}${c.newPeople ? ` (${c.newPeople} à créer dans l'annuaire)` : ''}`,
    plural(c.nights, 'nuitée'), `${c.meals} repas`,
    c.tickets ? `billets ${money(c.tickets)}` : null,
  ].filter(Boolean).join(' · ')
}
function roadmapCounts(c) {
  return [plural(c.legs, 'trajet'), plural(c.lodgings, 'hébergement'), plural(c.sections, 'rubrique'), `${c.schedule} ligne${c.schedule > 1 ? 's' : ''} de planning`].join(' · ')
}
</script>

<template>
  <template v-if="company">
    <header class="page-head">
      <div>
        <h1>Importer des devis</h1>
        <p>Reprenez vos classeurs « Devis_Spectacle_Ville.xlsx » : rien n'est enregistré avant votre validation.</p>
      </div>
      <button v-if="step !== 'drop'" class="btn" @click="reset"><Icon name="upload" />Autres fichiers</button>
    </header>

    <div v-if="!allowed" class="alert bad">Il faut les droits « Modifier les dates » et « Modifier l'annuaire » sur {{ company.name }} pour importer.</div>

    <!-- 1. Dépôt -->
    <template v-else-if="step === 'drop'">
      <label class="dropzone" :class="{ over: dragging, busy }" @dragover.prevent="dragging = true" @dragleave.prevent="dragging = false" @drop.prevent="drop">
        <input ref="input" type="file" accept=".xlsx,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet" multiple class="sr-only" :disabled="busy" @change="send($event.target.files)">
        <span class="dropzone-icon"><Icon name="upload" :size="28" /></span>
        <b v-if="busy">Lecture des classeurs…</b>
        <template v-else>
          <b>Glissez vos classeurs ici</b>
          <span class="muted">ou <u>choisissez-les sur l'ordinateur</u> — jusqu'à 20 fichiers .xlsx de 10 Mo</span>
        </template>
      </label>
      <section class="card import-help">
        <h2>Ce qui est repris</h2>
        <ul>
          <li><b>Onglet DEVIS</b> : lieu, dates, distribution, villes de départ et prix des billets, rooming, repas, petits-déjeuners, cachet, camion.</li>
          <li><b>Coordonnées</b> : téléphones, e-mails et régimes, ajoutés à l'annuaire sans écraser ce qui y est déjà.</li>
          <li><b>Feuille de route</b> : trajets aller et retour, hébergements, rubriques pratiques et planning.</li>
        </ul>
        <p class="muted small">Chaque classeur est vérifié : copie d'un autre fichier, feuille de route restée sur une autre date, date déjà présente… Vous corrigez avant d'enregistrer.</p>
      </section>
    </template>

    <!-- 2. Relecture -->
    <template v-else-if="step === 'review'">
      <article v-for="f in files" :key="f.index" class="card import-file" :class="{ off: f.error || !forms[f.index].include }">
        <template v-if="f.error">
          <div class="card-head" style="margin:0"><h2 class="file-name"><Icon name="file" />{{ f.file }}</h2><span class="chip bad">Non lisible</span></div>
          <p class="muted" style="margin:8px 0 0">{{ f.error }}</p>
        </template>
        <template v-else>
          <div class="card-head">
            <label class="check file-toggle"><input v-model="forms[f.index].include" type="checkbox"><h2 class="file-name">{{ f.file }}</h2></label>
            <span class="chip" :class="forms[f.index].include ? 'ok' : 'dark'">{{ forms[f.index].include ? 'À importer' : 'Ignoré' }}</span>
          </div>
          <ul v-if="f.warnings.length" class="import-warnings">
            <li v-for="(w, i) in f.warnings" :key="i" class="alert" :class="LEVEL[w.level]">{{ w.message }}</li>
          </ul>
          <template v-if="forms[f.index].include">
            <div class="form-grid">
              <label class="field">Spectacle
                <select v-model="forms[f.index].showId">
                  <option v-for="s in company.shows" :key="s.id" :value="s.id">{{ s.title }}</option>
                  <option v-if="canCreateShow" value="">Nouveau spectacle…</option>
                </select>
              </label>
              <label v-if="!forms[f.index].showId" class="field">Titre du nouveau spectacle<input v-model="forms[f.index].showTitle" required></label>
              <label class="field">Lieu<input v-model="forms[f.index].venue" required></label>
              <label class="field">Ville<input v-model="forms[f.index].city"></label>
              <label class="field wide">Adresse<input v-model="forms[f.index].address"></label>
              <label class="field">1re représentation<input v-model="forms[f.index].firstDate" type="date" required></label>
              <label class="field">Dernière représentation<input v-model="forms[f.index].lastDate" type="date"></label>
              <label class="field">Représentations<input v-model.number="forms[f.index].performances" type="number" min="1" max="99"></label>
              <label class="field">Statut
                <select v-model="forms[f.index].status"><option value="confirmed">Confirmé</option><option value="option">Option</option></select>
              </label>
              <label class="field">Arrivée technique<input v-model="forms[f.index].techArrival" type="date"></label>
              <label class="field">Arrivée artistique<input v-model="forms[f.index].artisticArrival" type="date"></label>
              <label class="field">Départ de l'équipe<input v-model="forms[f.index].departure" type="date"></label>
            </div>
            <div class="import-summary">
              <p><b>Équipe</b> · {{ counts(f.counts) }}</p>
              <p class="team-chips">
                <span v-for="t in f.team" :key="t.name" class="chip" :class="t.known ? 'info' : 'warn'" :title="t.known ? 'Déjà dans l\'annuaire' : 'Sera ajouté à l\'annuaire'">{{ t.name }}<template v-if="!t.known"> · nouveau</template></span>
              </p>
              <label v-if="f.roadmap.found" class="check">
                <input v-model="forms[f.index].includeRoadmap" type="checkbox">
                <span>Reprendre la feuille de route <span class="muted small">({{ roadmapCounts(f.counts) }})</span></span>
              </label>
              <p v-else class="muted small">Pas de feuille de route exploitable dans ce classeur.</p>
              <p v-if="!forms[f.index].includeRoadmap && f.counts.tickets" class="muted small">Sans feuille de route, le prix des billets du devis est repris sur un trajet aller et un trajet retour à compléter.</p>
            </div>
          </template>
          <p v-else class="muted small" style="margin:0">{{ f.show.title }} · {{ f.event.venue }}<template v-if="f.event.city"> ({{ f.event.city }})</template> · {{ fperiod(f.event.firstDate, f.event.lastDate) }}</p>
        </template>
      </article>

      <div class="import-bar">
        <span>{{ chosen.length }} date{{ chosen.length > 1 ? 's' : '' }} sur {{ files.length }} fichier{{ files.length > 1 ? 's' : '' }}</span>
        <button class="btn btn-primary" :disabled="busy || !chosen.length" @click="commit"><Icon name="check" />{{ busy ? 'Import en cours…' : `Importer ${chosen.length} date${chosen.length > 1 ? 's' : ''}` }}</button>
      </div>
    </template>

    <!-- 3. Résultat -->
    <section v-else class="card">
      <h2 style="margin-bottom:12px">Import terminé</h2>
      <p v-if="result.skippedTravel" class="alert" style="margin-bottom:12px">Sans le droit « Modifier les voyages », les trajets n'ont pas été repris.</p>
      <ul class="import-done">
        <li v-for="c in result.created" :key="c.id">
          <RouterLink :to="`/events/${c.id}`"><b>{{ c.city || c.venue }}</b> — {{ c.show }}, {{ fperiod(c.firstDate, c.firstDate) }}</RouterLink>
          <span class="muted small"> · {{ c.venue }} · {{ c.team }} personne{{ c.team > 1 ? 's' : '' }} · {{ c.file }}</span>
        </li>
      </ul>
      <div class="actions" style="margin-top:16px">
        <RouterLink class="btn btn-primary" :to="`/companies/${id}`">Voir le tableau de bord</RouterLink>
        <button class="btn" @click="reset"><Icon name="upload" />Importer d'autres fichiers</button>
      </div>
    </section>
  </template>
</template>
