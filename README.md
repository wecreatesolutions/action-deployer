# Deployer action

This action handles deployment via deployer

```
  - name: Deploy
    uses: wearebuilders/action-deployer@master
    env:
      SSH_PRIVATE_KEY: ${{ secrets.PRIVATE_KEY }}
      GITHUB_TOKEN: ${{ secrets.GITHUB_TOKEN }}
      SLACK_WEBHOOK_TOKEN: ${{ secrets.SLACK_WEBHOOK_TOKEN }}
```


New way to configure git hub action. Direct docker image

```
name: Auto deployment production/staging

on:
  push:
    branches:
      - 'master'
      - 'staging'
      - 'production'

jobs:
  build:
    name: Deployment
    runs-on: self-hosted

    container:
      image: docker://wimwinterberg/action-deployer:1.0.1-7.3-cli-alpine

    steps:
      - name: Deploy
        run: deploy.sh
        env:
          SSH_PRIVATE_KEY: ${{ secrets.DEPLOYMENT_PRIVATE_KEY }}
          GITHUB_TOKEN: ${{ secrets.GITHUB_TOKEN }}
          SLACK_WEBHOOK_TOKEN: ${{ secrets.SLACK_WEBHOOK_TOKEN }}
          COMPOSER_AUTH_JSON: ${{ secrets.COMPOSER_AUTH_JSON }}

```
