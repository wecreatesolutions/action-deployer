<?php

namespace Deployer;

use Deployer\Exception\RunException;
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
 * - dotenv_dump                (default false) true runs `dotenv:dump <APP_ENV>` after the vendors, with the APP_ENV of
 *                                              shared/.env.local (the deploy stops when it has none)
 * - messenger_setup_transports (default: true when messenger_systemd_unit is set) messenger:setup-transports after
 *                                              the migration (the transport DSN only auto-creates the table in dev)
 * - messenger_systemd_unit     (default null)  e.g. 'keyplan-messenger@*': graceful consumer restart after the symlink
 * - messenger_restart_roles    (default [])    only restart the consumers on hosts with one of these role labels, e.g. ['batch']
 *
 * The migration runs on one host only: hosts with role label `batch` skip it.
 * assets:install is not part of the recipe: the composer scripts of the project run it during deploy:vendors
 * (`post-install-cmd` / `auto-scripts-waf`, with `--install-new-ui` for projects with the new ui).
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

set('dotenv_dump', false);
set('messenger_systemd_unit', null);
set('messenger_setup_transports', function () {
    return get('messenger_systemd_unit') !== null;
});
set('messenger_restart_roles', []);

// APP_ENV of the shared .env.local on the server ('' when it has none)
set('shared_app_env', function () {
    return run("grep -E '^APP_ENV=' {{deploy_path}}/shared/.env.local 2>/dev/null | tail -n 1 | cut -d= -f2- | tr -d \"'\\\"\" || true");
});

// for the deploy log
set('app_env', function () {
    $appEnv = get('shared_app_env');

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

desc('Dump env');
task('deploy:dump-env', function () {
    if (!get('dotenv_dump')) {
        return;
    }

    // always pass the env: without it dotenv:dump uses the default env of the command (prod in sef-core), and the
    // .env.local.php it writes wins over the .env.local, so a staging host would run with APP_ENV=prod
    $appEnv = get('shared_app_env');
    if (preg_match('/^[A-Za-z0-9_-]+$/', $appEnv) !== 1) {
        throw new \RuntimeException(sprintf('dotenv_dump: no valid APP_ENV in %s/shared/.env.local (found "%s")', get('deploy_path'), $appEnv));
    }

    run(sprintf('cd {{release_path}} && {{bin/console}} dotenv:dump %s', $appEnv));
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

    // run() + catch instead of test(): Deployer's test() compares the whole output with its marker, so a command that
    // prints something (stop-workers prints an [OK] line) would always count as failed
    try {
        run('cd {{release_path}} && {{bin/console}} messenger:stop-workers');
    } catch (RunException $exception) {
        warning(sprintf('messenger:stop-workers failed on %s: %s', currentHost()->getAlias(), trim($exception->getErrorOutput() ?: $exception->getOutput())));
    }
    try {
        run(sprintf("systemctl --user restart '%s'", $unit));
    } catch (RunException $exception) {
        warning(sprintf('could not restart %s on %s: the consumers may still run the previous release (%s)', $unit, currentHost()->getAlias(), trim($exception->getErrorOutput() ?: $exception->getOutput())));
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
