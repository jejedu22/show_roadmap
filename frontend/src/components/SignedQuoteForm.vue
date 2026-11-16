<script setup>
import { computed } from 'vue'
import { money } from '../format.js'
import { num } from '../signedQuote.js'
import Icon from './Icon.vue'

/** Formulaire du devis initial (saisie, modification, vérification d'un import) : modifie directement l'objet « form ». */
const props = defineProps({ form: Object })
const linesTotal = computed(() => props.form.lines.reduce((s, l) => s + (num(l.amount) ?? 0), 0))
const detailedMeals = computed(() => num(props.form.lunches) !== null || num(props.form.dinners) !== null)
const mealsTotal = computed(() => (num(props.form.lunches) ?? 0) + (num(props.form.dinners) ?? 0))
const addLine = () => props.form.lines.push({ label: '', detail: '', amount: '' })
</script>

<template>
  <div class="sq-form">
    <fieldset>
      <legend>En-tête</legend>
      <div class="form-grid" style="grid-template-columns:repeat(auto-fill,minmax(170px,1fr))">
        <label class="field">N° de devis<input v-model="form.number" maxlength="40"></label>
        <label class="field">Fourni le<input v-model="form.date" type="date"></label>
        <label class="field">Signé le<input v-model="form.signedOn" type="date"></label>
        <label class="field">Représentations<input v-model="form.performances" inputmode="numeric"></label>
        <label class="field" style="grid-column:span 2">Spectacle<input v-model="form.show" maxlength="255"></label>
        <label class="field" style="grid-column:span 2">Organisateur<input v-model="form.organizer" maxlength="255"></label>
        <label class="field" style="grid-column:span 2">Période<input v-model="form.period" maxlength="120"></label>
        <label class="field">Cession (€ HT)<input v-model="form.cession" inputmode="decimal"></label>
      </div>
    </fieldset>

    <fieldset>
      <legend>Défraiements</legend>
      <div class="form-grid" style="grid-template-columns:repeat(auto-fill,minmax(150px,1fr))">
        <label class="field">Repas du midi<input v-model="form.lunches" inputmode="numeric"></label>
        <label class="field">Repas du soir<input v-model="form.dinners" inputmode="numeric"></label>
        <label class="field">Repas (midi + soir)
          <input v-if="detailedMeals" :value="mealsTotal" disabled title="Midis + soirs">
          <input v-else v-model="form.meals" inputmode="numeric"></label>
        <label class="field">Nuitées (hôtel + pdéj.)<input v-model="form.nights" inputmode="numeric"></label>
        <label class="field">Total défraiements (€)<input v-model="form.allowances" inputmode="decimal"></label>
      </div>
    </fieldset>

    <fieldset>
      <legend>Déplacements de l'équipe</legend>
      <div v-for="(l, i) in form.lines" :key="i" class="actions" style="margin-bottom:8px">
        <label class="field" style="flex:2 1 160px">Intitulé<input v-model="l.label" maxlength="120"></label>
        <label class="field" style="flex:3 1 200px">Détail<input v-model="l.detail" maxlength="255"></label>
        <label class="field" style="flex:1 1 110px">Montant (€ HT)<input v-model="l.amount" inputmode="decimal"></label>
        <button type="button" class="btn btn-sm btn-icon btn-danger" style="align-self:flex-end" :aria-label="`Retirer la ligne ${l.label}`" @click="form.lines.splice(i, 1)"><Icon name="trash" :size="16" /></button>
      </div>
      <div class="actions" style="align-items:flex-end">
        <button type="button" class="btn btn-sm" @click="addLine"><Icon name="plus" :size="16" />Ajouter une ligne</button>
        <span v-if="form.lines.length" class="small" style="margin-left:auto">Total équipe : <b>{{ money(linesTotal) }}</b></span>
        <label v-else class="field" style="margin-left:auto;flex:0 1 200px">ou montant global (€ HT)<input v-model="form.teamTravel" inputmode="decimal"></label>
      </div>
      <div class="form-grid" style="grid-template-columns:repeat(auto-fill,minmax(170px,1fr));margin-top:10px">
        <label class="field">Transport du décor (€)<input v-model="form.decor" inputmode="decimal" title="Pour information, non comparé"></label>
      </div>
    </fieldset>

    <fieldset>
      <legend>Totaux du devis</legend>
      <div class="form-grid" style="grid-template-columns:repeat(auto-fill,minmax(170px,1fr))">
        <label class="field">Total HT (€)<input v-model="form.totalHt" inputmode="decimal"></label>
        <label class="field">Total TTC (€)<input v-model="form.totalTtc" inputmode="decimal"></label>
      </div>
    </fieldset>
  </div>
</template>

<style scoped>
.sq-form { display: flex; flex-direction: column; gap: 14px; }
.sq-form fieldset { border: 1px solid var(--line); border-radius: 12px; padding: 12px 14px 14px; margin: 0; min-width: 0; }
.sq-form legend { font-weight: 700; padding: 0 6px; font-size: .9rem; }
</style>
