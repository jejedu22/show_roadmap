# Conformité : mentions légales, RGPD et CNIL

> Ce document est un guide pratique pour l'exploitant d'une instance de Show Roadmap. Il ne remplace pas un conseil
> juridique : en cas de doute, adressez-vous à un juriste ou au service d'aide de la CNIL.

## 1. Faut-il une déclaration à la CNIL ?

**Non, plus depuis le 25 mai 2018.** Le RGPD a supprimé la quasi-totalité des déclarations préalables à la CNIL. Il les a
remplacées par une **responsabilité permanente** : vous devez pouvoir démontrer que vous êtes en règle. Pour cette
application, cela se traduit par :

| Obligation | Pour Show Roadmap |
|---|---|
| **Registre des traitements** (art. 30) | À tenir, même si vous avez moins de 250 personnes : le traitement n'est pas occasionnel. Un modèle prêt à remplir est en section 4. |
| **Information des personnes** (art. 13 et 14) | Assurée par la page « Confidentialité » de l'application. Prévenez aussi les personnes de l'annuaire que leurs données y figurent (un message type suffit, section 6). |
| **Contrat de sous-traitance** (art. 28) | Obligatoire avec votre hébergeur et votre prestataire d'e-mails. **Et** entre vous et les compagnies que vous hébergez, si l'instance est partagée (voir section 3). |
| **Sécurité** (art. 32) | Voir section 5 : ce que fait l'application et ce qui reste à votre charge. |
| **Droits des personnes** (art. 15 à 22) | Réponse sous un mois à l'adresse indiquée dans la page « Confidentialité ». |
| **Violation de données** (art. 33) | Notification à la CNIL **sous 72 heures** si elle présente un risque pour les personnes (notifications.cnil.fr), et inscription au registre des violations. |
| **Analyse d'impact (AIPD)** | Pas obligatoire a priori : pas de surveillance, pas de données sensibles à grande échelle. À réévaluer si vous ajoutez des fonctions (géolocalisation, données de santé détaillées…). |
| **Délégué à la protection des données (DPO)** | Pas obligatoire pour cet usage. |

Aucune formalité préalable n'est donc à accomplir auprès de la CNIL ; la CNIL peut en revanche contrôler votre
documentation à tout moment.

### Point d'attention : le régime alimentaire

Le champ « Régime alimentaire » peut révéler **un état de santé** (allergies, intolérances) ou **une conviction
religieuse** (« hallal », « casher »). Ce sont des données sensibles (art. 9) : leur traitement est en principe interdit
sauf **consentement explicite** de la personne. Donc :

- ne renseignez ce champ qu'avec l'accord de la personne (l'application le rappelle) ;
- limitez-le à l'utile (« allergie arachide », « végétarien », « sans porc ») ;
- retirez-le dès que la personne le demande.

## 2. Mentions légales

Les mentions légales sont obligatoires pour tout service de communication au public en ligne (loi du 21 juin 2004, LCEN,
art. 6-III). Pour un outil réservé à des utilisateurs connectés, l'obligation est moins nette, mais la page de connexion
et les liens de partage sont accessibles : mieux vaut les publier, c'est gratuit.

L'application fournit les pages **Mentions légales** (`/mentions-legales`) et **Confidentialité** (`/confidentialite`),
liées depuis la page de connexion et le menu. Elles se remplissent avec les variables du fichier `.env` :

| Variable | Contenu |
|---|---|
| `LEGAL_NAME` | Nom ou dénomination sociale de l'éditeur (pour un particulier : prénom et nom) |
| `LEGAL_FORM` | Statut : « Association loi 1901 », « SAS au capital de 1 000 € », « Particulier »… |
| `LEGAL_ID` | SIRET, RNA ou RCS si vous en avez un |
| `LEGAL_ADDRESS` | Adresse postale (siège social) |
| `LEGAL_EMAIL` | Adresse de contact |
| `LEGAL_PHONE` | Téléphone (obligatoire pour une société, facultatif sinon) |
| `LEGAL_DIRECTOR` | Directeur de la publication |
| `LEGAL_HOST_NAME`, `LEGAL_HOST_ADDRESS`, `LEGAL_HOST_PHONE` | Hébergeur : nom, adresse, téléphone |
| `LEGAL_PRIVACY_EMAIL` | Contact RGPD (par défaut `LEGAL_EMAIL`) |
| `LEGAL_SOURCE_URL` | Adresse du code source (licence AGPL, voir ci-dessous) |

Cas d'un **particulier non professionnel** : la loi permet de ne pas publier son identité, à condition de communiquer
à l'hébergeur ses coordonnées et d'afficher celles de l'hébergeur. Renseignez alors `LEGAL_NAME` avec un pseudonyme ou le
nom d'une structure, et gardez `LEGAL_HOST_*`.

La licence AGPL (§ 13) oblige à proposer le code source aux utilisateurs d'un service accessible par le réseau, y
compris si vous l'avez modifié. C'est le rôle de `LEGAL_SOURCE_URL` (lien affiché dans les pages légales) : indiquez
l'adresse de votre fork si vous en avez un.

## 3. Qui est responsable de quoi ?

- Chaque **compagnie** décide pourquoi et comment elle utilise les données de ses équipes : elle est **responsable du
  traitement**.
- Vous, qui hébergez l'instance pour ces compagnies, êtes leur **sous-traitant** pour ces données : vous ne les
  utilisez pas pour vous. Un contrat (art. 28) doit le prévoir ; la CNIL publie un modèle de clauses.
- Vous êtes responsable de traitement pour les **comptes utilisateurs** et le **journal de sécurité**.
- Si vous utilisez l'instance seul(e) pour votre propre compagnie, vous cumulez les deux rôles : pas de contrat à signer
  avec vous-même, mais le contrat avec l'hébergeur reste nécessaire.

## 4. Modèle de registre des traitements

À copier dans un tableur ou un document (la CNIL propose aussi un modèle sur cnil.fr, rubrique « Le registre des
activités de traitement »). Coordonnées du responsable : _à compléter_.

| | 1. Comptes utilisateurs | 2. Annuaire et équipes | 3. Déplacements, séjour, devis | 4. Journal de sécurité |
|---|---|---|---|---|
| **Finalité** | Authentifier, gérer les droits | Composer les équipes d'une tournée | Réserver, informer, calculer les frais | Sécurité, preuve des actions d'administration |
| **Personnes** | Utilisateurs de l'outil | Artistes, techniciens, administrateurs de tournée | Les mêmes | Utilisateurs |
| **Données** | E-mail, nom, mot de passe haché, droits, connexions | Nom, prénom, fonction, téléphone, e-mail, ville ; **régime alimentaire (sensible)** | Trajets, horaires, références et liens de billets, hébergement, repas, montants | E-mail, action, adresse IP, date |
| **Base légale** | Exécution du service | Contrat / intérêt légitime ; consentement pour le régime alimentaire | Contrat / intérêt légitime | Intérêt légitime |
| **Destinataires** | Administrateurs | Utilisateurs habilités ; personnes disposant d'un lien de partage (équipe, trajets) | Utilisateurs habilités selon leurs droits | Administrateurs |
| **Sous-traitants** | Hébergeur ; prestataire d'e-mails ; Google si connexion Google | Hébergeur ; Orfeo si la compagnie le relie (lecture) | Hébergeur | Hébergeur |
| **Transferts hors UE** | Google (cadre de Google) si activé ; selon prestataire d'e-mails | Selon hébergeur | Selon hébergeur | Selon hébergeur |
| **Durée** | Jusqu'à suppression du compte | Durée utile à la compagnie, suppression sur demande | Durée utile à la compagnie | 12 mois (purge automatique) |
| **Sécurité** | HTTPS, mots de passe hachés, limitation des tentatives, appareils révocables | Cloisonnement par compagnie, droits par groupes | Idem | Accès réservé aux administrateurs |

Tenez aussi un **registre des violations** (date, nature, données et personnes concernées, mesures prises) et les
**contrats de sous-traitance** signés.

## 5. Mesures de sécurité : ce que fait l'application, ce qui vous revient

**Déjà en place :** mots de passe hachés et soumis à une exigence de robustesse, limitation des tentatives de connexion et
des demandes de réinitialisation, liens de réinitialisation à usage unique (1 h) stockés hachés, sessions « rester
connecté » révocables et protégées contre le vol de cookie, cloisonnement strict entre compagnies, droits par groupes,
clés d'API tierces chiffrées, journal d'audit, protection CSRF, purge automatique des données techniques.

**À votre charge :** HTTPS (assuré par Traefik), mises à jour régulières de l'image et du système, **sauvegardes**
chiffrées de la base et test de restauration, mot de passe d'administrateur robuste, accès limité au serveur, retrait des
comptes des personnes parties.

## 6. Message type pour les personnes de l'annuaire

> Bonjour, dans le cadre de l'organisation des tournées de [compagnie], vos nom, fonction, coordonnées et informations de
> voyage sont enregistrés dans l'outil Show Roadmap. Votre régime alimentaire n'y figure que si vous nous l'avez indiqué.
> Vous pouvez à tout moment accéder à ces informations, les corriger ou demander leur suppression en écrivant à
> [adresse]. Plus de détails : [adresse de l'instance]/confidentialite.

## 7. Avant d'ouvrir l'instance : liste de contrôle

- [ ] Variables `LEGAL_*` renseignées ; pages `/mentions-legales` et `/confidentialite` relues.
- [ ] `MAILER_DSN` configuré (mot de passe oublié) et expéditeur autorisé par le fournisseur.
- [ ] Registre des traitements créé ; contrat avec l'hébergeur et le prestataire d'e-mails.
- [ ] Contrat de sous-traitance avec chaque compagnie hébergée (instance partagée).
- [ ] Sauvegardes automatiques testées.
- [ ] Personnes de l'annuaire informées ; consentement recueilli pour les régimes alimentaires.
- [ ] Procédure écrite : qui répond aux demandes de droits, qui notifie une violation.
