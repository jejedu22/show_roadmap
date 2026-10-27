# Show Roadmap

Application web de gestion de tournée pour compagnies de spectacle. Elle remplace les classeurs Excel
« Devis_<Spectacle>_<Ville>.xlsx » : devis, rooming-list, défraiements, suivi des frais de voyage, feuille de route, coordonnées.

- **backend/** : API JSON Symfony 7.4 (PHP 8.3), Doctrine ORM, MariaDB.
- **frontend/** : Vue 3 + Vite + Vue Router.
- **Docker** : une image FrankenPHP qui sert l'API et le front compilé, plus un conteneur MariaDB, derrière Traefik.

> **Logiciel libre** sous licence [GNU AGPL v3 ou ultérieure](LICENSE). Vous pouvez l'installer, l'étudier, le modifier
> et le partager ; si vous le proposez en ligne à d'autres utilisateurs, vous devez leur donner accès au code source de
> votre version (lien « code source » des pages légales). Voir [Licence et contribution](#licence-et-contribution).

## Fonctionnalités

- **Tableau de bord** : prochaine date, trajets sans billet, conflits de planning, dates par mois avec leur avancement,
  liste « À faire » calculée automatiquement. **Calendrier** mensuel des présences.
- **Fiche d'une date en 6 étapes** (Infos, Équipe, Séjour, Voyages, Devis, Feuille de route), chacune avec son avancement.
- **Voyages** : une carte par personne, ajout de l'aller ou du retour pré-rempli (villes, date) avec les trains SNCF (directs ou avec une correspondance) ;
  les gares les mieux reliées sont choisies automatiquement (Paris → Beauvais : Paris Gare du Nord).
- **Envoyer à l'équipe** : lien secret et révocable vers la feuille de route PDF, à copier ou à envoyer par la messagerie.

- **Compagnie** → **spectacles** (plusieurs possibles, y compris sur les mêmes dates) et **annuaire** de personnes.
- **Date de tournée** (un spectacle × un lieu × une période) : équipe, voyages, nuitées/repas/petits-déjeuners, hébergements, devis, feuille de route.
- Une personne engagée sur deux dates qui se chevauchent est signalée dans le planning et sur la fiche équipe.
- Documents en **vrais PDF** générés par le serveur (feuille de route, devis, rooming-list, défraiements, suivi des frais,
  coordonnées) et export Excel d'une date.
- **Lien de réservation** sur chaque trajet : il devient un bouton « Billet ↗ » cliquable dans la feuille de route PDF,
  pour que chaque intervenant récupère son billet lui-même.
- Connexion par mot de passe ou Google (comptes créés par un administrateur uniquement).
- Recherche de trains (directs ou avec une correspondance, y compris en changeant de gare à Paris, Lyon ou Lille) dans l'onglet Voyages, à partir des horaires SNCF en open data (sans clé).
- **Application installable (PWA)** : sur téléphone (« Ajouter à l'écran d'accueil ») ou ordinateur (bouton « Installer
  l'application » du menu sous Chrome / Edge), elle s'ouvre dans sa propre fenêtre avec son icône. L'interface est mise en
  cache pour démarrer vite, même sur une connexion faible ; les données, elles, ne sont jamais stockées sur l'appareil
  (hors connexion, un message invite à se reconnecter). Une nouvelle version est prise dès la réouverture de l'application.
  L'installation exige HTTPS (assuré par Traefik), sauf sur `localhost`.

## Déploiement Docker + Traefik

```bash
cp .env.example .env     # APP_HOST, APP_SECRET, DB_PASSWORD, ADMIN_*, réseau Traefik…
docker compose up -d --build
docker compose exec app php bin/console app:seed-demo   # données fictives (optionnel)
```

Au démarrage, le conteneur `app` attend MariaDB, applique les migrations et crée l'administrateur si la base est vide.
Le service rejoint le réseau Docker externe de Traefik (`TRAEFIK_NETWORK`) ; MariaDB reste sur un réseau interne.

Sauvegarde de la base :

```bash
docker compose exec db sh -c 'mariadb-dump -u roadmap -p"$MARIADB_PASSWORD" roadmap' > sauvegarde.sql
```

## Reprise des données de l'ancienne version (SQLite)

L'ancienne version (FastAPI) stockait ses données dans le fichier `app.db` du volume Docker `<projet>_roadmap-data`.
L'import se fait sur une base **sans compagnie** : ne lancez pas `app:seed-demo` avant.

```bash
# 1. Démarrer la nouvelle version (l'ancien conteneur est arrêté proprement, sa base est complète)
docker compose up -d --build

# 2. Copier la base depuis l'ancien volume (le nom exact est donné par : docker volume ls | grep roadmap-data)
docker run --rm -v <projet>_roadmap-data:/old -v "$PWD":/backup alpine \
  sh -c 'cp /old/app.db /backup/app.db && chmod 644 /backup/app.db'

# 3. Vérifier puis importer
docker compose cp app.db app:/tmp/app.db
docker compose exec app php bin/console app:import-sqlite /tmp/app.db --dry-run
docker compose exec app php bin/console app:import-sqlite /tmp/app.db
```

- Tout est repris : comptes, accès aux compagnies, spectacles, annuaire, dates, équipe, grilles, voyages, devis, feuille de route.
- L'import est tout ou rien : en cas d'erreur, rien n'est enregistré. Une relance est refusée, sans doublons.
- **Mots de passe** : ceux de l'ancienne version ne sont pas lisibles par Symfony. Un compte déjà présent avec le même e-mail
  (l'administrateur créé au démarrage) garde son mot de passe. Les autres comptes actifs reçoivent un mot de passe temporaire,
  affiché une seule fois à la fin de l'import, à transmettre aux personnes. La connexion Google fonctionne directement.
- Gardez l'ancien volume tant que vous n'avez pas vérifié les données ; supprimez-le ensuite (`docker volume rm <projet>_roadmap-data`).

## Import des classeurs Excel (devis)

Menu **Importer des devis** (droits « Modifier les dates » et « Modifier l'annuaire ») : déposez un ou plusieurs
classeurs `Devis_<Spectacle>_<Ville>.xlsx` (20 fichiers de 10 Mo au plus). Rien n'est enregistré avant validation.

- **Onglet DEVIS** : lieu, dates, distribution, villes de départ, prix des billets aller/retour, rooming, repas,
  petits-déjeuners, cachet, TVA, camion.
- **Coordonnées** : téléphone, e-mail, régime. L'annuaire est complété, jamais écrasé.
- **Feuille de route** : trajets (aller/retour, horaires, références), hébergements avec leurs occupants,
  rubriques pratiques (repas, transferts, wifi…) et planning.

Chaque fichier est contrôlé avant import :
- nom de fichier qui ne correspond pas au contenu (copie pas encore remplie) ;
- fichiers du lot portant sur le même lieu aux mêmes dates ;
- date déjà présente dans l'application ;
- feuille de route restée sur une autre date (elle n'est alors pas reprise par défaut) ;
- dates d'arrivée et de départ incohérentes (recalculées d'après le rooming).

Tout se corrige à l'écran avant d'importer.
Sans feuille de route, le prix des billets du devis est porté sur un trajet aller et un trajet retour « à compléter ».

### Onglet « Horaires TGV » seul

Dans l'onglet **Voyages** d'une date, le bouton **Importer depuis Excel (Horaires TGV)** (droit « Gérer les voyages »)
lit uniquement l'onglet « Horaires TGV » d'un classeur de devis et propose les trajets de chacun avant enregistrement.

- **Structures acceptées :** colonnes aller et retour côte à côte (position repérée d'après les dates d'en-tête) ;
  prénom sur la ligne des gares ou des horaires ; date propre à un trajet ou nouvelle date en cours d'onglet ;
  trajets « Autonome » ou en voiture sur une ligne ; trajet isolé sans prénom (rattaché à la personne du dessus, signalé).
- **Billets :** référence, prix, mode (Direct → TGV INOUI, OUIGO, TER, voiture, avion…). Les lignes « Echange »,
  « Annulation », « Remboursement » corrigent le prix du trajet et sont notées ; un billet entièrement remboursé
  ou remplacé par un échange est proposé décoché, de même qu'un trajet déjà saisi. Les totaux sont ignorés.
- **Personnes :** les prénoms sont rapprochés de l'équipe de la date (prénom, nom, initiales comme « JC ») ;
  un prénom non reconnu se rattache à la main ou s'ignore.
- **Enregistrement :** ajout aux trajets existants, ou remplacement des trajets des personnes importées.

## Développement

Prérequis : PHP 8.3 (pdo_mysql, intl, zip, gd), Composer, Node 22, MariaDB.

```bash
# Base locale : utilisateur app/app avec droits sur roadmap et roadmap_test
cd backend
composer install
cp .env .env.local   # adapter DATABASE_URL, APP_SECRET, ADMIN_EMAIL, ADMIN_PASSWORD
php bin/console doctrine:database:create
php bin/console doctrine:migrations:migrate
php bin/console app:bootstrap-admin
php bin/console app:seed-demo
php -S 127.0.0.1:8000 -t public public/dev-router.php

cd ../frontend
npm install
npm run dev          # http://localhost:5173, /api relayé vers :8000
```

Tests (sur une base MariaDB `roadmap_test`, schéma recréé à chaque test, appels SNCF/Google simulés) :

```bash
cd backend
php bin/console doctrine:database:create --env=test
php vendor/bin/phpunit
```

Après une modification d'entité : `php bin/console doctrine:migrations:diff` puis `migrate`.

## Horaires SNCF (open data)

L'onglet **Voyages** propose les **trains directs et avec une correspondance** entre deux gares à une date donnée
(TGV INOUI, OUIGO, Intercités, TER, Transilien, RER, cars…) et pré-remplit départ, arrivée, heures, mode et numéro de train.
Un trajet avec correspondance crée un trajet par tronçon. Prix et référence de billet restent à saisir.

- Sources, publiées en open data **sans clé** :
  - [horaires SNCF nationaux](https://www.data.gouv.fr/datasets/horaires-sncf) (TGV, Intercités, TER) pour les mois à venir, `GTFS_URL` ;
  - [horaires Transilien et RER](https://ressources.data.sncf.com/explore/dataset/sncf-transilien-gtfs/) pour les gares
    d'Île-de-France (Herblay, Argenteuil…), `GTFS_IDF_URL`. Ils ne sont publiés qu'**un mois à l'avance environ** :
    au-delà, l'écran le signale. Laissez `GTFS_IDF_URL` vide pour ne pas les charger.
- Les gares communes aux deux fichiers sont fusionnées (Paris Saint-Lazare, Gare du Nord…). Les correspondances se font
  dans la même gare, ou d'une gare à l'autre à Paris, Lyon et Lille. Les trajets à deux correspondances ou plus ne sont pas
  proposés : cherchez-les tronçon par tronçon.
- Le conteneur charge les horaires au premier démarrage puis les rafraîchit chaque nuit, en arrière-plan (environ 15 Mo
  à télécharger, 40 s d'import). Import manuel : `docker compose exec app php bin/console app:gtfs-import`.
- Si la SNCF déplace un fichier, renseignez la nouvelle adresse dans `GTFS_URL` ou `GTFS_IDF_URL` (`.env`).
- Le bouton « SNCF Connect » copie le trajet (« Paris Gare du Nord Beauvais 18/11/2026 ») dans le presse-papiers, à coller dans
  la recherche de SNCF Connect, puis ouvre `SNCF_CONNECT_URL`. SNCF Connect ne documente pas de lien de recherche pré-remplie ;
  si vous en trouvez un, mettez-le dans `SNCF_CONNECT_URL` avec les repères `{origin}`, `{destination}` et `{date}` (AAAA-MM-JJ).

## Connexion avec Google

1. Google Cloud Console → *APIs et services* → *Identifiants* → *ID client OAuth* de type **Application Web**.
2. URI de redirection autorisée : `https://<APP_HOST>/api/auth/google/callback`.
3. Renseigner `GOOGLE_CLIENT_ID` et `GOOGLE_CLIENT_SECRET` dans `.env`.

Seuls les comptes déjà créés par un administrateur (même e-mail, vérifié par Google) peuvent se connecter.

## Intégration Orfeo (étape 1 : récupération des dates)

1. **Relier la compagnie à Orfeo :** dans Orfeo, créez une clé d'API (Paramètres → API & webhooks). Collez-la dans
   **Paramètres** de la compagnie (droit « Paramètres de la compagnie »). La clé est vérifiée auprès d'Orfeo, stockée
   chiffrée (clé dérivée d'`APP_SECRET`) et jamais réaffichée.
2. **Récupérer les dates :** dans **Importer des devis → Depuis Orfeo**, choisissez une période. Jusqu'à 40 dates sont
   présentées à la **même relecture que les classeurs Excel** : corrections, rapprochement avec l'annuaire et les listes,
   dates déjà présentes. Rien n'est enregistré avant validation.

| Orfeo | Application |
|---|---|
| spectacle, projet (titre, dates, statut, annulation) | spectacle, date (confirmée, option ou annulée) |
| lieu du projet, contacts liés | lieu, adresse, ville, contact d'accueil |
| participants confirmés ou à confirmer + fiche (nom, ville, téléphone, e-mail) | équipe de la date, annuaire complété sans écraser |
| planning : représentations, répétitions, technique, repas, autres | planning sur place (intitulés rapprochés des listes) |
| planning : hébergements (lieu, personnes) | hébergements et affectation de l'équipe |
| notes de planning et de régie | rubriques de la feuille de route |

- **Lien avec Orfeo :** chaque date, personne et spectacle garde son identifiant Orfeo. Une date déjà importée est
  reconnue (même si son lieu a changé dans Orfeo) et proposée décochée. Une personne renommée dans Orfeo est retrouvée.
- **Pas repris :**
  - les voyages Orfeo : ils n'ont ni gares ni billets, et sont signalés pour être saisis dans l'onglet Voyages ;
  - les postes non pourvus (N.N.) ;
  - le détail des repas et nuitées jour par jour : la présence est pré-remplie d'après la période de la date.
- **Accès à l'API :** lecture seule, au plus 10 requêtes par seconde (limite d'Orfeo). Une période chargée peut prendre jusqu'à une minute.

## Listes paramétrées (fonctions, intitulés de planning)

**Administration → Listes** (droit global « Gérer les listes ») : les fonctions des personnes et les intitulés du planning
sur place proposés dans les listes déroulantes (annuaire, équipe d'une date, planning). Chaque ligne de planning a en plus une
**précision** libre (« #1 scolaire », un lieu…), affichée dans la feuille de route.

- Retirer une entrée ne modifie pas les fiches existantes : la valeur y reste, marquée « hors liste ».
- Au premier démarrage, les listes sont pré-remplies avec des valeurs courantes et les fonctions déjà saisies.
- **Import des devis** : les valeurs des fichiers sont rapprochées des listes, sans tenir compte de la casse, des accents ni
  des tirets. Un intitulé qui commence par une entrée est découpé : « Représentation #1 - Place Kléber » donne
  Représentation + « #1 - Place Kléber ». Les autres valeurs sont présentées à la relecture. On peut les rattacher à une
  entrée (une suggestion est proposée) ou les ajouter à la liste ; l'ajout demande le droit « Gérer les listes ».

## Utilisateurs, groupes et droits

- **Droits** : une liste fixe d'actions. Sur les compagnies : consulter, organiser les dates, gérer les voyages,
  voir / gérer les coordonnées, voir / modifier les montants, gérer les spectacles, partager la feuille de route,
  paramètres. Administration : gérer les utilisateurs, les groupes, créer / supprimer des compagnies.
- **Groupes** : des droits + un périmètre (toutes les compagnies ou certaines) + des membres. Les droits de plusieurs
  groupes s'additionnent. Groupes fournis : « Équipe <compagnie> » (tous les droits sur une compagnie, repris des anciens
  accès), « Régie et logistique », « Consultation », « Administration ».
- **Super-administrateur** : tous les droits (le compte créé au premier démarrage).
- Le serveur vérifie chaque action et **ne transmet pas** ce qui n'est pas autorisé (montants, coordonnées).
- **Invitations** : un compte est créé sans mot de passe ; un lien personnel (7 jours, usage unique) permet à la personne
  de choisir le sien. **Mot de passe oublié** : l'administrateur génère un lien de réinitialisation (24 h).
  Aucun e-mail n'est envoyé par l'application : le lien est à transmettre (bouton « Envoyer par e-mail »).
- **Garde-fous** : seul un super-administrateur attribue des droits d'administration ou un accès à toutes les compagnies ;
  un gestionnaire de groupes ne donne accès qu'aux compagnies qu'il voit ; impossible de se désactiver ou de supprimer
  le dernier super-administrateur.
- **Journal** : connexions (réussies et échouées), invitations, changements de droits, suppressions.

## Mot de passe oublié et appareils de confiance

- **Mot de passe oublié :** sur la page de connexion, l'utilisateur saisit son adresse et reçoit un lien de
  réinitialisation, valable 1 heure et à usage unique. La réponse est la même que le compte existe ou non. Les demandes
  sont limitées à 5 par 15 minutes par adresse IP et à 3 par heure par adresse e-mail. Réinitialiser le mot de passe
  révoque tous les appareils de confiance.
  - **Configuration :** il faut un serveur d'envoi dans `.env` : `MAILER_DSN=smtp://utilisateur:motdepasse@smtp.exemple.fr:587`
    et `MAILER_FROM="Show Roadmap <no-reply@votre-domaine.fr>"`. Encodez les caractères spéciaux du mot de passe (`@` → `%40`).
  - **Sans configuration** (`null://null`), le lien est masqué et un administrateur génère les liens depuis « Utilisateurs ».
- **Appareils de confiance :** la case « Rester connecté sur cet appareil » garde la session ouverte 30 jours après la
  dernière utilisation.
  - **Sécurité du jeton :** il change à chaque reprise de session et n'est stocké qu'en empreinte. Un ancien jeton
    réutilisé plus tard est considéré comme volé : l'appareil est alors révoqué.
  - **Révocation :** chaque appareil est révocable dans « Mon compte ». Tous sont révoqués au changement de mot de passe
    et à la désactivation du compte.

## Mentions légales et RGPD

L'application fournit les pages publiques **Mentions légales** (`/mentions-legales`) et **Confidentialité**
(`/confidentialite`), liées depuis la page de connexion. Elles se complètent avec les variables `LEGAL_*` du `.env`
(éditeur, hébergeur, contact RGPD, adresse du code source).

- **Pas de déclaration à la CNIL** : depuis 2018, on tient un registre des traitements à la place. Un modèle de registre,
  la liste des obligations et un message type pour les personnes de l'annuaire sont dans
  [docs/conformite-rgpd.md](docs/conformite-rgpd.md).
- **Durées de conservation appliquées automatiquement** (commande `app:purge`, lancée chaque nuit par le conteneur) :
  journal d'audit 12 mois, liens périmés et appareils de confiance inutilisés 30 jours.
- **Régime alimentaire** : donnée potentiellement sensible, à ne renseigner qu'avec l'accord de la personne.

## Sécurité

- Mots de passe hachés par Symfony (`auto`) : 12 caractères minimum, robustesse au moins moyenne, sans l'adresse e-mail.
  Changer de mot de passe ferme les autres sessions. Les jetons des liens ne sont stockés qu'en empreinte SHA-256.
- Session par cookie `HttpOnly` / `SameSite=Lax` / `Secure` en HTTPS.
- Protection CSRF : toute écriture sur l'API exige l'en-tête `X-Requested-With`.
- Limitation des tentatives de connexion (8 par 5 minutes) et des demandes de lien de réinitialisation.
- Cloisonnement strict entre compagnies.
- Un compte désactivé perd sa session immédiatement.
- L'export Excel écrit les textes en chaînes explicites : une saisie commençant par « = » ne devient jamais une formule.

## Licence et contribution

Show Roadmap est distribué sous licence **GNU Affero General Public License v3 ou ultérieure** (voir [LICENSE](LICENSE)).
Les composants tiers et leurs licences sont listés dans [THIRD_PARTY_NOTICES.md](THIRD_PARTY_NOTICES.md).

- Contribuer : [CONTRIBUTING.md](CONTRIBUTING.md) (n'utilisez que des données fictives dans les tickets et les tests).
- Signaler une faille : [SECURITY.md](SECURITY.md).
- Les données de démonstration (`app:seed-demo`) et les jeux de test sont entièrement fictifs.

