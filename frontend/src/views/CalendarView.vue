<script setup>
import { computed, ref, watch } from 'vue'
import { get, setCompany } from '../api.js'
import { daysFrom, monthLabel, todayIso } from '../format.js'
import Icon from '../components/Icon.vue'

const props = defineProps({ id: String })
const company = ref(null)
const month = ref(todayIso().slice(0, 7)) // AAAA-MM

watch(() => props.id, async (id) => {
  setCompany(Number(id))
  company.value = await get(`/companies/${id}`)
  const next = company.value.events.find((e) => daysFrom(e.end) >= 0)
  if (next) month.value = next.start.slice(0, 7)
}, { immediate: true })

const iso = (d) => `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`
const shift = (n) => { const [y, m] = month.value.split('-').map(Number); const d = new Date(y, m - 1 + n, 1); month.value = iso(d).slice(0, 7) }
const showIndex = computed(() => Object.fromEntries((company.value?.shows || []).map((s, i) => [s.id, i % 5])))
const days = computed(() => {
  const [y, m] = month.value.split('-').map(Number)
  const first = new Date(y, m - 1, 1)
  const start = new Date(first); start.setDate(1 - ((first.getDay() + 6) % 7)) // lundi
  return Array.from({ length: 42 }, (_, i) => {
    const d = new Date(start); d.setDate(start.getDate() + i)
    const day = iso(d)
    return { day, n: d.getDate(), other: d.getMonth() !== m - 1, today: day === todayIso(),
      events: company.value.events.filter((e) => e.start <= day && day <= e.end).map((e) => ({ ...e, perf: e.firstDate <= day && day <= e.lastDate })) }
  })
})
</script>

<template>
  <template v-if="company">
    <div class="page-head">
      <div><h1>Calendrier</h1><p>Présence des équipes (arrivées → départs) ; les jours de représentation sont en gras.</p></div>
      <div class="actions">
        <button class="btn btn-icon" aria-label="Mois précédent" @click="shift(-1)"><Icon name="left" /></button>
        <b style="min-width:150px;text-align:center;text-transform:capitalize;font-family:var(--display);font-size:20px">{{ monthLabel(month + '-01') }}</b>
        <button class="btn btn-icon" aria-label="Mois suivant" @click="shift(1)"><Icon name="left" style="transform:rotate(180deg)" /></button>
        <button class="btn btn-sm" @click="month = todayIso().slice(0, 7)">Aujourd'hui</button>
      </div>
    </div>
    <div class="filters" aria-label="Légende">
      <span v-for="s in company.shows" :key="s.id" class="chip" :class="['info', 'warn', 'ok', 'bad', 'dark'][showIndex[s.id]]">{{ s.title }}</span>
    </div>
    <div class="cal" role="grid" :aria-label="monthLabel(month + '-01')">
      <div v-for="d in ['lun.', 'mar.', 'mer.', 'jeu.', 'ven.', 'sam.', 'dim.']" :key="d" class="dow" role="columnheader">{{ d }}</div>
      <div v-for="d in days" :key="d.day" class="day" :class="{ other: d.other, today: d.today }" role="gridcell">
        <span class="n">{{ d.n }}</span>
        <RouterLink v-for="e in d.events" :key="e.id" class="bar" :class="[`show-${showIndex[e.showId]}`, { perf: e.perf }]" :to="`/events/${e.id}`"
          :title="`${e.showTitle} — ${e.venue}`">{{ e.city || e.venue }}</RouterLink>
      </div>
    </div>
  </template>
</template>
