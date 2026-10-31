<?php

namespace Deployer;

use Utils\ProjectUtils;

/*
 * WAF recipe (Deployer 8, image >= 2.0.0) for the SEF/WAF projects (keyplan, service-engine, bouwmeester-bouwcloud,
 * bluebricks). A project deploy.php only needs `require 'recipes/waf.php';`, its application name, shared_files, hosts
 * and the options that differ from the defaults (see also recipes/wcs.php).
 *
 * Hosts (Deployer 8 labels instead of Deployer 6 stage/roles):
 *   host('keyplan.test.wecreatesolutions.nl')->setLabels(['stage' => 'staging'])->set('app_env', 'staging')->set('deploy_path', '/projects/keyplan');
 *   host('keyplan-batch.prod.wecreatesolutions.nl')->setLabels(['stage' => 'production', 'role' => 'batch'])->...;
 *
 * Options (set() in the project deploy.php), next to the ones of recipes/wcs.php:
 * - dotenv_dump              (default false) true runs `dotenv:dump {{app_env}}` after the vendors
 * - messenger_systemd_unit   (default null)  e.g. 'keyplan-messenger@*': enables messenger:setup-transports after the
 *                                            migration and a graceful consumer restart after the symlink
 * - messenger_restart_roles  (default [])    only restart the consumers on hosts with one of these role labels, e.g. ['batch']
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
// user could not create a new log file or dir in it (e.g. messenger_{{app_env}}.log, var/log/se_company) when the
// shared dir was new
set('writable_dirs', ['var/cache/{{app_env}}', 'var/log']);

set('bin/console', '{{bin/php}} {{release_path}}/bin/console');
set('console_options', function () {
    return '';
});
set('composer_options', '--verbose --prefer-dist --no-progress --no-dev --optimize-autoloader'); // removed --no-interaction ~ we like to see more info :) - Deployer 7+ adds {{composer_action}} itself

set('dotenv_dump', false);
set('messenger_systemd_unit', null);
set('messenger_restart_roles', []);
// endregion

// region sef app version
set(
    'app_version',
    function () {

        $cmd = "curl -sS -H 'Authorization: token {{github_token}}' --location --request GET 'https://raw.githubusercontent.com/{{repository_name}}/{{revision}}/application/config/application/Config.php'";
        $configContents = runLocally($cmd);

        return (new ProjectUtils())->parseAppVersion(ProjectUtils::APP_TYPE_SEF, $configContents);
    }
);
// endregion

// region app env
set(
    'app_env',
    function () {
        switch (get('stage')) {
            case 'staging':
                $appEnv = 'staging';
                break;
            case 'production':
                $appEnv = 'prod';
                break;
            default:
                echo 'Unable to determine app environment';
                exit(1);
        }

        return $appEnv;
    }
);
// endregion

// region tasks
desc('Running migration');
task(
    'deploy:migrate',
    function () {
        if (!hostHasRole(['batch'])) {
            $result = run('cd {{release_path}} && {{bin/console}} waf:migration:migrate');
            writeln($result);

            // messenger transport table (idempotent): the transport DSN only auto-creates messenger_messages in dev
            if (get('messenger_systemd_unit') !== null) {
                writeln('Setting up messenger transports');
                $result = run('cd {{release_path}} && {{bin/console}} messenger:setup-transports');
                writeln($result);
            }
        }
    }
);

desc('Display additional info');
task(
    'deploy:info-stage',
    function () {
        writeln('Deployment target is <info>{{stage}}</info>, using APP environment <info>{{app_env}}</info>, deploying app version <info>{{app_version}}</info>');
    }
);

desc('Clear cache');
task('deploy:cache:clear', function () {
    run('cd {{release_path}} && rm -rf var/cache/{{app_env}}/*');
});

desc('Warmup cache');
task('deploy:cache:warmup', function () {
    $result = run('cd {{release_path}} && {{bin/console}} cache:warmup');
    writeln($result);
});

// the webpack entries live in the generated _assets/, so install the waf assets before the frontend build
desc('Install the waf assets');
task('deploy:assets-install', function () {
    if (frontendBuildOnHost()) {
        writeln(run('cd {{release_path}} && {{bin/console}} assets:install --install-new-ui --always-copy-assets'));
    }
});
before('deploy:build-frontend', 'deploy:assets-install');

desc('Dump env');
task('deploy:dump-env', function () {
    if (get('dotenv_dump')) {
        writeln(run('cd {{release_path}} && {{bin/console}} dotenv:dump {{app_env}}'));
    }
});
after('deploy:vendors', 'deploy:dump-env');

// region messenger consumers
// graceful: a running messenger:consume worker finishes its current message and exits, systemd restarts it on the
// new release. the systemd unit is provisioned on the host, not by this deploy - || true keeps it a no-op until then
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

    writeln(run('cd {{release_path}} && {{bin/console}} messenger:stop-workers || true'));
    writeln(run(sprintf("systemctl --user restart '%s' || true", $unit)));
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
