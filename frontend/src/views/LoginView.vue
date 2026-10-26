<script setup>
import { ref } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { post, session } from '../api.js'
import Icon from '../components/Icon.vue'

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
  <div class="login-page">
    <div class="login-card">
      <div style="display:flex;align-items:center;gap:10px">
        <span class="brand-mark"><Icon name="logo" :size="20" /></span>
        <b style="font-family:var(--display);font-size:20px">Show Roadmap</b>
      </div>
      <div>
        <h1 style="font-size:28px">Bon retour !</h1>
        <p class="muted" style="margin:4px 0 0">Connectez-vous pour préparer vos tournées.</p>
      </div>
      <div v-if="error" class="alert bad" role="alert">{{ error }}</div>
      <form style="display:flex;flex-direction:column;gap:14px" @submit.prevent="submit">
        <label class="field">E-mail<input v-model="email" type="email" required autofocus autocomplete="username"></label>
        <label class="field">Mot de passe<input v-model="password" type="password" required autocomplete="current-password"></label>
        <button class="btn btn-primary" :disabled="busy">Se connecter</button>
      </form>
      <template v-if="session.google">
        <div class="muted small" style="text-align:center">ou</div>
        <a class="btn" href="/api/auth/google">Se connecter avec Google</a>
      </template>
    </div>
  </div>
</template>
