# v2.0.5
* waf: option `new_ui` (default false) replaces assets_install / assets_install_options: projects with the new ui
  get `assets:install --install-new-ui --always-copy-assets` before the frontend build (copies for the production
  build, the composer scripts keep symlinks for local development); the other projects only get the assets:install
  of their composer scripts

# v2.0.4
* no `app_env` anymore: APP_ENV comes from the shared .env.local on the server (like Symfony), the deploy log shows
  it; writable_dirs `var/cache` + `var/log`, cache clear `var/cache/*`, `dotenv:dump` without env
* acl only: a host without setfacl stops the deploy instead of falling back to chmod 0777
* NODE_AUTH_TOKEN only for `npm install`, as a deployer secret (no longer in the env of every server command)
* GITHUB_TOKEN via stdin to curl (not in the process list of the server), curl -f, REVISION file in the release
* keep_releases 5 (deployer 8 default 10), release name continues after the highest of latest_release and the release
  dirs, no default deploy_path
* github release: tagged on the deployed commit, a missing app version or failing api call is a warning
* php_fpm_service from bin/php (e.g. php8.4-fpm), no second deploy:writable (the default acl covers new files)
* waf: options assets_install / assets_install_options (default --install-new-ui --always-copy-assets) and
  messenger_setup_transports, warnings when the messenger restart fails
* symfony: database:migrate skips batch hosts; docs: use `{{bin/console}}` without `{{bin/php}}`
* setup-permissions skips vendor and node_modules, no double output (writeln(run())), slack only with a non-empty
  SLACK_WEBHOOK_TOKEN, a warning for unsupported COMPOSER_AUTH_JSON keys
* ProjectUtils: typed, QA cards in the changelog (`\#QA-123` / `\QA-#123`), no symfony/yaml: the image has no
  composer and vendor anymore
* deploy.sh: StrictHostKeyChecking accept-new, no google dns override, curl -f; deployer.phar checked against its
  sha256; removed entrypoint.sh, action.yaml, composer.json/lock and the unused apk packages
* Makefile: no ECR, `release` pushes the version tag only, `release-latest` also latest
* README rewritten

# v2.0.3
* writable dirs with acl (www-data + the deploy user, default acl for files created later) when the host has
  setfacl, chmod 0777 with a warning otherwise; http_user www-data (acl tested on the test server, keyplan-platform,
  keyplan-batch, bouwmeester.bouwcloud.org and admin.bluebricks.nl)
* new `recipes/symfony.php` (deployer 8, replaces wab-symfony.php): deployer's recipe/symfony.php + the shared wcs
  config, app version from config/packages/app.yaml, database:migrate before the symlink, copy_dirs vendor only
* shared config of waf.php and symfony.php in `recipes/wcs.php`: server env (composer superuser, NODE_AUTH_TOKEN),
  writable acl, frontend build (`node_version` null uses .nvmrc), php-fpm reload, hostHasRole()
* hosts with role label `batch` get no frontend build (`frontend_build_skip_roles`), the php-fpm reload is skipped
  when the service does not run on the host (warning when it does run on a batch host); the waf migration already
  skipped batch hosts
* removed the deployer 6 recipes sef.php, wab-symfony.php, wab-react.php, wab-reactV2.php and their bootstrap; the
  deployer 8 bootstrap is now `recipes/bootstrap_github_action.php` (projects on these recipes keep image 1.1.20)

# v2.0.2
* waf: `sef_env` is now `app_env` (host config `->set('app_env', 'staging')`, `{{app_env}}`), info line "using APP
  environment"
* deploy.sh: verbose (-v) by default again, every task stays folded in the GitHub log

# v2.0.1
* deploy.sh: `--ansi` (colors in the GitHub log), normal verbosity unless INPUT_VERBOSELEVEL is set (deployer 8 prints
  every command with -v)
* waf: tar without -v, slack shows the host again ({{hostname}}), duration of the whole deploy (deployer 8 runs tasks
  in worker processes)

# v2.0.0
* image `ghcr.io/wecreatesolutions/action-deployer:2.0.0-8.4.27-cli-alpine` (was `wimwinterberg/action-deployer`)
* php 8.4.27, deployer 8.0.5 (was 6.8.0), deprecation notices hidden in the deploy log
* new recipe `recipes/waf.php` (deployer 8) with its bootstrap `recipes/bootstrap_github_action_v8.php`: everything of
  sef.php plus the config the waf projects shared in their deploy.php
  * hosts use labels: `->setLabels(['stage' => 'staging'])`, batch hosts `'role' => 'batch'`
  * COMPOSER_ALLOW_SUPERUSER and NODE_AUTH_TOKEN (from the workflow env) on the server
  * var/log writable, deploy:writable again before deploy:symlink
  * waf:migration:migrate, frontend build (nvm install/use, npm install, assets:install, build), optional dotenv:dump
  * optional messenger (setup-transports, consumer restart per systemd unit), php-fpm reload for the opcache
  * releases continue after the highest deployer 6 release
  * composer http-basic password no longer in the deploy log
* sef.php and wab-*.php stay deployer 6 recipes: use image 1.1.20 for those
* removed deployer/recipes (php ~7.0), symfony/yaml 7.4

v1.1.20
* using php 7.4
* using google dns server

# v1.1.7
* added ScriptHandler SEF logic to deployer
* composer install, cache:clear, migration, init cache
* update deployer to version 6.8.0
 
# v1.0.2
* added shared logic for sef projects

# v1.0.0
* removed node, npm, yarn
* added make for building docker image
* added duration in slack message
* should use pre build docker image

# v0.0.14
* Added node, npm and yarn to docker

# v0.0.13
* Add verbose level

# v0.0.12
* Added custom tasks for sf - crobjob scanning and encore build

# v0.0.11
* Sym-linking the build directory instead of copying

# v0.0.10
* Added shared .env.local file used for building
* Added symlink for private_html (used for https)

# v0.0.9
* Altered build process. Also copying build files instead of symlinking

# v0.0.8
* Added wab-react. Used for apps and react websites 

# v0.0.7
* Added setup permissions
* Added deploy additional info
* Added slack notification when deployment starts
* Display task info when creating github release

# v0.0.6
* Support for WAB SF4 projects
* Moved shared tasks from SEF to bootstrap
* Set write mode to chmod since ACL is enabled default for IPS containers
* Copy vendor directory for SF

# v0.0.5
* Added functionality to create a release
* Added functionality to set composer authentication

# v0.0.4
* Added failure slack message
* Added supported role batch - will not run migration
* Forces annsicon for coloring support in github

# v0.0.3
* download deploy configuration from repo

# v0.0.2
* fixed typo in var
* adjusted slack message

# v0.0.1
* Initial version
