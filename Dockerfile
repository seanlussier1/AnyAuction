# syntax=docker/dockerfile:1
#
# Production image for AnyAuction. Apache + mod_php (one image, mod_rewrite
# in front of public/index.php). Composer install is baked at build time.
#
# Local build:   docker build -t anyauction:latest .
# ECR push:      see the README / deployment notes.
#
# Runtime config — pass these via `docker run -e ...`:
#   APP_ENV, DISPLAY_ERROR_DETAILS,
#   DB_HOST, DB_PORT, DB_NAME, DB_USER, DB_PASS,
#   STRIPE_PUBLISHABLE_KEY, STRIPE_SECRET_KEY, APP_BASE_URL

FROM php:8.2-apache

# PHP extensions + Apache rewrite (Slim's front-controller routes through it).
RUN apt-get update \
    && apt-get install -y --no-install-recommends \
        git \
        unzip \
        libzip-dev \
        zlib1g-dev \
    && docker-php-ext-install -j"$(nproc)" pdo pdo_mysql \
    && a2enmod rewrite \
    && rm -rf /var/lib/apt/lists/*

# Point Apache at /var/www/html/public and allow .htaccess overrides so the
# Slim rewrite rule takes effect.
ENV APACHE_DOCUMENT_ROOT=/var/www/html/public
RUN sed -ri -e 's!/var/www/html!${APACHE_DOCUMENT_ROOT}!g' /etc/apache2/sites-available/*.conf \
    && sed -ri -e 's!/var/www/!${APACHE_DOCUMENT_ROOT}/!g' /etc/apache2/apache2.conf /etc/apache2/conf-available/*.conf \
    && printf '<Directory %s/>\n    AllowOverride All\n    Require all granted\n</Directory>\n' "${APACHE_DOCUMENT_ROOT}" \
        > /etc/apache2/conf-available/anyauction.conf \
    && a2enconf anyauction

# Composer (pinned via the official image tag).
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /var/www/html

# Pull deps in a separate layer for caching. composer.lock is gitignored
# in this project, so we COPY just composer.json and let Composer resolve.
# (If you decide to commit composer.lock, change to:
#   COPY composer.json composer.lock ./
#   RUN composer install --no-dev …
# for fully reproducible builds.)
COPY composer.json ./
RUN composer install --no-dev --no-interaction --no-progress --optimize-autoloader --no-scripts

# App code (the .dockerignore keeps secrets, vendor, and dev junk out).
COPY . .

# Bake the git SHA into the image so the running app can serve its own
# version on /api/heartbeat. The CI workflow passes GIT_SHA=${{ github.sha }}
# at build time; defaults to "dev" for local builds.
ARG GIT_SHA=dev
RUN printf '%s' "$GIT_SHA" > /var/www/html/.version

# Uploads dir must exist and be writable by the web user — the dev path
# creates it lazily but the prod image bakes it in.
RUN mkdir -p public/assets/uploads \
    && chown -R www-data:www-data /var/www/html

EXPOSE 80
# php:8.2-apache's default CMD already runs apache2-foreground.
