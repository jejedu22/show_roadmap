<script setup>
import { computed, ref, watch } from 'vue'
import { post, showError, toast, upload } from '../api.js'
import { fdate, money } from '../format.js'
import Modal from './Modal.vue'

/**
 * Import de l'onglet « Horaires TGV » d'un classeur de devis : lecture, vérification à l'écran, enregistrement.
 * Les trajets déjà saisis, annulés ou remplacés par un échange sont proposés décochés.
 */
const props = defineProps({ open: Boolean, ev: Object, apply: Function })
const emit = defineEmits(['close'])
const data = ref(null)
const busy = ref(false)
const replace = ref(false)
const fileInput = ref(null)

watch(() => props.open, (open) => { if (open) { data.value = null; replace.value = false } })

async function read(e) {
  const file = e.target.files?.[0]
  if (!file) return
  busy.value = true
  try {
    const res = await upload(`/events/${props.ev.id}/legs/excel`, 'file', [file])
    for (const p of res.persons) {
      p.target = p.participationId ?? ''
      for (const l of p.legs) l.keep = !l.exists && !l.cancelled && !l.replaced
    }
    data.value = res
  } catch (err) { showError(err) } finally {
    busy.value = false
    if (fileInput.value) fileInput.value.value = ''
  }
}

const selected = computed(() => (data.value?.persons || []).filter((p) => p.target)
  .flatMap((p) => p.legs.filter((l) => l.keep).map(({ keep, exists, cancelled, replaced, ...l }) => ({ ...l, participationId: p.target }))))

async function save() {
  busy.value = true
  const n = selected.value.length
  if (await props.apply(post(`/events/${props.ev.id}/legs/import`, { legs: selected.value, replace: replace.value }))) {
    toast(`${n} trajet${n > 1 ? 's' : ''} importé${n > 1 ? 's' : ''}`)
    emit('close')
  }
  busy.value = false
}
const why = (l) => l.exists ? 'déjà saisi' : l.cancelled ? 'annulé' : l.replaced ? 'remplacé par un échange' : ''
</script>

<template>
  <Modal :open="open" wide title="Importer les horaires TGV" @close="emit('close')">
    <div style="display:flex;flex-direction:column;gap:14px">
      <label class="field">Classeur de devis (.xlsx) : seul l'onglet « Horaires TGV » est lu
        <input ref="fileInput" type="file" accept=".xlsx" :disabled="busy" @change="read">
      </label>
      <p v-if="busy && !data" class="muted small" style="margin:0">Lecture du classeur…</p>

      <template v-if="data">
        <ul v-if="data.warnings.length" class="card warn small" style="margin:0;padding:10px 10px 10px 28px">
          <li v-for="(w, i) in data.warnings" :key="i">{{ w }}</li>
        </ul>
        <p v-if="!data.persons.length" class="muted">Aucun trajet trouvé dans l'onglet.</p>

        <section v-for="(p, i) in data.persons" :key="i" class="card" style="padding:12px">
          <div style="display:flex;gap:10px;align-items:center;flex-wrap:wrap;margin-bottom:8px">
            <b>« {{ p.name || 'Sans prénom' }} »</b>
            <label class="small" style="display:flex;gap:6px;align-items:center">→
              <select v-model="p.target" :aria-label="`Participant pour ${p.name}`">
                <option value="">Ignorer</option>
                <option v-for="o in data.participants" :key="o.id" :value="o.id">{{ o.name }}</option>
              </select>
            </label>
            <span v-if="!p.participationId" class="chip warn small">non reconnu</span>
          </div>
          <label v-for="(l, j) in p.legs" :key="j" class="small" :class="{ muted: !l.keep || !p.target }"
            style="display:flex;gap:8px;align-items:flex-start;padding:4px 0;border-top:1px solid var(--line)">
            <input v-model="l.keep" type="checkbox" :disabled="!p.target" style="margin-top:3px">
            <span style="flex:1">
              <b>{{ l.direction === 'outbound' ? 'Aller' : 'Retour' }}</b> · {{ fdate(l.day, true) }} ·
              {{ l.origin || '?' }} → {{ l.destination || '?' }}<template v-if="l.depTime"> · {{ l.depTime }}<template v-if="l.arrTime"> – {{ l.arrTime }}</template></template>
              <template v-if="l.mode"> · {{ l.mode }}</template><template v-if="l.reference"> · <span class="mono">{{ l.reference }}</span></template>
              <template v-if="l.price"> · {{ money(l.price) }}</template>
              <span v-if="why(l)" class="chip small" style="margin-left:6px">{{ why(l) }}</span>
              <span v-if="l.note" class="muted" style="display:block">{{ l.note }}</span>
            </span>
          </label>
        </section>

        <label class="small" style="display:flex;gap:8px;align-items:center">
          <input v-model="replace" type="checkbox">Remplacer les trajets existants des personnes importées (sinon, ajout)
        </label>
        <div class="actions">
          <button type="button" class="btn btn-primary" :disabled="busy || !selected.length" @click="save">Importer {{ selected.length }} trajet{{ selected.length > 1 ? 's' : '' }}</button>
          <button type="button" class="btn btn-ghost" @click="emit('close')">Annuler</button>
        </div>
      </template>
    </div>
  </Modal>
</template>
