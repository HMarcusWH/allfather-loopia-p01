# Disposable conformance probe ONLY. Not a deployable application image.
FROM node:22.16.0-bookworm-slim
RUN apt-get update && apt-get install -y --no-install-recommends \
    git rsync ca-certificates curl \
    php8.2-cli php8.2-mysql php8.2-curl php8.2-mbstring mariadb-client \
    && rm -rf /var/lib/apt/lists/*
RUN npm install -g npm@10.9.2
WORKDIR /srv/allfather
COPY . /srv/allfather/
ENTRYPOINT ["bash","ops/railway/qualify-disposable.sh"]
