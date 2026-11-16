# FrankenPHP handles requests in parallel threads, so the copies of a duplicate callback
# really hit the ledger at the same time.
FROM dunglas/frankenphp:1-php8.4
RUN install-php-extensions pdo_pgsql zip
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
ENV FRANKENPHP_CONFIG="num_threads 16" COMPOSER_ROOT_VERSION=dev-master
