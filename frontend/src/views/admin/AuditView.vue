<script setup>
import { ref, watch } from 'vue'
import { get } from '../../api.js'

const entries = ref(null)
const query = ref('')
let timer
const ACTIONS = {
  'login.success': ['Connexion', 'ok'], 'login.failure': ['Échec de connexion', 'bad'], 'login.google_refused': ['Google refusé', 'bad'],
  'user.invite': ['Invitation', 'info'], 'user.invite_link': ["Lien d'invitation", 'info'], 'user.reset_link': ['Lien de réinitialisation', 'warn'],
  'user.activate': ['Compte activé', 'ok'], 'user.password_reset': ['Mot de passe réinitialisé', 'warn'], 'user.password_change': ['Mot de passe changé', 'ok'],
  'user.password_change_failed': ['Changement refusé', 'bad'], 'user.update': ['Compte modifié', 'info'], 'user.delete': ['Compte supprimé', 'bad'],
  'group.create': ['Groupe créé', 'info'], 'group.update': ['Groupe modifié', 'info'], 'group.delete': ['Groupe supprimé', 'bad'],
  'company.create': ['Compagnie créée', 'info'], 'company.delete': ['Compagnie supprimée', 'bad'],
}
async function load() { entries.value = await get(`/admin/audit?q=${encodeURIComponent(query.value)}`) }
watch(query, () => { clearTimeout(timer); timer = setTimeout(load, 300) }, { immediate: true })
const when = (iso) => new Date(iso).toLocaleString('fr-FR', { dateStyle: 'short', timeStyle: 'short' })
const details = (d) => Object.entries(d || {}).map(([k, v]) => `${k} : ${Array.isArray(v) ? v.join(', ') || '—' : v}`).join(' · ')
</script>

<template>
  <div class="page-head"><div><h1>Journal</h1><p>Connexions et actions sur les comptes, groupes et compagnies (300 dernières).</p></div></div>
  <label class="field" style="max-width:420px"><span class="sr-only">Rechercher</span><input v-model="query" type="search" placeholder="Filtrer par adresse, action…"></label>
  <div class="card table-wrap">
    <table class="table">
      <thead><tr><th>Date</th><th>Action</th><th>Par</th><th>Concerne</th><th>Détails</th><th>IP</th></tr></thead>
      <tbody>
        <tr v-for="e in entries || []" :key="e.id">
          <td style="white-space:nowrap">{{ when(e.at) }}</td>
          <td><span class="chip" :class="(ACTIONS[e.action] || [])[1] || 'dark'">{{ (ACTIONS[e.action] || [e.action])[0] }}</span></td>
          <td>{{ e.actor || '—' }}</td><td>{{ e.target }}</td><td class="small muted">{{ details(e.details) }}</td><td class="small muted mono">{{ e.ip }}</td>
        </tr>
        <tr v-if="entries && !entries.length"><td colspan="6" class="empty">Rien à afficher.</td></tr>
      </tbody>
    </table>
  </div>
</template>
