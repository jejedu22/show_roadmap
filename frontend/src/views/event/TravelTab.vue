<script setup>
import { inject, nextTick, ref } from 'vue'
import { del, toast } from '../../api.js'
import { byDeparture, fdate, money, sum } from '../../format.js'
import Avatar from '../../components/Avatar.vue'
import Icon from '../../components/Icon.vue'
import LegPanel from '../../components/LegPanel.vue'

const { ev, apply } = inject('event')
const panel = ref(null) // { part, direction, leg }
const panelEl = ref(null)
const open = ref({})

const legsOf = (p, dir) => p.legs.filter((l) => l.direction === dir).sort(byDeparture)
const status = (p) => {
  const out = legsOf(p, 'outbound').length, back = legsOf(p, 'return').length
  return out && back ? ['Complet', 'ok'] : !out && !back ? ['À réserver', 'bad'] : !out ? ['Aller à réserver', 'warn'] : ['Retour à réserver', 'warn']
}
const isOpen = (p) => open.value[p.id] ?? status(p)[1] !== 'ok'
const name = (p) => p.person.firstName || p.person.lastName
async function edit(part, direction, leg = null) {
  panel.value = { part, direction, leg }
  await nextTick()
  if (window.innerWidth < 900) panelEl.value?.scrollIntoView({ behavior: 'smooth' })
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
        <span class="muted">{{ ev.readiness.legsCovered }} / {{ ev.readiness.legsNeeded }} trajets · billets <b style="color:var(--ink)">{{ money(ev.quote.travelTickets) }}</b></span>
      </div>
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
            <div v-for="l in legsOf(p, dir)" :key="l.id" class="leg" :class="{ back: dir === 'return' }">
              <span class="dir">{{ label }}</span>
              <span class="what"><b>{{ fdate(l.day, true) }}</b> · {{ l.origin }} → {{ l.destination }}<template v-if="l.depTime"> · <b>{{ l.depTime }}<template v-if="l.arrTime"> – {{ l.arrTime }}</template></b></template></span>
              <span v-if="l.mode" class="mode">{{ l.mode }}</span>
              <span v-if="l.reference" class="ref mono">{{ l.reference }}</span>
              <span v-if="l.price" class="small muted">{{ money(l.price) }}</span>
              <a v-if="l.bookingUrl" class="btn-ticket" :href="l.bookingUrl" target="_blank" rel="noopener noreferrer">Billet ↗</a>
              <button v-else type="button" class="chip missing" style="cursor:pointer;background:none" @click="edit(p, dir, l)">Lien manquant</button>
              <span class="actions">
                <button type="button" class="btn btn-sm btn-icon" :aria-label="`Modifier ce trajet de ${name(p)}`" @click="edit(p, dir, l)"><Icon name="edit" :size="16" /></button>
                <button type="button" class="btn btn-sm btn-icon btn-danger" :aria-label="`Supprimer ce trajet de ${name(p)}`" @click="remove(p, l)"><Icon name="trash" :size="16" /></button>
              </span>
            </div>
            <button v-if="!legsOf(p, dir).length" type="button" class="btn btn-dashed" :class="{ violet: dir === 'outbound' }" @click="edit(p, dir)">
              <Icon name="plus" />Ajouter {{ dir === 'outbound' ? "l'aller" : 'le retour' }} de {{ name(p) }}</button>
          </template>
          <button v-if="legsOf(p, 'outbound').length && legsOf(p, 'return').length" type="button" class="btn btn-sm btn-ghost" style="align-self:flex-start" @click="edit(p, 'outbound')">
            <Icon name="plus" :size="16" />Autre trajet (correspondance, échange…)</button>
        </div>
      </article>

      <section v-if="ev.participations.length" class="card">
        <div class="card-head"><h3>Récapitulatif des frais</h3>
          <RouterLink class="btn btn-sm" :to="`/events/${ev.id}/print/travelcost`" target="_blank">Suivi des frais PDF</RouterLink></div>
        <div class="table-wrap">
          <table class="table">
            <thead><tr><th>Nom</th><th>Départ de</th><th class="r">Aller</th><th class="r">Retour</th><th class="r">Total</th></tr></thead>
            <tbody><tr v-for="c in ev.personCosts" :key="c.participationId"><td>{{ c.name }}</td><td>{{ c.departureCity }}</td>
              <td class="r">{{ money(c.outbound) }}</td><td class="r">{{ money(c.return) }}</td><td class="r">{{ money(c.outbound + c.return) }}</td></tr></tbody>
            <tfoot><tr><td colspan="2">Total</td><td class="r">{{ money(sum(ev.personCosts, 'outbound')) }}</td><td class="r">{{ money(sum(ev.personCosts, 'return')) }}</td>
              <td class="r">{{ money(ev.quote.travelTickets) }}</td></tr></tfoot>
          </table>
        </div>
      </section>
    </section>

    <aside ref="panelEl" class="side-col">
      <LegPanel v-if="panel" :key="`${panel.part.id}-${panel.direction}-${panel.leg?.id}`" :ev="ev" :part="panel.part" :direction="panel.direction"
        :leg="panel.leg" :apply="apply" @close="panel = null" />
      <div v-else class="card">
        <h3 style="margin-bottom:8px">Comment ça marche</h3>
        <p class="muted small" style="margin:0">Cliquez sur « Ajouter l'aller » ou « Ajouter le retour » : villes et date sont pré-remplies, les trains directs SNCF sont proposés.
          Collez le lien du billet : il devient un bouton « Billet » dans la feuille de route envoyée à l'équipe.</p>
      </div>
    </aside>
  </div>
</template>
