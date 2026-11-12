<script setup>
import { computed, inject, reactive, ref, watch } from 'vue'
import { post, put, del, toast, showError } from '../../api.js'
import Avatar from '../../components/Avatar.vue'
import Icon from '../../components/Icon.vue'
import ListSelect from '../../components/ListSelect.vue'

const { ev, apply, allowed } = inject('event')
const rows = reactive({})
const selected = ref([])
const query = ref('')
const creating = ref(false)
const person = reactive({ lastName: '', firstName: '', function: '', homeCity: '', phone: '', email: '' })
watch(ev, (e) => {
  // group : même valeur pour les occupants d'une même chambre partagée
  for (const p of e.participations) rows[p.id] = { id: p.id, role: p.role, roomType: p.roomType, group: p.roomWith?.length ? `g${Math.min(p.id, ...p.roomWith)}` : '',
    departureCity: p.departureCity, lodgingId: p.lodgingId ?? '', note: p.note }
}, { immediate: true })

// Chambres partagées : double, twin (deux personnes) ou appartement (plusieurs) ; « group » relie les occupants
const ROOMS = { single: 'Single', double: 'Double (grand lit)', twin: 'Twin (deux lits)', appartement: 'Appartement' }
const MAX = { double: 2, twin: 2, appartement: Infinity }
const shared = (r) => r.roomType in MAX
const nameOf = (id) => { const p = ev.value.participations.find((x) => x.id === id); return p ? p.person.fullName : '' }
const occupants = (id) => rows[id]?.group ? Object.values(rows).filter((r) => r.group === rows[id].group).map((r) => r.id) : [id]
const others = (id) => occupants(id).filter((x) => x !== id)
const leave = (id) => {
  const rest = others(id)
  rows[id].group = ''
  if (rest.length === 1) rows[rest[0]].group = '' // une personne seule ne partage plus rien
}
// Double, twin : une seule autre personne ; libres, ou déjà dans cette chambre
const mates = (p) => ev.value.participations.filter((o) => o.id !== p.id && (!rows[o.id]?.group || rows[o.id].group === rows[p.id].group))
function setMate(id, mateId) {
  others(id).forEach(leave)
  if (!mateId) return leave(id)
  leave(mateId)
  rows[id].group = rows[mateId].group = `g${Math.min(id, mateId)}`
  rows[mateId].roomType = rows[id].roomType
}
// Appartement : on coche ou décoche les colocataires
function toggleFlatmate(id, otherId) {
  if (others(id).includes(otherId)) return leave(otherId)
  leave(otherId)
  rows[id].group ||= `g${id}`
  Object.assign(rows[otherId], { group: rows[id].group, roomType: rows[id].roomType })
}
function setRoomType(id, type) {
  const group = others(id)
  rows[id].roomType = type
  if (!shared(rows[id]) || group.length + 1 > MAX[type]) leave(id) // plus de partage, ou chambre trop petite
  else group.forEach((o) => { rows[o].roomType = type })
}
const roomWarning = (p) => {
  const r = rows[p.id]
  if (!r || !shared(r)) return ''
  if (!others(p.id).length) return r.roomType === 'appartement' ? '' : 'Chambre à partager : choisissez le second occupant'
  const diff = others(p.id).filter((o) => String(rows[o].lodgingId ?? '') !== String(r.lodgingId ?? ''))
  return diff.length ? `Hébergement différent de ${diff.map(nameOf).join(', ')}` : ''
}

const available = computed(() => {
  const q = query.value.trim().toLowerCase()
  return ev.value.availablePeople.filter((p) => !q || `${p.fullName} ${p.function}`.toLowerCase().includes(q))
})
async function save() {
  const participations = Object.values(rows).map(({ group, ...r }) => ({ ...r, roomWith: others(r.id) }))
  if (await apply(put(`/events/${ev.value.id}/participations`, { participations }))) toast('Équipe enregistrée')
}
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
    <form class="main-col" @submit.prevent="save"><fieldset :disabled="!allowed('events.edit')" style="border:0;padding:0;margin:0;display:flex;flex-direction:column;gap:12px;min-width:0">
      <div class="page-head"><h2>Équipe de la date</h2><button v-if="ev.participations.length && allowed('events.edit')" class="btn btn-primary">Enregistrer</button></div>
      <article v-for="p in ev.participations" :key="p.id" class="card" :class="{ warn: p.conflicts.length }">
        <div class="person-head">
          <Avatar :id="p.person.id" :initials="(p.person.firstName.charAt(0) + p.person.lastName.charAt(0)).toUpperCase()" :name="p.person.fullName" />
          <span class="who"><b>{{ p.person.fullName }}</b><span class="muted small">{{ p.person.phone || p.person.email || 'Pas de coordonnées' }}</span></span>
          <span v-if="p.conflicts.length" class="chip bad">Aussi sur <RouterLink v-for="o in p.conflicts" :key="o.id" :to="`/events/${o.id}`" style="color:inherit">{{ o.showTitle }}</RouterLink></span>
          <button v-if="allowed('events.edit')" type="button" class="btn btn-sm btn-icon btn-danger status" :aria-label="`Retirer ${p.person.fullName}`" @click="remove(p)"><Icon name="trash" :size="16" /></button>
        </div>
        <div class="form-grid" style="margin-top:12px">
          <label class="field">Fonction sur cette date<ListSelect v-model="rows[p.id].role" kind="function" /></label>
          <label class="field">Ville de départ<input v-model="rows[p.id].departureCity"></label>
          <label class="field">Chambre<select :value="rows[p.id].roomType" @change="setRoomType(p.id, $event.target.value)">
            <option v-for="(label, r) in ROOMS" :key="r" :value="r">{{ label }}</option></select></label>
          <label v-if="shared(rows[p.id]) && rows[p.id].roomType !== 'appartement'" class="field">Partagée avec
            <select :value="others(p.id)[0] ?? ''" @change="setMate(p.id, Number($event.target.value) || '')">
              <option value="">— à choisir —</option><option v-for="o in mates(p)" :key="o.id" :value="o.id">{{ o.person.fullName }}</option></select></label>
          <fieldset v-if="rows[p.id].roomType === 'appartement'" class="field wide flatmates"><legend>Partagé avec</legend>
            <label v-for="o in ev.participations.filter((x) => x.id !== p.id)" :key="o.id" class="flatmate">
              <input type="checkbox" :checked="others(p.id).includes(o.id)" @change="toggleFlatmate(p.id, o.id)">{{ o.person.fullName }}</label>
          </fieldset>
          <label class="field">Hébergement<select v-model="rows[p.id].lodgingId"><option value="">— à choisir —</option><option v-for="l in ev.lodgings" :key="l.id" :value="l.id">{{ l.name }}</option></select></label>
          <label class="field wide">Note<input v-model="rows[p.id].note" placeholder="Arrive en voiture, allergie…"></label>
        </div>
        <p v-if="roomWarning(p)" class="chip warn" style="margin:10px 0 0;white-space:normal">{{ roomWarning(p) }}</p>
      </article>
      <div v-if="!ev.participations.length" class="card empty"><h3>Personne n'est encore affecté</h3><p>Choisissez les membres de l'équipe dans la colonne de droite.</p></div>
      <div v-if="ev.participations.length" class="actions"><button v-if="allowed('events.edit')" class="btn btn-primary">Enregistrer l'équipe</button>
        <RouterLink class="btn" :to="`/events/${ev.id}/travel`">Étape suivante : voyages →</RouterLink></div>
    </fieldset></form>

    <aside v-if="allowed('events.edit')" class="side-col">
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
          <button v-if="allowed('people.edit')" type="button" class="btn" @click="creating = !creating"><Icon name="plus" :size="16" />Nouvelle personne</button>
        </div>
        <form v-if="creating" style="display:flex;flex-direction:column;gap:10px;margin-top:14px" @submit.prevent="createAndAdd">
          <label class="field">Nom<input v-model="person.lastName" required></label>
          <label class="field">Prénom<input v-model="person.firstName"></label>
          <label class="field">Fonction<ListSelect v-model="person.function" kind="function" /></label>
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

<style scoped>
.flatmates { border: 0; padding: 0; margin: 0; display: flex; flex-wrap: wrap; gap: 6px 14px; }
.flatmates legend { padding: 0; margin-bottom: 4px; }
.flatmate { display: inline-flex; align-items: center; gap: 6px; color: var(--ink); font-size: 14px; }
.flatmate input { width: 16px; height: 16px; min-height: 0; }
</style>
