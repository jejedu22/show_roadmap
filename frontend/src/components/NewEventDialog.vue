<script setup>
import { reactive, watch } from 'vue'
import { useRouter } from 'vue-router'
import { post, showError, toast } from '../api.js'
import Modal from './Modal.vue'

/** Création d'une date : choix (ou création) du spectacle, lieu, dates. */
const props = defineProps({ open: Boolean, company: Object, showId: Number })
const emit = defineEmits(['close'])
const router = useRouter()
const form = reactive({ showId: '', newShow: '', venue: '', city: '', firstDate: '', lastDate: '' })

watch(() => props.open, (open) => {
  if (open) Object.assign(form, { showId: props.showId || props.company?.shows[0]?.id || 'new', newShow: '', venue: '', city: '', firstDate: '', lastDate: '' })
})

async function submit() {
  try {
    let showId = form.showId
    if (showId === 'new') {
      const c = await post(`/companies/${props.company.id}/shows`, { title: form.newShow })
      showId = Math.max(...c.shows.filter((s) => s.title === form.newShow.trim()).map((s) => s.id))
    }
    const ev = await post(`/shows/${showId}/events`, { venue: form.venue, city: form.city, firstDate: form.firstDate, lastDate: form.lastDate || form.firstDate })
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
          <option value="new">＋ Nouveau spectacle…</option>
        </select>
      </label>
      <label v-if="form.showId === 'new'" class="field">Titre du nouveau spectacle<input v-model="form.newShow" required></label>
      <div class="form-grid">
        <label class="field">Lieu / théâtre<input v-model="form.venue" required placeholder="Théâtre du Beauvaisis"></label>
        <label class="field">Ville<input v-model="form.city" placeholder="Beauvais"></label>
        <label class="field">1re représentation<input v-model="form.firstDate" type="date" required></label>
        <label class="field">Dernière représentation<input v-model="form.lastDate" type="date" :min="form.firstDate"></label>
      </div>
      <p class="muted small" style="margin:0">Vous pourrez ensuite composer l'équipe, l'hébergement, les voyages et le devis.</p>
      <div class="actions"><button class="btn btn-primary">Créer la date</button><button type="button" class="btn btn-ghost" @click="emit('close')">Annuler</button></div>
    </form>
  </Modal>
</template>
