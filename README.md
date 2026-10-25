# Show Roadmap

Application web de gestion de tournée pour compagnies de spectacle : elle remplace les classeurs Excel
« Devis_<Spectacle>_<Ville>.xlsx » (devis, rooming-list, défraiements, suivi des frais de voyage, feuille de route, coordonnées).

## Modèle

- **Compagnie** → **Spectacles** (plusieurs spectacles possibles, y compris sur les mêmes dates) et **annuaire** de personnes.
- **Date de tournée** (un spectacle × un lieu × une période) : équipe, voyages, nuitées/repas/petits-déjeuners, hébergements, devis, feuille de route.
- Une personne peut être sur plusieurs dates ; si deux dates se chevauchent, le planning et l'équipe affichent un avertissement.
- Documents imprimables (PDF via l'impression du navigateur) : feuille de route, rooming-list, défraiements, suivi des frais de voyage, coordonnées, devis. Export Excel d'une date.
- Multi-compagnies : chaque utilisateur ne voit que les compagnies que l'administrateur lui a attribuées.

## Lancer en local

```bash
python -m venv .venv && . .venv/bin/activate
pip install -r requirements-dev.txt
export SECRET_KEY=dev COOKIE_SECURE=false DATABASE_URL=sqlite:///./data/app.db \
       ADMIN_EMAIL=admin@example.com ADMIN_PASSWORD=un-mot-de-passe-long
uvicorn app.main:app --reload
python -m app.seed   # données de démonstration fictives (optionnel)
pytest
```

## Déploiement Docker + Traefik

```bash
cp .env.example .env     # renseigner APP_HOST, SECRET_KEY, ADMIN_*, nom du réseau Traefik
docker compose up -d --build
```

Le service rejoint le réseau Docker externe de Traefik (`TRAEFIK_NETWORK`) et est exposé via les labels
du `docker-compose.yml` (routeur HTTPS + resolver). Adaptez `TRAEFIK_ENTRYPOINT` / `TRAEFIK_CERTRESOLVER`
à votre configuration. Les données (SQLite) sont dans le volume `roadmap-data` : sauvegardez ce volume.
Le compte administrateur est créé au premier démarrage si la base est vide ; créez ensuite les autres comptes
dans **Utilisateurs**.

## Recherche d'horaires SNCF

Renseignez `SNCF_API_TOKEN` (jeton gratuit sur https://numerique.sncf.com/startup/api/). Dans l'onglet **Voyages**,
« Rechercher un train » propose des itinéraires (API SNCF/Navitia) qui pré-remplissent départ, arrivée, heures et mode.
Les prix et références de billet restent à saisir. Le bouton « Ouvrir SNCF Connect » ouvre `SNCF_CONNECT_URL`
(placeholders `{origin}`, `{destination}`, `{date}` ; par défaut la page d'accueil, car je n'ai pas pu vérifier
un lien de recherche pré-rempli).

## Sécurité

Mots de passe hachés (scrypt), session par cookie signé `HttpOnly` / `SameSite=Lax` / `Secure`, jeton CSRF sur
toutes les requêtes d'écriture, limitation des tentatives de connexion (en mémoire, par IP + e-mail),
cloisonnement strict entre compagnies. Lancer avec un seul worker (limitation de connexion en mémoire, SQLite).

## Limites connues de cette V1

- Pas d'import des anciens fichiers Excel (saisie manuelle ou duplication d'une date existante).
- Montants en `float` arrondis à 2 décimales ; schéma créé au démarrage (pas encore de migrations Alembic).
- Pas de pièces jointes (billets PDF), pas d'e-mails, pas de réinitialisation de mot de passe par l'utilisateur (l'admin le fait).
