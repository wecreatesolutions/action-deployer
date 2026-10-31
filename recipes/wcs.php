<?php

namespace Deployer;

/*
 * Shared deploy config of the WCS recipes (waf.php, symfony.php), Deployer 8. Not meant to be required by a project
 * deploy.php directly.
 *
 * Options (set() in the project deploy.php):
 * - frontend_build            (default true)  false when the project has no frontend or builds it in an own task
 * - frontend_build_skip_roles (default ['batch']) hosts with one of these role labels get no frontend build (a batch
 *                                             server only runs workers)
 * - node_version              (default '22')  installed and used with nvm for the frontend build; null uses .nvmrc
 * - npm_install_command       (default 'npm install')
 * - frontend_build_command    (default 'npm run build')
 * - php_fpm_service           (default from bin/php, e.g. 'php8.4-fpm') reloaded after the symlink so the opcache does
 *                                             not serve the previous release, skipped when it does not run on the
 *                                             host; null to skip
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
set('bin/composer', '{{bin/php}} /usr/bin/composer');

set('frontend_build', true);
set('frontend_build_skip_roles', ['batch']);
set('node_version', '22');
set('npm_install_command', 'npm install');
set('frontend_build_command', 'npm run build');
set('php_fpm_service', function () {
    return basename(get('bin/php')) . '-fpm';
});
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
