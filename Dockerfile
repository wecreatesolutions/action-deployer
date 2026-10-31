# used https://github.com/musps/action-deployer-php/ for inspiration

# Container image that runs your code
FROM php:8.4.27-cli-alpine

# links the ghcr.io package to this repository
LABEL org.opencontainers.image.source=https://github.com/wecreatesolutions/action-deployer

ENV DEPLOYER_VERSION=8.0.5

RUN apk update --no-cache \
    && apk add --no-cache \
    bash \
    openssh-client \
    rsync \
    wget \
    curl \
    git \
    zip \
    bind-tools

# ohange default shell to bash (needed for conveniently adding an ssh key)
RUN sed -i -e "s/bin\/ash/bin\/bash/" /etc/passwd

# setting php include path
COPY config/include_path.ini $PHP_INI_DIR/conf.d/

# hide deprecation notices (e.g. of older bundled packages) in the deploy log
COPY config/error_reporting.ini $PHP_INI_DIR/conf.d/

# installing deployer
RUN curl -fL https://github.com/deployphp/deployer/releases/download/v$DEPLOYER_VERSION/deployer.phar > /usr/local/bin/deployer \
    && chmod +x /usr/local/bin/deployer

## installing node and npm
#RUN apk add --update nodejs npm yarn

# installing composer
RUN curl -sS https://getcomposer.org/installer | php -- --install-dir=/usr/bin --filename=composer

ADD recipes /deployer/recipes
ADD Utils /Utils

COPY composer.json composer.json
COPY composer.lock composer.lock

RUN composer install --optimize-autoloader --no-progress

# Copies your code file from your action repository to the filesystem path `/` of the container
COPY entrypoint.sh /entrypoint.sh
COPY deploy.sh /bin/deploy.sh

RUN chmod +x /entrypoint.sh
RUN chmod +x /bin/deploy.sh

# Code file to execute when the docker container starts up (`entrypoint.sh`)
#ENTRYPOINT ["/entrypoint.sh"]
