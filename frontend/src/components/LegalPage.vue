<script setup>
import { RouterLink } from 'vue-router'
import { loadLegal, legal } from '../legal.js'
import { session } from '../api.js'
import Icon from './Icon.vue'

/** Cadre des pages publiques (mentions légales, confidentialité) : lisible connecté ou non. */
defineProps({ title: String, updated: String })
loadLegal()
</script>

<template>
  <div class="legal-page">
    <header class="legal-head">
      <RouterLink class="brand" :to="session.user ? '/' : '/login'"><span class="brand-mark"><Icon name="logo" :size="20" /></span>Show Roadmap</RouterLink>
      <nav aria-label="Pages légales">
        <RouterLink to="/mentions-legales">Mentions légales</RouterLink>
        <RouterLink to="/confidentialite">Confidentialité</RouterLink>
      </nav>
    </header>
    <main class="legal-body">
      <h1>{{ title }}</h1>
      <p v-if="updated" class="muted small">Dernière mise à jour : {{ updated }}</p>
      <p v-if="legal.loaded && !legal.configured" class="alert" role="status">
        Les informations de l'éditeur et de l'hébergeur ne sont pas encore renseignées : l'exploitant doit compléter les variables <code>LEGAL_*</code> du fichier <code>.env</code> (voir <code>docs/conformite-rgpd.md</code>).
      </p>
      <slot />
    </main>
    <footer class="legal-foot muted small">
      Logiciel libre sous licence AGPL-3.0<template v-if="legal.sourceUrl"> · <a :href="legal.sourceUrl" target="_blank" rel="noopener">code source</a></template>
    </footer>
  </div>
</template>
