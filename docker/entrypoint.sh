#!/bin/sh
set -e

php bin/console cache:clear --no-interaction

# MariaDB peut mettre quelques secondes à accepter les connexions.
tries=0
until php bin/console dbal:run-sql -q "SELECT 1" >/dev/null 2>&1; do
	tries=$((tries + 1))
	[ "$tries" -ge 30 ] && echo "Base de données injoignable" >&2 && exit 1
	sleep 2
done

php bin/console doctrine:migrations:migrate --no-interaction --allow-no-migration
php bin/console app:bootstrap-admin

# Tâches de fond : horaires SNCF (open data) chargés au premier démarrage puis rafraîchis chaque nuit ;
# purge des données techniques périmées (journal d'audit > 12 mois, liens et appareils expirés).
(
	while true; do
		php bin/console app:gtfs-import --if-older-than=20 --no-interaction || true
		php bin/console app:purge --no-interaction || true
		sleep 3600
	done
) &

exec "$@"
