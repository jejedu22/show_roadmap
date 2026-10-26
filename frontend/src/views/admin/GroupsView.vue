<script setup>
import { computed, reactive, ref, onMounted } from 'vue'
import { api, get, post, put, showError, toast } from '../../api.js'
import Avatar from '../../components/Avatar.vue'
import Icon from '../../components/Icon.vue'
import Modal from '../../components/Modal.vue'

const data = ref(null)
const editing = ref(null) // id, ou 'new'
const form = reactive({ name: '', description: '', permissions: [], allCompanies: false, companyIds: [], userIds: [] })
const memberQuery = ref('')

onMounted(async () => { data.value = await get('/admin/groups') })

const label = (code) => [...data.value.catalog.company, ...data.value.catalog.global].find((p) => p.code === code)?.label || code
const companyName = (id) => data.value.companies.find((c) => c.id === id)?.name || '(compagnie non visible)'
const userOf = (id) => data.value.users.find((u) => u.id === id)
const locked = (g) => g.admin && !data.value.canGrantAdmin
const members = computed(() => {
  const q = memberQuery.value.trim().toLowerCase()
  return data.value.users.filter((u) => !q || `${u.name} ${u.email}`.toLowerCase().includes(q))
})

function start(g) {
  editing.value = g ? g.id : 'new'
  memberQuery.value = ''
  Object.assign(form, g ? { name: g.name, description: g.description, permissions: [...g.permissions], allCompanies: g.allCompanies, companyIds: [...g.companyIds], userIds: [...g.userIds] }
    : { name: '', description: '', permissions: ['company.view'], allCompanies: false, companyIds: [], userIds: [] })
}
async function save() {
  try {
    data.value = editing.value === 'new' ? await post('/admin/groups', form) : await put(`/admin/groups/${editing.value}`, form)
    toast(`Groupe « ${form.name} » enregistré`)
    editing.value = null
  } catch (e) { showError(e) }
}
async function remove() {
  if (!confirm(`Supprimer le groupe « ${form.name} » ? Ses membres perdent les droits qu'il donnait.`)) return
  try { data.value = await api(`/admin/groups/${editing.value}`, { method: 'DELETE', body: {} }); toast('Groupe supprimé'); editing.value = null } catch (e) { showError(e) }
}
</script>

<template>
  <div class="page-head">
    <div><h1>Groupes et droits</h1><p>Un groupe réunit des <b>droits</b>, un <b>périmètre</b> (toutes les compagnies ou certaines) et des <b>membres</b>. Les droits de plusieurs groupes s'additionnent.</p></div>
    <button class="btn btn-primary" @click="start(null)"><Icon name="plus" />Nouveau groupe</button>
  </div>

  <div v-if="data" class="tiles" style="grid-template-columns:repeat(auto-fill,minmax(320px,1fr))">
    <article v-for="g in data.groups" :key="g.id" class="card" style="display:flex;flex-direction:column;gap:10px">
      <div class="card-head" style="margin:0"><h2 style="font-size:19px">{{ g.name }}</h2>
        <button class="btn btn-sm" :disabled="locked(g)" :title="locked(g) ? 'Réservé aux super-administrateurs' : ''" @click="start(g)"><Icon name="edit" :size="16" />Modifier</button></div>
      <p v-if="g.description" class="muted small" style="margin:0">{{ g.description }}</p>
      <div class="actions" style="gap:6px">
        <span class="chip" :class="g.allCompanies ? 'info' : 'warn'">{{ g.allCompanies ? 'Toutes les compagnies' : g.companyIds.length ? g.companyIds.map(companyName).join(', ') : 'Aucune compagnie' }}</span>
        <span v-if="g.admin" class="chip bad">Administration</span>
      </div>
      <div class="actions" style="gap:6px"><span v-for="p in g.permissions" :key="p" class="chip ok">{{ label(p) }}</span>
        <span v-if="!g.permissions.length" class="muted small">Aucun droit</span></div>
      <div class="avatars">
        <Avatar v-for="id in g.userIds.slice(0, 10)" :id="id" :key="id" small :initials="(userOf(id)?.name || userOf(id)?.email || '?').slice(0, 2).toUpperCase()" :name="userOf(id)?.email" />
        <span class="muted small" style="align-self:center">{{ g.userIds.length }} membre{{ g.userIds.length > 1 ? 's' : '' }}</span>
      </div>
    </article>
  </div>

  <Modal :open="editing !== null" :title="editing === 'new' ? 'Nouveau groupe' : `Groupe « ${form.name} »`" @close="editing = null">
    <form v-if="data" style="display:flex;flex-direction:column;gap:16px" @submit.prevent="save">
      <label class="field">Nom<input v-model="form.name" required maxlength="120" placeholder="Régie, Production, Comptabilité…"></label>
      <label class="field">Description<input v-model="form.description" placeholder="À quoi sert ce groupe ?"></label>

      <fieldset style="border:0;padding:0;margin:0"><legend class="eyebrow" style="color:var(--violet-dark);margin-bottom:6px">Droits sur les compagnies</legend>
        <label v-for="p in data.catalog.company" :key="p.code" class="check" style="align-items:flex-start;min-height:0;padding:5px 0">
          <input v-model="form.permissions" type="checkbox" :value="p.code" style="margin-top:2px"><span><b>{{ p.label }}</b><br><span class="muted small">{{ p.help }}</span></span></label>
      </fieldset>

      <fieldset style="border:0;padding:0;margin:0"><legend class="eyebrow" style="color:var(--violet-dark);margin-bottom:6px">Périmètre</legend>
        <label class="check"><input v-model="form.allCompanies" type="radio" :value="true" :disabled="!data.canGrantAdmin && !form.allCompanies"> Toutes les compagnies, y compris les futures</label>
        <label class="check"><input v-model="form.allCompanies" type="radio" :value="false"> Seulement :</label>
        <div v-if="!form.allCompanies" style="padding-left:30px">
          <label v-for="c in data.companies" :key="c.id" class="check"><input v-model="form.companyIds" type="checkbox" :value="c.id"> {{ c.name }}</label>
        </div>
      </fieldset>

      <fieldset style="border:0;padding:0;margin:0"><legend class="eyebrow" style="color:var(--coral-dark);margin-bottom:6px">Administration</legend>
        <p v-if="!data.canGrantAdmin" class="muted small" style="margin:0 0 6px">Réservé aux super-administrateurs.</p>
        <label v-for="p in data.catalog.global" :key="p.code" class="check" style="align-items:flex-start;min-height:0;padding:5px 0">
          <input v-model="form.permissions" type="checkbox" :value="p.code" :disabled="!data.canGrantAdmin" style="margin-top:2px"><span><b>{{ p.label }}</b><br><span class="muted small">{{ p.help }}</span></span></label>
      </fieldset>

      <fieldset style="border:0;padding:0;margin:0"><legend class="eyebrow" style="color:var(--violet-dark);margin-bottom:6px">Membres ({{ form.userIds.length }})</legend>
        <input v-if="data.users.length > 8" v-model="memberQuery" type="search" placeholder="Rechercher…" style="margin-bottom:6px">
        <div style="max-height:220px;overflow:auto">
          <label v-for="u in members" :key="u.id" class="check"><input v-model="form.userIds" type="checkbox" :value="u.id">
            {{ u.name || u.email }} <span class="muted small">{{ u.name ? u.email : '' }}{{ u.isActive ? '' : ' · désactivé' }}</span></label>
        </div>
      </fieldset>

      <div class="actions">
        <button class="btn btn-primary">Enregistrer</button>
        <button type="button" class="btn btn-ghost" @click="editing = null">Annuler</button>
        <button v-if="editing !== 'new'" type="button" class="btn btn-danger" style="margin-left:auto" @click="remove"><Icon name="trash" :size="16" />Supprimer</button>
      </div>
    </form>
  </Modal>
</template>
