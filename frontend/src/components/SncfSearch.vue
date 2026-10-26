<script setup>
import { ref } from 'vue'
import { get, session } from '../api.js'

/** Recherche d'horaires SNCF : émet `pick` avec les champs à pré-remplir dans le trajet. */
const props = defineProps({ eventId: Number, leg: Object })
const emit = defineEmits(['pick'])
const open = ref(false)
const from = ref(''), to = ref(''), time = ref('')
const places = ref([])
const ids = new Map()
const results = ref([])
const message = ref('')
let timer

function suggest(value) {
  clearTimeout(timer)
  if (value.length < 2) return
  timer = setTimeout(async () => {
    try {
      places.value = await get(`/events/${props.eventId}/sncf/places?q=${encodeURIComponent(value)}`)
      places.value.forEach((p) => ids.set(p.name, p.id))
    } catch { /* suggestions facultatives */ }
  }, 250)
}

function toggle() {
  open.value = !open.value
  from.value ||= props.leg.origin
  to.value ||= props.leg.destination
}

async function search() {
  results.value = []
  if (!ids.has(from.value) || !ids.has(to.value) || !props.leg.day) {
    message.value = 'Choisissez les deux gares dans la liste proposée et renseignez la date du trajet.'
    return
  }
  message.value = 'Recherche…'
  try {
    const q = new URLSearchParams({ origin: ids.get(from.value), dest: ids.get(to.value), day: props.leg.day, time: time.value })
    results.value = await get(`/events/${props.eventId}/sncf/journeys?${q}`)
    message.value = results.value.length ? '' : 'Aucun itinéraire trouvé.'
  } catch (e) { message.value = e.message }
}

function pick(j) {
  emit('pick', { origin: from.value, destination: to.value, depTime: j.depTime, arrTime: j.arrTime, mode: j.mode,
    note: j.numbers ? `Train ${j.numbers}` : '' })
}

const connectHref = () => `/api/events/${props.eventId}/sncf/connect?` + new URLSearchParams({
  origin: from.value || props.leg.origin, dest: to.value || props.leg.destination, day: props.leg.day })
const duration = (m) => `${Math.floor(m / 60)}h${String(m % 60).padStart(2, '0')}`
</script>

<template>
  <div style="grid-column:1/-1">
    <div class="actions" style="margin-top:0">
      <button v-if="session.sncf" type="button" class="sec" @click="toggle">🔎 Rechercher un train (SNCF)</button>
      <a class="btn sec" target="_blank" rel="noopener" :href="connectHref()">Ouvrir SNCF Connect ↗</a>
    </div>
    <div v-if="open">
      <datalist :id="`sncf-${leg._key}`"><option v-for="p in places" :key="p.id" :value="p.name" /></datalist>
      <div class="grid g3" style="margin-top:8px">
        <div><label>Gare de départ</label><input v-model="from" :list="`sncf-${leg._key}`" autocomplete="off" placeholder="Paris Gare de Lyon" @input="suggest(from)"></div>
        <div><label>Gare d'arrivée</label><input v-model="to" :list="`sncf-${leg._key}`" autocomplete="off" placeholder="Beauvais" @input="suggest(to)"></div>
        <div><label>À partir de (heure)</label><input v-model="time" type="time"></div>
      </div>
      <div class="actions"><button type="button" @click="search">Chercher</button></div>
      <div class="sncf-results small">
        <p v-if="message">{{ message }}</p>
        <button v-for="(j, i) in results" :key="i" type="button" class="sec" @click="pick(j)">
          {{ j.depTime }} → {{ j.arrTime }} · {{ j.mode }} {{ j.numbers }}<template v-if="j.transfers"> · {{ j.transfers }} corresp.</template> · {{ duration(j.durationMin) }}
        </button>
      </div>
    </div>
  </div>
</template>
