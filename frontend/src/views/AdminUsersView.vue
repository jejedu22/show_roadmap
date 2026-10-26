<script setup>
import { reactive, ref, onMounted } from 'vue'
import { get, post, put, session, showError, toast } from '../api.js'
import Avatar from '../components/Avatar.vue'

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
    toast(`Compte ${form.email} créé`)
    Object.assign(form, { email: '', name: '', password: '', isAdmin: false })
  } catch (e) { showError(e) }
}
async function save(u) {
  try { load(await put(`/admin/users/${u.id}`, edits[u.id])); toast('Compte mis à jour') } catch (e) { showError(e) }
}
</script>

<template>
  <div class="page-head"><div><h1>Utilisateurs</h1><p>Comptes, droits d'administration et compagnies accessibles.</p></div></div>
  <form class="card" @submit.prevent="create">
    <h2 style="margin-bottom:14px">Nouveau compte</h2>
    <div class="form-grid">
      <label class="field">E-mail<input v-model="form.email" type="email" required></label>
      <label class="field">Nom<input v-model="form.name"></label>
      <label class="field">Mot de passe initial (10 min.)<input v-model="form.password" type="password" required minlength="10" autocomplete="new-password"></label>
    </div>
    <div class="actions" style="margin-top:12px"><label class="check"><input v-model="form.isAdmin" type="checkbox"> Administrateur</label><button class="btn btn-primary">Créer le compte</button></div>
    <p class="muted small" style="margin:10px 0 0">Pour la connexion Google, utilisez l'adresse Google de la personne.</p>
  </form>
  <template v-if="data">
    <form v-for="u in data.users" :key="u.id" class="card" @submit.prevent="save(u)">
      <div class="person-head">
        <Avatar :id="u.id" :initials="(u.name || u.email).slice(0, 2).toUpperCase()" :name="u.name || u.email" />
        <span class="who"><b>{{ u.name || u.email }}</b><span class="muted small">{{ u.email }}</span></span>
        <span class="status"><span v-if="!u.isActive" class="chip bad">Désactivé</span><span v-else-if="u.isAdmin" class="chip info">Admin</span></span>
      </div>
      <div class="form-grid" style="margin-top:14px">
        <div>
          <label class="check"><input v-model="edits[u.id].isAdmin" type="checkbox" :disabled="u.id === session.user.id"> Administrateur</label>
          <label class="check"><input v-model="edits[u.id].isActive" type="checkbox" :disabled="u.id === session.user.id"> Compte actif</label>
        </div>
        <fieldset style="border:0;padding:0;margin:0"><legend class="field">Compagnies accessibles</legend>
          <label v-for="c in data.companies" :key="c.id" class="check"><input v-model="edits[u.id].companyIds" type="checkbox" :value="c.id"> {{ c.name }}</label>
        </fieldset>
        <label class="field">Nouveau mot de passe (optionnel)<input v-model="edits[u.id].password" type="password" autocomplete="new-password"></label>
      </div>
      <div class="actions" style="margin-top:12px"><button class="btn">Enregistrer</button></div>
    </form>
  </template>
</template>
