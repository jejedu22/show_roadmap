<script setup>
import { computed, reactive, ref, watch } from 'vue'
import { useRouter } from 'vue-router'
import { can, post, showError, toast } from '../api.js'
import Modal from './Modal.vue'
import PeoplePicker from './PeoplePicker.vue'

/**
 * Création d'une date : choix (ou création) du spectacle, lieu, dates, équipe. L'équipe habituelle du spectacle est cochée
 * d'office : on décoche ceux qui n'y seront pas ; les autres personnes de l'annuaire peuvent être ajoutées.
 */
const props = defineProps({ open: Boolean, company: Object, showId: Number })
const emit = defineEmits(['close'])
const router = useRouter()
const form = reactive({ showId: '', newShow: '', venue: '', city: '', firstDate: '', lastDate: '', personIds: [] })
const others = ref(false)

watch(() => props.open, (open) => {
  if (open) Object.assign(form, { showId: props.showId || props.company?.shows[0]?.id || (can('shows.edit', props.company?.permissions) ? 'new' : ''), newShow: '', venue: '', city: '', firstDate: '', lastDate: '' })
})
const show = computed(() => props.company?.shows.find((s) => s.id === form.showId))
const people = computed(() => props.company?.people || [])
const usual = computed(() => people.value.filter((p) => show.value?.defaultPersonIds?.includes(p.id)))
const rest = computed(() => people.value.filter((p) => !usual.value.includes(p)))
// Spectacle choisi : son équipe habituelle cochée ; nouveau spectacle : personne (le choix devient son équipe habituelle)
watch(() => [form.showId, props.open], () => { form.personIds = usual.value.map((p) => p.id); others.value = !usual.value.length })

async function submit() {
  try {
    let showId = form.showId
    if (showId === 'new') {
      const c = await post(`/companies/${props.company.id}/shows`, { title: form.newShow, defaultPersonIds: form.personIds })
      showId = Math.max(...c.shows.filter((s) => s.title === form.newShow.trim()).map((s) => s.id))
    }
    const ev = await post(`/shows/${showId}/events`, { venue: form.venue, city: form.city, firstDate: form.firstDate, lastDate: form.lastDate || form.firstDate,
      personIds: can('events.edit', props.company?.permissions) ? form.personIds : [] })
    toast('Date créée')
    emit('close')
    router.push(`/events/${ev.id}/team`)
  } catch (e) { showError(e) }
}
</script>

<template>
  <Modal :open="open" title="Nouvelle date" @close="emit('close')">
    <form style="display:flex;flex-direction:column;gap:14px" @submit.prevent="submit">
      <label class="field">Spectacle
        <select v-model="form.showId" required>
          <option v-for="s in company?.shows || []" :key="s.id" :value="s.id">{{ s.title }}</option>
          <option v-if="can('shows.edit', company?.permissions)" value="new">＋ Nouveau spectacle…</option>
        </select>
      </label>
      <label v-if="form.showId === 'new'" class="field">Titre du nouveau spectacle<input v-model="form.newShow" required></label>
      <div class="form-grid">
        <label class="field">Lieu / théâtre<input v-model="form.venue" required placeholder="Théâtre du Beauvaisis"></label>
        <label class="field">Ville<input v-model="form.city" placeholder="Beauvais"></label>
        <label class="field">1re représentation<input v-model="form.firstDate" type="date" required></label>
        <label class="field">Dernière représentation<input v-model="form.lastDate" type="date" :min="form.firstDate"></label>
      </div>
      <template v-if="people.length">
        <PeoplePicker v-if="usual.length" v-model="form.personIds" :people="usual" label="Équipe habituelle du spectacle : décochez ceux qui n'y seront pas" />
        <button v-if="usual.length && rest.length && !others" type="button" class="btn btn-sm btn-ghost" style="align-self:flex-start" @click="others = true">
          ＋ Ajouter d'autres personnes de l'annuaire ({{ rest.length }})</button>
        <PeoplePicker v-if="others && rest.length" v-model="form.personIds" :people="rest"
          :label="form.showId === 'new' ? 'Équipe : elle deviendra aussi l\'équipe habituelle du spectacle' : usual.length ? 'Autres personnes de l\'annuaire' : 'Équipe de la date (ce spectacle n\'a pas encore d\'équipe habituelle)'" />
      </template>
      <p class="muted small" style="margin:0">Vous pourrez ensuite compléter l'équipe, l'hébergement, les voyages et le devis.</p>
      <div class="actions"><button class="btn btn-primary">Créer la date</button><button type="button" class="btn btn-ghost" @click="emit('close')">Annuler</button></div>
    </form>
  </Modal>
</template>
