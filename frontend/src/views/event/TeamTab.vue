<script setup>
import { inject, reactive, ref, watch } from 'vue'
import { post, put, del } from '../../api.js'

const { ev, apply } = inject('event')
const rows = reactive({})
const selected = ref([])
watch(ev, (e) => {
  for (const p of e.participations) rows[p.id] = { id: p.id, role: p.role, roomType: p.roomType, departureCity: p.departureCity, lodgingId: p.lodgingId ?? '', note: p.note }
}, { immediate: true })

const save = () => apply(put(`/events/${ev.value.id}/participations`, { participations: Object.values(rows) }))
const remove = (p) => confirm(`Retirer ${p.person.fullName} de cette date ?`) && apply(del(`/events/${ev.value.id}/participations/${p.id}`))
async function add() {
  if (await apply(post(`/events/${ev.value.id}/participations`, { personIds: selected.value }))) selected.value = []
}
</script>

<template>
  <form class="card scroll" @submit.prevent="save">
    <table>
      <thead><tr><th>Nom</th><th>Fonction sur cette date</th><th>Chambre</th><th>Hébergement</th><th>Ville de départ</th><th>Note</th><th></th></tr></thead>
      <tbody>
        <tr v-for="p in ev.participations" :key="p.id">
          <td><b>{{ p.person.fullName }}</b>
            <br v-if="p.conflicts.length"><span v-if="p.conflicts.length" class="badge bad">⚠ aussi sur
              <template v-for="(o, i) in p.conflicts" :key="o.id"><RouterLink :to="`/events/${o.id}`">{{ o.showTitle }}</RouterLink>{{ i < p.conflicts.length - 1 ? ', ' : '' }}</template></span></td>
          <td><input v-model="rows[p.id].role"></td>
          <td><select v-model="rows[p.id].roomType"><option v-for="r in ['single', 'double', 'twin', 'appartement']" :key="r">{{ r }}</option></select></td>
          <td><select v-model="rows[p.id].lodgingId"><option value="">—</option><option v-for="l in ev.lodgings" :key="l.id" :value="l.id">{{ l.name }}</option></select></td>
          <td><input v-model="rows[p.id].departureCity"></td>
          <td><input v-model="rows[p.id].note"></td>
          <td><button type="button" class="danger" @click="remove(p)">✕</button></td>
        </tr>
        <tr v-if="!ev.participations.length"><td colspan="7" class="muted">Personne n'est encore affecté à cette date.</td></tr>
      </tbody>
    </table>
    <div v-if="ev.participations.length" class="actions"><button>Enregistrer</button></div>
  </form>

  <div class="card">
    <h3>Ajouter des personnes à cette date</h3>
    <template v-if="ev.availablePeople.length">
      <div class="grid g3">
        <label v-for="p in ev.availablePeople" :key="p.id" style="font-size:.95rem;color:var(--ink)">
          <input v-model="selected" type="checkbox" :value="p.id"> {{ p.fullName }} <span class="muted small">{{ p.function }}</span></label>
      </div>
      <div class="actions"><button :disabled="!selected.length" @click="add">Ajouter la sélection</button></div>
    </template>
    <p v-else class="muted">Tout l'annuaire est déjà affecté. <RouterLink :to="`/companies/${ev.companyId}/people`">Gérer l'annuaire</RouterLink></p>
    <p class="muted small">Les grilles de nuitées et de repas sont pré-remplies selon les dates d'arrivée/départ ; ajustez-les dans l'onglet Séjour.</p>
  </div>
</template>
