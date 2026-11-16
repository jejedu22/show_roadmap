<script setup>
import { computed, inject, ref } from 'vue'
import { del, put, toast } from '../../api.js'
import { fdate, money } from '../../format.js'
import { toForm } from '../../signedQuote.js'
import Icon from '../../components/Icon.vue'
import SignedQuoteDialog from '../../components/SignedQuoteDialog.vue'
import SignedQuoteForm from '../../components/SignedQuoteForm.vue'

/** Devis initial : devis signé par l'organisateur (importé d'un PDF ou saisi), comparé au réalisé. */
const { ev, apply, allowed } = inject('event')
const canEdit = computed(() => allowed('signed.edit') && allowed('finance.view'))
const importing = ref(false)
const form = ref(null)
const s = computed(() => ev.value.signedQuote)

const rows = computed(() => [
  { label: 'Déplacements de l\'équipe', note: 'billets, dépenses de trajet et transports locaux saisis', signed: s.value?.teamTravel, real: ev.value.quote.teamTransport, money: true },
  { label: 'Repas', note: 'midi + soir', signed: s.value?.meals, real: ev.value.quote.mealsCount },
  { label: 'Nuitées', note: 'hôtel + petit-déjeuner, par personne', signed: s.value?.nights, real: ev.value.quote.personNightsCount },
])
const fmt = (r, v) => v === null || v === undefined ? '—' : r.money ? money(v) : v.toLocaleString('fr-FR')
const gap = (r) => (r.signed === null || r.signed === undefined) ? null : Math.round((r.real - r.signed) * 100) / 100
const info = computed(() => s.value ? [
  ['Spectacle', s.value.show], ['Organisateur', s.value.organizer], ['Période', s.value.period],
  ['Représentations', s.value.performances], ['Cession', s.value.cession !== null ? money(s.value.cession) : ''],
  ['Repas du midi', s.value.lunches], ['Repas du soir', s.value.dinners],
  ['Total défraiements', s.value.allowances !== null ? money(s.value.allowances) : ''],
  ['Transport du décor', s.value.decor !== null ? `${money(s.value.decor)} (non comparé)` : ''],
  ['Total HT', s.value.totalHt !== null ? money(s.value.totalHt) : ''], ['Total TTC', s.value.totalTtc !== null ? money(s.value.totalTtc) : ''],
].filter(([, v]) => v !== '' && v !== null && v !== undefined) : [])
const linesTotal = computed(() => (s.value?.lines || []).reduce((t, l) => t + l.amount, 0))

const edit = () => { form.value = toForm(s.value) }
async function save() { if (await apply(put(`/events/${ev.value.id}/signed-quote`, form.value))) { toast('Devis initial enregistré'); form.value = null } }
async function remove() { if (confirm('Retirer le devis initial de cette date ?') && await apply(del(`/events/${ev.value.id}/signed-quote`))) toast('Devis initial retiré') }
</script>

<template>
  <div v-if="!ev.quote" class="card empty"><h3>Montants non accessibles</h3><p>Le devis initial demande le droit « Voir les montants ».</p></div>
  <div v-else class="two-cols">
    <div class="main-col">
      <form v-if="form" class="card" @submit.prevent="save">
        <h2 style="margin-bottom:14px">{{ s ? 'Modifier le devis initial' : 'Saisir le devis initial' }}</h2>
        <SignedQuoteForm :form="form" />
        <div class="actions" style="margin-top:14px"><button class="btn btn-primary">Enregistrer</button><button type="button" class="btn btn-ghost" @click="form = null">Annuler</button></div>
      </form>

      <template v-else>
        <section class="card">
          <div class="card-head">
            <h2>Devis initial et réalisé <span v-if="s" class="muted small" style="font-weight:400;font-family:inherit">
              {{ [s.number && `n° ${s.number}`, s.date && `fourni le ${fdate(s.date)}`, s.signedOn && `signé le ${fdate(s.signedOn)}`].filter(Boolean).join(' · ') }}</span></h2>
          </div>
          <div v-if="!s" class="empty" style="padding:8px 0">
            <p class="muted small" style="margin:0 0 12px">Renseignez le devis accepté par l'organisateur (ou importez son PDF) pour le comparer au réalisé : déplacements de l'équipe, nombre de repas et de nuitées.</p>
            <div v-if="canEdit" class="actions">
              <button type="button" class="btn btn-primary" @click="importing = true"><Icon name="upload" :size="16" />Importer le devis PDF</button>
              <button type="button" class="btn" @click="edit"><Icon name="edit" :size="16" />Saisir à la main</button>
            </div>
          </div>
          <div v-else class="table-wrap">
            <table class="table">
              <thead><tr><th></th><th class="r">Devis initial</th><th class="r">Réalisé</th><th class="r">Écart</th></tr></thead>
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
            <p class="muted small" style="margin:8px 0 0">Écart = réalisé − devis : <b style="color:var(--coral-dark)">au-dessus</b> du devis initial, ou <b style="color:var(--mint-dark)">en dessous</b>. Le réalisé vient des étapes Séjour et Voyages et des frais de transport de l'équipe.</p>
          </div>
        </section>

        <section v-if="s" class="card">
          <div class="card-head"><h2>Détail du devis</h2>
            <span v-if="canEdit" class="actions">
              <button type="button" class="btn btn-sm" @click="edit"><Icon name="edit" :size="16" />Modifier</button>
              <button type="button" class="btn btn-sm btn-icon btn-danger" aria-label="Retirer le devis initial" @click="remove"><Icon name="trash" :size="16" /></button>
            </span>
          </div>
          <dl v-if="info.length" class="sq-info">
            <template v-for="[k, v] in info" :key="k"><dt>{{ k }}</dt><dd>{{ v }}</dd></template>
          </dl>
          <h3 style="margin:14px 0 6px">Déplacements de l'équipe</h3>
          <div v-if="s.lines.length" class="table-wrap">
            <table class="table small">
              <thead><tr><th>Intitulé</th><th>Détail</th><th class="r">Montant HT</th></tr></thead>
              <tbody><tr v-for="(l, i) in s.lines" :key="i"><td>{{ l.label }}</td><td>{{ l.detail }}</td><td class="r">{{ money(l.amount) }}</td></tr></tbody>
              <tfoot><tr><td colspan="2"><b>Total équipe</b></td><td class="r"><b>{{ money(linesTotal) }}</b></td></tr></tfoot>
            </table>
          </div>
          <p v-else class="small" style="margin:0">{{ s.teamTravel !== null ? `Montant global : ${money(s.teamTravel)}` : 'Non renseignés' }}</p>
        </section>
      </template>
    </div>

    <aside class="side-col">
      <section class="card">
        <h3 style="margin-bottom:8px">Devis initial</h3>
        <p class="muted small" style="margin:0 0 12px">Le devis signé par l'organisateur. Il est repris dans l'export Excel (onglet « Devis initial »), avec la comparaison au réalisé en formules.</p>
        <div class="actions">
          <button v-if="canEdit && s" type="button" class="btn btn-sm" @click="importing = true"><Icon name="upload" :size="16" />Importer un autre PDF</button>
          <a class="btn btn-sm" :href="`/api/events/${ev.id}/costs.xlsx`"><Icon name="download" :size="16" />Export Excel des coûts</a>
        </div>
        <p v-if="!canEdit" class="muted small" style="margin:12px 0 0">Consultation seule : la saisie demande le droit « Saisir le devis initial ».</p>
      </section>
    </aside>
    <SignedQuoteDialog :open="importing" :ev="ev" :apply="apply" @close="importing = false" />
  </div>
</template>

<style scoped>
.sq-info { display: grid; grid-template-columns: max-content 1fr; gap: 6px 16px; margin: 0; font-size: .92rem; }
.sq-info dt { color: var(--muted); }
.sq-info dd { margin: 0; }
</style>
