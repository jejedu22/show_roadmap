<script setup>
import { computed, provide, ref, watch } from 'vue'
import { useRoute } from 'vue-router'
import { get, setCompany, showError } from '../api.js'
import { fperiod } from '../format.js'
import Icon from '../components/Icon.vue'
import ShareDialog from '../components/ShareDialog.vue'

const props = defineProps({ id: String })
const route = useRoute()
const ev = ref(null)
const sharing = ref(false)
const PATHS = { info: '', team: '/team', stay: '/stay', travel: '/travel', quote: '/quote', roadmap: '/roadmap' }

watch(() => props.id, async (id) => {
  ev.value = null
  ev.value = await get(`/events/${id}`)
  setCompany(ev.value.companyId)
}, { immediate: true })

/** Chaque modification renvoie la date complète : on remplace l'état. Retourne true si l'enregistrement a réussi. */
async function apply(promise) {
  try { ev.value = await promise; return true } catch (e) { showError(e); return false }
}
provide('event', { ev, apply })
const status = computed(() => ({ confirmed: ['Confirmé', 'ok'], option: ['Option', 'warn'], cancelled: ['Annulé', 'bad'] })[ev.value.status])
</script>

<template>
  <template v-if="ev">
    <RouterLink class="back-link" :to="`/companies/${ev.companyId}`">← Tableau de bord</RouterLink>

    <header class="hero">
      <div style="min-width:0">
        <span class="eyebrow">{{ ev.showTitle }}</span>
        <h1>{{ ev.venue }}</h1>
        <div class="hero-chips">
          <span class="dates">{{ fperiod(ev.firstDate, ev.lastDate) }}</span>
          <span v-if="ev.city">{{ ev.city }}</span>
          <span>{{ ev.performances }} représentation{{ ev.performances > 1 ? 's' : '' }}</span>
          <span :class="status[1]">{{ status[0] }}</span>
        </div>
      </div>
      <div class="actions">
        <RouterLink class="btn" :to="`/events/${ev.id}/print/roadmap`" target="_blank"><Icon name="file" />Feuille de route PDF</RouterLink>
        <button class="btn btn-sun" @click="sharing = true"><Icon name="send" />Envoyer à l'équipe</button>
      </div>
    </header>

    <ol class="stepper" aria-label="Étapes de l'organisation">
      <li v-for="(s, i) in ev.readiness.steps" :key="s.key" :class="{ done: s.done }">
        <RouterLink :to="`/events/${ev.id}${PATHS[s.key]}`" :class="{ current: route.meta.step === s.key }" :aria-current="route.meta.step === s.key ? 'step' : null">
          <span class="state">{{ s.done ? '✓ ' : '' }}{{ s.detail }}</span><b>{{ i + 1 }} · {{ s.label }}</b>
        </RouterLink>
      </li>
    </ol>

    <div v-if="ev.overlaps.length" class="alert" role="note">
      Cette date chevauche
      <template v-for="(o, i) in ev.overlaps" :key="o.id"><RouterLink :to="`/events/${o.id}`">{{ o.showTitle }} — {{ o.venue }} ({{ fperiod(o.firstDate, o.lastDate) }})</RouterLink>{{ i < ev.overlaps.length - 1 ? ', ' : '' }}</template>.
      <template v-if="ev.participations.some((p) => p.conflicts.length)"> Engagé(e)s sur les deux : <b>{{ ev.participations.filter((p) => p.conflicts.length).map((p) => p.person.firstName || p.person.lastName).join(', ') }}</b>.</template>
    </div>

    <RouterView />
    <ShareDialog :open="sharing" :ev="ev" :apply="apply" @close="sharing = false" />
  </template>
</template>
