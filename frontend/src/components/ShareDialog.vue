<script setup>
import { computed, ref } from 'vue'
import { post, del, showError, toast } from '../api.js'
import { fperiod } from '../format.js'
import Modal from './Modal.vue'
import Icon from './Icon.vue'

/** Partage de la feuille de route : lien public révocable, copie, envoi par la messagerie. */
const props = defineProps({ open: Boolean, ev: Object, apply: Function })
const emit = defineEmits(['close'])
const busy = ref(false)
const url = computed(() => props.ev.share.token ? `${location.origin}/partage/${props.ev.share.token}` : '')
const withoutEmail = computed(() => props.ev.participations.filter((p) => !p.person.email).map((p) => p.person.firstName || p.person.lastName))
const mailto = computed(() => {
  const subject = `Feuille de route — ${props.ev.showTitle} — ${props.ev.venue}`
  const body = `Bonjour,\n\nVoici la feuille de route pour ${props.ev.showTitle} au ${props.ev.venue}${props.ev.city ? ` (${props.ev.city})` : ''}, ${fperiod(props.ev.firstDate, props.ev.lastDate)} :\n${url.value}\n\nVos billets sont accessibles depuis les boutons « Billet » du document.\n\nÀ bientôt !`
  return `mailto:?bcc=${encodeURIComponent(props.ev.share.emails.join(','))}&subject=${encodeURIComponent(subject)}&body=${encodeURIComponent(body)}`
})

async function create() {
  busy.value = true
  await props.apply(post(`/events/${props.ev.id}/share`))
  busy.value = false
}
async function copy() {
  try { await navigator.clipboard.writeText(url.value); toast('Lien copié') } catch { showError('Copie impossible : sélectionnez le lien à la main') }
}
async function markSent() { await props.apply(post(`/events/${props.ev.id}/share`)); toast('Feuille de route marquée comme partagée') }
async function revoke() {
  if (confirm('Révoquer le lien ? Les personnes qui l\'ont reçu ne pourront plus ouvrir la feuille de route.')) await props.apply(del(`/events/${props.ev.id}/share`))
}
</script>

<template>
  <Modal :open="open" title="Envoyer à l'équipe" @close="emit('close')">
    <template v-if="!ev.share.token">
      <p style="margin:0">Créez un lien vers la <b>feuille de route PDF</b> : chaque intervenant l'ouvre sans compte et récupère ses billets via les boutons « Billet ».</p>
      <p class="muted small" style="margin:0">Le lien est secret et peut être révoqué à tout moment. Il donne accès à tout le document (adresses, codes d'accès…) : ne le diffusez qu'à l'équipe.</p>
      <button class="btn btn-primary" :disabled="busy" @click="create"><Icon name="link" />Créer le lien de partage</button>
    </template>
    <template v-else>
      <label class="field">Lien de la feuille de route<input :value="url" readonly @focus="$event.target.select()"></label>
      <div class="actions">
        <button class="btn btn-primary" @click="copy"><Icon name="copy" />Copier le lien</button>
        <a class="btn btn-sun" :href="mailto" @click="markSent"><Icon name="send" />Écrire à l'équipe ({{ ev.share.emails.length }})</a>
        <a class="btn" :href="url" target="_blank" rel="noopener">Ouvrir</a>
      </div>
      <p v-if="withoutEmail.length" class="alert" style="margin:0">Sans e-mail dans l'annuaire : {{ withoutEmail.join(', ') }}. Envoyez-leur le lien autrement.</p>
      <p class="muted small" style="margin:0">Le lien reste valable : si vous modifiez la date, l'équipe voit toujours la version à jour.</p>
      <button class="btn btn-danger" style="align-self:flex-start" @click="revoke">Révoquer le lien</button>
    </template>
  </Modal>
</template>
