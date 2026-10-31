# action-deployer

Docker image to deploy WCS projects with [Deployer 8](https://deployer.org/docs/8.x) from a GitHub Actions job.

- Image: `ghcr.io/wecreatesolutions/action-deployer:<version>-8.4.27-cli-alpine` (PHP 8.4.27, Deployer 8.0.5), see `deploy.env` and `CHANGELOG.md`
- Recipes: `recipes/waf.php` (SEF/WAF projects) and `recipes/symfony.php` (Symfony projects), both on the shared `recipes/wcs.php`
- The Deployer 6 recipes (`sef.php`, `wab-symfony.php`, ...) only exist in the 1.x images (`wimwinterberg/action-deployer:1.1.20-7.4-cli-alpine`)

## Workflow

```yaml
name: Auto deployment production/staging

on:
  push:
    branches:
      - 'master'
      - 'staging'

jobs:
  build:
    name: Deployment
    runs-on: self-hosted

    container:
      image: ghcr.io/wecreatesolutions/action-deployer:2.0.5-8.4.27-cli-alpine

    steps:
      - name: Deploy
        run: deploy.sh
        env:
          SSH_PRIVATE_KEY: ${{ secrets.DEPLOYMENT_PRIVATE_KEY }}
          GITHUB_TOKEN: ${{ secrets.GITHUB_TOKEN }}
          SLACK_WEBHOOK_TOKEN: ${{ secrets.SLACK_WEBHOOK_TOKEN }}
          COMPOSER_AUTH_JSON: ${{ secrets.COMPOSER_AUTH_JSON }}
          NODE_AUTH_TOKEN: ${{ secrets.NODE_AUTH_TOKEN }} # only when .npmrc reads ${NODE_AUTH_TOKEN}
#          INPUT_VERBOSELEVEL: vv                          # default v
#          INPUT_DEPLOYERFILELOCATION: .deployment/deploy.php
```

`deploy.sh` downloads the `deploy.php` of the pushed commit and runs `deployer deploy`. The branch selects the hosts:
`master` and `production` deploy to the hosts labelled `stage => production`, every other branch to `stage => staging`.

The package is internal on ghcr.io: workflows of the repositories in the organisation can pull it.

## deploy.php

```php
<?php

namespace Deployer;

require 'recipes/waf.php'; // or recipes/symfony.php

set('application', 'Keyplan Horti');
set('shared_files', ['.env.local', 'keyplan-tuinbouw-android-firebase.json']);

host('keyplan-horti.test.wecreatesolutions.nl')
    ->setLabels(['stage' => 'staging'])
    ->set('deploy_path', '/projects/keyplan-horti');

host('keyplan-platform.prod.wecreatesolutions.nl')
    ->setLabels(['stage' => 'production'])
    ->set('deploy_path', '/projects/keyplan-horti');

// a batch host runs the workers: no migration, no frontend build
host('keyplan-batch.prod.wecreatesolutions.nl')
    ->setLabels(['stage' => 'production', 'role' => 'batch'])
    ->set('deploy_path', '/projects/keyplan-horti');
```

The app environment (`APP_ENV`) comes from the shared `.env.local` on the server, not from the `deploy.php`.

## What the recipes do

- Download the tarball of the pushed commit (`REVISION` in the release), copy `vendor` of the previous release.
- `COMPOSER_ALLOW_SUPERUSER=1` for composer on the server (root), composer auth from `COMPOSER_AUTH_JSON`
  (`http-basic`).
- Writable dirs via acl for `www-data` (default acl, so files created later inherit it); a host without
  `setfacl` stops the deploy (`apt install acl`).
- Frontend build with nvm (`nvm install` + `nvm use`), `NODE_AUTH_TOKEN` only for `npm install`.
- waf: `assets:install`, `waf:migration:migrate`, cache clear/warmup, optional messenger.
  symfony: Deployer's `recipe/symfony.php` with `database:migrate` before the symlink.
- Graceful `systemctl reload` of php-fpm after the symlink (clears the opcache), when it runs on the host.
- Keeps 5 releases; the first Deployer 8 deploy continues the release numbering of Deployer 6. Releases made by
  Deployer 6 are not cleaned up by Deployer 8: remove them once by hand.
- Production: GitHub release of the app version with the changelog of that version, tagged on the deployed commit.
- Slack notifications when `SLACK_WEBHOOK_TOKEN` is set.

The options per recipe are documented at the top of `recipes/wcs.php`, `recipes/waf.php` and `recipes/symfony.php`.

## Build and publish

```bash
make build            # build ghcr.io/wecreatesolutions/action-deployer:$(VERSION)
make test             # php and deployer version of the image
make release          # build without cache, test and push the version tag (docker login ghcr.io first)
make release-latest   # release + push latest
```

Bump `VERSION` in `deploy.env` and add a `CHANGELOG.md` entry for every release.
