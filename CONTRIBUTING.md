# Contribuer à Show Roadmap

Merci de votre intérêt ! Show Roadmap est un logiciel libre ([AGPL-3.0-or-later](LICENSE)) qui aide les compagnies de
spectacle vivant à préparer leurs tournées. Les retours de terrain sont aussi précieux que le code.

## Proposer une amélioration ou signaler un bug

Ouvrez un ticket en décrivant la situation vécue (« je prépare une tournée avec… et je ne peux pas… »). **N'y collez
jamais de données personnelles réelles** (noms, téléphones, e-mails, billets) : utilisez des exemples fictifs. Pour une
faille de sécurité, voir [SECURITY.md](SECURITY.md).

## Développer

Voir la section « Développement » du [README](README.md) (PHP 8.3, MariaDB, Node). Avant de proposer une modification :

```bash
cd backend && php bin/phpunit          # tests (base MariaDB de test, voir README)
cd frontend && npm run build           # compilation de l'interface
```

- Une modification = un sujet, avec ses tests. Les fonctions sensibles (droits, import, RGPD) doivent être testées.
- Suivez le style du code voisin (nommage, commentaires en français, peu de code mort).
- Les données de démonstration et les tests ne contiennent **que des données fictives**.
- L'interface est en français ; les textes utilisateur restent simples et sans jargon.

## Licence des contributions

En proposant une contribution, vous acceptez qu'elle soit distribuée sous la licence du projet (AGPL-3.0-or-later) et
vous certifiez en avoir le droit (principe du *Developer Certificate of Origin*, https://developercertificate.org).
Ajoutez de préférence une ligne `Signed-off-by: Prénom Nom <e-mail>` à vos commits (`git commit -s`).
