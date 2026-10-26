<script setup>
import { computed, onMounted, ref } from 'vue'
import { useRouter } from 'vue-router'
import { get, post, session } from '../api.js'
import Icon from '../components/Icon.vue'

/** Page publique : choisir son mot de passe depuis un lien d'invitation ou de réinitialisation. */
const props = defineProps({ token: String })
const router = useRouter()
const info = ref(null)
const error = ref('')
const name = ref('')
const password = ref('')
const confirm = ref('')
const busy = ref(false)

onMounted(async () => {
  try { info.value = await get(`/auth/token/${props.token}`); name.value = info.value.name } catch (e) { error.value = e.message }
})
const mismatch = computed(() => confirm.value && confirm.value !== password.value)
async function submit() {
  if (mismatch.value) return
  busy.value = true
  error.value = ''
  try {
    session.user = await post(`/auth/token/${props.token}`, { password: password.value, name: name.value })
    window.dispatchEvent(new Event('companies-changed'))
    router.push('/')
  } catch (e) { error.value = e.message } finally { busy.value = false }
}
</script>

<template>
  <div class="login-page">
    <div class="login-card">
      <div style="display:flex;align-items:center;gap:10px"><span class="brand-mark"><Icon name="logo" :size="20" /></span><b style="font-family:var(--display);font-size:20px">Show Roadmap</b></div>
      <template v-if="info">
        <div>
          <h1 style="font-size:26px">{{ info.type === 'invite' ? 'Bienvenue !' : 'Nouveau mot de passe' }}</h1>
          <p class="muted" style="margin:4px 0 0">{{ info.type === 'invite' ? 'Choisissez votre mot de passe pour activer votre compte' : 'Choisissez un nouveau mot de passe pour' }} <b>{{ info.email }}</b>.</p>
        </div>
        <div v-if="error" class="alert bad" role="alert">{{ error }}</div>
        <form style="display:flex;flex-direction:column;gap:14px" @submit.prevent="submit">
          <input type="email" :value="info.email" autocomplete="username" hidden>
          <label v-if="info.type === 'invite'" class="field">Votre nom<input v-model="name" autocomplete="name"></label>
          <label class="field">Mot de passe<input v-model="password" type="password" required :minlength="info.minLength" autocomplete="new-password"></label>
          <label class="field">Confirmation<input v-model="confirm" type="password" required autocomplete="new-password" :aria-invalid="mismatch"></label>
          <p v-if="mismatch" class="alert bad" style="margin:0">Les deux saisies diffèrent.</p>
          <p class="muted small" style="margin:0">Au moins {{ info.minLength }} caractères. Le plus simple : une phrase de 4 mots, par exemple « valise-rouge-train-du-soir ».</p>
          <button class="btn btn-primary" :disabled="busy || mismatch">Enregistrer et me connecter</button>
        </form>
      </template>
      <template v-else-if="error">
        <h1 style="font-size:24px">Lien inutilisable</h1>
        <p class="alert bad" style="margin:0">{{ error }}</p>
        <RouterLink class="btn" to="/login">Aller à la connexion</RouterLink>
      </template>
    </div>
  </div>
</template>
