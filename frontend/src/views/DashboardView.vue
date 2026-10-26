<script setup>
import { computed, ref, watch } from 'vue'
import { can, get, session, setCompany } from '../api.js'
import { daysFrom, fdate, monthLabel, relative } from '../format.js'
import Icon from '../components/Icon.vue'
import EventRow from '../components/EventRow.vue'
import NewEventDialog from '../components/NewEventDialog.vue'

const props = defineProps({ id: String })
const company = ref(null)
const showFilter = ref(null)
const creating = ref(false)
const showPast = ref(false)

watch(() => props.id, async (id) => {
  setCompany(Number(id))
  company.value = null
  showFilter.value = null
  company.value = await get(`/companies/${id}`)
}, { immediate: true })

const filtered = computed(() => company.value.events.filter((e) => !showFilter.value || e.showId === showFilter.value))
const upcoming = computed(() => filtered.value.filter((e) => daysFrom(e.end) >= 0))
const past = computed(() => filtered.value.filter((e) => daysFrom(e.end) < 0).reverse())
const byMonth = (list) => {
  const groups = []
  for (const e of list) {
    const m = monthLabel(e.firstDate)
    if (groups.at(-1)?.[0] !== m) groups.push([m, []])
    groups.at(-1)[1].push(e)
  }
  return groups
}
const allUpcoming = computed(() => company.value.events.filter((e) => daysFrom(e.end) >= 0))
const next = computed(() => allUpcoming.value[0])
const missingLegs = computed(() => allUpcoming.value.flatMap((e) => e.readiness.todo.filter((t) => t.step === 'travel').map((t) => ({ ...t, e }))))
const conflicts = computed(() => {
  const people = new Map()
  for (const e of allUpcoming.value) for (const name of e.conflicts) (people.get(name) || people.set(name, []).get(name)).push(e)
  return [...people.entries()]
})
const todo = computed(() => allUpcoming.value.flatMap((e) => e.readiness.todo.map((t) => ({ ...t, e }))))
const stepPath = (t) => `/events/${t.e.id}${t.step === 'info' ? '' : '/' + t.step}`
const greeting = computed(() => (session.user.name || '').split(' ')[0] || 'à vous')
</script>

<template>
  <template v-if="company">
    <header class="page-head">
      <div>
        <h1>Bonjour {{ greeting }}</h1>
        <p>{{ company.name }} · {{ allUpcoming.length }} date{{ allUpcoming.length > 1 ? 's' : '' }} à venir<template v-if="todo.length"> · {{ todo.length }} point{{ todo.length > 1 ? 's' : '' }} à régler</template></p>
      </div>
      <div class="actions">
        <RouterLink v-if="can('events.edit', company.permissions) && can('people.edit', company.permissions)" class="btn" :to="`/companies/${company.id}/import`"><Icon name="upload" />Importer des devis</RouterLink>
        <button v-if="can('events.edit', company.permissions)" class="btn btn-primary" @click="creating = true"><Icon name="plus" />Nouvelle date</button>
      </div>
    </header>

    <section v-if="company.events.length" class="tiles" aria-label="Points d'attention">
      <RouterLink v-if="next" class="tile violet" :to="`/events/${next.id}`">
        <span class="eyebrow">Prochaine date</span>
        <span class="big">{{ next.city || next.venue }}, {{ relative(next.start) }}</span>
        <span class="small">{{ next.showTitle }} · {{ next.teamSize }} personne{{ next.teamSize > 1 ? 's' : '' }} · préparation {{ next.readiness.done }}/{{ next.readiness.total }}</span>
      </RouterLink>
      <RouterLink v-if="missingLegs.length" class="tile sun" :to="stepPath(missingLegs[0])">
        <span class="eyebrow">À réserver</span>
        <span class="big">{{ missingLegs.length }} trajet{{ missingLegs.length > 1 ? 's' : '' }} sans billet</span>
        <span class="small">{{ missingLegs.slice(0, 3).map((t) => t.label.replace('Réserver ', '')).join(', ') }}{{ missingLegs.length > 3 ? '…' : '' }}</span>
      </RouterLink>
      <div v-else class="tile calm"><span class="eyebrow">Voyages</span><span class="big">Tous les trajets sont saisis</span></div>
      <RouterLink v-if="conflicts.length" class="tile outline" :to="`/events/${conflicts[0][1][0].id}/team`">
        <span class="eyebrow">Conflit de planning</span>
        <span class="big">{{ conflicts.map(([n]) => n).join(', ') }} sur 2 dates</span>
        <span class="small muted">{{ [...new Set(conflicts.flatMap(([, evs]) => evs.map((e) => e.venue)))].join(' et ') }} se chevauchent</span>
      </RouterLink>
      <div v-else class="tile calm"><span class="eyebrow">Planning</span><span class="big">Aucun conflit</span><span class="small muted">Personne n'est engagé sur deux dates à la fois</span></div>
    </section>

    <div v-if="!company.shows.length" class="card empty">
      <h3>Bienvenue dans {{ company.name }}</h3>
      <p>Commencez par créer une date : vous pourrez y ajouter un nouveau spectacle au passage.</p>
      <div class="actions" style="justify-content:center">
        <button v-if="can('events.edit', company.permissions)" class="btn btn-primary" @click="creating = true"><Icon name="plus" />Créer une première date</button>
        <RouterLink v-if="can('events.edit', company.permissions) && can('people.edit', company.permissions)" class="btn" :to="`/companies/${company.id}/import`"><Icon name="upload" />Importer des devis Excel</RouterLink>
      </div>
    </div>

    <div v-else class="two-cols">
      <section class="main-col" aria-labelledby="dates-title">
        <div class="page-head">
          <h2 id="dates-title">Dates à venir</h2>
          <div v-if="company.shows.length > 1" class="filters" role="group" aria-label="Filtrer par spectacle">
            <button type="button" :aria-pressed="!showFilter" @click="showFilter = null">Tous</button>
            <button v-for="s in company.shows" :key="s.id" type="button" :aria-pressed="showFilter === s.id" @click="showFilter = s.id">{{ s.title }}</button>
          </div>
        </div>
        <template v-for="[month, list] in byMonth(upcoming)" :key="month">
          <h3 class="month">{{ month }}</h3>
          <EventRow v-for="e in list" :key="e.id" :e="e" />
        </template>
        <div v-if="!upcoming.length" class="card empty"><h3>Aucune date à venir</h3><p>Ajoutez la prochaine date de tournée.</p>
          <button v-if="can('events.edit', company.permissions)" class="btn btn-primary" @click="creating = true"><Icon name="plus" />Nouvelle date</button></div>
        <template v-if="past.length">
          <button type="button" class="btn btn-ghost" style="align-self:flex-start" :aria-expanded="showPast" @click="showPast = !showPast">
            <Icon name="chevron" />{{ showPast ? 'Masquer' : 'Voir' }} les {{ past.length }} date{{ past.length > 1 ? 's' : '' }} passée{{ past.length > 1 ? 's' : '' }}</button>
          <template v-if="showPast"><EventRow v-for="e in past" :key="e.id" :e="e" /></template>
        </template>
        <details v-if="company.cancelledEvents.length" class="small muted">
          <summary>Dates annulées ({{ company.cancelledEvents.length }})</summary>
          <p v-for="e in company.cancelledEvents" :key="e.id" style="margin:6px 0"><RouterLink :to="`/events/${e.id}`">{{ fdate(e.firstDate, true) }} — {{ e.showTitle }} — {{ e.venue }}</RouterLink></p>
        </details>
      </section>

      <aside class="side-col card" aria-labelledby="todo-title">
        <h2 id="todo-title">À faire</h2>
        <div v-if="todo.length" class="todo">
          <RouterLink v-for="(t, i) in todo.slice(0, 10)" :key="i" :to="stepPath(t)">
            <span class="dot" aria-hidden="true" /><span>{{ t.label }}<br><span class="muted small">{{ t.e.venue }} · {{ fdate(t.e.firstDate, true) }}</span></span>
          </RouterLink>
          <p v-if="todo.length > 10" class="muted small">… et {{ todo.length - 10 }} autre{{ todo.length - 10 > 1 ? 's' : '' }}</p>
        </div>
        <p v-else class="muted">Tout est prêt pour les dates à venir.</p>
        <p class="muted small" style="margin:0">Liste calculée à partir des dates : équipe, hébergement, trajets, devis, feuille de route.</p>
      </aside>
    </div>

    <NewEventDialog :open="creating" :company="company" @close="creating = false" />
  </template>
</template>
