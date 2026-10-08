FROM node:22.16.0-bookworm-slim AS console
WORKDIR /src
COPY frontend/console /src/frontend/console
RUN npm install -g npm@10.9.2 && cd frontend/console && npm ci && npm test && npm run build && npm run stage
FROM php:8.2-cli-bookworm
ENV DEBIAN_FRONTEND=noninteractive
RUN apt-get update && apt-get install -y --no-install-recommends age mariadb-server mariadb-client libonig-dev libcurl4-openssl-dev ca-certificates openssl \
 && docker-php-ext-install -j2 pdo_mysql mbstring curl \
 && rm -rf /var/lib/apt/lists/*
WORKDIR /srv/allfather
COPY . /srv/allfather/
COPY --from=console /src/public_html/assets/operator-console/ /srv/allfather/public_html/assets/operator-console/
ENTRYPOINT ["bash","ops/backup-rehearsal-railway-once.sh"]
