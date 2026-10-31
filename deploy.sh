#!/bin/sh -l

set -e

eval $(ssh-agent -s)

# accept-new: the host key of a new server is stored, a changed host key of a known server stops the deploy
# (no: never checked, a man in the middle would not be noticed)
echo "StrictHostKeyChecking accept-new" >> /etc/ssh/ssh_config
echo "$SSH_PRIVATE_KEY" | tr -d '\r' > /tmp/id_rsa
chmod 600 /tmp/id_rsa
ssh-add /tmp/id_rsa

# region $INPUT_VERBOSELEVEL
# -v: deployer 8 prints every command and its output, folded per task in the GitHub log (vv / vvv for more)
if [ -z "$INPUT_VERBOSELEVEL" ]; then
  INPUT_VERBOSELEVEL='v'
fi
VERBOSE_ARG="-$INPUT_VERBOSELEVEL"
#endregion

# region $INPUT_DEPLOYERFILELOCATION
if [ -z "$INPUT_DEPLOYERFILELOCATION" ]; then
  INPUT_DEPLOYERFILELOCATION='.deployment/deploy.php'
fi
#endregion

# download the deploy.php of the pushed commit
curl -fsS -H 'Authorization: token '"${GITHUB_TOKEN}"'' --location 'https://raw.githubusercontent.com/'"${GITHUB_REPOSITORY}"'/'"${GITHUB_SHA}"'/'"${INPUT_DEPLOYERFILELOCATION}"'' -o deploy.php

deployer --version
# --ansi: the runner output is not a tty, without it symfony console drops the colors
deployer --file=./deploy.php --ansi $VERBOSE_ARG deploy
