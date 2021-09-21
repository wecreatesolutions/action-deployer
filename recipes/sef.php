<?php

namespace Deployer;

use Utils\ProjectUtils;

require_once __DIR__ . '/bootstrap_github_action.php';

// ahared files/dirs between deploys
set('shared_dirs', [
    'var/log',
]);

set('shared_files', [
    '.env.local',
]);

// copy dirs
set('copy_dirs', ['vendor']);

// writable dirs by web server
set('writable_dirs', ['var/cache/{{sef_env}}']);
set('writable_mode', 'chmod');
set('writable_chmod_mode', '0777');

set('bin/console', '{{bin/php}} {{release_path}}/bin/console');
set('console_options', function () {
    return '';
});
set('composer_options', '{{composer_action}} --verbose --prefer-dist --no-progress --no-dev --optimize-autoloader'); // removed --no-interaction ~ we like to see more info :)

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
        $stage = get('stage') ?? get('default_stage');
        switch ($stage) {
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

// endregion

// region tasks
desc('Running migration');
task(
    'deploy:migrate',
    function () {
        $roles = has('roles') ? get('roles') : null;
        if (!is_array($roles) || !in_array('batch', $roles)) {
            $cmd = 'cd {{release_path}} && {{bin/php}} application/utils/bin/run.php {{sef_env}} migration/migrate';

            $result = run($cmd);
            writeln($result);
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

task(
    'deploy',
    [
        'deploy:info',
        'deploy:prepare',
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
        'cleanup',
        'success',
    ]
);
// endregion
