FROM mcr.microsoft.com/playwright:v1.56.1-noble
ENV DEBIAN_FRONTEND=noninteractive
RUN apt-get update && apt-get install -y --no-install-recommends php8.3-cli php8.3-mysql php8.3-mbstring php8.3-curl openssl ca-certificates && rm -rf /var/lib/apt/lists/*
WORKDIR /opt/browser
RUN npm install --no-audit --no-fund playwright@1.56.1
WORKDIR /srv/allfather
COPY . /srv/allfather/
RUN cp ops/p09-visual-browser.mjs /opt/browser/p09-visual-browser.mjs
ENTRYPOINT ["bash","ops/staging-acceptance-railway-once.sh"]
