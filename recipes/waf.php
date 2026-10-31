<?php

namespace Deployer;

use Utils\ProjectUtils;

/*
 * WAF recipe (Deployer 8, image >= 2.0.0) for the SEF/WAF projects (keyplan, service-engine, bouwmeester-bouwcloud,
 * bluebricks). A project deploy.php only needs `require 'recipes/waf.php';`, its application name, shared_files, hosts
 * and the options that differ from the defaults (see also recipes/wcs.php).
 *
 * Hosts (Deployer 8 labels): the stage selects the hosts from the branch, the app environment (APP_ENV) comes from
 * the shared .env.local on the server, like in Symfony.
 *   host('keyplan.test.wecreatesolutions.nl')->setLabels(['stage' => 'staging'])->set('deploy_path', '/projects/keyplan');
 *   host('keyplan-batch.prod.wecreatesolutions.nl')->setLabels(['stage' => 'production', 'role' => 'batch'])->...;
 *
 * Options (set() in the project deploy.php), next to the ones of recipes/wcs.php:
 * - assets_install             (default true)  `assets:install` before the frontend build (the webpack entries live
 *                                              in the generated _assets/)
 * - assets_install_options     (default '--install-new-ui --always-copy-assets') copies instead of symlinks: needed
 *                                              for Encore 6, with Encore 7 '--install-new-ui' is enough
 * - dotenv_dump                (default false) true runs `dotenv:dump` after the vendors
 * - messenger_setup_transports (default: true when messenger_systemd_unit is set) messenger:setup-transports after
 *                                              the migration (the transport DSN only auto-creates the table in dev)
 * - messenger_systemd_unit     (default null)  e.g. 'keyplan-messenger@*': graceful consumer restart after the symlink
 * - messenger_restart_roles    (default [])    only restart the consumers on hosts with one of these role labels, e.g. ['batch']
 *
 * The migration runs on one host only: hosts with role label `batch` skip it.
 */

require_once __DIR__ . '/bootstrap_github_action.php';
require_once __DIR__ . '/wcs.php';

// region setup configuration

// shared files/dirs between deploys
set('shared_dirs', [
    'var/log',
]);

set('shared_files', [
    '.env.local',
]);

// writable dirs by web server - var/log is shared but never got its permissions from a deploy, so the web server
// user could not create a new log file or dir in it (e.g. messenger_<env>.log, var/log/se_company) when the
// shared dir was new; var/cache/<APP_ENV> inherits the default acl of var/cache
set('writable_dirs', ['var/cache', 'var/log']);

set('bin/console', '{{bin/php}} {{release_path}}/bin/console');
set('console_options', function () {
    return '';
});
set('composer_options', '--verbose --prefer-dist --no-progress --no-dev --optimize-autoloader'); // removed --no-interaction ~ we like to see more info :) - Deployer 7+ adds {{composer_action}} itself

set('assets_install', true);
set('assets_install_options', '--install-new-ui --always-copy-assets');
set('dotenv_dump', false);
set('messenger_systemd_unit', null);
set('messenger_setup_transports', function () {
    return get('messenger_systemd_unit') !== null;
});
set('messenger_restart_roles', []);

// APP_ENV of the shared .env.local on the server, for the deploy log
set('app_env', function () {
    $appEnv = run("grep -E '^APP_ENV=' {{deploy_path}}/shared/.env.local 2>/dev/null | tail -n 1 | cut -d= -f2- | tr -d \"'\\\"\" || true");

    return $appEnv !== '' ? $appEnv : 'unknown (no APP_ENV in shared/.env.local)';
});
// endregion

// region sef app version
set(
    'app_version',
    function () {

        $cmd = "curl -fsS -H 'Authorization: token {{github_token}}' --location 'https://raw.githubusercontent.com/{{repository_name}}/{{revision}}/application/config/application/Config.php'";
        $configContents = runLocally($cmd);

        return (new ProjectUtils())->parseAppVersion(ProjectUtils::APP_TYPE_SEF, $configContents);
    }
);
// endregion

// region tasks
desc('Running migration');
task(
    'deploy:migrate',
    function () {
        if (hostHasRole(['batch'])) {
            return;
        }

        run('cd {{release_path}} && {{bin/console}} waf:migration:migrate');

        if (get('messenger_setup_transports')) {
            run('cd {{release_path}} && {{bin/console}} messenger:setup-transports');
        }
    }
);

desc('Display additional info');
task(
    'deploy:info-stage',
    function () {
        writeln('Deployment target is <info>{{stage}}</info>, APP_ENV <info>{{app_env}}</info> (shared/.env.local), deploying app version <info>{{app_version}}</info>');
    }
);

desc('Clear cache');
task('deploy:cache:clear', function () {
    run('cd {{release_path}} && rm -rf var/cache/*');
});

desc('Warmup cache');
task('deploy:cache:warmup', function () {
    run('cd {{release_path}} && {{bin/console}} cache:warmup');
});

// the webpack entries live in the generated _assets/, so install the waf assets before the frontend build
desc('Install the waf assets');
task('deploy:assets-install', function () {
    if (get('assets_install') && frontendBuildOnHost()) {
        run('cd {{release_path}} && {{bin/console}} assets:install {{assets_install_options}}');
    }
});
before('deploy:build-frontend', 'deploy:assets-install');

desc('Dump env');
task('deploy:dump-env', function () {
    if (get('dotenv_dump')) {
        run('cd {{release_path}} && {{bin/console}} dotenv:dump');
    }
});
after('deploy:vendors', 'deploy:dump-env');

// region messenger consumers
// graceful: a running messenger:consume worker finishes its current message and exits, systemd restarts it on the
// new release. the systemd unit is provisioned on the host, not by this deploy.
desc('Restart messenger consumers');
task('deploy:messenger-restart', function () {
    $unit = get('messenger_systemd_unit');
    if ($unit === null) {
        return;
    }

    $restartRoles = get('messenger_restart_roles');
    if ($restartRoles !== [] && !hostHasRole($restartRoles)) {
        return;
    }

    if (!test('cd {{release_path}} && {{bin/console}} messenger:stop-workers')) {
        warning('messenger:stop-workers failed on {{alias}}');
    }
    if (!test(sprintf("systemctl --user restart '%s'", $unit))) {
        warning(sprintf('could not restart %s on {{alias}}: the consumers may still run the previous release', $unit));
    }
});
after('deploy:symlink', 'deploy:messenger-restart');
// endregion

task(
    'deploy',
    [
        'deploy:info',
        'deploy:setup',
        'deploy:lock',
        'deploy:release',
        'deploy:update_code',
        'composer-set-auth',
        'deploy:shared',
        'deploy:writable',
        'deploy:vendors',
        'deploy:cache:clear',
        'deploy:cache:warmup',
        'deploy:migrate',
        'deploy:symlink',
        'deploy:unlock',
        'deploy:cleanup',
        'deploy:success',
    ]
);
// endregion
