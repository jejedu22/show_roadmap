<script setup>
import { reactive, ref } from 'vue'
import { put, showError, toast } from '../../api.js'
import { loadLists, setLists } from '../../lists.js'
import Icon from '../../components/Icon.vue'

/** Listes déroulantes de l'application : fonctions des personnes, intitulés du planning sur place, modes de transport et types de dépense des voyages. */
const LISTS = [
  ['function', 'Fonctions', 'Proposées dans l\'annuaire et pour l\'équipe de chaque date.', 'Régisseuse plateau'],
  ['schedule', 'Intitulés de planning', 'Proposés dans le planning sur place ; une précision libre complète chaque ligne (« #1 scolaire », un lieu…).', 'Rencontre avec le public'],
  ['mode', 'Modes de transport', 'Proposés pour chaque trajet de la partie Voyages ; un train choisi dans les horaires SNCF prend le mode correspondant de la liste. « Classe » coché : le trajet précise 1re ou 2nde classe (absente de la feuille de route).', 'Covoiturage'],
  ['link', 'Sites de réservation', 'Proposés sous le lien de chaque trajet (partie Voyages) : un clic met l\'adresse du site ; on peut toujours coller le lien précis du billet.', 'Kombo'],
  ['expense', 'Types de dépense', 'Proposés pour les dépenses et remboursements de l\'aller et du retour (partie Voyages) ; comptés dans le coût, absents de la feuille de route.', 'Navette aéroport'],
]
const KINDS = LISTS.map(([kind]) => kind)
// Entrées : { label, withClass } (« withClass » : modes de transport dont le trajet précise une classe)
const items = reactive(Object.fromEntries(KINDS.map((k) => [k, []])))
const fresh = reactive(Object.fromEntries(KINDS.map((k) => [k, ''])))
const dirty = reactive(Object.fromEntries(KINDS.map((k) => [k, false])))
const busy = ref(false)

const fill = (l) => KINDS.forEach((k) => { items[k] = (l[k] || []).map((label) => ({ label, withClass: k === 'mode' && (l.modeClass || []).includes(label), url: (l.linkUrls || {})[label] || '' })) })
loadLists(true).then(fill)

const changed = (kind) => { dirty[kind] = true }
function add(kind) {
  const v = fresh[kind].trim()
  if (!v) return
  if (items[kind].some((x) => x.label.localeCompare(v, 'fr', { sensitivity: 'base' }) === 0)) return showError(`« ${v} » est déjà dans la liste`)
  items[kind].push({ label: v, withClass: false, url: kind === 'link' ? 'https://' : '' })
  fresh[kind] = ''
  changed(kind)
}
function move(kind, i, delta) {
  const list = items[kind]
  const j = i + delta
  if (j < 0 || j >= list.length) return
  ;[list[i], list[j]] = [list[j], list[i]]
  changed(kind)
}
function remove(kind, i) { items[kind].splice(i, 1); changed(kind) }
function sort(kind) { items[kind].sort((a, b) => a.label.localeCompare(b.label, 'fr')); changed(kind) }
async function save(kind) {
  busy.value = true
  try {
    const data = await put(`/admin/lists/${kind}`, { items: items[kind].map((x) => ({ label: x.label.trim(), withClass: x.withClass, ...(kind === 'link' ? { url: x.url.trim() } : {}) })).filter((x) => x.label) })
    setLists(data)
    fill(data)
    dirty[kind] = false
    toast('Liste enregistrée')
  } catch (e) { showError(e) } finally { busy.value = false }
}
</script>

<template>
  <div class="page-head"><div><h1>Listes</h1><p>Les choix proposés dans les listes déroulantes. Retirer une entrée ne modifie pas les fiches déjà saisies : la valeur y reste, marquée « hors liste ».</p></div></div>
  <div class="lists-grid">
    <section v-for="[kind, title, help, example] in LISTS" :key="kind" class="card" :class="{ wide: kind === 'mode' || kind === 'link' }">
      <div class="card-head" style="margin-bottom:4px">
        <h2>{{ title }} <span class="muted small">({{ items[kind].length }})</span></h2>
        <button type="button" class="btn btn-sm btn-ghost" @click="sort(kind)">Trier A → Z</button>
      </div>
      <p class="muted small" style="margin:0 0 12px">{{ help }}</p>
      <ol class="list-edit">
        <li v-for="(item, i) in items[kind]" :key="`${kind}-${i}`">
          <input v-model="item.label" :aria-label="`Entrée ${i + 1}`" maxlength="120" @input="changed(kind)">
          <input v-if="kind === 'link'" v-model="item.url" type="url" class="url-input" :aria-label="`Adresse de ${item.label}`" placeholder="https://…" maxlength="500" @input="changed(kind)">
          <label v-if="kind === 'mode'" class="class-check" :title="`Le trajet en ${item.label} précise 1re ou 2nde classe`">
            <input v-model="item.withClass" type="checkbox" @change="changed(kind)">Classe</label>
          <button type="button" class="btn btn-sm btn-icon" :aria-label="`Monter ${item.label}`" :disabled="i === 0" @click="move(kind, i, -1)"><Icon name="chevron" :size="16" style="transform:rotate(180deg)" /></button>
          <button type="button" class="btn btn-sm btn-icon" :aria-label="`Descendre ${item.label}`" :disabled="i === items[kind].length - 1" @click="move(kind, i, 1)"><Icon name="chevron" :size="16" /></button>
          <button type="button" class="btn btn-sm btn-icon btn-danger" :aria-label="`Retirer ${item.label}`" @click="remove(kind, i)"><Icon name="trash" :size="16" /></button>
        </li>
      </ol>
      <form class="actions" style="margin-top:10px" @submit.prevent="add(kind)">
        <label class="field" style="flex:1 1 200px"><span class="sr-only">Nouvelle entrée</span><input v-model="fresh[kind]" maxlength="120" :placeholder="`Nouvelle entrée, ex. ${example}`"></label>
        <button class="btn btn-sm"><Icon name="plus" :size="16" />Ajouter</button>
      </form>
      <div class="actions" style="margin-top:14px">
        <button type="button" class="btn btn-primary" :disabled="busy || !dirty[kind]" @click="save(kind)">Enregistrer la liste</button>
        <span v-if="dirty[kind]" class="chip warn">Modifications non enregistrées</span>
      </div>
    </section>
  </div>
</template>

<style scoped>
/* Modes de transport : la case « Classe » prend de la place, la carte occupe deux colonnes sur grand écran */
@media (min-width: 1100px) { .wide { grid-column: span 2; } }
.url-input { flex: 1.4 !important; }
.class-check { display: inline-flex; align-items: center; gap: 5px; flex: none; font-size: 13px; font-weight: 600; color: var(--muted); cursor: pointer; }
.class-check input { flex: none; min-height: 0; width: 16px; height: 16px; }
</style>
