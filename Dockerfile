# Staging-only Railway image. This is not a qualified or activated release.
# Keep the repository root private; ONLY public_html may be served.
FROM node:22.16.0-bookworm-slim AS console
WORKDIR /src
COPY frontend/console /src/frontend/console
RUN npm install -g npm@10.9.2 \
    && cd /src/frontend/console \
    && test "$(node --version)" = "v22.16.0" \
    && test "$(npm --version)" = "10.9.2" \
    && npm ci \
    && npm test \
    && npm run build \
    && npm run stage

FROM php:8.2-apache-bookworm
RUN apt-get update \
    && apt-get install -y --no-install-recommends ca-certificates libonig-dev libcurl4-openssl-dev mariadb-client \
    && docker-php-ext-install -j"$(nproc)" pdo_mysql mbstring curl \
    && a2dismod -f mpm_event mpm_worker \
    && a2enmod mpm_prefork rewrite headers \
    && rm -rf /var/lib/apt/lists/*

WORKDIR /srv/allfather
COPY . /srv/allfather
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
