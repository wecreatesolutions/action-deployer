<?php

namespace Deployer;

use Deployer\Exception\RunException;

/*
 * Shared deploy config of the WCS recipes (waf.php, symfony.php), Deployer 8. Not meant to be required by a project
 * deploy.php directly.
 *
 * Options (set() in the project deploy.php):
 * - frontend_build            (default true)  false when the project has no frontend or builds it in an own task
 * - frontend_build_skip_roles (default ['batch']) hosts with one of these role labels get no frontend build (a batch
 *                                             server only runs workers)
 * - node_version              (default: null when the release has an .nvmrc, else '22') installed and used with nvm for
 *                                             the frontend build; null uses the .nvmrc of the release
 * - npm_install_command       (default 'npm install')
 * - frontend_build_command    (default 'npm run build')
 * - bin/composer              (default: the first of /usr/local/bin/composer2, /usr/bin/composer,
 *                                             /usr/local/bin/composer, `command -v composer`) run with bin/php
 * - php_fpm_service           (default from bin/php, e.g. 'php8.4-fpm') reloaded after the symlink so the opcache does
 *                                             not serve the previous release, skipped when it does not run on the
 *                                             host; null to skip
 * - messenger_systemd_unit     (default null)  systemd user unit of the messenger consumers on the host, e.g.
 *                                             'keyplan-messenger@*': after the symlink `messenger:stop-workers` and
 *                                             `systemctl --user restart '<unit>'` (the real restart: stop-workers only
 *                                             reaches the old consumer when the restart-signal cache is shared, the
 *                                             cache is per release); a failure only gives a warning. The unit is
 *                                             provisioned on the host, not by the deploy; a user unit of the deploy user
 *                                             needs lingering (`loginctl enable-linger <user>`), else it does not start
 *                                             after a reboot and stops with the last session of that user
 * - messenger_restart_roles    (default [])    only restart the consumers on hosts with one of these role labels, e.g. ['batch']
 * - messenger_setup_transports (default: waf true when messenger_systemd_unit is set, symfony false)
 *                                             `messenger:setup-transports` after the migration, not on hosts with role
 *                                             label `batch`; symfony projects usually create the transport table with a
 *                                             doctrine migration (auto_setup=0), so they only turn it on when needed
 *
 * Environment of the deployment workflow:
 * - NODE_AUTH_TOKEN: needed when the project .npmrc reads ${NODE_AUTH_TOKEN} (private @wecreatesolutions npm packages).
 *   The workflow env only reaches this container: the recipe passes it to `npm install` on the server only (as a
 *   deployer secret, so not in the log).
 *
 * Writable dirs use acl (www-data and the deploy user, default acl so files created later inherit it): a host
 * without setfacl stops the deploy (apt install acl).
 */

// region setup configuration
set('default_timeout', 3600);

// copy the vendor dir of the previous release to speed up composer install
set('copy_dirs', ['vendor']);

set('writable_mode', function () {
    if (!commandExist('setfacl')) {
        throw new \RuntimeException(sprintf('setfacl not found on %s: install it with `apt install acl` (writable dirs use acl)', currentHost()->getAlias()));
    }

    return 'acl';
});
set('http_user', 'www-data');

set('bin/php', '/usr/bin/php8.4');
// composer lives in different places on the servers: composer2 first (always composer 2, on the test server newer
// than /usr/bin/composer)
set('bin/composer', function () {
    foreach (['/usr/local/bin/composer2', '/usr/bin/composer', '/usr/local/bin/composer'] as $path) {
        if (test(sprintf('[ -f %s ]', $path))) {
            return get('bin/php') . ' ' . $path;
        }
    }

    $path = run('command -v composer || command -v composer2 || true');
    if ($path === '') {
        throw new \RuntimeException(sprintf('composer not found on %s: set bin/composer for this host', currentHost()->getAlias()));
    }

    return get('bin/php') . ' ' . $path;
});

set('frontend_build', true);
set('frontend_build_skip_roles', ['batch']);
// the .nvmrc of the project wins, '22' only when it has none
set('node_version', function () {
    return test('[ -f {{release_path}}/.nvmrc ]') ? null : '22';
});
set('npm_install_command', 'npm install');
set('frontend_build_command', 'npm run build');
set('php_fpm_service', function () {
    return basename(get('bin/php')) . '-fpm';
});
set('messenger_systemd_unit', null);
set('messenger_restart_roles', []);
set('messenger_setup_transports', false);
set('node_auth_token', function () {
    $nodeAuthToken = getenv('NODE_AUTH_TOKEN');

    return is_string($nodeAuthToken) && $nodeAuthToken !== '' ? $nodeAuthToken : null;
});
// endregion

// region environment of the commands on the server
// composer runs as root on the servers: without COMPOSER_ALLOW_SUPERUSER it disables its plugins, so symfony/runtime
// never writes vendor/autoload_runtime.php and bin/console fails in a new deploy path (exit 255)
desc('Set the environment of the commands on the server');
task(
    'deploy:server-env',
    function () {
        set('env', array_merge(get('env', []), ['COMPOSER_ALLOW_SUPERUSER' => '1']));
    }
);
before('composer-set-auth', 'deploy:server-env');
// endregion

// region frontend
desc('Build frontend');
task('deploy:build-frontend', function () {
    if (!frontendBuildOnHost()) {
        return;
    }

    // no version: nvm reads .nvmrc of the release
    $nodeVersion = get('node_version') ?? '';
    $nvm         = '. ~/.nvm/nvm.sh && cd {{release_path}}';

    // install node before using it (a new server or node version does not have it yet)
    run("$nvm && nvm install $nodeVersion");

    // only npm install gets the token of the private registry, as a deployer secret (not in the log)
    $nodeAuthToken = get('node_auth_token');
    if ($nodeAuthToken !== null) {
        run("$nvm && nvm use $nodeVersion && NODE_AUTH_TOKEN=%node_auth_token% {{npm_install_command}}", secrets: ['node_auth_token' => quote($nodeAuthToken)]);
    } elseif (test('grep -qs NODE_AUTH_TOKEN {{release_path}}/.npmrc')) {
        throw new \RuntimeException('.npmrc reads NODE_AUTH_TOKEN, but it is not set: add NODE_AUTH_TOKEN: ${{ secrets.NODE_AUTH_TOKEN }} to the env of the deploy step in the deployment workflow');
    } else {
        run("$nvm && nvm use $nodeVersion && {{npm_install_command}}");
    }

    run("$nvm && nvm use $nodeVersion && {{frontend_build_command}}");
});
after('deploy:vendors', 'deploy:build-frontend');
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

    if (!test(sprintf('systemctl is-active --quiet %s', $service))) {
        writeln(sprintf('%s does not run on {{alias}}, no reload', $service));

        return;
    }

    // a batch server only runs workers: php-fpm there is unexpected (reloaded anyway, so it never serves an old release)
    if (hostHasRole(['batch'])) {
        warning(sprintf('%s runs on batch host {{alias}}: a batch server only needs php-cli for the workers', $service));
    }

    run(sprintf('systemctl reload %s', $service));
});
after('deploy:symlink', 'deploy:php-fpm-reload');
// endregion

// region messenger consumers
// hook it after the migration of the recipe (waf: deploy:migrate, symfony: database:migrate)
desc('Set up the messenger transports');
task('deploy:messenger-setup-transports', function () {
    if (!get('messenger_setup_transports') || hostHasRole(['batch'])) {
        return;
    }

    run('cd {{release_path}} && {{bin/console}} messenger:setup-transports');
});

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

/**
 * Whether the frontend is built on the current host (frontend_build, not on frontend_build_skip_roles hosts)
 */
function frontendBuildOnHost(): bool
{
    return get('frontend_build') && !hostHasRole(get('frontend_build_skip_roles'));
}

/**
 * Whether the current host has one of the given role labels (Deployer 8 replacement of the Deployer 6 roles)
 *
 * @param string[] $roles
 */
function hostHasRole(array $roles): bool
{
    return in_array(currentHost()->getLabels()['role'] ?? null, $roles, true);
}
