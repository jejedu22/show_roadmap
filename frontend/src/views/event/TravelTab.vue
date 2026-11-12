<script setup>
import { inject, ref } from 'vue'
import { del, toast } from '../../api.js'
import { byDeparture, fdate, money, sum } from '../../format.js'
import Avatar from '../../components/Avatar.vue'
import Icon from '../../components/Icon.vue'
import CostShareDialog from '../../components/CostShareDialog.vue'
import LegsImportDialog from '../../components/LegsImportDialog.vue'
import TravelEditor from '../../components/TravelEditor.vue'

const { ev, apply, allowed } = inject('event')
const panel = ref(null) // { part, direction, leg } ou { part, direction, expense, isExpense: true }
const open = ref({})
const importing = ref(false)
const sharingCosts = ref(false)

const legsOf = (p, dir) => p.legs.filter((l) => l.direction === dir).sort(byDeparture)
const status = (p) => {
  const out = legsOf(p, 'outbound').length, back = legsOf(p, 'return').length
  return out && back ? ['Complet', 'ok'] : !out && !back ? ['À réserver', 'bad'] : !out ? ['Aller à réserver', 'warn'] : ['Retour à réserver', 'warn']
}
const expensesOf = (p, dir) => (p.expenses || []).filter((x) => x.direction === dir)
const canExpense = () => allowed('travel.edit') && allowed('finance.view')
const isOpen = (p) => open.value[p.id] ?? status(p)[1] !== 'ok'
const name = (p) => p.person.firstName || p.person.lastName
// Formulaire de saisie déplié sous l'élément cliqué (at : clé de l'emplacement)
const show = (state) => { panel.value = state }
const isAt = (at) => panel.value?.at === at
const edit = (part, direction, leg, at) => show({ part, direction, leg, at })
const editExpense = (part, direction, expense, at) => show({ part, direction, expense, isExpense: true, at })
async function removeExpense(p, x) {
  if (confirm(`Supprimer cette ligne « ${x.kind} » de ${name(p)} ?`) && await apply(del(`/events/${ev.value.id}/items/expense/${x.id}`))) toast('Ligne supprimée')
}
async function remove(p, l) {
  if (confirm(`Supprimer ce trajet de ${name(p)} ?`) && await apply(del(`/events/${ev.value.id}/items/leg/${l.id}`))) toast('Trajet supprimé')
}
</script>

<template>
  <div class="two-cols">
    <section class="main-col" aria-labelledby="voy">
      <div class="page-head">
        <h2 id="voy">Voyages</h2>
        <span class="muted">{{ ev.readiness.legsCovered }} / {{ ev.readiness.legsNeeded }} trajets<template v-if="ev.quote"> · billets <b style="color:var(--ink)">{{ money(ev.quote.travelTickets) }}</b><template v-if="ev.quote.travelExpenses"> · dépenses <b style="color:var(--ink)">{{ money(ev.quote.travelExpenses) }}</b></template></template></span>
        <button v-if="allowed('travel.edit') && ev.participations.length" type="button" class="btn btn-sm" style="margin-left:auto" @click="importing = true">Importer depuis Excel (Horaires TGV)</button>
      </div>
      <p v-if="allowed('travel.edit') && ev.participations.length" class="muted small" style="margin:0">Cliquez sur « Ajouter l'aller » ou « Ajouter le retour » : le formulaire s'ouvre juste en dessous, villes et date pré-remplies, trains SNCF proposés.
        Collez le lien du billet : il devient un bouton « Billet » dans la feuille de route envoyée à l'équipe.</p>
      <LegsImportDialog :open="importing" :ev="ev" :apply="apply" @close="importing = false" />
      <p v-if="!ev.participations.length" class="card empty">Ajoutez d'abord des personnes à l'étape Équipe.</p>

      <article v-for="p in ev.participations" :key="p.id" class="card" :class="{ warn: status(p)[1] !== 'ok' }">
        <div class="person-head">
          <Avatar :id="p.person.id" :initials="(p.person.firstName.charAt(0) + p.person.lastName.charAt(0)).toUpperCase()" :name="p.person.fullName" />
          <span class="who"><b>{{ p.person.fullName }}</b><span class="muted small">{{ p.role || 'Équipe' }} · part de {{ p.departureCity || '?' }}</span></span>
          <span class="status chip" :class="status(p)[1]">{{ status(p)[0] }}</span>
          <button v-if="status(p)[1] === 'ok'" type="button" class="btn btn-sm btn-icon" :aria-expanded="isOpen(p)" :aria-label="`Trajets de ${name(p)}`"
            @click="open[p.id] = !isOpen(p)"><Icon name="chevron" :size="16" :style="isOpen(p) ? 'transform:rotate(180deg)' : ''" /></button>
        </div>
        <div v-if="isOpen(p)" class="legs">
          <template v-for="[dir, label] in [['outbound', 'ALLER'], ['return', 'RETOUR']]" :key="dir">
            <template v-for="l in legsOf(p, dir)" :key="l.id">
            <div class="leg" :class="{ back: dir === 'return', editing: isAt(`l${l.id}`) }">
              <span class="dir">{{ label }}</span>
              <span class="what"><b>{{ fdate(l.day, true) }}</b> · {{ l.origin }} → {{ l.destination }}<template v-if="l.depTime"> · <b>{{ l.depTime }}<template v-if="l.arrTime"> – {{ l.arrTime }}</template></b></template></span>
              <span v-if="l.mode" class="mode">{{ l.mode }}<template v-if="l.travelClass"> · {{ l.travelClass === '1' ? '1re' : '2nde' }}</template></span>
              <span v-if="l.reference" class="ref mono">{{ l.reference }}</span>
              <span v-if="l.price" class="small muted">{{ money(l.price) }}</span>
              <a v-if="l.bookingUrl" class="btn-ticket" :href="l.bookingUrl" target="_blank" rel="noopener noreferrer">Billet ↗</a>
              <button v-else-if="allowed('travel.edit')" type="button" class="chip missing" style="cursor:pointer;background:none" @click="edit(p, dir, l, `l${l.id}`)">Lien manquant</button>
              <span v-if="allowed('travel.edit')" class="actions">
                <button type="button" class="btn btn-sm btn-icon" :aria-label="`Modifier ce trajet de ${name(p)}`" @click="edit(p, dir, l, `l${l.id}`)"><Icon name="edit" :size="16" /></button>
                <button type="button" class="btn btn-sm btn-icon btn-danger" :aria-label="`Supprimer ce trajet de ${name(p)}`" @click="remove(p, l)"><Icon name="trash" :size="16" /></button>
              </span>
            </div>
            <TravelEditor v-if="isAt(`l${l.id}`)" :panel="panel" :ev="ev" :apply="apply" @close="panel = null" />
            </template>
            <button v-if="!legsOf(p, dir).length && allowed('travel.edit')" type="button" class="btn btn-dashed" :class="{ violet: dir === 'outbound' }" @click="edit(p, dir, null, `add-${p.id}-${dir}`)">
              <Icon name="plus" />Ajouter {{ dir === 'outbound' ? "l'aller" : 'le retour' }} de {{ name(p) }}</button>
            <TravelEditor v-if="isAt(`add-${p.id}-${dir}`)" :panel="panel" :ev="ev" :apply="apply" @close="panel = null" />
            <template v-for="x in expensesOf(p, dir)" :key="`x${x.id}`">
            <div class="leg expense" :class="{ back: dir === 'return', editing: isAt(`x${x.id}`) }">
              <span class="dir">{{ x.amount < 0 ? 'REMB.' : 'DÉPENSE' }}</span>
              <span class="what"><b>{{ fdate(x.day, true) }}</b> · {{ x.kind }}<template v-if="x.comment"> · <span class="muted">{{ x.comment }}</span></template></span>
              <b class="small" :style="x.amount < 0 ? 'color:var(--mint-dark)' : ''">{{ money(x.amount) }}</b>
              <span v-if="canExpense()" class="actions">
                <button type="button" class="btn btn-sm btn-icon" :aria-label="`Modifier la ligne ${x.kind} de ${name(p)}`" @click="editExpense(p, dir, x, `x${x.id}`)"><Icon name="edit" :size="16" /></button>
                <button type="button" class="btn btn-sm btn-icon btn-danger" :aria-label="`Supprimer la ligne ${x.kind} de ${name(p)}`" @click="removeExpense(p, x)"><Icon name="trash" :size="16" /></button>
              </span>
            </div>
            <TravelEditor v-if="isAt(`x${x.id}`)" :panel="panel" :ev="ev" :apply="apply" @close="panel = null" />
            </template>
          </template>
          <button v-if="legsOf(p, 'outbound').length && legsOf(p, 'return').length && allowed('travel.edit')" type="button" class="btn btn-sm btn-ghost" style="align-self:flex-start" @click="edit(p, 'outbound', null, `other-${p.id}`)">
            <Icon name="plus" :size="16" />Autre trajet (correspondance, échange…)</button>
          <TravelEditor v-if="isAt(`other-${p.id}`)" :panel="panel" :ev="ev" :apply="apply" @close="panel = null" />
          <span v-if="canExpense()" class="actions" style="align-self:flex-start">
            <button type="button" class="btn btn-sm btn-ghost" @click="editExpense(p, 'outbound', null, `xadd-${p.id}`)"><Icon name="plus" :size="16" />Dépense / remboursement aller</button>
            <button type="button" class="btn btn-sm btn-ghost" @click="editExpense(p, 'return', null, `xadd-${p.id}`)"><Icon name="plus" :size="16" />Dépense / remboursement retour</button>
          </span>
          <TravelEditor v-if="isAt(`xadd-${p.id}`)" :panel="panel" :ev="ev" :apply="apply" @close="panel = null" />
        </div>
      </article>

      <section v-if="ev.participations.length && ev.personCosts" class="card">
        <div class="card-head"><h3>Récapitulatif des frais</h3>
          <span class="actions">
            <RouterLink class="btn btn-sm" :to="`/events/${ev.id}/print/travelcost`" target="_blank">Suivi des frais PDF</RouterLink>
            <a class="btn btn-sm" :href="`/api/events/${ev.id}/costs.xlsx`"><Icon name="download" :size="16" />Excel des coûts</a>
            <button v-if="allowed('share.manage')" type="button" class="btn btn-sm" @click="sharingCosts = true"><Icon name="link" :size="16" />Envoyer les coûts</button>
          </span></div>
        <CostShareDialog :open="sharingCosts" :ev="ev" :apply="apply" @close="sharingCosts = false" />
        <div class="table-wrap">
          <table class="table">
            <caption class="sr-only">Billets, dépenses et remboursements compris</caption>
            <thead><tr><th>Nom</th><th>Départ de</th><th class="r">Aller</th><th class="r">Retour</th><th class="r">Total</th></tr></thead>
            <tbody><tr v-for="c in ev.personCosts" :key="c.participationId"><td>{{ c.name }}</td><td>{{ c.departureCity }}</td>
              <td class="r">{{ money(c.outbound) }}</td><td class="r">{{ money(c.return) }}</td><td class="r">{{ money(c.outbound + c.return) }}</td></tr></tbody>
            <tfoot><tr><td colspan="2">Total</td><td class="r">{{ money(sum(ev.personCosts, 'outbound')) }}</td><td class="r">{{ money(sum(ev.personCosts, 'return')) }}</td>
              <td class="r">{{ money(ev.quote.travelTotal) }}</td></tr></tfoot>
          </table>
        </div>
      </section>
    </section>

  </div>
</template>

<style scoped>
.leg.editing { outline: 2px solid var(--violet); outline-offset: -2px; }
</style>
