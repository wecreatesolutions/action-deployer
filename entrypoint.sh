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

deployer --version
deployer $CMD_ARGS
