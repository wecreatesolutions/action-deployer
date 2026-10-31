<?php

namespace Deployer;

/*
 * Shared deploy config of the WCS recipes (waf.php, symfony.php), Deployer 8. Not meant to be required by a project
 * deploy.php directly.
 *
 * Options (set() in the project deploy.php):
 * - frontend_build           (default true)  false when the project has no frontend or builds it in an own task
 * - node_version             (default '22')  installed and used with nvm for the frontend build; null uses .nvmrc
 * - npm_install_command      (default 'npm install')
 * - frontend_build_command   (default 'npm run build')
 * - php_fpm_service          (default 'php8.4-fpm') reloaded after the symlink so the opcache does not serve the
 *                                            previous release; null to skip
 *
 * Environment of the deployment workflow:
 * - NODE_AUTH_TOKEN: needed when the project .npmrc reads ${NODE_AUTH_TOKEN} (private @wecreatesolutions npm packages).
 *   The workflow env only reaches this container, so the recipe passes it on to the commands on the server.
 */

// region setup configuration
set('default_timeout', 3600);

// copy the vendor dir of the previous release to speed up composer install
set('copy_dirs', ['vendor']);

// acl: www-data and the deploy user get write access to the writable dirs, and files created later (e.g. by a
// console command as root) inherit it via the default acl - instead of world writable dirs. hosts without setfacl
// (apt install acl) keep chmod.
set('writable_mode', function () {
    if (commandExist('setfacl')) {
        return 'acl';
    }

    warning('setfacl not found on {{alias}} (apt install acl): writable dirs use chmod {{writable_chmod_mode}}');

    return 'chmod';
});
set('http_user', 'www-data');
set('writable_chmod_mode', '0777');

set('bin/php', '/usr/bin/php8.4');
set('bin/composer', '{{bin/php}} /usr/bin/composer');

set('frontend_build', true);
set('node_version', '22');
set('npm_install_command', 'npm install');
set('frontend_build_command', 'npm run build');
set('php_fpm_service', 'php8.4-fpm');
// endregion

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

// region frontend
desc('Build frontend');
task('deploy:build-frontend', function () {
    if (!get('frontend_build')) {
        return;
    }

    // no version: nvm reads .nvmrc of the release
    $nodeVersion = get('node_version') ?? '';
    $nvm         = '. ~/.nvm/nvm.sh && cd {{release_path}}';

    // install node before using it (a new server or node version does not have it yet)
    writeln(run("$nvm && nvm install $nodeVersion"));
    writeln(run("$nvm && nvm use $nodeVersion && {{npm_install_command}}"));
    writeln(run("$nvm && nvm use $nodeVersion && {{frontend_build_command}}"));
});
after('deploy:vendors', 'deploy:build-frontend');
// endregion

// region writable after the console commands
// deploy:writable runs before the console commands of the deploy (cache warmup, migrations), so everything they
// create afterwards is owned by the deploy user. run it again once they are done (acl skips dirs already set).
before('deploy:symlink', 'deploy:writable');
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

/**
 * Whether the current host has one of the given role labels (Deployer 8 replacement of the Deployer 6 roles)
 *
 * @param string[] $roles
 */
function hostHasRole(array $roles): bool
{
    return in_array(currentHost()->getLabels()['role'] ?? null, $roles, true);
}
