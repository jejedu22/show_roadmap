<script setup>
import { ref } from 'vue'
import { get, put, showError, toast } from '../../api.js'
import { applyTheme } from '../../theme.js'
import Icon from '../../components/Icon.vue'

/** Thème de couleurs de l'application, des PDF et des exports Excel, pour tous les utilisateurs. */
const data = ref(null)
const busy = ref(false)
get('/admin/themes').then((d) => { data.value = d }).catch(showError)

const SWATCHES = [['violet', 'Principale'], ['coral', 'Accent'], ['sun', 'Surlignage'], ['mint', 'Réussite'], ['ink', 'Encre']]

async function choose(t) {
  if (busy.value || t.key === data.value.current) return
  busy.value = true
  try {
    data.value = await put('/admin/theme', { key: t.key })
    applyTheme(t)
    toast(`Thème « ${t.name} » appliqué`)
  } catch (e) { showError(e) } finally { busy.value = false }
}
</script>

<template>
  <div class="page-head"><div><h1>Apparence</h1>
    <p>Le thème de couleurs s'applique à tous les utilisateurs : écrans de l'application, documents PDF (feuille de route, devis…) et exports Excel.</p></div></div>
  <div v-if="data" class="theme-grid" role="radiogroup" aria-label="Thème de couleurs">
    <button v-for="t in data.themes" :key="t.key" type="button" class="theme-card" role="radio" :aria-checked="t.key === data.current"
      :disabled="busy" @click="choose(t)">
      <span class="theme-preview" :style="{ background: t.colors.bg }">
        <span class="tp-hero" :style="{ background: t.colors.violet }">
          <span class="tp-dot" :style="{ background: t.colors.coral }" /><span class="tp-kicker" :style="{ background: t.colors.sun }" />
          <span class="tp-title" />
        </span>
        <span class="tp-row"><span class="tp-chip" :style="{ background: t.colors.mintSoft, color: t.colors.mintDark }">OK</span>
          <span class="tp-btn" :style="{ background: t.colors.sun, color: t.colors.ink }">Envoyer</span>
          <span class="tp-btn" :style="{ background: t.colors.ink, color: '#fff' }">Menu</span></span>
      </span>
      <span class="theme-body">
        <span class="theme-name"><b>{{ t.name }}</b><span v-if="t.key === data.current" class="chip ok"><Icon name="check" :size="14" />Actif</span></span>
        <span class="muted small">{{ t.description }}</span>
        <span class="swatches"><span v-for="[k, label] in SWATCHES" :key="k" class="swatch" :style="{ background: t.colors[k] }" :title="label" /></span>
      </span>
    </button>
  </div>
</template>

<style scoped>
.theme-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(250px, 1fr)); gap: 16px; }
.theme-card { display: flex; flex-direction: column; padding: 0; border: 2px solid var(--line); border-radius: var(--radius); background: #fff; color: var(--ink);
  font: inherit; text-align: left; cursor: pointer; overflow: hidden; transition: border-color .15s, box-shadow .15s; }
.theme-card:hover { border-color: var(--violet-line); }
.theme-card[aria-checked=true] { border-color: var(--violet); box-shadow: 0 0 0 3px color-mix(in srgb, var(--violet) 18%, transparent); }
.theme-preview { display: flex; flex-direction: column; gap: 10px; padding: 14px; }
.tp-hero { position: relative; display: flex; flex-direction: column; gap: 6px; height: 64px; padding: 12px; border-radius: 12px; overflow: hidden; }
.tp-dot { position: absolute; right: -14px; top: -16px; width: 56px; height: 56px; border-radius: 50%; }
.tp-kicker { width: 44px; height: 6px; border-radius: 3px; }
.tp-title { width: 110px; height: 12px; border-radius: 4px; background: #fff; }
.tp-row { display: flex; gap: 8px; align-items: center; }
.tp-chip, .tp-btn { padding: 3px 10px; border-radius: 9px; font-size: 12px; font-weight: 700; }
.theme-body { display: flex; flex-direction: column; gap: 6px; padding: 12px 14px 14px; border-top: 1px solid var(--line); }
.theme-name { display: flex; align-items: center; justify-content: space-between; gap: 8px; }
.swatches { display: flex; gap: 6px; margin-top: 2px; }
.swatch { width: 22px; height: 22px; border-radius: 50%; border: 1px solid color-mix(in srgb, var(--ink) 12%, transparent); }
</style>
