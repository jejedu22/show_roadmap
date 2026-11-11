<script setup>
/** Logo d'une compagnie dans un cartouche blanc (Paramètres de la compagnie) ; à défaut, ses initiales. */
const props = defineProps({ company: Object, size: { type: Number, default: 26 }, maxRatio: { type: Number, default: 3.2 } })
const abbr = (name = '') => name.split(/\s+/).filter((w) => w.length > 2 || /^[A-Z]/.test(w)).slice(0, 2).map((w) => w[0]).join('').toUpperCase() || name.slice(0, 2).toUpperCase() || '?'
</script>

<template>
  <span v-if="company?.logo" class="company-logo" :style="{ height: `${size}px`, minWidth: `${size}px`, maxWidth: `${size * maxRatio}px` }">
    <img :src="company.logo" :alt="`Logo ${company.name}`"></span>
  <span v-else class="brand-mark company-initials" :style="{ width: `${size}px`, height: `${size}px`, fontSize: `${Math.round(size * 0.42)}px` }" aria-hidden="true">{{ abbr(company?.name) }}</span>
</template>

<style scoped>
.company-logo { display: inline-flex; align-items: center; justify-content: center; flex: none; padding: 3px 5px; border-radius: 8px; background: #fff;
  box-shadow: 0 0 0 1px color-mix(in srgb, var(--ink) 10%, transparent); }
.company-logo img { display: block; max-width: 100%; max-height: 100%; object-fit: contain; }
.company-initials { flex: none; border-radius: 8px; background: var(--coral); font-weight: 800; color: var(--ink); }
</style>
