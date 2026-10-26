<script setup>
import { reactive, ref, onMounted } from 'vue'
import { get, post, put, session, showError } from '../api.js'

const data = ref(null)
const edits = reactive({})
const form = reactive({ email: '', name: '', password: '', isAdmin: false })

function load(d) {
  data.value = d
  for (const u of d.users) edits[u.id] = { isAdmin: u.isAdmin, isActive: u.isActive, companyIds: u.companies.map((c) => c.id), password: '' }
}
onMounted(async () => load(await get('/admin/users')))

async function create() {
  try {
    load(await post('/admin/users', form))
    Object.assign(form, { email: '', name: '', password: '', isAdmin: false })
  } catch (e) { showError(e) }
}
async function save(u) {
  try { load(await put(`/admin/users/${u.id}`, edits[u.id])) } catch (e) { showError(e) }
}
</script>

<template>
  <h1>Utilisateurs</h1>
  <form class="card grid g3" @submit.prevent="create">
    <div><label>E-mail</label><input v-model="form.email" type="email" required></div>
    <div><label>Nom</label><input v-model="form.name"></div>
    <div><label>Mot de passe initial (10 min.)</label><input v-model="form.password" type="password" required minlength="10" autocomplete="new-password"></div>
    <div><label><input v-model="form.isAdmin" type="checkbox"> Administrateur</label><button>Créer</button></div>
  </form>
  <p class="muted small">Pour la connexion Google, créez le compte avec l'adresse Google de la personne.</p>
  <template v-if="data">
    <form v-for="u in data.users" :key="u.id" class="card" @submit.prevent="save(u)">
      <b>{{ u.name || u.email }}</b> <span class="muted small">{{ u.email }}</span>
      <div class="grid g3" style="margin-top:8px">
        <div>
          <label><input v-model="edits[u.id].isAdmin" type="checkbox" :disabled="u.id === session.user.id"> Admin</label>
          <label><input v-model="edits[u.id].isActive" type="checkbox" :disabled="u.id === session.user.id"> Actif</label>
        </div>
        <div><label>Compagnies accessibles</label>
          <label v-for="c in data.companies" :key="c.id"><input v-model="edits[u.id].companyIds" type="checkbox" :value="c.id"> {{ c.name }}</label>
        </div>
        <div><label>Nouveau mot de passe (optionnel)</label><input v-model="edits[u.id].password" type="password" autocomplete="new-password"></div>
      </div>
      <div class="actions"><button class="sec">Enregistrer</button></div>
    </form>
  </template>
</template>
