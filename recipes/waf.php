<?php

namespace Deployer;

use Utils\ProjectUtils;

/*
 * WAF recipe (Deployer 8, image >= 1.2.0): everything of sef.php plus the config the WAF projects (keyplan, service-engine, bouwmeester-bouwcloud,
 * bluebricks) shared in their own deploy.php. A project deploy.php only needs `require 'recipes/waf.php';`, its
 * application name, shared_files, hosts and the options below that differ from the defaults.
 *
 * Hosts (Deployer 8 labels instead of Deployer 6 stage/roles):
 *   host('keyplan.test.wecreatesolutions.nl')->setLabels(['stage' => 'staging'])->set('sef_env', 'staging')->set('deploy_path', '/projects/keyplan');
 *   host('keyplan-batch.prod.wecreatesolutions.nl')->setLabels(['stage' => 'production', 'role' => 'batch'])->...;
 *
 * Options (set() in the project deploy.php):
 * - frontend_build           (default true)  false when the project builds its frontend in an own task
 * - node_version             (default '22')  installed and used with nvm for the frontend build
 * - npm_install_command      (default 'npm install')
 * - frontend_build_command   (default 'npm run build')
 * - dotenv_dump              (default false) true runs `dotenv:dump {{sef_env}}` after the vendors
 * - messenger_systemd_unit   (default null)  e.g. 'keyplan-messenger@*': enables messenger:setup-transports after the
 *                                            migration and a graceful consumer restart after the symlink
 * - messenger_restart_roles  (default [])    only restart the consumers on hosts with one of these role labels, e.g. ['batch']
 * - php_fpm_service          (default 'php8.4-fpm') reloaded after the symlink so the opcache does not serve the
 *                                            previous release; null to skip
 *
 * Environment of the deployment workflow:
 * - NODE_AUTH_TOKEN: needed when the project .npmrc reads ${NODE_AUTH_TOKEN} (private @wecreatesolutions npm packages).
 *   The workflow env only reaches this container, so the recipe passes it on to the commands on the server.
 */

require_once __DIR__ . '/bootstrap_github_action_v8.php';

// region setup configuration
set('default_timeout', 3600);

// shared files/dirs between deploys
set('shared_dirs', [
    'var/log',
]);

set('shared_files', [
    '.env.local',
]);

// copy dirs
set('copy_dirs', ['vendor']);

// writable dirs by web server - var/log is shared but never got its permissions from a deploy, so the web server
// user could not create a new log file or dir in it (e.g. messenger_{{sef_env}}.log, var/log/se_company) when the
// shared dir was new
set('writable_dirs', ['var/cache/{{sef_env}}', 'var/log']);
set('writable_mode', 'chmod');
set('writable_chmod_mode', '0777');

set('bin/php', '/usr/bin/php8.4');
set('bin/composer', '{{bin/php}} /usr/bin/composer');
set('bin/console', '{{bin/php}} {{release_path}}/bin/console');
set('console_options', function () {
    return '';
});
set('composer_options', '--verbose --prefer-dist --no-progress --no-dev --optimize-autoloader'); // removed --no-interaction ~ we like to see more info :) - Deployer 7+ adds {{composer_action}} itself

set('frontend_build', true);
set('node_version', '22');
set('npm_install_command', 'npm install');
set('frontend_build_command', 'npm run build');
set('dotenv_dump', false);
set('messenger_systemd_unit', null);
set('messenger_restart_roles', []);
set('php_fpm_service', 'php8.4-fpm');
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

// region sef env
set(
    'sef_env',
    function () {
        switch (get('stage')) {
            case 'staging':
                $sefEnv = 'staging';
                break;
            case 'production':
                $sefEnv = 'prod';
                break;
            default:
                echo 'Unable to determine sef environment';
                exit(1);
        }

        return $sefEnv;
    }
);
// endregion

// region tasks

// region environment of the commands on the server
// composer runs as root on the servers: without COMPOSER_ALLOW_SUPERUSER it disables its plugins, so symfony/runtime
// never writes vendor/autoload_runtime.php and bin/console fails in a new deploy path (exit 255)
desc('Set the environment of the commands on the server');
task(
    'deploy:server-env',
    function () {
        $env = ['COMPOSER_ALLOW_SUPERUSER' => '1'];

        $nodeAuthToken = getenv('NODE_AUTH_TOKEN');
        if (is_string($nodeAuthToken) && $nodeAuthToken !== '') {
            $env['NODE_AUTH_TOKEN'] = $nodeAuthToken;
        } elseif (test('grep -qs NODE_AUTH_TOKEN {{release_path}}/.npmrc')) {
            throw new \RuntimeException('.npmrc reads NODE_AUTH_TOKEN, but it is not set: add NODE_AUTH_TOKEN: ${{ secrets.NODE_AUTH_TOKEN }} to the env of the deploy step in the deployment workflow');
        }

        set('env', array_merge(get('env', []), $env));
    }
);
before('composer-set-auth', 'deploy:server-env');
// endregion

desc('Running migration');
task(
    'deploy:migrate',
    function () {
        if (!wafHostHasRole(['batch'])) {
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
        writeln('Deployment target is <info>{{stage}}</info>, using SEF environment <info>{{sef_env}}</info>, deploying app version <info>{{app_version}}</info>');
    }
);

desc('Clear cache');
task('deploy:cache:clear', function () {
    run('cd {{release_path}} && rm -rf var/cache/{{sef_env}}/*');
});

desc('Warmup cache');
task('deploy:cache:warmup', function () {
    $result = run('cd {{release_path}} && {{bin/console}} cache:warmup');
    writeln($result);
});

// region frontend
desc('Build frontend');
task('deploy:build-frontend', function () {
    if (!get('frontend_build')) {
        return;
    }

    // install node before using it (a new server or node version does not have it yet)
    writeln(run('. ~/.nvm/nvm.sh && nvm install {{node_version}}'));
    writeln(run('. ~/.nvm/nvm.sh && nvm use {{node_version}} && cd {{release_path}} && {{npm_install_command}}'));
    writeln(run('cd {{release_path}} && {{bin/console}} assets:install --install-new-ui --always-copy-assets'));
    writeln(run('. ~/.nvm/nvm.sh && nvm use {{node_version}} && cd {{release_path}} && {{frontend_build_command}}'));
});
after('deploy:vendors', 'deploy:build-frontend');
// endregion

desc('Dump env');
task('deploy:dump-env', function () {
    if (get('dotenv_dump')) {
        writeln(run('cd {{release_path}} && {{bin/console}} dotenv:dump {{sef_env}}'));
    }
});
after('deploy:vendors', 'deploy:dump-env');

// region writable after the console commands
// deploy:writable runs before deploy:cache:warmup / deploy:migrate, so everything those console commands create
// afterwards is owned by the deploy user with umask 022 and is not writable for the web server user.
// run it again once all console commands are done.
before('deploy:symlink', 'deploy:writable');
// endregion

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
    if ($restartRoles !== [] && !wafHostHasRole($restartRoles)) {
        return;
    }

    writeln(run('cd {{release_path}} && {{bin/console}} messenger:stop-workers || true'));
    writeln(run(sprintf("systemctl --user restart '%s' || true", $unit)));
});
after('deploy:symlink', 'deploy:messenger-restart');
// endregion

// region opcache
// the opcache keeps serving the previous release after the symlink switch, a graceful php-fpm reload clears it
// without dropping running requests (restart would)
desc('Reload php-fpm (clears the opcache)');
task('deploy:php-fpm-reload', function () {
    $service = get('php_fpm_service');
    if ($service === null) {
        return;
    }

    run(sprintf('systemctl reload %s', $service));
});
after('deploy:symlink', 'deploy:php-fpm-reload');
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

/**
 * Whether the current host has one of the given role labels (Deployer 8 replacement of the Deployer 6 roles)
 *
 * @param string[] $roles
 */
function wafHostHasRole(array $roles): bool
{
    return in_array(currentHost()->getLabels()['role'] ?? null, $roles, true);
}
