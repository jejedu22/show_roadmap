<script setup>
import { reactive } from 'vue'
import { put, session, toast, showError } from '../api.js'

const form = reactive({ name: session.user.name, currentPassword: '', newPassword: '' })

async function save() {
  try {
    session.user = await put('/me', form)
    toast(form.newPassword ? 'Mot de passe modifié' : 'Profil mis à jour')
    form.currentPassword = form.newPassword = ''
  } catch (e) { showError(e) }
}
</script>

<template>
  <div class="page-head"><div><h1>Mon compte</h1><p>{{ session.user.email }}</p></div></div>
  <form class="card" @submit.prevent="save">
    <div class="form-grid">
      <label class="field">Nom affiché<input v-model="form.name"></label>
      <label class="field">Mot de passe actuel<input v-model="form.currentPassword" type="password" autocomplete="current-password"></label>
      <label class="field">Nouveau mot de passe (10 caractères min.)<input v-model="form.newPassword" type="password" autocomplete="new-password"></label>
    </div>
    <div class="actions" style="margin-top:16px"><button class="btn btn-primary">Enregistrer</button></div>
  </form>
</template>
