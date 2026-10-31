# Deploys WCS projects with Deployer 8 from a GitHub Actions job: `container: image: ghcr.io/wecreatesolutions/action-deployer:<version>`
# with `run: deploy.sh` (see README.md)
FROM php:8.4.27-cli-alpine

# links the ghcr.io package to this repository
LABEL org.opencontainers.image.source=https://github.com/wecreatesolutions/action-deployer

# sha256 of deployer.phar as published with the GitHub release
ENV DEPLOYER_VERSION=8.0.5
ENV DEPLOYER_SHA256=0026dfa73626a8ccde06222fe5fccf03e6d4a19e765914bb87d7d2cc21f3e57d

# bash (shell of root, deploy.sh), ssh (deployer), curl (deploy.sh, recipes), git (deployer)
RUN apk add --no-cache \
    bash \
    openssh-client \
    curl \
    git

# change default shell to bash (needed for conveniently adding an ssh key)
RUN sed -i -e "s/bin\/ash/bin\/bash/" /etc/passwd

# include path /deployer: a project deploy.php can `require 'recipes/waf.php';`
COPY config/include_path.ini $PHP_INI_DIR/conf.d/

# hide deprecation notices (e.g. of older bundled packages) in the deploy log
COPY config/error_reporting.ini $PHP_INI_DIR/conf.d/

# installing deployer, verified against the published checksum
RUN curl -fsSL -o /usr/local/bin/deployer https://github.com/deployphp/deployer/releases/download/v$DEPLOYER_VERSION/deployer.phar \
    && echo "$DEPLOYER_SHA256  /usr/local/bin/deployer" | sha256sum -c - \
    && chmod +x /usr/local/bin/deployer

ADD recipes /deployer/recipes
ADD Utils /deployer/Utils

COPY deploy.sh /bin/deploy.sh
RUN chmod +x /bin/deploy.sh
