#!/bin/sh -l

set -e

eval $(ssh-agent -s)

#echo -e "StrictHostKeyChecking no" >> /etc/ssh/ssh_config
#echo "$SSH_PRIVATE_KEY" | tr -d '\r' > /tmp/id_rsa
#chmod 600 /tmp/id_rsa
#ssh-add /tmp/id_rsa
#
#deployer --version
#deployer deploy
