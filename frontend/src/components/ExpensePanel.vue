<script setup>
import { computed, reactive } from 'vue'
import { post, put, toast } from '../api.js'
import Icon from './Icon.vue'
import ListSelect from './ListSelect.vue'
import { lists, loadLists } from '../lists.js'

/**
 * Dépense (taxi, parking, frais d'échange…) ou remboursement sur l'aller ou le retour d'une personne.
 * Comptée dans le coût des voyages et le devis ; jamais dans la feuille de route.
 */
const props = defineProps({ ev: Object, part: Object, direction: String, expense: Object, apply: Function })
const emit = defineEmits(['close'])

const legDay = (dir) => props.part.legs.filter((l) => l.direction === dir).map((l) => l.day).sort()[0]
const x = props.expense
const form = reactive({
  participationId: props.part.id,
  direction: x?.direction ?? props.direction,
  sign: x && x.amount < 0 ? -1 : 1,
  kind: x?.kind ?? '',
  amount: x ? String(Math.abs(x.amount)).replace('.', ',') : '',
  day: x?.day ?? legDay(props.direction) ?? (props.direction === 'return' ? (props.ev.departure || props.ev.lastDate) : props.ev.start),
  comment: x?.comment ?? '',
})
// Type par défaut (Administration → Listes) pour une nouvelle ligne
loadLists().then(() => { if (!x && !form.kind) form.kind = lists.defaults.expense || '' })
const who = computed(() => props.part.person.firstName || props.part.person.lastName)
const title = computed(() => `${x ? 'Modifier la ligne' : 'Dépense ou remboursement'} · ${form.direction === 'return' ? 'retour' : 'aller'} de ${who.value}`)

async function save() {
  const value = Number(String(form.amount).replace(/\s/g, '').replace(',', '.'))
  const body = { participationId: form.participationId, direction: form.direction, kind: form.kind, day: form.day, comment: form.comment,
    amount: Number.isFinite(value) ? String(form.sign * Math.abs(value)) : form.amount }
  const url = `/events/${props.ev.id}/items/expense${x ? '/' + x.id : ''}`
  if (!await props.apply(x ? put(url, body) : post(url, body))) return
  toast(form.sign < 0 ? 'Remboursement enregistré' : 'Dépense enregistrée')
  emit('close')
}
</script>

<template>
  <form class="panel" :aria-label="title" @submit.prevent="save">
    <div class="card-head" style="margin:0">
      <h2>{{ title }}</h2>
      <button type="button" class="btn btn-sm btn-icon" aria-label="Fermer" @click="emit('close')"><Icon name="close" :size="18" /></button>
    </div>
    <div class="form-grid" style="grid-template-columns:repeat(2,minmax(0,1fr))">
      <label class="field">Nature<select v-model.number="form.sign"><option :value="1">Dépense</option><option :value="-1">Remboursement</option></select></label>
      <label class="field">Sens<select v-model="form.direction"><option value="outbound">Aller</option><option value="return">Retour</option></select></label>
      <label class="field">Type<ListSelect v-model="form.kind" kind="expense" required empty="Choisir…" /></label>
      <label class="field">Montant (€)<input v-model="form.amount" inputmode="decimal" placeholder="0,00" required></label>
      <label class="field">Date<input v-model="form.day" type="date" required></label>
    </div>
    <label class="field">Commentaire<input v-model="form.comment" maxlength="255" placeholder="Gare → théâtre, n° de dossier…"></label>
    <p class="muted small" style="margin:0">Compté dans le coût des voyages et le devis ; n'apparaît pas dans la feuille de route.</p>
    <div class="actions"><button class="btn btn-sun" style="flex:1">Enregistrer</button></div>
  </form>
</template>
