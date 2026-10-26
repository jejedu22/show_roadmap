<script setup>
import { computed, reactive, ref, onMounted } from 'vue'
import { api, get, post, put, session, showError, toast } from '../../api.js'
import { fdate, relative } from '../../format.js'
import Avatar from '../../components/Avatar.vue'
import Icon from '../../components/Icon.vue'
import Modal from '../../components/Modal.vue'

const data = ref(null)
const query = ref('')
const filter = ref('all')
const open = ref(null)
const edit = reactive({ name: '', groupIds: [], isActive: true, isAdmin: false })
const inviting = ref(false)
const invite = reactive({ email: '', name: '', groupIds: [] })
const link = ref(null) // { url, email, type }

function load(d) {
  data.value = d
  if (d.link) link.value = { url: d.link, email: d.linkFor, type: d.linkType }
}
onMounted(async () => load(await get('/admin/users')))

const FILTERS = [['all', 'Tous'], ['active', 'Actifs'], ['pending', 'Invitations en attente'], ['inactive', 'Désactivés']]
const users = computed(() => {
  const q = query.value.trim().toLowerCase()
  return data.value.users.filter((u) => (!q || `${u.name} ${u.email}`.toLowerCase().includes(q))
    && (filter.value === 'all' || (filter.value === 'active' && u.isActive && !u.invitationExpiresAt)
      || (filter.value === 'pending' && u.invitationExpiresAt) || (filter.value === 'inactive' && !u.isActive)))
})
const groupName = (id) => data.value.groups.find((g) => g.id === id)?.name
const lockedGroup = (g) => g.admin && !session.user.isAdmin
const initialsOf = (u) => (u.name || u.email).split(/[\s@.]+/).filter(Boolean).slice(0, 2).map((w) => w[0]).join('').toUpperCase()

function toggle(u) {
  if (open.value === u.id) { open.value = null; return }
  open.value = u.id
  Object.assign(edit, { name: u.name, groupIds: [...u.groupIds], isActive: u.isActive, isAdmin: u.isAdmin })
}
async function run(promise, msg) { try { load(await promise); if (msg) toast(msg); return true } catch (e) { showError(e) } }
const save = (u) => run(put(`/admin/users/${u.id}`, edit), 'Compte mis à jour')
const makeLink = (u) => run(post(`/admin/users/${u.id}/link`), null)
const remove = (u) => confirm(`Supprimer définitivement le compte ${u.email} ?`) && run(api(`/admin/users/${u.id}`, { method: 'DELETE', body: {} }), 'Compte supprimé').then((ok) => ok && (open.value = null))
async function sendInvite() {
  if (await run(post('/admin/users', invite), `Compte créé pour ${invite.email}`)) { inviting.value = false; Object.assign(invite, { email: '', name: '', groupIds: [] }) }
}
async function copy() { try { await navigator.clipboard.writeText(link.value.url); toast('Lien copié') } catch { showError('Copie impossible : sélectionnez le lien') } }
const mailto = computed(() => {
  if (!link.value) return ''
  const invite = link.value.type === 'invite'
  const subject = invite ? 'Votre accès à Show Roadmap' : 'Réinitialisation de votre mot de passe Show Roadmap'
  const body = `Bonjour,\n\n${invite ? 'Un compte Show Roadmap a été créé pour vous.' : 'Voici un lien pour choisir un nouveau mot de passe.'}\nOuvrez ce lien pour choisir votre mot de passe (valable ${invite ? '7 jours' : '24 heures'}, une seule fois) :\n${link.value.url}\n\nÀ bientôt !`
  return `mailto:${encodeURIComponent(link.value.email)}?subject=${encodeURIComponent(subject)}&body=${encodeURIComponent(body)}`
})
</script>

<template>
  <div class="page-head">
    <div><h1>Utilisateurs</h1><p>Comptes, groupes et accès. Les droits viennent des groupes ; les mots de passe ne passent jamais par vous.</p></div>
    <button class="btn btn-primary" @click="inviting = true"><Icon name="plus" />Inviter quelqu'un</button>
  </div>

  <template v-if="data">
    <div class="actions">
      <label class="field" style="flex:1 1 280px;max-width:420px"><span class="sr-only">Rechercher</span><input v-model="query" type="search" placeholder="Rechercher un nom, une adresse…"></label>
      <div class="filters" role="group" aria-label="Filtrer">
        <button v-for="[k, label] in FILTERS" :key="k" type="button" :aria-pressed="filter === k" @click="filter = k">{{ label }}</button>
      </div>
    </div>

    <div class="card" style="padding:6px 20px">
      <div v-for="u in users" :key="u.id" style="border-bottom:1px solid var(--line);padding:12px 0">
        <div class="person-head">
          <Avatar :id="u.id" :initials="initialsOf(u)" :name="u.name || u.email" />
          <span class="who"><b>{{ u.name || u.email }}</b><span class="muted small">{{ u.email }}</span></span>
          <span class="actions" style="gap:6px">
            <span v-if="u.isAdmin" class="chip info">Super-admin</span>
            <span v-if="!u.isActive" class="chip bad">Désactivé</span>
            <span v-else-if="u.invitationExpiresAt" class="chip warn">Invitation jusqu'au {{ fdate(u.invitationExpiresAt.slice(0, 10), true) }}</span>
            <span v-else class="muted small">{{ u.lastLoginAt ? `Connecté ${relative(u.lastLoginAt.slice(0, 10))}` : 'Jamais connecté' }}</span>
            <span v-for="id in u.groupIds" :key="id" class="chip dark">{{ groupName(id) }}</span>
          </span>
          <button class="btn btn-sm status" :aria-expanded="open === u.id" @click="toggle(u)">{{ open === u.id ? 'Fermer' : 'Gérer' }}</button>
        </div>

        <form v-if="open === u.id" class="card" style="margin-top:12px;background:var(--bg)" @submit.prevent="save(u)">
          <div class="form-grid">
            <label class="field">Nom affiché<input v-model="edit.name"></label>
            <div>
              <label class="check"><input v-model="edit.isActive" type="checkbox" :disabled="u.id === session.user.id"> Compte actif</label>
              <label v-if="session.user.isAdmin" class="check"><input v-model="edit.isAdmin" type="checkbox" :disabled="u.id === session.user.id"> Super-administrateur (tous les droits)</label>
            </div>
          </div>
          <fieldset style="border:0;padding:0;margin:14px 0 0">
            <legend class="field" style="margin-bottom:6px">Groupes</legend>
            <div class="form-grid" style="gap:0 14px">
              <label v-for="g in data.groups" :key="g.id" class="check" :title="lockedGroup(g) ? 'Groupe d\'administration : réservé aux super-administrateurs' : ''">
                <input v-model="edit.groupIds" type="checkbox" :value="g.id" :disabled="lockedGroup(g)"> {{ g.name }}<span v-if="g.admin" class="chip info" style="margin-left:4px">admin</span></label>
            </div>
            <p class="muted small" style="margin:6px 0 0"><RouterLink to="/admin/groups">Voir ce que permet chaque groupe</RouterLink></p>
          </fieldset>
          <div class="actions" style="margin-top:14px">
            <button class="btn btn-primary">Enregistrer</button>
            <button v-if="u.isActive" type="button" class="btn" @click="makeLink(u)"><Icon name="link" :size="16" />{{ u.lastLoginAt ? 'Lien de réinitialisation' : "Nouveau lien d'invitation" }}</button>
            <button v-if="u.id !== session.user.id" type="button" class="btn btn-danger" style="margin-left:auto" @click="remove(u)"><Icon name="trash" :size="16" />Supprimer</button>
          </div>
        </form>
      </div>
      <p v-if="!users.length" class="empty">Aucun compte ne correspond.</p>
    </div>
  </template>

  <Modal :open="inviting" title="Inviter quelqu'un" @close="inviting = false">
    <form style="display:flex;flex-direction:column;gap:14px" @submit.prevent="sendInvite">
      <label class="field">Adresse e-mail<input v-model="invite.email" type="email" required autocomplete="off"></label>
      <label class="field">Nom (facultatif)<input v-model="invite.name"></label>
      <fieldset v-if="data" style="border:0;padding:0;margin:0"><legend class="field" style="margin-bottom:6px">Groupes</legend>
        <label v-for="g in data.groups" :key="g.id" class="check"><input v-model="invite.groupIds" type="checkbox" :value="g.id" :disabled="lockedGroup(g)"> {{ g.name }}</label>
      </fieldset>
      <p class="muted small" style="margin:0">Vous obtiendrez un lien personnel, valable 7 jours, pour que la personne choisisse son mot de passe. Elle peut aussi se connecter avec Google si c'est activé.</p>
      <div class="actions"><button class="btn btn-primary">Créer le compte</button><button type="button" class="btn btn-ghost" @click="inviting = false">Annuler</button></div>
    </form>
  </Modal>

  <Modal :open="!!link" :title="link?.type === 'invite' ? 'Lien d\'invitation' : 'Lien de réinitialisation'" @close="link = null">
    <template v-if="link">
      <p style="margin:0">Transmettez ce lien à <b>{{ link.email }}</b>. Il est personnel, valable {{ link.type === 'invite' ? '7 jours' : '24 heures' }} et ne sert qu'une fois.
        <b>Il ne sera plus affiché</b> : copiez-le maintenant.</p>
      <label class="field">Lien<input :value="link.url" readonly @focus="$event.target.select()"></label>
      <div class="actions">
        <button class="btn btn-primary" @click="copy"><Icon name="copy" />Copier</button>
        <a class="btn btn-sun" :href="mailto"><Icon name="send" />Envoyer par e-mail</a>
      </div>
    </template>
  </Modal>
</template>
