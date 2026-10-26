<script setup>
import { onMounted, reactive, ref } from 'vue'
import { get, put, del, session, toast, showError } from '../api.js'
import Icon from '../components/Icon.vue'

const form = reactive({ name: session.user.name, currentPassword: '', newPassword: '' })
const rights = ref(null)
const devices = ref([])
onMounted(async () => { [rights.value, devices.value] = await Promise.all([get('/me/rights'), get('/me/devices')]) })

const when = (iso) => new Date(iso).toLocaleString('fr-FR', { day: 'numeric', month: 'short', year: 'numeric', hour: '2-digit', minute: '2-digit' })
async function revoke(d) {
  if (!confirm(d.current ? 'Ne plus faire confiance à cet appareil ? Vous resterez connecté jusqu\'à la fermeture du navigateur.' : `Révoquer « ${d.label} » ? Il faudra s'y reconnecter.`)) return
  try { devices.value = await del(`/me/devices/${d.id}`); toast('Appareil révoqué') } catch (e) { showError(e) }
}
async function revokeOthers() {
  if (!confirm('Révoquer tous les autres appareils ?')) return
  try { devices.value = await del('/me/devices'); toast('Autres appareils révoqués') } catch (e) { showError(e) }
}

async function save() {
  try {
    session.user = await put('/me', form)
    toast(form.newPassword ? 'Mot de passe modifié (vos autres sessions et appareils sont déconnectés)' : 'Profil mis à jour')
    if (form.newPassword) devices.value = await get('/me/devices')
    form.currentPassword = form.newPassword = ''
  } catch (e) { showError(e) }
}
</script>

<template>
  <div class="page-head"><div><h1>Mon compte</h1><p>{{ session.user.email }}</p></div></div>
  <div class="two-cols">
    <div class="main-col">
    <form class="card" style="display:flex;flex-direction:column;gap:12px" @submit.prevent="save">
      <h2>Profil et mot de passe</h2>
      <div class="form-grid">
        <label class="field">Nom affiché<input v-model="form.name" autocomplete="name"></label>
        <label class="field">Mot de passe actuel<input v-model="form.currentPassword" type="password" autocomplete="current-password"></label>
        <label class="field">Nouveau mot de passe<input v-model="form.newPassword" type="password" autocomplete="new-password" minlength="12"></label>
      </div>
      <p class="muted small" style="margin:0">Au moins 12 caractères, difficile à deviner (une phrase de 4 mots convient). Changer de mot de passe ferme vos autres sessions et révoque vos appareils de confiance.</p>
      <div class="actions"><button class="btn btn-primary">Enregistrer</button></div>
    </form>
    <section class="card">
      <div class="card-head" style="margin-bottom:4px">
        <h2>Appareils de confiance</h2>
        <button v-if="devices.filter((d) => !d.current).length" type="button" class="btn btn-sm btn-danger" @click="revokeOthers">Révoquer les autres</button>
      </div>
      <p class="muted small" style="margin:0 0 10px">Appareils où vous avez coché « Rester connecté » : la session y reste ouverte 30 jours après la dernière utilisation.</p>
      <p v-if="!devices.length" class="muted" style="margin:0">Aucun appareil. Cochez « Rester connecté sur cet appareil » à la connexion pour ne plus avoir à vous reconnecter.</p>
      <ul class="device-list">
        <li v-for="d in devices" :key="d.id">
          <span class="who"><b>{{ d.label }}</b> <span v-if="d.current" class="chip ok">Cet appareil</span>
            <span class="muted small">Ajouté le {{ when(d.createdAt) }} · dernière utilisation {{ when(d.lastUsed) }}<template v-if="d.ip"> · {{ d.ip }}</template></span></span>
          <button type="button" class="btn btn-sm btn-icon btn-danger" :aria-label="`Révoquer ${d.label}`" @click="revoke(d)"><Icon name="trash" :size="16" /></button>
        </li>
      </ul>
    </section>
    </div>
    <aside v-if="rights" class="side-col card">
      <h2>Mes droits</h2>
      <p v-if="rights.isAdmin" class="chip info" style="align-self:flex-start">Super-administrateur : tous les droits</p>
      <template v-else>
        <p class="muted small" style="margin:0">Groupes : {{ rights.groups.map((g) => g.name).join(', ') || 'aucun' }}</p>
        <div v-for="c in rights.companies" :key="c.id"><b>{{ c.name }}</b>
          <div class="actions" style="gap:4px;margin-top:4px"><span v-for="p in c.permissions" :key="p.code" class="chip ok">{{ p.label }}</span></div></div>
        <div v-if="rights.global.length"><b>Administration</b>
          <div class="actions" style="gap:4px;margin-top:4px"><span v-for="p in rights.global" :key="p.code" class="chip info">{{ p.label }}</span></div></div>
        <p v-if="!rights.companies.length && !rights.global.length" class="muted">Aucun accès pour l'instant : demandez à un administrateur de vous ajouter à un groupe.</p>
      </template>
    </aside>
  </div>
</template>
