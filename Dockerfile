FROM php:8.2-cli-bookworm
RUN apt-get update && apt-get install -y --no-install-recommends libonig-dev libcurl4-openssl-dev ca-certificates openssl && docker-php-ext-install -j2 pdo_mysql mbstring curl && rm -rf /var/lib/apt/lists/*
WORKDIR /srv/allfather
COPY . /srv/allfather/
ENTRYPOINT ["bash","ops/staging-acceptance-railway-once.sh"]
