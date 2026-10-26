<script setup>
import { computed, inject, reactive, ref, watch } from 'vue'
import { post, put, del, toast, showError } from '../../api.js'
import Avatar from '../../components/Avatar.vue'
import Icon from '../../components/Icon.vue'

const { ev, apply } = inject('event')
const rows = reactive({})
const selected = ref([])
const query = ref('')
const creating = ref(false)
const person = reactive({ lastName: '', firstName: '', function: '', homeCity: '', phone: '', email: '' })
watch(ev, (e) => {
  for (const p of e.participations) rows[p.id] = { id: p.id, role: p.role, roomType: p.roomType, departureCity: p.departureCity, lodgingId: p.lodgingId ?? '', note: p.note }
}, { immediate: true })

const available = computed(() => {
  const q = query.value.trim().toLowerCase()
  return ev.value.availablePeople.filter((p) => !q || `${p.fullName} ${p.function}`.toLowerCase().includes(q))
})
async function save() { if (await apply(put(`/events/${ev.value.id}/participations`, { participations: Object.values(rows) }))) toast('Équipe enregistrée') }
const remove = (p) => confirm(`Retirer ${p.person.fullName} de cette date ?`) && apply(del(`/events/${ev.value.id}/participations/${p.id}`))
async function add() {
  const n = selected.value.length
  if (await apply(post(`/events/${ev.value.id}/participations`, { personIds: selected.value }))) { selected.value = []; toast(`${n} personne${n > 1 ? 's' : ''} ajoutée${n > 1 ? 's' : ''}`) }
}
/** Crée la personne dans l'annuaire puis l'ajoute directement à la date. */
async function createAndAdd() {
  try {
    const company = await post(`/companies/${ev.value.companyId}/people`, person)
    const created = company.people.filter((p) => p.lastName === person.lastName.trim() && p.firstName === person.firstName.trim()).sort((a, b) => b.id - a.id)[0]
    if (await apply(post(`/events/${ev.value.id}/participations`, { personIds: [created.id] }))) {
      toast(`${created.fullName} ajouté(e) à l'annuaire et à la date`)
      Object.assign(person, { lastName: '', firstName: '', function: '', homeCity: '', phone: '', email: '' })
      creating.value = false
    }
  } catch (e) { showError(e) }
}
</script>

<template>
  <div class="two-cols">
    <form class="main-col" @submit.prevent="save">
      <div class="page-head"><h2>Équipe de la date</h2><button v-if="ev.participations.length" class="btn btn-primary">Enregistrer</button></div>
      <article v-for="p in ev.participations" :key="p.id" class="card" :class="{ warn: p.conflicts.length }">
        <div class="person-head">
          <Avatar :id="p.person.id" :initials="(p.person.firstName.charAt(0) + p.person.lastName.charAt(0)).toUpperCase()" :name="p.person.fullName" />
          <span class="who"><b>{{ p.person.fullName }}</b><span class="muted small">{{ p.person.phone || p.person.email || 'Pas de coordonnées' }}</span></span>
          <span v-if="p.conflicts.length" class="chip bad">Aussi sur <RouterLink v-for="o in p.conflicts" :key="o.id" :to="`/events/${o.id}`" style="color:inherit">{{ o.showTitle }}</RouterLink></span>
          <button type="button" class="btn btn-sm btn-icon btn-danger status" :aria-label="`Retirer ${p.person.fullName}`" @click="remove(p)"><Icon name="trash" :size="16" /></button>
        </div>
        <div class="form-grid" style="margin-top:12px">
          <label class="field">Fonction sur cette date<input v-model="rows[p.id].role"></label>
          <label class="field">Ville de départ<input v-model="rows[p.id].departureCity"></label>
          <label class="field">Chambre<select v-model="rows[p.id].roomType"><option v-for="r in ['single', 'double', 'twin', 'appartement']" :key="r">{{ r }}</option></select></label>
          <label class="field">Hébergement<select v-model="rows[p.id].lodgingId"><option value="">— à choisir —</option><option v-for="l in ev.lodgings" :key="l.id" :value="l.id">{{ l.name }}</option></select></label>
          <label class="field wide">Note<input v-model="rows[p.id].note" placeholder="Arrive en voiture, allergie…"></label>
        </div>
      </article>
      <div v-if="!ev.participations.length" class="card empty"><h3>Personne n'est encore affecté</h3><p>Choisissez les membres de l'équipe dans la colonne de droite.</p></div>
      <div v-if="ev.participations.length" class="actions"><button class="btn btn-primary">Enregistrer l'équipe</button>
        <RouterLink class="btn" :to="`/events/${ev.id}/stay`">Étape suivante : séjour →</RouterLink></div>
    </form>

    <aside class="side-col">
      <div class="card">
        <h2 style="margin-bottom:12px">Ajouter</h2>
        <label v-if="ev.availablePeople.length > 6" class="field" style="margin-bottom:8px"><span class="sr-only">Rechercher</span><input v-model="query" type="search" placeholder="Rechercher…"></label>
        <div style="display:flex;flex-direction:column;max-height:360px;overflow:auto">
          <label v-for="p in available" :key="p.id" class="check"><input v-model="selected" type="checkbox" :value="p.id">
            <span>{{ p.fullName }} <span class="muted small">{{ p.function }}</span></span></label>
        </div>
        <p v-if="!ev.availablePeople.length" class="muted small" style="margin:0">Tout l'annuaire est déjà sur cette date.</p>
        <div class="actions" style="margin-top:12px">
          <button type="button" class="btn btn-primary" :disabled="!selected.length" @click="add">Ajouter {{ selected.length || '' }}</button>
          <button type="button" class="btn" @click="creating = !creating"><Icon name="plus" :size="16" />Nouvelle personne</button>
        </div>
        <form v-if="creating" style="display:flex;flex-direction:column;gap:10px;margin-top:14px" @submit.prevent="createAndAdd">
          <label class="field">Nom<input v-model="person.lastName" required></label>
          <label class="field">Prénom<input v-model="person.firstName"></label>
          <label class="field">Fonction<input v-model="person.function"></label>
          <label class="field">Ville de départ<input v-model="person.homeCity"></label>
          <label class="field">Téléphone<input v-model="person.phone" type="tel"></label>
          <label class="field">E-mail<input v-model="person.email" type="email"></label>
          <button class="btn btn-primary">Créer et ajouter</button>
        </form>
        <p class="muted small" style="margin:12px 0 0">Nuitées et repas sont pré-remplis selon les dates d'arrivée et de départ.</p>
      </div>
    </aside>
  </div>
</template>
