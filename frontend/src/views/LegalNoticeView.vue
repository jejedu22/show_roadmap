<script setup>
import { computed } from 'vue'
import { legal } from '../legal.js'
import LegalPage from '../components/LegalPage.vue'

const val = (v) => v || null
const p = computed(() => legal.publisher)
const h = computed(() => legal.host)
</script>

<template>
  <LegalPage title="Mentions légales" updated="octobre 2026">
    <section>
      <h2>Éditeur du site</h2>
      <p>
        <b>{{ val(p.name) || '[nom ou dénomination sociale non renseigné]' }}</b><template v-if="p.form"> — {{ p.form }}</template><br>
        <template v-if="p.id">Identification : {{ p.id }}<br></template>
        <template v-if="p.address">{{ p.address }}<br></template>
        <template v-if="p.email">E-mail : <a :href="`mailto:${p.email}`">{{ p.email }}</a><br></template>
        <template v-if="p.phone">Téléphone : {{ p.phone }}</template>
      </p>
      <p v-if="p.director"><b>Directeur de la publication :</b> {{ p.director }}</p>
    </section>

    <section>
      <h2>Hébergeur</h2>
      <p>
        <b>{{ val(h.name) || '[hébergeur non renseigné]' }}</b><br>
        <template v-if="h.address">{{ h.address }}<br></template>
        <template v-if="h.phone">Téléphone : {{ h.phone }}</template>
      </p>
    </section>

    <section>
      <h2>Nature du service</h2>
      <p>Show Roadmap est un outil de gestion de tournées de spectacle vivant (dates, équipes, déplacements, hébergements, devis et feuilles de route), réservé aux utilisateurs autorisés par l'éditeur. L'accès se fait par un compte personnel ; seules les feuilles de route partagées par lien sont consultables sans compte, par les personnes qui en disposent.</p>
    </section>

    <section>
      <h2>Logiciel libre et propriété intellectuelle</h2>
      <p>
        Le logiciel Show Roadmap est un logiciel libre, distribué sous la licence
        <a href="https://www.gnu.org/licenses/agpl-3.0.html" target="_blank" rel="noopener">GNU Affero General Public License v3 ou ultérieure</a>.
        Vous pouvez en obtenir le code source<template v-if="legal.sourceUrl"> à l'adresse <a :href="legal.sourceUrl" target="_blank" rel="noopener">{{ legal.sourceUrl }}</a></template>, l'étudier, le modifier et le redistribuer dans les conditions de cette licence.
      </p>
      <p>Les contenus saisis dans l'outil (noms, plannings, documents générés) restent la propriété des compagnies qui les ont saisis. Les polices Inter et Bricolage Grotesque sont distribuées sous licence SIL Open Font License 1.1.</p>
    </section>

    <section>
      <h2>Données personnelles</h2>
      <p>Le traitement des données personnelles est décrit dans la <RouterLink to="/confidentialite">politique de confidentialité</RouterLink>.</p>
    </section>

    <section>
      <h2>Responsabilité</h2>
      <p>L'éditeur s'efforce d'assurer l'exactitude des informations et la disponibilité du service, sans garantie. Les horaires de train proposés proviennent des données ouvertes de la SNCF, à titre indicatif : ils sont à vérifier avant toute réservation. L'éditeur ne peut être tenu responsable d'une saisie erronée ou d'une indisponibilité temporaire.</p>
    </section>

    <section>
      <h2>Droit applicable</h2>
      <p>Les présentes mentions sont soumises au droit français.</p>
    </section>
  </LegalPage>
</template>
