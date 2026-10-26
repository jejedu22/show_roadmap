<script setup>
import { reactive, ref } from 'vue'
import { put, showError, toast } from '../../api.js'
import { loadLists, setLists } from '../../lists.js'
import Icon from '../../components/Icon.vue'

/** Listes déroulantes de l'application : fonctions des personnes, intitulés du planning sur place. */
const LISTS = [
  ['function', 'Fonctions', 'Proposées dans l\'annuaire et pour l\'équipe de chaque date.', 'Régisseuse plateau'],
  ['schedule', 'Intitulés de planning', 'Proposés dans le planning sur place ; une précision libre complète chaque ligne (« #1 scolaire », un lieu…).', 'Rencontre avec le public'],
]
const items = reactive({ function: [], schedule: [] })
const fresh = reactive({ function: '', schedule: '' })
const dirty = reactive({ function: false, schedule: false })
const busy = ref(false)

loadLists(true).then((l) => { items.function = [...l.function]; items.schedule = [...l.schedule] })

const changed = (kind) => { dirty[kind] = true }
function add(kind) {
  const v = fresh[kind].trim()
  if (!v) return
  if (items[kind].some((x) => x.localeCompare(v, 'fr', { sensitivity: 'base' }) === 0)) return showError(`« ${v} » est déjà dans la liste`)
  items[kind].push(v)
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
function sort(kind) { items[kind].sort((a, b) => a.localeCompare(b, 'fr')); changed(kind) }
async function save(kind) {
  busy.value = true
  try {
    const data = await put(`/admin/lists/${kind}`, { items: items[kind].map((x) => x.trim()).filter(Boolean) })
    setLists(data)
    items[kind] = [...data[kind]]
    dirty[kind] = false
    toast('Liste enregistrée')
  } catch (e) { showError(e) } finally { busy.value = false }
}
</script>

<template>
  <div class="page-head"><div><h1>Listes</h1><p>Les choix proposés dans les listes déroulantes. Retirer une entrée ne modifie pas les fiches déjà saisies : la valeur y reste, marquée « hors liste ».</p></div></div>
  <div class="lists-grid">
    <section v-for="[kind, title, help, example] in LISTS" :key="kind" class="card">
      <div class="card-head" style="margin-bottom:4px">
        <h2>{{ title }} <span class="muted small">({{ items[kind].length }})</span></h2>
        <button type="button" class="btn btn-sm btn-ghost" @click="sort(kind)">Trier A → Z</button>
      </div>
      <p class="muted small" style="margin:0 0 12px">{{ help }}</p>
      <ol class="list-edit">
        <li v-for="(item, i) in items[kind]" :key="`${kind}-${i}`">
          <input v-model="items[kind][i]" :aria-label="`Entrée ${i + 1}`" maxlength="120" @input="changed(kind)">
          <button type="button" class="btn btn-sm btn-icon" :aria-label="`Monter ${item}`" :disabled="i === 0" @click="move(kind, i, -1)"><Icon name="chevron" :size="16" style="transform:rotate(180deg)" /></button>
          <button type="button" class="btn btn-sm btn-icon" :aria-label="`Descendre ${item}`" :disabled="i === items[kind].length - 1" @click="move(kind, i, 1)"><Icon name="chevron" :size="16" /></button>
          <button type="button" class="btn btn-sm btn-icon btn-danger" :aria-label="`Retirer ${item}`" @click="remove(kind, i)"><Icon name="trash" :size="16" /></button>
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
