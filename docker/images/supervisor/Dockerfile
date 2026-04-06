FROM php:8.2-fpm-bullseye

RUN set -eux; \
    apt-get update; \
    apt-get install -y --no-install-recommends ca-certificates curl gnupg; \
    curl -fsSL https://www.postgresql.org/media/keys/ACCC4CF8.asc \
        | gpg --dearmor -o /usr/share/keyrings/postgresql.gpg; \
    echo "deb [signed-by=/usr/share/keyrings/postgresql.gpg] http://apt.postgresql.org/pub/repos/apt bullseye-pgdg main" \
        > /etc/apt/sources.list.d/pgdg.list

RUN apt-get update && apt-get install -y --no-install-recommends \
    supervisor \
    unzip \
    postgresql-client-14 \
    libpq-dev \
    libpng-dev \
    libzip-dev \
    openssl \
    libssl-dev \
    ffmpeg \
    libxml2-dev \
    libonig-dev \
    libkrb5-dev \
    libc-client2007e-dev \
    snmp libsnmp-dev \
 && rm -rf /var/lib/apt/lists/*

RUN docker-php-ext-install opcache
RUN docker-php-ext-configure imap --with-kerberos --with-imap-ssl \
 && docker-php-ext-install -j"$(nproc)" \
    pdo_pgsql \
    gd \
    imap \
    xml \
    zip \
    mbstring

RUN pecl install redis && docker-php-ext-enable redis
RUN docker-php-ext-install snmp

COPY files/supervisord.conf /etc/supervisor/conf.d/supervisord.conf
EXPOSE 8008
CMD ["/usr/bin/supervisord","-n"]
