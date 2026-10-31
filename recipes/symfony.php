<?php

namespace Deployer;

use Utils\ProjectUtils;

/*
 * Symfony recipe (Deployer 8, image >= 2.0.0), replaces the Deployer 6 wab-symfony.php: Deployer's own
 * recipe/symfony.php plus the shared WCS config (recipes/wcs.php). A project deploy.php needs
 * `require 'recipes/symfony.php';`, its application name, shared dirs/files, hosts and the options that differ.
 *
 * Hosts (Deployer 8 labels instead of Deployer 6 stage/roles):
 *   host('beleidsportaal.test.wecreatesolutions.nl')->setLabels(['stage' => 'staging'])->set('deploy_path', '/projects/beleidsportaal');
 *
 * Next to the options of recipes/wcs.php (frontend build, node version, php-fpm reload):
 * - the app version comes from parameters.app.release in config/packages/app.yaml
 * - database:migrate (doctrine migrations) runs before the symlink; hook project migrations after it, e.g.
 *   after('database:migrate', 'tenants-database:migrate');
 * - shared_dirs default var/log, shared_files .env.local, writable_dirs var, var/cache, var/log, var/sessions
 */

require_once __DIR__ . '/bootstrap_github_action.php';
require_once 'recipe/symfony.php';
require_once __DIR__ . '/wcs.php';

// region symfony app version
set(
    'app_version',
    function () {

        $cmd            = "curl -sS -H 'Authorization: token {{github_token}}' --location --request GET 'https://raw.githubusercontent.com/{{repository_name}}/{{revision}}/config/packages/app.yaml'";
        $configContents = runLocally($cmd);

        return (new ProjectUtils())->parseAppVersion(ProjectUtils::APP_TYPE_SF4, $configContents);
    }
);
// endregion

// region tasks
// composer auth for private packages
before('deploy:vendors', 'composer-set-auth');

// the database settings come from the shared .env.local
before('deploy:symlink', 'database:migrate');
// endregion
