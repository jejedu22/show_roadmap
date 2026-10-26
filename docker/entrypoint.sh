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

exec "$@"
