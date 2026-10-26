<script setup>
import { computed } from 'vue'
import { dateBox, daysFrom } from '../format.js'
import Avatar from './Avatar.vue'

/** Une date de tournée dans une liste : bloc date, lieu, statut, préparation, équipe. */
const props = defineProps({ e: Object })
const box = computed(() => dateBox(props.e.firstDate, props.e.lastDate))
const past = computed(() => daysFrom(props.e.end) < 0)
const r = computed(() => props.e.readiness)
const pct = computed(() => Math.round((r.value.done / r.value.total) * 100))
const barColor = computed(() => pct.value >= 80 ? 'var(--violet)' : pct.value >= 40 ? 'var(--sun)' : 'var(--coral)')
</script>

<template>
  <RouterLink class="event-row" :to="`/events/${e.id}`">
    <span class="datebox" :class="{ option: e.status === 'option', past }"><b>{{ box[0] }}</b><span>{{ box[1] }}</span></span>
    <span class="event-main">
      <span class="event-title">
        <b>{{ e.venue }}</b>
        <span v-if="e.status === 'option'" class="chip warn">Option</span>
        <span v-else-if="e.status === 'cancelled'" class="chip bad">Annulé</span>
        <span v-else class="chip ok">Confirmé</span>
        <span v-for="name in e.conflicts" :key="name" class="chip bad">Conflit · {{ name }}</span>
      </span>
      <span class="muted small">{{ e.showTitle }}<template v-if="e.city"> · {{ e.city }}</template> · {{ e.teamSize }} personne{{ e.teamSize > 1 ? 's' : '' }}</span>
    </span>
    <span class="event-side">
      <span style="display:flex;justify-content:space-between;font-size:12px" class="muted"><span>Préparation</span><b style="color:var(--ink)">{{ r.done }} / {{ r.total }}</b></span>
      <span class="progress" role="progressbar" :aria-valuenow="r.done" aria-valuemin="0" :aria-valuemax="r.total" :aria-label="`Préparation ${r.done} sur ${r.total}`">
        <span :style="{ width: pct + '%', background: barColor }" /></span>
      <span v-if="e.team.length" class="avatars"><Avatar v-for="m in e.team.slice(0, 7)" :id="m.id" :key="m.id" small :initials="m.initials" :name="m.name" />
        <span v-if="e.team.length > 7" class="muted small" style="align-self:center">+{{ e.team.length - 7 }}</span></span>
      <span v-else class="muted small">Aucune personne affectée</span>
    </span>
  </RouterLink>
</template>
