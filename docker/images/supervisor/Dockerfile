FROM php:8.2-fpm

# system deps + supervisor
RUN apt-get update && apt-get install -y --no-install-recommends \
    supervisor \
    libpq-dev \
    libpng-dev \
    libzip-dev \
    libxml2-dev \
    libc-client2007e-dev \
    libkrb5-dev \
    snmp \
    libsnmp-dev \
    curl \
    unzip \
 && rm -rf /var/lib/apt/lists/*

# PHP extensions from source (official way for php:* images)
RUN docker-php-ext-configure imap --with-kerberos --with-imap-ssl \
 && docker-php-ext-install -j$(nproc) \
    pdo_pgsql \
    gd \
    curl \
    imap \
    xml \
    zip \
    mbstring

# Redis via PECL
RUN pecl install redis \
 && docker-php-ext-enable redis

# snmp extension (needs libsnmp-dev installed above)
RUN docker-php-ext-install snmp

# supervisor config
COPY files/supervisord.conf /etc/supervisor/conf.d/supervisord.conf

EXPOSE 8008

CMD ["/usr/bin/supervisord","-n"]
