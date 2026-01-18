FROM php:8.2-fpm-bullseye

RUN apt-get update && apt-get install -y --no-install-recommends \
    supervisor \
    curl unzip \
    libpq-dev \
    libpng-dev \
    libzip-dev \
    libxml2-dev \
    libkrb5-dev \
    libc-client2007e-dev \
    snmp libsnmp-dev \
 && rm -rf /var/lib/apt/lists/*

# Иногда configure ищет headers по старому пути (c-client)
RUN ln -s /usr/include/x86_64-linux-gnu/c-client /usr/include/c-client || true

RUN docker-php-ext-configure imap --with-kerberos --with-imap-ssl \
 && docker-php-ext-install -j"$(nproc)" \
    pdo_pgsql \
    gd \
    imap \
    xml \
    zip \
    mbstring

RUN pecl install redis \
 && docker-php-ext-enable redis

RUN docker-php-ext-install snmp
