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
// Appareil de confiance : la case est retenue d'une visite à l'autre sur ce navigateur
const remember = ref((() => { try { return localStorage.getItem('rememberDevice') === '1' } catch { return false } })())
const forgot = ref(false)
const sent = ref('')

async function submit() {
  busy.value = true
  error.value = ''
  try {
    try { localStorage.setItem('rememberDevice', remember.value ? '1' : '0') } catch { /* stockage indisponible */ }
    session.user = await post('/login', { email: email.value.trim().toLowerCase(), password: password.value, _remember_me: remember.value })
    window.dispatchEvent(new Event('companies-changed'))
    router.push('/')
  } catch (e) {
    error.value = e.message
  } finally {
    busy.value = false
  }
}

async function requestLink() {
  busy.value = true
  error.value = ''
  try {
    sent.value = (await post('/auth/forgot', { email: email.value.trim().toLowerCase() })).message
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
      <form v-if="forgot" style="display:flex;flex-direction:column;gap:14px" @submit.prevent="requestLink">
        <h2 style="font-size:18px;margin:0">Mot de passe oublié</h2>
        <template v-if="sent">
          <p class="alert info" role="status" style="margin:0">{{ sent }}</p>
          <p class="muted small" style="margin:0">Le lien est valable 1 heure. Rien reçu ? Vérifiez l'adresse saisie, puis demandez-en un nouveau.</p>
        </template>
        <template v-else>
          <p class="muted small" style="margin:0">Indiquez l'adresse de votre compte : vous recevrez un lien pour choisir un nouveau mot de passe.</p>
          <label class="field">E-mail<input v-model="email" type="email" required autofocus autocomplete="username"></label>
          <button class="btn btn-primary" :disabled="busy">Recevoir le lien</button>
        </template>
        <button type="button" class="btn btn-ghost" @click="forgot = false; sent = ''; error = ''">Retour à la connexion</button>
      </form>
      <template v-else>
        <form style="display:flex;flex-direction:column;gap:14px" @submit.prevent="submit">
          <label class="field">E-mail<input v-model="email" type="email" required autofocus autocomplete="username"></label>
          <label class="field">Mot de passe<input v-model="password" type="password" required autocomplete="current-password"></label>
          <label class="check"><input v-model="remember" type="checkbox"><span>Rester connecté sur cet appareil <span class="muted small">(30 jours)</span></span></label>
          <button class="btn btn-primary" :disabled="busy">Se connecter</button>
          <button v-if="session.passwordReset" type="button" class="btn btn-ghost btn-sm" style="align-self:center" @click="forgot = true; error = ''">Mot de passe oublié ?</button>
        </form>
        <p class="muted small" style="margin:-6px 0 0">Ne cochez « Rester connecté » que sur un appareil personnel. Vous pouvez le révoquer à tout moment dans « Mon compte ».</p>
        <template v-if="session.google">
          <div class="muted small" style="text-align:center">ou</div>
          <a class="btn" :href="`/api/auth/google${remember ? '?remember=1' : ''}`">Se connecter avec Google</a>
        </template>
      </template>
    </div>
  </div>
</template>
