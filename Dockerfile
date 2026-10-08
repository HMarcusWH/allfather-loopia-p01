FROM node:22.16.0-bookworm-slim
ENV DEBIAN_FRONTEND=noninteractive
RUN apt-get update && apt-get install -y --no-install-recommends \
 git rsync ca-certificates curl openssl tar \
 php8.2-cli php8.2-mysql php8.2-curl php8.2-mbstring php8.2-xml \
 mariadb-server mariadb-client \
 && rm -rf /var/lib/apt/lists/*
RUN npm install -g npm@10.9.2
WORKDIR /srv/allfather
COPY . /srv/allfather/
RUN cp /srv/allfather/.offline-test/launch.sh /usr/local/bin/allfather-exact-qualify.sh \
 && cp /srv/allfather/.offline-test/tracked-tree-manifest.tsv /opt/allfather-tracked-manifest.tsv
ENTRYPOINT ["bash","/usr/local/bin/allfather-exact-qualify.sh"]
