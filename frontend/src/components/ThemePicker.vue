<script setup>
import Icon from './Icon.vue'

/**
 * Choix d'un thème de couleurs : aperçu de chaque thème (bandeau, puces, boutons, nuancier).
 * `none` : libellé d'une première carte « aucun thème propre » (valeur null), par ex. « Thème par défaut ».
 */
defineProps({ themes: Object, modelValue: { type: String, default: null }, none: String, noneKey: String, disabled: Boolean })
const emit = defineEmits(['update:modelValue'])
const SWATCHES = [['violet', 'Principale'], ['coral', 'Accent'], ['sun', 'Surlignage'], ['mint', 'Réussite'], ['ink', 'Encre']]
</script>

<template>
  <div class="theme-grid" role="radiogroup" aria-label="Thème de couleurs">
    <button v-for="[key, t, label] in [...(none && themes[noneKey] ? [[null, themes[noneKey], none]] : []), ...Object.entries(themes).map(([k, v]) => [k, v, v.name])]"
      :key="key ?? 'none'" type="button" class="theme-card" role="radio" :aria-checked="key === modelValue" :disabled="disabled"
      @click="key !== modelValue && emit('update:modelValue', key)">
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
        <span class="theme-name"><b>{{ label }}</b><span v-if="key === modelValue" class="chip ok"><Icon name="check" :size="14" />Choisi</span></span>
        <span class="muted small">{{ key === null ? `Suit le thème par défaut (actuellement ${t.name})` : t.description }}</span>
        <span class="swatches"><span v-for="[k, l] in SWATCHES" :key="k" class="swatch" :style="{ background: t.colors[k] }" :title="l" /></span>
      </span>
    </button>
  </div>
</template>

<style scoped>
.theme-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(230px, 1fr)); gap: 14px; }
.theme-card { display: flex; flex-direction: column; padding: 0; border: 2px solid var(--line); border-radius: var(--radius); background: #fff; color: var(--ink);
  font: inherit; text-align: left; cursor: pointer; overflow: hidden; transition: border-color .15s, box-shadow .15s; }
.theme-card:hover:not(:disabled) { border-color: var(--violet-line); }
.theme-card:disabled { cursor: default; }
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
