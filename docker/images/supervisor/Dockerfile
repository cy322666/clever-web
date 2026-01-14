FROM ubuntu:20.04

RUN apt-get update && apt-get install supervisor -y

RUN apt install lsb-release ca-certificates apt-transport-https software-properties-common -y
RUN add-apt-repository ppa:ondrej/php

RUN apt install php8.2 -y
RUN apt install php8.2-pgsql -y \
    php8.2-gd \
    php8.2-fpm \
    php8.2-curl \
    php8.2-common \
    php8.2-imap \
    php8.2-redis \
    php8.2-snmp \
    php8.2-xml \
    php8.2-zip \
    php8.2-mbstring

COPY files/supervisord.conf /etc/supervisor/conf.d/supervisord.conf
#COPY files/worker.conf /etc/supervisor/conf.d/worker.conf

EXPOSE 8008

CMD ["/usr/bin/supervisord"]
