# used https://github.com/musps/action-deployer-php/ for inspiration

# Container image that runs your code
FROM php:7.3-cli-alpine

ENV DEPLOYER_VERSION=6.7.3

RUN apk update --no-cache \
    && apk add --no-cache \
    bash \
    openssh-client \
    rsync \
    wget \
    curl \
    git \
    zip

# ohange default shell to bash (needed for conveniently adding an ssh key)
RUN sed -i -e "s/bin\/ash/bin\/bash/" /etc/passwd

# setting php include path
COPY config/include_path.ini $PHP_INI_DIR/conf.d/

# installing deployer
RUN curl -L https://deployer.org/releases/v$DEPLOYER_VERSION/deployer.phar > /usr/local/bin/deployer \
    && chmod +x /usr/local/bin/deployer

# installing composer
RUN curl -sS https://getcomposer.org/installer | php -- --install-dir=/usr/bin --filename=composer

ADD recipes /deployer/recipes
ADD Utils /Utils

COPY composer.json composer.json
COPY composer.lock composer.lock

RUN composer install --optimize-autoloader --no-progress

# Copies your code file from your action repository to the filesystem path `/` of the container
COPY entrypoint.sh /entrypoint.sh

RUN chmod +x /entrypoint.sh

# Code file to execute when the docker container starts up (`entrypoint.sh`)
ENTRYPOINT ["/entrypoint.sh"]
