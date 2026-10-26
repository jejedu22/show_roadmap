# 1) Front Vue
FROM node:22-alpine AS front
WORKDIR /front
COPY frontend/package.json frontend/package-lock.json ./
RUN npm ci
COPY frontend/ ./
RUN npm run build

# 2) Dépendances PHP (sans outils de dev)
FROM composer:2 AS vendor
WORKDIR /app
COPY backend/composer.json backend/composer.lock backend/symfony.lock ./
RUN composer install --no-dev --no-scripts --no-autoloader --prefer-dist --ignore-platform-reqs
COPY backend/ ./
RUN test -f .env || (echo "backend/.env manquant" >&2 && exit 1)
RUN composer dump-autoload --no-dev --classmap-authoritative \
 && rm -rf tests var .env.local .env.*.local public/dev-router.php phpunit.dist.xml

# 3) Image finale : FrankenPHP (Caddy + PHP) sert l'API Symfony et le front
FROM dunglas/frankenphp:1-php8.3
RUN install-php-extensions pdo_mysql intl zip gd opcache
COPY docker/php.ini $PHP_INI_DIR/conf.d/zz-app.ini
COPY docker/Caddyfile /etc/app/Caddyfile
COPY docker/entrypoint.sh /usr/local/bin/app-entrypoint

WORKDIR /app
COPY --from=vendor /app ./
COPY --from=front /front/dist/ ./public/

ENV APP_ENV=prod APP_DEBUG=0
RUN chmod +x /usr/local/bin/app-entrypoint \
 && mkdir -p var && chown -R www-data:www-data var /data/caddy /config/caddy
USER www-data

EXPOSE 8080
HEALTHCHECK --interval=30s --timeout=3s --start-period=30s CMD php -r "exit(@file_get_contents('http://127.0.0.1:8080/healthz') === 'ok' ? 0 : 1);"
ENTRYPOINT ["app-entrypoint"]
CMD ["frankenphp", "run", "--config", "/etc/app/Caddyfile"]
