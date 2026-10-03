# Validez - Backend (Symfony 7.4 / API Platform 4.2) - imagine php-fpm.
# Imaginea contine doar runtime-ul; codul se monteaza din backend/ (vezi docker-compose*.yml).
FROM php:8.2-fpm

# pdo_pgsql: PostgreSQL (ltree, implementation-plan.md Step 1)
# gd: dompdf / endroid/qr-code ; intl: validator/serializer ; zip: composer
# pcntl: oprirea curata a comenzilor de lunga durata la SIGTERM (model ArtaNFT participare-jobs)
RUN apt-get update && apt-get install -y --no-install-recommends \
    libpq-dev libicu-dev libonig-dev libzip-dev libpng-dev libjpeg62-turbo-dev libfreetype6-dev libwebp-dev \
    zip unzip git curl \
    && docker-php-ext-configure gd --with-freetype --with-jpeg --with-webp \
    && docker-php-ext-install pdo_pgsql intl mbstring zip pcntl gd opcache \
    && rm -rf /var/lib/apt/lists/*

# Datele se stocheaza in UTC; php-fpm si CLI folosesc acelasi ini.
RUN echo "date.timezone=UTC" > /usr/local/etc/php/conf.d/zz-timezone.ini
# Doar FPM: CLI are nevoie de argv pentru console/composer.
RUN printf '[www]\nphp_admin_value[register_argc_argv] = off\n' > /usr/local/etc/php-fpm.d/zz-security.conf

# Composer
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

# User cu aceleasi UID/GID ca pe host (fisierele create in container raman ale lui petro)
ARG UID=1000
ARG GID=1000
RUN groupadd -g ${GID} devgroup \
    && useradd -m -u ${UID} -g ${GID} devuser

WORKDIR /var/www/validez-be

USER devuser
