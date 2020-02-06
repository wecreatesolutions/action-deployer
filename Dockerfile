# used https://github.com/musps/action-deployer-php/ for inspiration

# Container image that runs your code
FROM php:7.3-cli-alpine

ENV DEPLOYER_VERSION=6.7.3
ENV DEPLOYER_RECIPES_VERSION=6.2.2

RUN apk update --no-cache \
    && apk add --no-cache \
    bash \
    openssh-client \
    rsync

# ohange default shell to bash (needed for conveniently adding an ssh key)
RUN sed -i -e "s/bin\/ash/bin\/bash/" /etc/passwd

# setting php include path
COPY config/include_path.ini $PHP_INI_DIR/conf.d/

RUN curl -L https://deployer.org/releases/v$DEPLOYER_VERSION/deployer.phar > /usr/local/bin/deployer \
    && chmod +x /usr/local/bin/deployer

RUN curl -sS --location --request GET 'https://api.github.com/repos/deployphp/recipes/tarball/'$DEPLOYER_RECIPES_VERSION > recipes.tar.gz \
    && tar -xvf recipes.tar.gz \
    && rm recipes.tar.gz \
    && mkdir ./deployer \
    && mv deployphp-recipes-*/recipe ./deployer/recipes \
    && rm -rf deployphp-recipes-*

ADD recipes /deployer/recipes

# Copies your code file from your action repository to the filesystem path `/` of the container
COPY entrypoint.sh /entrypoint.sh

RUN chmod +x /entrypoint.sh


# Code file to execute when the docker container starts up (`entrypoint.sh`)
ENTRYPOINT ["/entrypoint.sh"]
