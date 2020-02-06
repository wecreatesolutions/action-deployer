#!/bin/sh -l

set -e

if [ -z "$1" ]; then
    CMD_ARGS=""
else
    CMD_ARGS="$@"
fi

eval $(ssh-agent -s)

echo -e "StrictHostKeyChecking no" >> /etc/ssh/ssh_config
echo "$SSH_PRIVATE_KEY" | tr -d '\r' > /tmp/id_rsa
chmod 600 /tmp/id_rsa
ssh-add /tmp/id_rsa

printenv

echo $GITHUB_TOKEN;
echo $GITHUB_REPOSITORY;
echo $GITHUB_SHA;
echo $INPUT_DEPLOYER-FILE-LOCATION;

# download deployer from repository
curl -sS -H 'Authorization: token '"${GITHUB_TOKEN}"'' --location --request GET 'https://raw.githubusercontent.com/'"${GITHUB_REPOSITORY}"'/'"${GITHUB_SHA}"''"${INPUT_DEPLOYER-FILE-LOCATION}"'' > deploy.php

deployer --version
#deployer --file=./deploy.php -v deploy

cat ./deploy.php
