FROM php:8.2-cli-bookworm
RUN docker-php-ext-install -j2 pdo_mysql
WORKDIR /srv/allfather
COPY . /srv/allfather/
ENTRYPOINT ["bash","ops/staging-railway-once.sh"]
