#!/bin/sh -l

set -e

eval $(ssh-agent -s)

echo -e "StrictHostKeyChecking no" >> /etc/ssh/ssh_config
echo "$SSH_PRIVATE_KEY" | tr -d '\r' > /tmp/id_rsa
chmod 600 /tmp/id_rsa
ssh-add /tmp/id_rsa

# region $INPUT_VERBOSELEVEL
if [ -z "$INPUT_VERBOSELEVEL" ]
then
  INPUT_VERBOSELEVEL='v'
fi
#endregion

# region $INPUT_DEPLOYERFILELOCATION
if [ -z "$INPUT_DEPLOYERFILELOCATION" ]
then
  INPUT_DEPLOYERFILELOCATION='.deployment/deploy.php'
fi
#endregion

# download deployer from repository
curl -sS -H 'Authorization: token '"${GITHUB_TOKEN}"'' --location --request GET 'https://raw.githubusercontent.com/'"${GITHUB_REPOSITORY}"'/'"${GITHUB_SHA}"'/'"${INPUT_DEPLOYERFILELOCATION}"'' > deploy.php

# forces coloring
export ANSICON=1

printf "\033[32;1m%s \033[0m\033[34;1m%s \033[0m\033[90;1m%s\033[0m\n" "✓" "subject" "message"

deployer --version
deployer --file=./deploy.php -$INPUT_VERBOSELEVEL deploy
