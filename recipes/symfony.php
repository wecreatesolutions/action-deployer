<?php

namespace Deployer;

use Utils\ProjectUtils;

/*
 * Symfony recipe (Deployer 8, image >= 2.0.0), replaces the Deployer 6 wab-symfony.php: Deployer's own
 * recipe/symfony.php plus the shared WCS config (recipes/wcs.php). A project deploy.php needs
 * `require 'recipes/symfony.php';`, its application name, shared dirs/files, hosts and the options that differ.
 *
 * Hosts (Deployer 8 labels instead of Deployer 6 stage/roles), APP_ENV comes from the shared .env.local:
 *   host('beleidsportaal.test.wecreatesolutions.nl')->setLabels(['stage' => 'staging'])->set('deploy_path', '/projects/beleidsportaal');
 *
 * Next to the options of recipes/wcs.php (frontend build, node version, php-fpm reload):
 * - the app version comes from parameters.app.release in config/packages/app.yaml
 * - database:migrate (doctrine migrations) runs before the symlink, not on hosts with role label `batch`; hook project
 *   migrations after it, e.g. after('database:migrate', 'tenants-database:migrate');
 * - {{bin/console}} already contains php in Deployer 8 ('{{bin/php}} .../bin/console'): use `{{bin/console}} ...`,
 *   never `{{bin/php}} {{bin/console}}` (php would print the console script instead of running it)
 * - shared_dirs default var/log, shared_files .env.local, writable_dirs var, var/cache, var/log, var/sessions
 * - the Deployer 6 tasks cron-scan and deploy-assets (yarn encore) are gone: cron scanning belongs in the project
 *   deploy.php, a yarn build goes via npm_install_command / frontend_build_command
 */

require_once __DIR__ . '/bootstrap_github_action.php';
require_once 'recipe/symfony.php';
require_once __DIR__ . '/wcs.php';

// region symfony app version
set(
    'app_version',
    function () {

        $cmd            = "curl -fsS -H 'Authorization: token {{github_token}}' --location 'https://raw.githubusercontent.com/{{repository_name}}/{{revision}}/config/packages/app.yaml'";
        $configContents = runLocally($cmd);

        return (new ProjectUtils())->parseAppVersion(ProjectUtils::APP_TYPE_SF4, $configContents);
    }
);
// endregion

// region tasks
// composer auth for private packages
before('deploy:vendors', 'composer-set-auth');

// doctrine migrations of recipe/symfony.php, on one host only (a batch host shares the database)
desc('Migrates database');
task('database:migrate', function () {
    if (hostHasRole(['batch'])) {
        return;
    }

    $options = '--allow-no-migration';
    if (get('migrations_config') !== '') {
        $options = "$options --configuration={{release_or_current_path}}/{{migrations_config}}";
    }
    run("cd {{release_or_current_path}} && {{bin/console}} doctrine:migrations:migrate $options {{console_options}}");
});

// the database settings come from the shared .env.local
before('deploy:symlink', 'database:migrate');
// endregion
