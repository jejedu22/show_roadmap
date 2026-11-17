<script setup>
import { computed, ref, watch } from 'vue'
import { post, showError, toast, upload } from '../api.js'
import { lists, loadLists } from '../lists.js'
import Modal from './Modal.vue'

/**
 * Import du planning d'une fiche technique PDF (dernière page) : lignes lues, modifiables (jour, heure, intitulé,
 * précision = texte de la fiche), à cocher ; effectifs P / L / S affichés pour information, non importés.
 * Un intitulé absent de la liste des intitulés de planning y est ajouté à l'import.
 */
const props = defineProps({ open: Boolean, ev: Object, apply: Function })
const emit = defineEmits(['close'])
const read = ref(null)
const replace = ref(false)
const busy = ref(false)
const fileInput = ref(null)

watch(() => props.open, (open) => { if (open) { read.value = null; replace.value = false; loadLists() } })

/** Comparaison sans casse, accents ni tirets, comme côté serveur (« Petit déjeuner » = « Petit-déjeuner ») */
const key = (s) => (s || '').normalize('NFD').replace(/[\u0300-\u036f]/g, '').toLowerCase().replace(/-/g, ' ').replace(/\s+/g, ' ').trim()
const known = computed(() => new Set(lists.schedule.map(key)))
const isNew = (label) => key(label) !== '' && !known.value.has(key(label))

async function pick(e) {
  const file = e.target.files?.[0]
  if (!file) return
  busy.value = true
  try {
    read.value = await upload(`/events/${props.ev.id}/schedule/pdf`, 'file', [file])
  } catch (err) { showError(err) } finally {
    busy.value = false
    if (fileInput.value) fileInput.value.value = ''
  }
}

const selected = computed(() => (read.value?.rows || []).filter((r) => r.checked))
const incomplete = computed(() => selected.value.filter((r) => !r.day || !r.label.trim()).length)
/** Nouveaux intitulés des lignes cochées (une fois chacun) */
const newLabels = computed(() => {
  const seen = new Map()
  for (const r of selected.value) if (isNew(r.label) && !seen.has(key(r.label))) seen.set(key(r.label), r.label.trim())
  return [...seen.values()]
})

async function save() {
  busy.value = true
  const n = selected.value.length
  const rows = selected.value.map(({ day, time, label, detail }) => ({ day, time, label, detail }))
  const added = newLabels.value.length
  if (await props.apply(post(`/events/${props.ev.id}/schedule/import`, { rows, replace: replace.value, addLabels: true }))) {
    if (added) loadLists(true)
    toast(`${n} ligne${n > 1 ? 's' : ''} de planning importée${n > 1 ? 's' : ''}` + (added ? ` · ${added} intitulé${added > 1 ? 's' : ''} ajouté${added > 1 ? 's' : ''} à la liste` : ''))
    emit('close')
  }
  busy.value = false
}
</script>

<template>
  <Modal :open="open" wide title="Importer le planning d'une fiche technique" @close="emit('close')">
    <div style="display:flex;flex-direction:column;gap:14px">
      <label class="field">Fiche technique (PDF) : le planning est lu sur la dernière page
        <input ref="fileInput" type="file" accept=".pdf,application/pdf" :disabled="busy" @change="pick">
      </label>
      <p v-if="busy && !read" class="muted small" style="margin:0">Lecture de la fiche…</p>

      <template v-if="read">
        <p class="small" style="margin:0">Fichier lu : <b>{{ read.file }}</b> (page {{ read.page }} sur {{ read.pages }}) — vérifiez les lignes avant d'importer.
          L'intitulé est pris dans votre liste quand il y figure ; sinon il est marqué « nouveau » et sera ajouté à la liste. Le texte de la fiche devient la précision. Effectifs et remarques ne sont pas repris.</p>
        <datalist id="sched-pdf-labels"><option v-for="e in lists.schedule" :key="e" :value="e" /></datalist>
        <ul v-if="read.warnings.length" class="card warn small" style="margin:0;padding:10px 10px 10px 28px"><li v-for="(w, i) in read.warnings" :key="i">{{ w }}</li></ul>

        <div class="table-wrap">
          <table class="table small sched">
            <thead><tr><th><span class="sr-only">Importer</span></th><th>Jour</th><th>Heure</th><th>Intitulé</th><th>Précision (texte de la fiche)</th><th>P / L / S</th></tr></thead>
            <tbody>
              <tr v-for="(r, i) in read.rows" :key="i" :class="{ muted: !r.checked }">
                <td><input v-model="r.checked" type="checkbox" :aria-label="`Importer la ligne ${i + 1}`"></td>
                <td><input v-model="r.day" type="date" :title="r.dayText" style="width:9.5em"></td>
                <td><input v-model="r.time" maxlength="20" style="width:7em" placeholder="sans heure"></td>
                <td><div style="display:flex;align-items:center;gap:6px">
                  <input v-model="r.label" list="sched-pdf-labels" maxlength="120" placeholder="— choisir ou saisir —" style="min-width:11em;width:100%" :aria-label="`Intitulé de la ligne ${i + 1}`">
                  <span v-if="isNew(r.label)" class="chip warn" title="Absent de la liste : sera ajouté aux intitulés de planning">nouveau</span></div></td>
                <td><input v-model="r.detail" maxlength="255" style="min-width:16em;width:100%"></td>
                <td class="muted" style="white-space:nowrap">{{ r.staff }}</td>
              </tr>
            </tbody>
          </table>
        </div>
        <p v-if="read.rows.some((r) => !r.time)" class="muted small" style="margin:0">Les lignes sans horaire (pré-montage, chargement…) sont décochées : cochez-les pour les importer.</p>

        <label class="small" style="display:flex;gap:8px;align-items:flex-start">
          <input v-model="replace" type="checkbox" style="margin-top:3px">
          <span>Remplacer le planning existant ({{ ev.schedule.length }} ligne{{ ev.schedule.length > 1 ? 's' : '' }})<span class="muted" style="display:block">
            Sinon, les lignes sont ajoutées. En remplaçant, les invitations rattachées à une représentation supprimée sont à répartir à nouveau.</span></span>
        </label>
        <p v-if="newLabels.length" class="small" style="margin:0">
          <b>{{ newLabels.length }} nouvel{{ newLabels.length > 1 ? 's' : '' }} intitulé{{ newLabels.length > 1 ? 's' : '' }}</b> ajouté{{ newLabels.length > 1 ? 's' : '' }} à la liste des intitulés de planning à l'import :
          {{ newLabels.join(' · ') }}. <span class="muted">Corrigez-les ou choisissez un intitulé existant si besoin.</span></p>
        <p v-if="incomplete" class="alert small" style="margin:0">{{ incomplete }} ligne{{ incomplete > 1 ? 's' : '' }} cochée{{ incomplete > 1 ? 's' : '' }} sans jour ou sans intitulé : complétez-les ou décochez-les.</p>
        <div class="actions">
          <button type="button" class="btn btn-primary" :disabled="busy || !selected.length || incomplete > 0" @click="save">Importer {{ selected.length }} ligne{{ selected.length > 1 ? 's' : '' }}</button>
          <button type="button" class="btn btn-ghost" @click="emit('close')">Annuler</button>
        </div>
      </template>
    </div>
  </Modal>
</template>

<style scoped>
.sched td { vertical-align: middle; }
.sched input:not([type=checkbox]), .sched select { padding: 6px 8px; font-size: 13px; }
</style>
