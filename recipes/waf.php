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
 * - dotenv_dump                (default false) true runs `dotenv:dump <APP_ENV>` after the vendors, with the APP_ENV of
 *                                              shared/.env.local (the deploy stops when it has none)
 * - messenger_setup_transports (default: true when messenger_systemd_unit is set for the project or the host) see
 *                                              recipes/wcs.php, together with messenger_systemd_unit and
 *                                              messenger_restart_roles; a host without consumers can set the unit to
 *                                              null, it still runs setup-transports with the migration
 * - migrate_after_delay        (default 5)     seconds deploy:migrate-after waits before `waf:migration:migrate --after`,
 *                                              so requests that still run the previous release can finish (0 = no wait)
 *
 * The migration runs on one host only: hosts with role label `batch` skip it.
 * After-migrations (expand/contract): when the release has application/utils/migration/migrations-after.php,
 * deploy:migrate-after runs `waf:migration:migrate --after` after the symlink, the php-fpm reload and the messenger
 * restart (not on batch hosts). A failure leaves the new release live; they run again with the next deploy, so they
 * must be idempotent (e.g. DROP TABLE IF EXISTS).
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
set('migrate_after_delay', 5);
// waf: the transport DSN only auto-creates the table in dev. Follows the project unit, not the host value: a web host
// with ->set('messenger_systemd_unit', null) (no consumers there) still sets up the transports, the batch host never
// runs the migration
set('messenger_setup_transports', function () {
    return Deployer::get()->config->get('messenger_systemd_unit') !== null || get('messenger_systemd_unit') !== null;
});

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

after('deploy:migrate', 'deploy:messenger-setup-transports');

// expand/contract: migrations that may only run when the previous release no longer runs (e.g. dropping a table the
// old code still writes to). Runs after the symlink, the php-fpm reload and the messenger restart (on all hosts, as
// Deployer finishes a task on every host before the next one), only when the release has migrations-after.php (an app
// with that file has a waf-framework with `waf:migration:migrate --after`). A failure leaves the new release live and
// fails the deploy; the next deploy runs the after-migrations again, so they must be idempotent.
desc('Running the after-migrations (after the switch to the new release)');
task('deploy:migrate-after', function () {
    if (hostHasRole(['batch'])) {
        return;
    }

    if (!test('[ -f {{release_path}}/application/utils/migration/migrations-after.php ]')) {
        return;
    }

    // the php-fpm reload is graceful: let requests that still run the previous release finish
    $delay = (int)get('migrate_after_delay');
    if ($delay > 0) {
        writeln(sprintf('waiting %d s for requests of the previous release', $delay));
        run(sprintf('sleep %d', $delay));
    }

    run('cd {{release_path}} && {{bin/console}} waf:migration:migrate --after');
});
// after deploy:messenger-restart (wcs.php hooks the php-fpm reload and then the messenger restart after the symlink),
// so the old consumers are stopped too; a failed restart only gives a warning there and the after-migrations still run
after('deploy:messenger-restart', 'deploy:migrate-after');

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
