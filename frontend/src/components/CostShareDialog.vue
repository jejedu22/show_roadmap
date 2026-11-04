<script setup>
import { computed, ref } from 'vue'
import { post, del, showError, toast } from '../api.js'
import { fperiod } from '../format.js'
import Modal from './Modal.vue'
import Icon from './Icon.vue'

/** Partage de l'export Excel des coûts : lien public révocable (distinct de celui de la feuille de route), copie, envoi par la messagerie. */
const props = defineProps({ open: Boolean, ev: Object, apply: Function })
const emit = defineEmits(['close'])
const busy = ref(false)
const url = computed(() => props.ev.costShare?.token ? `${location.origin}/partage/couts/${props.ev.costShare.token}` : '')
const mailto = computed(() => {
  const subject = `Coûts — ${props.ev.showTitle} — ${props.ev.venue}`
  const body = `Bonjour,\n\nVoici le détail des coûts pour ${props.ev.showTitle} au ${props.ev.venue}${props.ev.city ? ` (${props.ev.city})` : ''}, ${fperiod(props.ev.firstDate, props.ev.lastDate)} (fichier Excel) :\n${url.value}\n\nLe fichier est toujours à jour : devis, défraiements, billets, dépenses et remboursements.\n\nBien cordialement,`
  return `mailto:?subject=${encodeURIComponent(subject)}&body=${encodeURIComponent(body)}`
})

async function create() {
  busy.value = true
  await props.apply(post(`/events/${props.ev.id}/cost-share`))
  busy.value = false
}
async function copy() {
  try { await navigator.clipboard.writeText(url.value); toast('Lien copié') } catch { showError('Copie impossible : sélectionnez le lien à la main') }
}
async function markSent() { await props.apply(post(`/events/${props.ev.id}/cost-share`)) }
async function revoke() {
  if (confirm('Révoquer le lien ? Les personnes qui l\'ont reçu ne pourront plus télécharger les coûts.')) await props.apply(del(`/events/${props.ev.id}/cost-share`))
}
</script>

<template>
  <Modal :open="open" title="Envoyer les coûts" @close="emit('close')">
    <template v-if="!ev.costShare?.token">
      <p style="margin:0">Créez un lien de téléchargement de l'<b>export Excel des coûts</b> : devis, tarifs, coûts par personne, défraiements (repas, petits-déjeuners, nuitées jour par jour), voyages (billets, dépenses et remboursements).</p>
      <p class="muted small" style="margin:0">Le lien s'ouvre sans compte, il est secret et révocable à tout moment, et distinct de celui de la feuille de route. Il ne contient ni coordonnées ni rooming.</p>
      <div class="actions">
        <button class="btn btn-primary" :disabled="busy" @click="create"><Icon name="link" />Créer le lien</button>
        <a class="btn" :href="`/api/events/${ev.id}/costs.xlsx`"><Icon name="download" :size="16" />Télécharger pour vérifier</a>
      </div>
    </template>
    <template v-else>
      <label class="field">Lien de l'export des coûts<input :value="url" readonly @focus="$event.target.select()"></label>
      <div class="actions">
        <button class="btn btn-primary" @click="copy"><Icon name="copy" />Copier le lien</button>
        <a class="btn btn-sun" :href="mailto" @click="markSent"><Icon name="send" />Écrire un e-mail</a>
        <a class="btn" :href="url"><Icon name="download" :size="16" />Télécharger</a>
      </div>
      <p class="muted small" style="margin:0">Le lien reste valable : le fichier est généré à chaque téléchargement, avec les montants à jour.
        <template v-if="ev.costShare.sharedAt"> Dernier envoi noté le {{ new Date(ev.costShare.sharedAt).toLocaleDateString('fr-FR') }}.</template></p>
      <button class="btn btn-danger" style="align-self:flex-start" @click="revoke">Révoquer le lien</button>
    </template>
  </Modal>
</template>
