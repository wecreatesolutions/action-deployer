#!/bin/sh -l

set -e

eval $(ssh-agent -s)

echo -e "StrictHostKeyChecking no" >> /etc/ssh/ssh_config
echo "$SSH_PRIVATE_KEY" | tr -d '\r' > /tmp/id_rsa
chmod 600 /tmp/id_rsa
ssh-add /tmp/id_rsa

# region $INPUT_VERBOSELEVEL
# deployer 8 prints every command and its output from -v on: normal output by default, set INPUT_VERBOSELEVEL (v, vv,
# vvv) in the workflow env for more
VERBOSE_ARG=''
if [ -n "$INPUT_VERBOSELEVEL" ]; then
  VERBOSE_ARG="-$INPUT_VERBOSELEVEL"
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
# --ansi: the runner output is not a tty, without it symfony console drops the colors
deployer --file=./deploy.php --ansi $VERBOSE_ARG deploy
