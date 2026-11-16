<script setup>
import { ref, watch } from 'vue'
import { put, showError, toast, upload } from '../api.js'
import { fdate, money } from '../format.js'
import Modal from './Modal.vue'

/** Import d'un devis PDF signé : lecture, vérification à l'écran (montants modifiables), enregistrement. */
const props = defineProps({ open: Boolean, ev: Object, apply: Function })
const emit = defineEmits(['close'])
const read = ref(null)
const form = ref({})
const busy = ref(false)
const fileInput = ref(null)

watch(() => props.open, (open) => { if (open) { read.value = null; form.value = {} } })

async function pick(e) {
  const file = e.target.files?.[0]
  if (!file) return
  busy.value = true
  try {
    const r = await upload(`/events/${props.ev.id}/signed-quote/pdf`, 'file', [file])
    read.value = r
    const n = (x) => (x ?? '') === '' ? '' : String(x).replace('.', ',')
    form.value = { number: r.number, date: r.date || '', signedOn: props.ev.signedQuote?.signedOn || '', teamTravel: n(r.teamTravel), meals: n(r.meals), nights: n(r.nights) }
  } catch (err) { showError(err) } finally {
    busy.value = false
    if (fileInput.value) fileInput.value.value = ''
  }
}

async function save() {
  busy.value = true
  if (await props.apply(put(`/events/${props.ev.id}/signed-quote`, form.value))) {
    toast('Devis signé enregistré')
    emit('close')
  }
  busy.value = false
}
</script>

<template>
  <Modal :open="open" wide title="Importer un devis signé (PDF)" @close="emit('close')">
    <div style="display:flex;flex-direction:column;gap:14px">
      <label class="field">Devis au format PDF (exporté depuis le modèle Excel de la compagnie)
        <input ref="fileInput" type="file" accept=".pdf,application/pdf" :disabled="busy" @change="pick">
      </label>
      <p v-if="busy && !read" class="muted small" style="margin:0">Lecture du devis…</p>

      <template v-if="read">
        <p class="small" style="margin:0">Fichier lu : <b>{{ read.file }}</b> — vérifiez les valeurs avant d'enregistrer.</p>
        <table class="table small"><tbody>
          <tr><td class="muted">Spectacle</td><td>{{ read.show || '—' }}</td><td class="muted">Organisateur</td><td>{{ read.organizer || '—' }}</td></tr>
          <tr><td class="muted">Période</td><td>{{ read.period || '—' }}</td><td class="muted">Représentations</td><td>{{ read.performances ?? '—' }}</td></tr>
          <tr v-if="read.totalHt !== null"><td class="muted">Total du devis</td><td colspan="3">{{ money(read.totalHt) }} HT<template v-if="read.totalTtc !== null"> · {{ money(read.totalTtc) }} TTC</template> (pour information)</td></tr>
        </tbody></table>
        <ul v-if="read.warnings.length" class="card warn small" style="margin:0;padding:10px 10px 10px 28px"><li v-for="(w, i) in read.warnings" :key="i">{{ w }}</li></ul>
        <ul v-if="read.checks.length" class="small" style="margin:0;padding-left:20px;color:var(--mint-dark)"><li v-for="(c, i) in read.checks" :key="i">{{ c }}</li></ul>

        <form class="form-grid" style="grid-template-columns:repeat(auto-fill,minmax(180px,1fr))" @submit.prevent="save">
          <label class="field">N° de devis<input v-model="form.number" maxlength="40"></label>
          <label class="field">Fourni le<input v-model="form.date" type="date"></label>
          <label class="field">Signé le<input v-model="form.signedOn" type="date"></label>
          <label class="field">Déplacements équipe (€ HT)<input v-model="form.teamTravel" inputmode="decimal"></label>
          <label class="field">Repas (midi + soir)<input v-model="form.meals" inputmode="numeric"></label>
          <label class="field">Nuitées (hôtel + pdéj.)<input v-model="form.nights" inputmode="numeric"></label>
        </form>
        <p class="muted small" style="margin:0">
          <template v-if="read.teamLines.length">Déplacements de l'équipe : {{ read.teamLines.map((l) => `${l.label} — ${l.detail || 'sans détail'} : ${money(l.amount)}`).join(' ; ') }}<template v-if="read.decor !== null"> (décor à part : {{ money(read.decor) }}, non comparé)</template>. </template>
          <template v-if="read.lunches !== null">Repas : {{ read.lunches }} midis + {{ read.dinners }} soirs. </template>
          <template v-if="read.date">Devis fourni le {{ fdate(read.date) }}.</template>
        </p>
        <p v-if="ev.signedQuote" class="alert small" style="margin:0">Le devis signé déjà enregistré pour cette date ({{ ev.signedQuote.number || 'sans numéro' }}) sera remplacé.</p>
        <div class="actions">
          <button type="button" class="btn btn-primary" :disabled="busy" @click="save">Enregistrer le devis signé</button>
          <button type="button" class="btn btn-ghost" @click="emit('close')">Annuler</button>
        </div>
      </template>
    </div>
  </Modal>
</template>
