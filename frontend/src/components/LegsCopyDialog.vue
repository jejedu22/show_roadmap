<script setup>
import { computed, ref, watch } from 'vue'
import { get, post, showError, toast } from '../api.js'
import { fdate, fperiod, money } from '../format.js'
import Modal from './Modal.vue'

/**
 * Reprise des trajets d'une date enchaînée : le retour de la date précédente devient l'aller ici, l'aller de la date
 * suivante devient le retour. Seules les personnes présentes dans les deux équipes sont proposées.
 */
const props = defineProps({ open: Boolean, ev: Object, apply: Function, finance: Boolean })
const emit = defineEmits(['close'])
const sources = ref(null)
const pick = ref(0)
const withPrice = ref(false)
const busy = ref(false)

watch(() => props.open, async (open) => {
  if (!open) return
  sources.value = null; pick.value = 0; withPrice.value = false
  try {
    const res = await get(`/events/${props.ev.id}/leg-sources`)
    for (const s of res) for (const l of s.legs) l.keep = !l.already
    sources.value = res
  } catch (e) { showError(e); emit('close') }
})

const source = computed(() => sources.value?.[pick.value])
const people = computed(() => {
  const by = {}
  for (const l of source.value?.legs || []) (by[l.person] ??= []).push(l)
  return Object.entries(by)
})
const selected = computed(() => (source.value?.legs || []).filter((l) => l.keep && !l.already))
const label = (s) => `${s.event.showTitle} — ${s.event.venue}${s.event.city ? ` (${s.event.city})` : ''} · ${fperiod(s.event.firstDate, s.event.lastDate)}`

async function save() {
  busy.value = true
  const n = selected.value.length
  if (await props.apply(post(`/events/${props.ev.id}/copy-legs`, { legIds: selected.value.map((l) => l.id), withPrice: withPrice.value }))) {
    toast(`${n} trajet${n > 1 ? 's' : ''} repris`)
    emit('close')
  }
  busy.value = false
}
</script>

<template>
  <Modal :open="open" wide title="Reprendre les trajets d'une autre date" @close="emit('close')">
    <div style="display:flex;flex-direction:column;gap:14px">
      <p v-if="!sources" class="muted small" style="margin:0">Recherche des dates voisines…</p>
      <p v-else-if="!sources.length" style="margin:0">Aucun trajet à reprendre : il faut une autre date de la compagnie où les mêmes personnes ont un <b>retour</b> (date précédente) ou un <b>aller</b> (date suivante) déjà saisi.</p>

      <template v-else>
        <p class="muted small" style="margin:0">Quand deux dates s'enchaînent, le trajet entre les deux lieux est le retour de la première et l'aller de la suivante : choisissez la date, cochez les trajets, ils sont copiés ici dans l'autre sens. Les deux trajets restent ensuite indépendants.</p>
        <label class="field">Date d'où reprendre les trajets
          <select v-model="pick">
            <option v-for="(s, i) in sources" :key="s.event.id" :value="i">{{ s.position === 'before' ? 'Précédente' : 'Suivante' }} : {{ label(s) }}</option>
          </select>
        </label>
        <p class="small" style="margin:0">Les trajets <b>{{ source.position === 'before' ? 'retour' : 'aller' }}</b> de cette date deviendront des trajets <b>{{ source.position === 'before' ? 'aller' : 'retour' }}</b> ici.</p>

        <section v-for="[name, legs] in people" :key="name" class="card" style="padding:12px">
          <b>{{ name }}</b>
          <label v-for="l in legs" :key="l.id" class="small" :class="{ muted: !l.keep || l.already }"
            style="display:flex;gap:8px;align-items:flex-start;padding:4px 0;border-top:1px solid var(--line);margin-top:6px">
            <input v-model="l.keep" type="checkbox" :disabled="l.already" style="margin-top:3px">
            <span style="flex:1">
              {{ fdate(l.day, true) }} · {{ l.origin || '?' }} → {{ l.destination || '?' }}<template v-if="l.depTime"> · <b>{{ l.depTime }}<template v-if="l.arrTime"> – {{ l.arrTime }}</template></b></template>
              <template v-if="l.mode"> · {{ l.mode }}</template><template v-if="l.reference"> · <span class="mono">{{ l.reference }}</span></template>
              <template v-if="l.price"> · {{ money(l.price) }}</template><template v-if="l.bookingUrl"> · lien du billet</template>
              <span v-if="l.already" class="chip small" style="margin-left:6px">déjà présent ici</span>
            </span>
          </label>
        </section>

        <label v-if="finance" class="small" style="display:flex;gap:8px;align-items:flex-start">
          <input v-model="withPrice" type="checkbox" style="margin-top:3px">
          <span>Compter aussi le prix des billets sur cette date<span class="muted" style="display:block">Décoché : le trajet est copié avec un prix à 0 €, pour ne pas compter deux fois le même billet dans les devis et les frais.</span></span>
        </label>
        <div class="actions">
          <button type="button" class="btn btn-primary" :disabled="busy || !selected.length" @click="save">Reprendre {{ selected.length }} trajet{{ selected.length > 1 ? 's' : '' }}</button>
          <button type="button" class="btn btn-ghost" @click="emit('close')">Annuler</button>
        </div>
      </template>
    </div>
  </Modal>
</template>
