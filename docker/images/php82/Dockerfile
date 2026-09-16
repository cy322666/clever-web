FROM php:8.2-fpm-alpine

ARG APK_REPOSITORY_MIRROR=https://dl-cdn.alpinelinux.org/alpine

WORKDIR /var/www/html/

# PHP: Copy configuration files & remove dist files
#RUN mkdir /phpIni
#COPY files/bin /usr/local/bin/с≠
COPY files/php/php.ini /usr/local/etc/php/conf.d/php.ini
COPY files/php/zz-clever-fpm.conf /usr/local/etc/php-fpm.d/zz-clever-fpm.conf
#COPY files/php/ext /phpIni

# SYS: Install required packages
RUN sed -i "s|https\?://dl-cdn.alpinelinux.org/alpine|${APK_REPOSITORY_MIRROR%/}|g" /etc/apk/repositories && \
    printf '\ntimeout 30\n' >> /etc/apk/config && \
    apk --no-cache upgrade && \
    apk --no-cache add bash git sudo openssh ffmpeg libxml2-dev postgresql-dev oniguruma-dev autoconf g++ make npm freetype-dev libjpeg-turbo-dev libpng-dev libzip-dev

RUN apk add --no-cache postgresql-client libpq-dev

# COMPOSER: install binary and prestissimo
RUN curl -sS https://getcomposer.org/installer | php -- --install-dir=/usr/bin --filename=composer

# PHP: Install php extensions
RUN pecl channel-update pecl.php.net
RUN pecl install pcov redis

#RUN docker-php-ext-configure gd --with-freetype --with-jpeg

#gd sockets iconv
RUN docker-php-ext-install mbstring xml pcntl zip pdo pdo_pgsql bcmath soap

#sockets gd iconv
RUN docker-php-ext-enable mbstring xml zip pcov redis pcntl bcmath pdo pdo_pgsql soap

RUN docker-php-ext-install exif

RUN docker-php-ext-install -j "$(nproc)" gd

RUN docker-php-ext-install intl opcache \
    && pecl install apcu \
    && docker-php-ext-enable apcu \
    && docker-php-ext-configure zip \
    && docker-php-ext-install zip

# fix work iconv library with alphine
RUN apk add --no-cache --repository "${APK_REPOSITORY_MIRROR%/}/edge/community/" --allow-untrusted gnu-libiconv
ENV LD_PRELOAD /usr/lib/preloadable_libiconv.so php
ENV NODE_PATH "/home/www-data/.npm-global/lib/node_modules"

RUN apk add --no-cache nodejs npm && \
    mkdir -p "/home/www-data/.npm-global/" && \
    npm config set prefix "/home/www-data/.npm-global/" && \
    npm install -g chokidar

RUN docker-php-ext-install gd mysqli pdo pdo_mysql -j5 gd mbstring mysqli pdo pdo_mysql shmop

# Match Debian supervisor workers on the shared Laravel runtime directories.
RUN set -eux; \
    awk -F: '$3 == 33 && $1 != "www-data" { print "UID/GID 33 is already occupied in " FILENAME > "/dev/stderr"; exit 1 }' /etc/passwd /etc/group; \
    deluser www-data; \
    if grep -q '^www-data:' /etc/group; then delgroup www-data; fi; \
    addgroup -S -g 33 www-data; \
    adduser -S -D -H -u 33 -G www-data -h /var/www -s /sbin/nologin www-data; \
    chown -R www-data:www-data /var/www/html/

#COPY ./start.sh /
#RUN chmod +x /start.sh

EXPOSE 9000

CMD ["php-fpm", "-F"]
