#!/bin/sh -l

set -e

eval $(ssh-agent -s)

echo -e "StrictHostKeyChecking no" >> /etc/ssh/ssh_config
echo "$SSH_PRIVATE_KEY" | tr -d '\r' > /tmp/id_rsa
chmod 600 /tmp/id_rsa
ssh-add /tmp/id_rsa

# region $INPUT_VERBOSELEVEL
if [ -z "$INPUT_VERBOSELEVEL" ]; then
  INPUT_VERBOSELEVEL='v'
fi
#endregion

# region $INPUT_DEPLOYERFILELOCATION
if [ -z "$INPUT_DEPLOYERFILELOCATION" ]; then
  INPUT_DEPLOYERFILELOCATION='.deployment/deploy.php'
fi
#endregion

# change DNS
echo "nameserver 8.8.8.8" > /etc/resolv.conf

# download deployer from repository
curl -sS -H 'Authorization: token '"${GITHUB_TOKEN}"'' --location --request GET 'https://raw.githubusercontent.com/'"${GITHUB_REPOSITORY}"'/'"${GITHUB_SHA}"'/'"${INPUT_DEPLOYERFILELOCATION}"'' >deploy.php

# forces coloring - WIP need to find a better solution for this - https://github.com/symfony/console/blob/fb5419f837e0bd960696ebfd143f213dd5c8f744/Output/StreamOutput.php#L100
export TERM_PROGRAM=Hyper

deployer --version
deployer --file=./deploy.php -$INPUT_VERBOSELEVEL deploy
