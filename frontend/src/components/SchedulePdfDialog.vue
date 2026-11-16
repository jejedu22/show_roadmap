<script setup>
import { computed, ref, watch } from 'vue'
import { post, showError, toast, upload } from '../api.js'
import Modal from './Modal.vue'
import ListSelect from './ListSelect.vue'

/**
 * Import du planning d'une fiche technique PDF (dernière page) : lignes lues, modifiables (jour, heure, intitulé de la liste,
 * précision = texte de la fiche), à cocher ; effectifs P / L / S affichés pour information, non importés.
 */
const props = defineProps({ open: Boolean, ev: Object, apply: Function })
const emit = defineEmits(['close'])
const read = ref(null)
const replace = ref(false)
const busy = ref(false)
const fileInput = ref(null)

watch(() => props.open, (open) => { if (open) { read.value = null; replace.value = false } })

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
const incomplete = computed(() => selected.value.filter((r) => !r.day || !r.label).length)

async function save() {
  busy.value = true
  const n = selected.value.length
  const rows = selected.value.map(({ day, time, label, detail }) => ({ day, time, label, detail }))
  if (await props.apply(post(`/events/${props.ev.id}/schedule/import`, { rows, replace: replace.value }))) {
    toast(`${n} ligne${n > 1 ? 's' : ''} de planning importée${n > 1 ? 's' : ''}`)
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
          L'intitulé est choisi dans votre liste ; le texte de la fiche devient la précision. Effectifs et remarques ne sont pas repris.</p>
        <ul v-if="read.warnings.length" class="card warn small" style="margin:0;padding:10px 10px 10px 28px"><li v-for="(w, i) in read.warnings" :key="i">{{ w }}</li></ul>

        <div class="table-wrap">
          <table class="table small sched">
            <thead><tr><th><span class="sr-only">Importer</span></th><th>Jour</th><th>Heure</th><th>Intitulé</th><th>Précision (texte de la fiche)</th><th>P / L / S</th></tr></thead>
            <tbody>
              <tr v-for="(r, i) in read.rows" :key="i" :class="{ muted: !r.checked }">
                <td><input v-model="r.checked" type="checkbox" :aria-label="`Importer la ligne ${i + 1}`"></td>
                <td><input v-model="r.day" type="date" :title="r.dayText" style="width:9.5em"></td>
                <td><input v-model="r.time" maxlength="20" style="width:7em" placeholder="sans heure"></td>
                <td><ListSelect v-model="r.label" kind="schedule" empty="— choisir —" /></td>
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
