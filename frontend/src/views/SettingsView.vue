<script setup>
import { reactive, ref, watch } from 'vue'
import { useRouter } from 'vue-router'
import { api, can, get, put, del, upload, showError, toast, setCompany } from '../api.js'
import { themes } from '../theme.js'
import ThemePicker from '../components/ThemePicker.vue'
import Icon from '../components/Icon.vue'

const props = defineProps({ id: String })
const router = useRouter()
const company = ref(null)
const form = reactive({ name: '', notes: '' })
const confirmName = ref('')

watch(() => props.id, async (id) => {
  setCompany(Number(id))
  company.value = await get(`/companies/${id}`)
  Object.assign(form, { name: company.value.name, notes: company.value.notes })
}, { immediate: true })

async function save() {
  try {
    company.value = await put(`/companies/${props.id}`, form)
    window.dispatchEvent(new Event('companies-changed'))
    toast('Paramètres enregistrés')
  } catch (e) { showError(e) }
}
// Logo : PNG, JPEG ou WebP (2 Mo au plus), réencodé par le serveur ; dans les PDF et les écrans de la compagnie
const logoBusy = ref(false)
async function sendLogo(e) {
  const file = e.target.files?.[0]
  e.target.value = ''
  if (!file) return
  if (file.size > 2 * 1024 * 1024) return showError('Image trop lourde : 2 Mo au plus.')
  logoBusy.value = true
  try {
    company.value = await upload(`/companies/${props.id}/logo`, 'logo', [file])
    window.dispatchEvent(new Event('companies-changed'))
    toast('Logo enregistré')
  } catch (err) { showError(err) } finally { logoBusy.value = false }
}
async function removeLogo() {
  if (!confirm('Retirer le logo de la compagnie ?')) return
  try {
    company.value = await del(`/companies/${props.id}/logo`)
    window.dispatchEvent(new Event('companies-changed'))
    toast('Logo retiré')
  } catch (err) { showError(err) }
}
// Thème de la compagnie : ses écrans, ses PDF et ses exports Excel (null = thème par défaut de l'application)
const themeBusy = ref(false)
async function chooseTheme(key) {
  themeBusy.value = true
  try {
    company.value = await put(`/companies/${props.id}/theme`, { theme: key })
    window.dispatchEvent(new Event('companies-changed')) // le menu applique aussitôt les nouvelles couleurs
    toast(key ? `Thème « ${themes.catalog[key].name} » choisi pour ${company.value.name}` : 'La compagnie suit le thème par défaut')
  } catch (e) { showError(e) } finally { themeBusy.value = false }
}
// Orfeo : la clé est envoyée une fois, vérifiée par le serveur puis stockée chiffrée ; elle n'est jamais réaffichée
const orfeoKey = ref('')
const orfeoBusy = ref(false)
async function connectOrfeo() {
  orfeoBusy.value = true
  try {
    company.value = await put(`/companies/${props.id}/orfeo`, { token: orfeoKey.value.trim() })
    orfeoKey.value = ''
    toast(`Relié à Orfeo (${company.value.orfeo.organization})`)
  } catch (e) { showError(e) } finally { orfeoBusy.value = false }
}
async function disconnectOrfeo() {
  if (!confirm('Délier la compagnie d\'Orfeo ? Les dates déjà importées sont conservées.')) return
  try { company.value = await del(`/companies/${props.id}/orfeo`); toast('Compagnie déliée d\'Orfeo') } catch (e) { showError(e) }
}
async function remove() {
  try {
    await api(`/companies/${props.id}`, { method: 'DELETE', body: { confirmName: confirmName.value } })
    window.dispatchEvent(new Event('companies-changed'))
    toast('Compagnie supprimée')
    router.push('/companies')
  } catch (e) { showError(e) }
}
</script>

<template>
  <template v-if="company">
    <div class="page-head"><div><h1>Paramètres</h1><p>{{ company.name }}</p></div></div>
    <form class="card" @submit.prevent="save"><fieldset :disabled="!can('company.manage', company.permissions)" style="border:0;padding:0;margin:0">
      <div class="form-grid">
        <label class="field">Nom de la compagnie<input v-model="form.name" required></label>
        <label class="field wide">Notes<textarea v-model="form.notes" placeholder="Coordonnées administratives, licence, SIRET…" /></label>
      </div>
      <div class="actions" style="margin-top:16px"><button class="btn btn-primary">Enregistrer</button></div>
    </fieldset></form>
    <section class="card">
      <h2 style="margin-bottom:4px">Logo</h2>
      <p class="muted small" style="margin:0 0 14px">Affiché dans les documents PDF (feuille de route, devis…) et dans les écrans de la compagnie.
        PNG, JPEG ou WebP, 2 Mo au plus ; un logo sur fond transparent rend le mieux.</p>
      <div class="logo-row">
        <span class="logo-preview" :class="{ empty: !company.logo }">
          <img v-if="company.logo" :src="company.logo" :alt="`Logo ${company.name}`"><span v-else class="muted small">Aucun logo</span></span>
        <div v-if="can('company.manage', company.permissions)" class="actions">
          <label class="btn" :class="{ disabled: logoBusy }"><Icon name="upload" :size="16" />{{ company.logo ? 'Remplacer' : 'Choisir une image' }}
            <input type="file" accept="image/png,image/jpeg,image/webp" class="sr-only" :disabled="logoBusy" @change="sendLogo"></label>
          <button v-if="company.logo" type="button" class="btn btn-danger" @click="removeLogo"><Icon name="trash" :size="16" />Retirer</button>
        </div>
      </div>
    </section>
    <section class="card">
      <h2 style="margin-bottom:4px">Thème de couleurs</h2>
      <p class="muted small" style="margin:0 0 14px">Les écrans de la compagnie, ses PDF (feuille de route, devis…) et ses exports Excel prennent
        ces couleurs : d'un coup d'œil, on sait sur quelle compagnie on travaille.</p>
      <ThemePicker :themes="themes.catalog" :model-value="company.theme" none="Thème par défaut" :none-key="themes.defaultKey"
        :disabled="themeBusy || !can('company.manage', company.permissions)" @update:model-value="chooseTheme" />
    </section>
    <section v-if="can('company.manage', company.permissions)" class="card">
      <div class="card-head" style="margin-bottom:6px">
        <h2>Orfeo</h2>
        <span v-if="company.orfeo.connected" class="chip ok">Relié à {{ company.orfeo.organization }}</span>
        <span v-else class="chip dark">Non relié</span>
      </div>
      <p class="muted" style="margin:0 0 12px">Reliez la compagnie à son compte <a href="https://orfeo.pro/" target="_blank" rel="noopener">Orfeo</a> pour récupérer ses dates, lieux, équipes et plannings depuis « Importer des devis ».
        La clé se crée dans Orfeo : <b>Paramètres → API &amp; webhooks</b>. Elle est vérifiée puis stockée chiffrée, et n'est jamais réaffichée.</p>
      <form class="actions" @submit.prevent="connectOrfeo">
        <label class="field" style="flex:1 1 280px">{{ company.orfeo.connected ? 'Nouvelle clé d\'API (pour la remplacer)' : 'Clé d\'API Orfeo' }}
          <input v-model="orfeoKey" type="password" autocomplete="off" spellcheck="false" required minlength="8" placeholder="Collez la clé ici"></label>
        <button class="btn btn-primary" style="align-self:flex-end" :disabled="orfeoBusy">{{ orfeoBusy ? 'Vérification…' : (company.orfeo.connected ? 'Remplacer la clé' : 'Relier à Orfeo') }}</button>
        <button v-if="company.orfeo.connected" type="button" class="btn btn-danger" style="align-self:flex-end" @click="disconnectOrfeo">Délier</button>
      </form>
    </section>
    <section v-if="can('companies.delete')" class="card" style="border:2px solid var(--coral-line)">
      <h2 style="margin-bottom:6px">Supprimer la compagnie</h2>
      <p class="muted" style="margin:0 0 14px">Supprime définitivement <b>{{ company.name }}</b>, ses spectacles, dates, équipes et voyages. Pour confirmer, saisissez son nom.</p>
      <div class="actions">
        <label class="field" style="flex:1 1 260px"><span class="sr-only">Nom de la compagnie</span><input v-model="confirmName" :placeholder="company.name"></label>
        <button class="btn btn-danger" :disabled="confirmName !== company.name" @click="remove">Supprimer définitivement</button>
      </div>
    </section>
  </template>
</template>

<style scoped>
.logo-row { display: flex; flex-wrap: wrap; align-items: center; gap: 16px; }
/* Flex (pas grid) : la hauteur du cadre est alors celle de référence pour max-height de l'image, qui ne déborde plus */
.logo-preview { display: flex; align-items: center; justify-content: center; box-sizing: border-box; flex: none; max-width: 100%; width: 220px; height: 100px; padding: 10px; overflow: hidden; border-radius: var(--radius-sm); border: 1px solid var(--line);
  background: repeating-conic-gradient(#f2f2f2 0 25%, #fff 0 50%) 0 0 / 16px 16px; }
.logo-preview.empty { background: var(--bg); border-style: dashed; }
.logo-preview img { display: block; max-width: 100%; max-height: 100%; min-width: 0; min-height: 0; object-fit: contain; }
</style>
