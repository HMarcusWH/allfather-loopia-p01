FROM node:22.16.0-bookworm-slim
RUN apt-get update && apt-get install -y --no-install-recommends mariadb-server mariadb-client age ca-certificates openssl tar && rm -rf /var/lib/apt/lists/*
RUN mkdir -p /opt/backup-modules && cd /opt/backup-modules && npm install --no-audit --no-fund @aws-sdk/client-s3
WORKDIR /srv/allfather
COPY . /srv/allfather/
RUN cp ops/backup-upload.mjs /opt/backup-modules/backup-upload.mjs
ENTRYPOINT ["bash","ops/staging-retained-snapshot-once.sh"]
