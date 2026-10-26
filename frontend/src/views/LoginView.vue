<script setup>
import { ref } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { post, session } from '../api.js'

const route = useRoute()
const router = useRouter()
const email = ref('')
const password = ref('')
const error = ref(route.query.error || '')
const busy = ref(false)

async function submit() {
  busy.value = true
  error.value = ''
  try {
    session.user = await post('/login', { email: email.value.trim().toLowerCase(), password: password.value })
    window.dispatchEvent(new Event('companies-changed'))
    router.push('/')
  } catch (e) {
    error.value = e.message
  } finally {
    busy.value = false
  }
}
</script>

<template>
  <div class="login card">
    <h1>Connexion</h1>
    <div v-if="error" class="alert">{{ error }}</div>
    <form class="grid" @submit.prevent="submit">
      <div><label>E-mail</label><input v-model="email" type="email" required autofocus autocomplete="username"></div>
      <div><label>Mot de passe</label><input v-model="password" type="password" required autocomplete="current-password"></div>
      <button :disabled="busy">Se connecter</button>
    </form>
    <template v-if="session.google">
      <p class="muted small" style="text-align:center;margin:14px 0 8px">ou</p>
      <a class="btn sec" style="display:block;text-align:center" href="/api/auth/google">Se connecter avec Google</a>
    </template>
  </div>
</template>
