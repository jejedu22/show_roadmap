<script setup>
import { computed, reactive, ref, watch } from 'vue'
import { get, post, put, session, toast } from '../api.js'
import { fdate } from '../format.js'
import Icon from './Icon.vue'

/**
 * Saisie d'un trajet (nouveau ou existant), pré-remplie selon le sens et la personne.
 * Avec les horaires SNCF chargés, les gares sont trouvées à partir des villes et les trains proposés (directs ou avec une
 * correspondance) ; un trajet avec correspondance est enregistré tronçon par tronçon.
 */
const props = defineProps({ ev: Object, part: Object, direction: String, leg: Object, apply: Function })
const emit = defineEmits(['close'])
const MODES = ['Train', 'TER', 'TGV INOUI', 'OUIGO', 'Intercités', 'Avion', 'Véhicule perso', 'Bus', 'Taxi']
const form = reactive({})
const stations = reactive({ from: '', to: '' })
const ids = new Map()
const places = ref([])
const trains = ref([])
const picked = ref(null)
const message = ref('')
const after = ref('')
let timer

const isTech = (p) => /régi|techni/i.test(p.role)
function defaults() {
  const out = props.direction === 'outbound'
  const day = out ? ((isTech(props.part) ? props.ev.techArrival : props.ev.artisticArrival) || props.ev.start) : (props.ev.departure || props.ev.lastDate)
  return { participationId: props.part.id, direction: props.direction, day, origin: out ? props.part.departureCity : props.ev.city,
    destination: out ? props.ev.city : props.part.departureCity, depTime: '', arrTime: '', mode: 'Train', reference: '', price: '', bookedOn: '', note: '', bookingUrl: '' }
}
let touched = false // gares modifiées à la main : la détection automatique ne les écrase pas
async function init() {
  Object.assign(form, props.leg ? { ...props.leg, bookedOn: props.leg.bookedOn || '', price: props.leg.price || '' } : defaults())
  trains.value = []; picked.value = null; message.value = ''; touched = false
  if (!session.sncf || !form.origin || !form.destination) return
  // Le serveur choisit les gares les mieux reliées entre les deux villes (Paris → Beauvais : Paris Gare du Nord)
  const q = new URLSearchParams({ from: form.origin, to: form.destination })
  const pair = await get(`/events/${props.ev.id}/sncf/pair?${q}`).catch(() => ({}))
  if (touched) return
  for (const [key, st] of [['from', pair.from], ['to', pair.to]]) if (st) { ids.set(st.name, st.id); stations[key] = st.name }
  if (!props.leg && pair.from && pair.to) search()
}
watch(() => [props.part?.id, props.direction, props.leg?.id], init, { immediate: true })

function suggest(value) {
  touched = true
  clearTimeout(timer)
  if (value.length < 2) return
  timer = setTimeout(async () => {
    places.value = await get(`/events/${props.ev.id}/sncf/places?q=${encodeURIComponent(value)}`).catch(() => [])
    places.value.forEach((p) => ids.set(p.name, p.id))
  }, 250)
}
async function search() {
  trains.value = []; picked.value = null
  if (!ids.has(stations.from) || !ids.has(stations.to) || !form.day) {
    message.value = 'Choisissez les deux gares dans la liste et la date pour voir les trains.'
    return
  }
  message.value = 'Recherche des trains…'
  try {
    const q = new URLSearchParams({ origin: ids.get(stations.from), dest: ids.get(stations.to), day: form.day, time: after.value })
    trains.value = await get(`/events/${props.ev.id}/sncf/journeys?${q}`)
    const [first, last] = session.sncfCoverage || []
    message.value = trains.value.length ? ''
      : last && (form.day > last || form.day < first) ? `Les horaires SNCF publiés vont du ${fdate(first)} au ${fdate(last)}.`
      : 'Aucun train ce jour-là, même avec une correspondance. Saisissez chaque tronçon, ou cherchez gare par gare.'
  } catch (e) { message.value = e.message }
}
// Tronçons du trajet choisi (le premier remplit le formulaire, les suivants sont créés à l'enregistrement)
const segments = computed(() => picked.value === null ? [] : trains.value[picked.value].segments || [])
const legOf = (g) => ({ origin: g.from, destination: g.to, depTime: g.depTime.replace(' (+1)', ''), arrTime: g.arrTime.replace(' (+1)', ''), mode: g.mode, note: g.number ? `Train ${g.number}` : '' })
function pick(t, i) {
  picked.value = i
  const [first] = t.segments || []
  Object.assign(form, first ? legOf(first) : { origin: stations.from, destination: stations.to, depTime: t.depTime, arrTime: t.arrTime, mode: t.mode, note: t.numbers ? `Train ${t.numbers}` : form.note })
}
const nextDay = (iso) => { const d = new Date(`${iso}T12:00:00`); d.setDate(d.getDate() + 1); return d.toISOString().slice(0, 10) }
const who = computed(() => props.part.person.firstName || props.part.person.lastName)
const title = computed(() => `${props.leg ? 'Modifier' : props.direction === 'outbound' ? 'Aller' : 'Retour'} de ${who.value}`)
async function save() {
  const url = `/events/${props.ev.id}/items/leg${props.leg ? '/' + props.leg.id : ''}`
  if (!await props.apply(props.leg ? put(url, form) : post(url, form))) return
  // Correspondance : un trajet par tronçon suivant, même jour (ou lendemain après minuit), sans prix ni référence
  for (const g of props.leg ? [] : segments.value.slice(1)) {
    const extra = { ...form, ...legOf(g), day: g.nextDay ? nextDay(form.day) : form.day, price: '', reference: '', bookedOn: '', bookingUrl: '' }
    if (!await props.apply(post(url, extra))) return
  }
  toast(segments.value.length > 1 && !props.leg ? `${segments.value.length} trajets de ${who.value} enregistrés` : `Trajet de ${who.value} enregistré`)
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
      <label class="field">De<input v-model="form.origin" required></label>
      <label class="field">À<input v-model="form.destination" required></label>
      <label class="field">Date<input v-model="form.day" type="date" required></label>
      <label class="field">Sens<select v-model="form.direction"><option value="outbound">Aller</option><option value="return">Retour</option></select></label>
    </div>

    <template v-if="session.sncf">
      <span class="eyebrow">Trains (horaires SNCF, direct ou une correspondance)</span>
      <datalist :id="`gares-${part.id}`"><option v-for="p in places" :key="p.id" :value="p.name" /></datalist>
      <div class="form-grid" style="grid-template-columns:repeat(2,minmax(0,1fr))">
        <label class="field">Gare de départ<input v-model="stations.from" :list="`gares-${part.id}`" autocomplete="off" @input="suggest(stations.from)"></label>
        <label class="field">Gare d'arrivée<input v-model="stations.to" :list="`gares-${part.id}`" autocomplete="off" @input="suggest(stations.to)"></label>
        <label class="field">Partir après<input v-model="after" type="time"></label>
        <button type="button" class="btn btn-sun" style="align-self:flex-end" @click="search"><Icon name="search" :size="16" />Chercher</button>
      </div>
      <p v-if="message" class="note" role="status">{{ message }}</p>
      <button v-for="(t, i) in trains" :key="i" type="button" class="train" :class="{ picked: picked === i }" :aria-pressed="picked === i" @click="pick(t, i)">
        <span>
          <b>{{ t.depTime }} → {{ t.arrTime }}</b> · {{ t.transfers ? '1 corresp.' : 'direct' }} · {{ t.mode }}
          <small v-if="t.transfers" class="train-legs"><template v-for="(g, j) in t.segments" :key="j">{{ j ? ' · ' : '' }}{{ g.from }} {{ g.depTime }} → {{ g.to }} {{ g.arrTime }}</template></small>
        </span><small>{{ Math.floor(t.durationMin / 60) }}h{{ String(t.durationMin % 60).padStart(2, '0') }}</small>
      </button>
    </template>

    <div class="form-grid" style="grid-template-columns:repeat(2,minmax(0,1fr))">
      <label class="field">Départ<input v-model="form.depTime" placeholder="14h27"></label>
      <label class="field">Arrivée<input v-model="form.arrTime" placeholder="17h25"></label>
      <label class="field">Mode<input v-model="form.mode" list="modes-list"></label>
      <label class="field">Prix (€)<input v-model="form.price" inputmode="decimal" placeholder="0,00"></label>
      <label class="field">Référence<input v-model="form.reference" placeholder="ABC123"></label>
      <label class="field">Réservé le<input v-model="form.bookedOn" type="date"></label>
    </div>
    <datalist id="modes-list"><option v-for="m in MODES" :key="m" :value="m" /></datalist>
    <label class="field">Lien du billet ou de la réservation<input v-model="form.bookingUrl" type="url" placeholder="https://…"></label>
    <label class="field">Note<input v-model="form.note"></label>
    <div class="actions">
      <button class="btn btn-sun" style="flex:1">Enregistrer</button>
      <a class="btn" href="https://www.sncf-connect.com/" target="_blank" rel="noopener">SNCF Connect ↗</a>
    </div>
  </form>
</template>
