<script setup>
import { computed, reactive, ref, watch } from 'vue'
import { get, post, put, session, toast } from '../api.js'
import { fdate } from '../format.js'
import { lists, loadLists } from '../lists.js'
import Icon from './Icon.vue'
import ListSelect from './ListSelect.vue'

/**
 * Saisie d'un trajet (nouveau ou existant), pré-remplie selon le sens et la personne.
 * Avec les horaires SNCF chargés, les gares sont trouvées à partir des villes et les trains proposés (directs ou avec une
 * correspondance) ; un trajet avec correspondance est enregistré tronçon par tronçon.
 */
const props = defineProps({ ev: Object, part: Object, direction: String, leg: Object, apply: Function })
const emit = defineEmits(['close'])
// Modes : liste paramétrée (Administration → Listes). Le mode d'un train SNCF (« TGV INOUI », « Transilien H »…) est rapproché
// d'une entrée : identique (casse, accents), sinon la plus longue entrée contenue dans le nom, sinon « Train » s'il est proposé.
const fold = (s) => s.normalize('NFD').replace(/[\u0300-\u036f]/g, '').toLowerCase().trim()
function listMode(raw) {
  const v = fold(raw || '')
  const modes = lists.mode
  return modes.find((m) => fold(m) === v)
    ?? [...modes].sort((a, b) => b.length - a.length).find((m) => new RegExp(`(^|[^a-z0-9])${fold(m).replace(/[.*+?^${}()|[\]\\]/g, '\\$&')}($|[^a-z0-9])`).test(v))
    ?? modes.find((m) => fold(m) === 'train') ?? ''
}
// Entrées par défaut (Administration → Listes) : mode de transport et site de réservation d'un nouveau trajet
const defaultMode = () => lists.defaults.mode || ''
const defaultLink = () => lists.linkUrls[lists.defaults.link] || ''
// Classe (1re / 2nde) : seulement pour les modes cochés « Classe » dans Administration → Listes ; absente de la feuille de route
const withClass = computed(() => lists.modeClass.includes(form.mode))
loadLists().then(() => {
  if (props.leg) return
  if (!form.mode) form.mode = defaultMode()
  if (!form.bookingUrl) form.bookingUrl = defaultLink()
})
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
    destination: out ? props.ev.city : props.part.departureCity, depTime: '', arrTime: '', mode: defaultMode(), travelClass: '', reference: '', price: '', bookedOn: '', note: '', bookingUrl: defaultLink() }
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
// Inverser départ et destination (villes, et gares SNCF) : par exemple pour saisir le retour à partir de l'aller
function swap() {
  ;[form.origin, form.destination] = [form.destination, form.origin]
  ;[stations.from, stations.to] = [stations.to, stations.from]
  touched = true
  if (session.sncf && ids.has(stations.from) && ids.has(stations.to) && form.day) search()
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
const legOf = (g) => ({ origin: g.from, destination: g.to, depTime: g.depTime.replace(' (+1)', ''), arrTime: g.arrTime.replace(' (+1)', ''), mode: listMode(g.mode), note: g.number ? `Train ${g.number}` : '' })
function pick(t, i) {
  picked.value = i
  const [first] = t.segments || []
  Object.assign(form, first ? legOf(first) : { origin: stations.from, destination: stations.to, depTime: t.depTime, arrTime: t.arrTime, mode: listMode(t.mode), note: t.numbers ? `Train ${t.numbers}` : form.note })
}
const nextDay = (iso) => { const d = new Date(`${iso}T12:00:00`); d.setDate(d.getDate() + 1); return d.toISOString().slice(0, 10) }
// SNCF Connect n'a pas de lien de recherche pré-remplie documenté : le trajet est copié pour être collé dans sa recherche,
// et le lien passe par le serveur (SNCF_CONNECT_URL, avec {origin}, {destination}, {date} si une adresse adaptée est configurée).
const tripText = computed(() => [stations.from || form.origin, stations.to || form.destination, form.day ? form.day.split('-').reverse().join('/') : '', form.depTime].filter(Boolean).join(' '))
const connectHref = computed(() => `/api/events/${props.ev.id}/sncf/connect?${new URLSearchParams({ origin: stations.from || form.origin || '', dest: stations.to || form.destination || '', day: form.day || '' })}`)
async function copyTrip() {
  try {
    await navigator.clipboard.writeText(tripText.value)
    toast(`« ${tripText.value} » copié : collez-le dans la recherche SNCF Connect`)
  } catch { /* presse-papiers indisponible (http, refus) : le lien s'ouvre quand même */ }
}
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
    <div class="route">
      <label class="field">De<input v-model="form.origin" required></label>
      <button type="button" class="btn btn-sm btn-icon swap" aria-label="Inverser départ et destination" title="Inverser départ et destination"
        @click="swap"><Icon name="swap" :size="16" /></button>
      <label class="field">À<input v-model="form.destination" required></label>
    </div>
    <div class="form-grid" style="grid-template-columns:repeat(2,minmax(0,1fr))">
      <label class="field">Date<input v-model="form.day" type="date" required></label>
      <label class="field">Sens<select v-model="form.direction"><option value="outbound">Aller</option><option value="return">Retour</option></select></label>
    </div>

    <template v-if="session.sncf">
      <span class="eyebrow">Trains (horaires SNCF, direct ou une correspondance)</span>
      <datalist :id="`gares-${part.id}`"><option v-for="p in places" :key="p.id" :value="p.name" /></datalist>
      <div class="route">
        <label class="field">Gare de départ<input v-model="stations.from" :list="`gares-${part.id}`" autocomplete="off" @input="suggest(stations.from)"></label>
        <button type="button" class="btn btn-sm btn-icon swap" aria-label="Inverser les gares" title="Inverser les gares" @click="swap"><Icon name="swap" :size="16" /></button>
        <label class="field">Gare d'arrivée<input v-model="stations.to" :list="`gares-${part.id}`" autocomplete="off" @input="suggest(stations.to)"></label>
      </div>
      <div class="form-grid" style="grid-template-columns:repeat(2,minmax(0,1fr))">
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
      <label class="field">Mode<ListSelect v-model="form.mode" kind="mode" /></label>
      <label v-if="withClass" class="field">Classe<select v-model="form.travelClass"><option value="">—</option><option value="1">1re classe</option><option value="2">2nde classe</option></select></label>
      <label class="field">Prix (€)<input v-model="form.price" inputmode="decimal" placeholder="0,00"></label>
      <label class="field">Référence<input v-model="form.reference" placeholder="ABC123"></label>
      <label class="field">Réservé le<input v-model="form.bookedOn" type="date"></label>
    </div>
    <label class="field">Lien du billet ou de la réservation<input v-model="form.bookingUrl" type="url" placeholder="https://… (collez le lien du billet, ou choisissez un site)"></label>
    <div v-if="lists.link.length" class="sites" role="group" aria-label="Sites de réservation">
      <button v-for="s in lists.link" :key="s" type="button" class="site" :class="{ on: form.bookingUrl === lists.linkUrls[s] }"
        :title="lists.linkUrls[s]" @click="form.bookingUrl = lists.linkUrls[s]">{{ s }}</button>
    </div>
    <label class="field">Note<input v-model="form.note"></label>
    <div class="actions">
      <button class="btn btn-sun" style="flex:1">Enregistrer</button>
      <a class="btn" :href="connectHref" target="_blank" rel="noopener" :title="`Copie « ${tripText} » pour le coller dans la recherche SNCF Connect`" @click="copyTrip">SNCF Connect ↗</a>
    </div>
  </form>
</template>

<style scoped>
.sites { display: flex; flex-wrap: wrap; gap: 6px; margin-top: -6px; }
.site { padding: 3px 10px; border-radius: 12px; border: 1px solid var(--ink-3); background: transparent; color: var(--muted-on-dark); font: inherit; font-size: 12px; font-weight: 600; cursor: pointer; }
.site:hover, .site.on { border-color: var(--sun); color: #fff; }
.route { display: grid; grid-template-columns: minmax(0, 1fr) auto minmax(0, 1fr); gap: 8px; align-items: end; }
.route .swap { margin-bottom: 4px; }
</style>
