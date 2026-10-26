<script setup>
import { reactive, ref } from 'vue'
import { put, session } from '../api.js'

const form = reactive({ name: session.user.name, currentPassword: '', newPassword: '' })
const msg = ref('')
const error = ref('')

async function save() {
  msg.value = error.value = ''
  try {
    session.user = await put('/me', form)
    msg.value = form.newPassword ? 'Mot de passe modifié.' : 'Profil mis à jour.'
    form.currentPassword = form.newPassword = ''
  } catch (e) { error.value = e.message }
}
</script>

<template>
  <h1>Mon compte</h1>
  <div v-if="msg" class="ok-msg">{{ msg }}</div><div v-if="error" class="alert">{{ error }}</div>
  <form class="card grid g2" @submit.prevent="save">
    <div><label>E-mail</label><input :value="session.user.email" disabled></div>
    <div><label>Nom affiché</label><input v-model="form.name"></div>
    <div><label>Mot de passe actuel</label><input v-model="form.currentPassword" type="password" autocomplete="current-password"></div>
    <div><label>Nouveau mot de passe (10 caractères min.)</label><input v-model="form.newPassword" type="password" autocomplete="new-password"></div>
    <div><button>Enregistrer</button></div>
  </form>
</template>
