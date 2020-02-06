# used https://github.com/musps/action-deployer-php/ for inspiration

# Container image that runs your code
FROM php:7.3-cli-alpine

RUN apk update --no-cache \
    && apk add --no-cache \
    bash \
    openssh-client \
    rsync

# Change default shell to bash (needed for conveniently adding an ssh key)
RUN sed -i -e "s/bin\/ash/bin\/bash/" /etc/passwd

# Override with custom opcache settings
COPY config/include.ini $PHP_INI_DIR/conf.d/

RUN curl -L https://deployer.org/releases/v6.5.0/deployer.phar > /usr/local/bin/deployer \
    && chmod +x /usr/local/bin/deployer

RUN curl -sS --location --request GET 'https://api.github.com/repos/deployphp/recipes/tarball/6.2.2' > recipes.tar.gz \
    && tar -xvf recipes.tar.gz \
    && rm recipes.tar.gz \
    && mv deployphp-recipes-*/recipe ./recipes \
    && rm -rf deployphp-recipes-*

ADD recipes /deployer/recipes

# Copies your code file from your action repository to the filesystem path `/` of the container
COPY entrypoint.sh /entrypoint.sh

#COPY test.php /test/test.php

RUN chmod +x /entrypoint.sh

# Code file to execute when the docker container starts up (`entrypoint.sh`)
ENTRYPOINT ["/entrypoint.sh"]
