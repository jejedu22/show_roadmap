<script setup>
import { computed } from 'vue'

/** Cases à cocher pour choisir des personnes de l'annuaire (nom, fonction), avec « Tout cocher » / « Tout décocher ». */
const props = defineProps({ people: { type: Array, default: () => [] }, modelValue: { type: Array, default: () => [] }, label: String, empty: String })
const emit = defineEmits(['update:modelValue'])
const sorted = computed(() => [...props.people].sort((a, b) => a.lastName.localeCompare(b.lastName, 'fr') || a.firstName.localeCompare(b.firstName, 'fr')))
const checked = (id) => props.modelValue.includes(id)
const toggle = (id) => emit('update:modelValue', checked(id) ? props.modelValue.filter((x) => x !== id) : [...props.modelValue, id])
const all = (on) => emit('update:modelValue', on ? [...new Set([...props.modelValue, ...props.people.map((p) => p.id)])]
  : props.modelValue.filter((id) => !props.people.some((p) => p.id === id)))
</script>

<template>
  <fieldset class="people-picker">
    <legend v-if="label">{{ label }}</legend>
    <p v-if="!people.length" class="muted small" style="margin:0">{{ empty || 'Personne dans l\'annuaire.' }}</p>
    <template v-else>
      <div class="pp-tools">
        <span class="muted small">{{ people.filter((p) => checked(p.id)).length }} / {{ people.length }}</span>
        <button type="button" class="btn btn-sm btn-ghost" @click="all(true)">Tout cocher</button>
        <button type="button" class="btn btn-sm btn-ghost" @click="all(false)">Tout décocher</button>
      </div>
      <div class="pp-list">
        <label v-for="p in sorted" :key="p.id" class="pp-item" :class="{ on: checked(p.id) }">
          <input type="checkbox" :checked="checked(p.id)" @change="toggle(p.id)">
          <span><b>{{ p.firstName }} {{ p.lastName }}</b><span v-if="p.function" class="muted small"> · {{ p.function }}</span></span>
        </label>
      </div>
    </template>
  </fieldset>
</template>

<style scoped>
.people-picker { border: 0; padding: 0; margin: 0; min-width: 0; display: flex; flex-direction: column; gap: 8px; }
.people-picker legend { padding: 0; margin-bottom: 6px; font-size: 13px; font-weight: 600; color: var(--muted); }
.pp-tools { display: flex; align-items: center; gap: 4px; flex-wrap: wrap; }
.pp-tools > span { margin-right: auto; }
.pp-list { display: grid; grid-template-columns: repeat(auto-fill, minmax(210px, 1fr)); gap: 6px; max-height: 280px; overflow-y: auto; }
.pp-item { display: flex; align-items: center; gap: 8px; padding: 7px 10px; border: 1px solid var(--line); border-radius: var(--radius-sm); cursor: pointer; font-size: 14px; }
.pp-item.on { border-color: var(--violet-line); background: var(--violet-soft); }
.pp-item input { flex: none; width: 16px; height: 16px; min-height: 0; }
</style>
