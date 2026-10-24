# Staging-only Railway image. This is not a qualified or activated release.
# Keep the repository root private; ONLY public_html may be served.
FROM node:22.16.0-bookworm-slim AS console
WORKDIR /src
COPY frontend/console /src/frontend/console
# The P09 console unit suite imports this pure shared contract from outside frontend/console.
# Copy only that offline helper into the Node build stage; no private runtime configuration.
COPY ops/acceptance/p09-fixture-contract.mjs /src/ops/acceptance/p09-fixture-contract.mjs
RUN npm install -g npm@10.9.2 \
    && cd /src/frontend/console \
    && test "$(node --version)" = "v22.16.0" \
    && test "$(npm --version)" = "10.9.2" \
    && npm ci \
    && npm test \
    && npm run build \
    && npm run stage

FROM php:8.5.12-apache-bookworm
RUN apt-get update \
    && apt-get install -y --no-install-recommends ca-certificates libonig-dev libcurl4-openssl-dev mariadb-client \
    && docker-php-ext-install -j"$(nproc)" pdo_mysql mbstring curl \
    && a2dismod -f mpm_event mpm_worker \
    && a2enmod mpm_prefork rewrite headers \
    && rm -rf /var/lib/apt/lists/*

WORKDIR /srv/allfather
COPY . /srv/allfather
# This repository has no hosted CI minutes: qualify the session-security patch on the actual PHP 8.5 runtime.
# The DB-backed end-to-end test remains a separate disposable MariaDB gate.
RUN php -l app/Auth/Authenticator.php \
    && php -l app/Http/Kernel.php \
    && php -l tests/security/session_csrf_recovery.php \
    && php -l tests/foundation/session_csrf_recovery_boundary.php \
    && php tests/run.php --layer=foundation --filter=session_csrf_recovery_boundary --strict \
    && php -l app/Product/P01/WatchlistRead.php \
    && php -l app/Product/P03/CustomerProfileRead.php \
    && php -l app/Beta/Plus46ReadUpgrade.php \
    && php -l app/Beta/BetaBundle.php \
    && php -l app/Substrate/M02/Permissions.php \
    && php -l app/Http/Controllers/ConsoleReadController.php \
    && php -l public_html/index.php \
    && php -l ops/activate-plus46-reads.php \
    && php -l tests/integration/plus46_customer_reads.php \
    && php -l tests/foundation/plus46_customer_reads_boundary.php \
    && php -l app/Provider/ModelPolicy.php \
    && php -l app/Provider/PreparedModelRequest.php \
    && php -l app/Provider/ModelExecutor.php \
    && php -l tests/unit/bounded_model.php \
    && php tests/run.php --layer=unit --filter=bounded_model --strict \
    && php tests/run.php --layer=foundation --filter=plus46_customer_reads_boundary --strict \
    && php tests/run.php --layer=foundation --filter=governed_browser_read_boundary --strict \
    && php tests/run.php --layer=foundation --filter=execution_authority_boundary --strict \
    && php tests/run.php --layer=foundation --filter=deployment_boundary --strict
COPY --from=console /src/public_html/assets/operator-console/ /srv/allfather/public_html/assets/operator-console/
COPY ops/railway/entrypoint.sh /usr/local/bin/allfather-railway-entrypoint
RUN chmod 0755 /usr/local/bin/allfather-railway-entrypoint \
    && mkdir -p /srv/allfather/storage \
    && chown www-data:www-data /srv/allfather/storage \
    && sed -ri 's!/var/www/html!/srv/allfather/public_html!g' /etc/apache2/sites-available/000-default.conf \
    && printf '%s\n' '<Directory /srv/allfather/public_html>' '    AllowOverride All' '    Require all granted' '    Options -Indexes -MultiViews' '</Directory>' > /etc/apache2/conf-available/allfather-railway.conf \
    && a2enconf allfather-railway \
    && apache2ctl -t

EXPOSE 80
ENTRYPOINT ["/usr/local/bin/allfather-railway-entrypoint"]
CMD ["apache2-foreground"]
