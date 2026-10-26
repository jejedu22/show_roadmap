# Show Roadmap

Application web de gestion de tournée pour compagnies de spectacle. Elle remplace les classeurs Excel
« Devis_<Spectacle>_<Ville>.xlsx » : devis, rooming-list, défraiements, suivi des frais de voyage, feuille de route, coordonnées.

- **backend/** : API JSON Symfony 7.4 (PHP 8.3), Doctrine ORM, MariaDB.
- **frontend/** : Vue 3 + Vite + Vue Router.
- **Docker** : une image FrankenPHP qui sert l'API et le front compilé, plus un conteneur MariaDB, derrière Traefik.

## Fonctionnalités

- **Compagnie** → **spectacles** (plusieurs possibles, y compris sur les mêmes dates) et **annuaire** de personnes.
- **Date de tournée** (un spectacle × un lieu × une période) : équipe, voyages, nuitées/repas/petits-déjeuners, hébergements, devis, feuille de route.
- Une personne engagée sur deux dates qui se chevauchent est signalée dans le planning et sur la fiche équipe.
- Documents imprimables (PDF via le navigateur) et export Excel d'une date.
- Multi-compagnies : chaque utilisateur ne voit que les compagnies que l'administrateur lui a attribuées.
- Connexion par mot de passe ou Google (comptes créés par un administrateur uniquement).
- Recherche d'horaires SNCF dans l'onglet Voyages (avec un jeton API).

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

## Recherche d'horaires SNCF

Renseignez `SNCF_API_TOKEN` (jeton gratuit sur https://numerique.sncf.com/startup/api/token-developpeur/).
« Rechercher un train » pré-remplit départ, arrivée, heures et mode ; prix et référence restent à saisir.
Le bouton « Ouvrir SNCF Connect » ouvre `SNCF_CONNECT_URL` (placeholders `{origin}`, `{destination}`, `{date}`).

## Connexion avec Google

1. Google Cloud Console → *APIs et services* → *Identifiants* → *ID client OAuth* de type **Application Web**.
2. URI de redirection autorisée : `https://<APP_HOST>/api/auth/google/callback`.
3. Renseigner `GOOGLE_CLIENT_ID` et `GOOGLE_CLIENT_SECRET` dans `.env`.

Seuls les comptes déjà créés par un administrateur (même e-mail, vérifié par Google) peuvent se connecter.

## Sécurité

- Mots de passe hachés par Symfony (`auto`), session par cookie `HttpOnly` / `SameSite=Lax` / `Secure` en HTTPS.
- Protection CSRF : toute écriture sur l'API exige l'en-tête `X-Requested-With`.
- Limitation des tentatives de connexion (8 par 5 minutes).
- Cloisonnement strict entre compagnies.
- Un compte désactivé perd sa session immédiatement.
- L'export Excel écrit les textes en chaînes explicites : une saisie commençant par « = » ne devient jamais une formule.
