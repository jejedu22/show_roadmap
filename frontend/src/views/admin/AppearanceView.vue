<script setup>
import { ref } from 'vue'
import { get, put, showError, toast } from '../../api.js'
import { setThemes, themes } from '../../theme.js'
import ThemePicker from '../../components/ThemePicker.vue'

/**
 * Thème par défaut : page de connexion, administration et compagnies qui n'ont pas choisi le leur
 * (chaque compagnie peut avoir son propre thème dans ses Paramètres).
 */
const current = ref(null)
const busy = ref(false)
get('/admin/themes').then((d) => { current.value = d.current }).catch(showError)

async function choose(key) {
  busy.value = true
  try {
    current.value = (await put('/admin/theme', { key })).current
    setThemes(null, key) // les pages sans thème propre suivent aussitôt
    toast(`Thème par défaut : « ${themes.catalog[key].name} »`)
  } catch (e) { showError(e) } finally { busy.value = false }
}
</script>

<template>
  <div class="page-head"><div><h1>Apparence</h1>
    <p>Thème par défaut : page de connexion, administration et compagnies qui n'ont pas choisi le leur. Chaque compagnie peut avoir
      son propre thème (Paramètres de la compagnie), pour reconnaître d'un coup d'œil sur laquelle on travaille ; il habille aussi
      ses PDF et ses exports Excel.</p></div></div>
  <ThemePicker v-if="current" :themes="themes.catalog" :model-value="current" :disabled="busy" @update:model-value="choose" />
</template>
