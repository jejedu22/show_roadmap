<script setup>
import { onMounted, reactive, ref } from 'vue'
import { get, put, session, toast, showError } from '../api.js'

const form = reactive({ name: session.user.name, currentPassword: '', newPassword: '' })
const rights = ref(null)
onMounted(async () => { rights.value = await get('/me/rights') })

async function save() {
  try {
    session.user = await put('/me', form)
    toast(form.newPassword ? 'Mot de passe modifié (vos autres sessions sont fermées)' : 'Profil mis à jour')
    form.currentPassword = form.newPassword = ''
  } catch (e) { showError(e) }
}
</script>

<template>
  <div class="page-head"><div><h1>Mon compte</h1><p>{{ session.user.email }}</p></div></div>
  <div class="two-cols">
    <form class="card main-col" @submit.prevent="save">
      <h2>Profil et mot de passe</h2>
      <div class="form-grid">
        <label class="field">Nom affiché<input v-model="form.name" autocomplete="name"></label>
        <label class="field">Mot de passe actuel<input v-model="form.currentPassword" type="password" autocomplete="current-password"></label>
        <label class="field">Nouveau mot de passe<input v-model="form.newPassword" type="password" autocomplete="new-password" minlength="12"></label>
      </div>
      <p class="muted small" style="margin:0">Au moins 12 caractères, difficile à deviner (une phrase de 4 mots convient). Changer de mot de passe ferme vos autres sessions.</p>
      <div class="actions"><button class="btn btn-primary">Enregistrer</button></div>
    </form>
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
