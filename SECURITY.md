# Politique de sécurité

## Signaler une vulnérabilité

**N'ouvrez pas de ticket public** pour une faille de sécurité. Utilisez le signalement privé de GitHub
(onglet *Security* → *Report a vulnerability*) ou écrivez au mainteneur (adresse dans le profil GitHub du dépôt).

Indiquez : la version ou le commit, les étapes pour reproduire, l'impact estimé. Vous recevrez un accusé de réception
sous 7 jours ; la correction est publiée dès qu'elle est prête, avec mention de votre signalement si vous le souhaitez.

## Versions prises en charge

Seule la dernière version de la branche `main` reçoit des correctifs.

## Si vous exploitez une instance

Gardez l'image et ses dépendances à jour (`git pull` puis `docker compose up -d --build`), sauvegardez la base, et lisez
[docs/conformite-rgpd.md](docs/conformite-rgpd.md) (sécurité, violations de données, obligations RGPD).
