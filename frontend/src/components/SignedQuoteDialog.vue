<script setup>
import { ref, watch } from 'vue'
import { put, showError, toast, upload } from '../api.js'
import { fdate } from '../format.js'
import { toForm } from '../signedQuote.js'
import Modal from './Modal.vue'
import SignedQuoteForm from './SignedQuoteForm.vue'

/** Import d'un devis PDF signé : lecture, vérification à l'écran (tout est modifiable), enregistrement. */
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
    form.value = toForm(r, { signedOn: props.ev.signedQuote?.signedOn || '' })
  } catch (err) { showError(err) } finally {
    busy.value = false
    if (fileInput.value) fileInput.value.value = ''
  }
}

async function save() {
  busy.value = true
  if (await props.apply(put(`/events/${props.ev.id}/signed-quote`, form.value))) {
    toast('Devis initial enregistré')
    emit('close')
  }
  busy.value = false
}
</script>

<template>
  <Modal :open="open" wide title="Importer le devis initial (PDF signé)" @close="emit('close')">
    <div style="display:flex;flex-direction:column;gap:14px">
      <label class="field">Devis au format PDF (exporté depuis le modèle Excel de la compagnie)
        <input ref="fileInput" type="file" accept=".pdf,application/pdf" :disabled="busy" @change="pick">
      </label>
      <p v-if="busy && !read" class="muted small" style="margin:0">Lecture du devis…</p>

      <template v-if="read">
        <p class="small" style="margin:0">Fichier lu : <b>{{ read.file }}</b> — vérifiez les valeurs avant d'enregistrer.</p>
        <ul v-if="read.warnings.length" class="card warn small" style="margin:0;padding:10px 10px 10px 28px"><li v-for="(w, i) in read.warnings" :key="i">{{ w }}</li></ul>
        <ul v-if="read.checks.length" class="small" style="margin:0;padding-left:20px;color:var(--mint-dark)"><li v-for="(c, i) in read.checks" :key="i">{{ c }}</li></ul>
        <SignedQuoteForm :form="form" />
        <p v-if="read.date" class="muted small" style="margin:0">Devis fourni le {{ fdate(read.date) }}. Le décor et les totaux sont conservés pour information ; seuls les déplacements de l'équipe, les repas et les nuitées sont comparés au réalisé.</p>
        <p v-if="ev.signedQuote" class="alert small" style="margin:0">Le devis initial déjà enregistré pour cette date ({{ ev.signedQuote.number || 'sans numéro' }}) sera remplacé.</p>
        <div class="actions">
          <button type="button" class="btn btn-primary" :disabled="busy" @click="save">Enregistrer le devis initial</button>
          <button type="button" class="btn btn-ghost" @click="emit('close')">Annuler</button>
        </div>
      </template>
    </div>
  </Modal>
</template>
