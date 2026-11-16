<script setup>
import { computed, ref } from 'vue'
import { del, put, toast } from '../api.js'
import { fdate, money } from '../format.js'
import Icon from './Icon.vue'
import SignedQuoteDialog from './SignedQuoteDialog.vue'

/** Devis signé comparé au réalisé : déplacements de l'équipe (€), repas (midi + soir), nuitées (hôtel + petit-déjeuner). */
const props = defineProps({ ev: Object, apply: Function, canEdit: Boolean })
const importing = ref(false)
const editing = ref(false)
const form = ref({})
const s = computed(() => props.ev.signedQuote)

const rows = computed(() => [
  { label: 'Déplacements de l\'équipe', note: 'billets, dépenses de trajet et transports locaux saisis', signed: s.value?.teamTravel, real: props.ev.quote.teamTransport, money: true },
  { label: 'Repas', note: 'midi + soir', signed: s.value?.meals, real: props.ev.quote.mealsCount },
  { label: 'Nuitées', note: 'hôtel + petit-déjeuner, par personne', signed: s.value?.nights, real: props.ev.quote.personNightsCount },
])
const fmt = (r, v) => v === null || v === undefined ? '—' : r.money ? money(v) : v.toLocaleString('fr-FR')
const gap = (r) => (r.signed === null || r.signed === undefined) ? null : Math.round((r.real - r.signed) * 100) / 100

function edit() {
  const n = (x) => (x ?? '') === '' ? '' : String(x).replace('.', ',')
  form.value = { number: s.value?.number || '', date: s.value?.date || '', signedOn: s.value?.signedOn || '', teamTravel: n(s.value?.teamTravel), meals: n(s.value?.meals), nights: n(s.value?.nights) }
  editing.value = true
}
async function save() { if (await props.apply(put(`/events/${props.ev.id}/signed-quote`, form.value))) { toast('Devis signé enregistré'); editing.value = false } }
async function remove() { if (confirm('Retirer le devis signé de cette date ?') && await props.apply(del(`/events/${props.ev.id}/signed-quote`))) toast('Devis signé retiré') }
</script>

<template>
  <section class="card">
    <div class="card-head">
      <h2>Devis signé <span v-if="s" class="muted small" style="font-weight:400;font-family:inherit">
        {{ [s.number && `n° ${s.number}`, s.date && `fourni le ${fdate(s.date)}`, s.signedOn && `signé le ${fdate(s.signedOn)}`].filter(Boolean).join(' · ') }}</span></h2>
      <span v-if="canEdit" class="actions">
        <button type="button" class="btn btn-sm" @click="importing = true"><Icon name="upload" :size="16" />Importer un devis PDF</button>
        <button v-if="!editing" type="button" class="btn btn-sm" @click="edit"><Icon name="edit" :size="16" />{{ s ? 'Modifier' : 'Saisir à la main' }}</button>
        <button v-if="s && !editing" type="button" class="btn btn-sm btn-icon btn-danger" aria-label="Retirer le devis signé" @click="remove"><Icon name="trash" :size="16" /></button>
      </span>
    </div>
    <SignedQuoteDialog :open="importing" :ev="ev" :apply="apply" @close="importing = false" />

    <form v-if="editing" @submit.prevent="save">
      <div class="form-grid" style="grid-template-columns:repeat(auto-fill,minmax(170px,1fr))">
        <label class="field">N° de devis<input v-model="form.number" maxlength="40"></label>
        <label class="field">Fourni le<input v-model="form.date" type="date"></label>
        <label class="field">Signé le<input v-model="form.signedOn" type="date"></label>
        <label class="field">Déplacements équipe (€ HT)<input v-model="form.teamTravel" inputmode="decimal"></label>
        <label class="field">Repas (midi + soir)<input v-model="form.meals" inputmode="numeric"></label>
        <label class="field">Nuitées (hôtel + pdéj.)<input v-model="form.nights" inputmode="numeric"></label>
      </div>
      <div class="actions" style="margin-top:12px"><button class="btn btn-primary">Enregistrer</button><button type="button" class="btn btn-ghost" @click="editing = false">Annuler</button></div>
    </form>

    <p v-else-if="!s" class="muted small" style="margin:0">Renseignez le devis accepté par l'organisateur (ou importez son PDF) pour le comparer au réalisé : déplacements de l'équipe, nombre de repas et de nuitées.</p>
    <div v-else class="table-wrap">
      <table class="table">
        <thead><tr><th></th><th class="r">Devis signé</th><th class="r">Réalisé</th><th class="r">Écart</th></tr></thead>
        <tbody>
          <tr v-for="r in rows" :key="r.label">
            <td>{{ r.label }}<span class="muted small" style="display:block">{{ r.note }}</span></td>
            <td class="r">{{ fmt(r, r.signed) }}</td>
            <td class="r">{{ fmt(r, r.real) }}</td>
            <td class="r">
              <span v-if="gap(r) === null" class="muted">—</span>
              <span v-else-if="gap(r) === 0" class="chip ok">Conforme</span>
              <b v-else :style="{ color: gap(r) > 0 ? 'var(--coral-dark)' : 'var(--mint-dark)' }" :title="gap(r) > 0 ? 'Au-dessus du devis' : 'En dessous du devis'">
                {{ gap(r) > 0 ? '+' : '−' }}{{ fmt(r, Math.abs(gap(r))) }}</b>
            </td>
          </tr>
        </tbody>
      </table>
      <p class="muted small" style="margin:8px 0 0">Écart = réalisé − devis : <b style="color:var(--coral-dark)">au-dessus</b> du devis signé, ou <b style="color:var(--mint-dark)">en dessous</b>. Le réalisé vient des étapes Séjour et Voyages et des frais de transport de l'équipe.</p>
    </div>
  </section>
</template>
