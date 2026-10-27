<script setup>
import { computed } from 'vue'
import { legal } from '../legal.js'
import { session } from '../api.js'
import LegalPage from '../components/LegalPage.vue'

const contact = computed(() => legal.privacyEmail || legal.publisher.email)
</script>

<template>
  <LegalPage title="Politique de confidentialité" updated="octobre 2026">
    <section>
      <h2>Qui est responsable de vos données ?</h2>
      <ul>
        <li><b>Comptes, connexions et journal de sécurité :</b> {{ legal.publisher.name || '[éditeur non renseigné]' }}, éditeur de ce site, est responsable du traitement.</li>
        <li><b>Équipes, tournées, déplacements, hébergements, devis :</b> chaque compagnie qui utilise l'outil est responsable du traitement des données de ses propres équipes. L'éditeur du site agit pour son compte en tant que sous-traitant (il héberge et sécurise ces données, sans les utiliser pour d'autres finalités).</li>
      </ul>
      <p>Contact pour toute question ou pour exercer vos droits : <template v-if="contact"><a :href="`mailto:${contact}`">{{ contact }}</a></template><template v-else>[adresse de contact non renseignée]</template>.</p>
    </section>

    <section>
      <h2>Quelles données, pourquoi et sur quel fondement ?</h2>
      <table class="legal-table">
        <thead><tr><th>Données</th><th>Finalité</th><th>Fondement</th></tr></thead>
        <tbody>
          <tr><td><b>Compte utilisateur</b> : e-mail, nom, mot de passe (conservé uniquement haché), groupes et droits, dates de création et de dernière connexion</td><td>Authentifier les utilisateurs et gérer leurs droits d'accès</td><td>Exécution du service demandé</td></tr>
          <tr><td><b>Annuaire des personnes</b> (artistes, techniciens) : nom, prénom, fonction, téléphone, e-mail, ville de départ</td><td>Composer les équipes et organiser les tournées</td><td>Exécution du contrat ou intérêt légitime de la compagnie</td></tr>
          <tr><td><b>Régime alimentaire</b> (peut révéler un état de santé ou une conviction religieuse)</td><td>Prévoir les repas</td><td><b>Consentement explicite</b> de la personne, retirable à tout moment</td></tr>
          <tr><td><b>Déplacements et séjour</b> : trajets, horaires, références et liens de réservation, hébergement et chambre, repas, défraiements, montants</td><td>Réserver, informer l'équipe, établir les devis et les frais</td><td>Exécution du contrat ou intérêt légitime de la compagnie</td></tr>
          <tr><td><b>Journal de sécurité</b> : e-mail, action, adresse IP, date (connexions, modifications de droits…)</td><td>Sécurité, détection d'abus, preuve des actions d'administration</td><td>Intérêt légitime (sécurité du service)</td></tr>
          <tr><td><b>Appareils de confiance</b> (si vous cochez « Rester connecté ») : type de navigateur et de système, adresse IP, dates d'utilisation</td><td>Garder votre session ouverte et vous permettre de la révoquer</td><td>Votre choix</td></tr>
        </tbody>
      </table>
      <p>Aucune donnée n'est utilisée à des fins commerciales ou de publicité, ni vendue. Aucun profilage ni décision automatisée.</p>
    </section>

    <section>
      <h2>Cookies</h2>
      <p>Le site n'utilise que des cookies strictement nécessaires à son fonctionnement, qui ne demandent pas de consentement. Aucun traceur publicitaire ou de mesure d'audience, aucune ressource tierce (les polices sont hébergées sur le site). L'application installable garde sur l'appareil les seuls fichiers de l'interface, jamais vos données.</p>
      <ul>
        <li><code>roadmap_session</code> : maintient votre connexion ; supprimé à la fermeture du navigateur ou après 12 heures d'inactivité.</li>
        <li><code>REMEMBERME</code> : uniquement si vous cochez « Rester connecté sur cet appareil » ; valable 30 jours après la dernière utilisation, révocable dans « Mon compte ».</li>
      </ul>
    </section>

    <section>
      <h2>Qui reçoit les données ?</h2>
      <ul>
        <li>Les utilisateurs autorisés de la compagnie concernée, selon les droits qui leur sont donnés (les coordonnées et les montants ne sont visibles que des personnes habilitées).</li>
        <li>Les personnes qui reçoivent le <b>lien de partage</b> d'une feuille de route : elles voient le document de cette date (équipe, trajets, hébergement, planning). Le lien est secret et révocable.</li>
        <li>L'hébergeur du site ({{ legal.host.name || '[non renseigné]' }}), en tant que sous-traitant technique.</li>
        <li v-if="session.passwordReset">Le prestataire d'envoi d'e-mails de l'éditeur, pour les messages de réinitialisation de mot de passe (votre adresse e-mail).</li>
        <li v-if="session.google">Google, si vous choisissez « Se connecter avec Google » : Google authentifie votre compte et ne transmet au site que votre adresse e-mail et votre nom. Cette connexion peut impliquer un transfert hors de l'Union européenne encadré par Google.</li>
        <li>Orfeo, si la compagnie relie son compte : l'outil <b>lit</b> les dates et équipes de la compagnie dans Orfeo avec la clé d'API qu'elle fournit (stockée chiffrée) ; aucune donnée personnelle n'est envoyée à Orfeo.</li>
        <li>Les horaires de train proviennent des données ouvertes de la SNCF, téléchargées sur le serveur : aucune donnée personnelle n'est transmise à la SNCF par le site.</li>
      </ul>
    </section>

    <section>
      <h2>Combien de temps ?</h2>
      <ul>
        <li><b>Journal de sécurité :</b> 12 mois, puis suppression automatique.</li>
        <li><b>Liens d'invitation et de réinitialisation :</b> 7 jours (invitation) ou 1 heure (mot de passe oublié), à usage unique, puis supprimés.</li>
        <li><b>Appareils de confiance :</b> supprimés après 30 jours sans utilisation, ou dès que vous les révoquez, changez de mot de passe ou que le compte est désactivé.</li>
        <li><b>Comptes :</b> jusqu'à leur suppression par un administrateur, notamment à la fin de la collaboration.</li>
        <li><b>Équipes, tournées et annuaire :</b> pendant la durée utile à la compagnie, puis supprimés par elle ; sur demande de la personne concernée, plus tôt.</li>
      </ul>
    </section>

    <section>
      <h2>Vos droits</h2>
      <p>Vous pouvez demander l'<b>accès</b> à vos données, leur <b>rectification</b>, leur <b>effacement</b>, la <b>limitation</b> ou l'<b>opposition</b> au traitement, la <b>portabilité</b> de celles que vous avez fournies, et retirer à tout moment un consentement donné (par exemple pour le régime alimentaire). Écrivez à <template v-if="contact"><a :href="`mailto:${contact}`">{{ contact }}</a></template><template v-else>[adresse de contact non renseignée]</template> ; si votre demande concerne les données saisies par une compagnie, elle sera transmise à celle-ci. Réponse sous un mois.</p>
      <p>Vous pouvez aussi introduire une réclamation auprès de la CNIL : <a href="https://www.cnil.fr/fr/plaintes" target="_blank" rel="noopener">cnil.fr/plaintes</a> — 3 place de Fontenoy, TSA 80715, 75334 Paris Cedex 07.</p>
    </section>

    <section>
      <h2>Sécurité</h2>
      <p>Connexion chiffrée (HTTPS), mots de passe hachés et soumis à une exigence de robustesse, limitation des tentatives de connexion, cloisonnement strict entre compagnies, droits d'accès par groupes, clés d'API chiffrées, journal des actions sensibles. En cas de violation de données susceptible de vous porter préjudice, vous en seriez informé dans les conditions prévues par le RGPD.</p>
    </section>
  </LegalPage>
</template>
