FROM php:8.3-cli-bookworm
RUN apt-get update -qq && apt-get install -y -qq ca-certificates curl gnupg git libpng-dev >/dev/null \
 && curl -fsSL https://deb.nodesource.com/setup_22.x | bash - >/dev/null 2>&1 && apt-get install -y -qq nodejs >/dev/null \
 && docker-php-ext-install pdo_mysql gd >/dev/null
RUN npx -y playwright@1.48.0 install --with-deps chromium >/dev/null
