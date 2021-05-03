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
set('writable_dirs', []);

// region sef app version
set(
    'app_version',
    function () {

        $cmd            = "curl -sS -H 'Authorization: token {{github_token}}' --location --request GET 'https://raw.githubusercontent.com/{{repository_name}}/{{revision}}/application/config/application/Config.php'";
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
                $sefEnv = 'acc_test';
                break;
            case 'production':
                $sefEnv = 'prod_prod';
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
desc('Running deployment scripts for SEF');
task(
    'composer-sef-deploy',
    function () {
        $arguments = [get('sef_env', 'xxx')];
        $roles     = has('roles') ? get('roles') : null;
        if (is_array($roles) && in_array('batch', $roles)) {
            $arguments[] = '--no-migration';
        }
        $cmd = 'cd {{release_path}} && {{bin/composer}} sef-deploy -- ' . implode(' ', $arguments);

        $result = run($cmd);
        writeln($result);
    }
);

desc('Display additional info');
task(
    'deploy:info-stage',
    function () {
        writeln('Deployment target is <info>{{stage}}</info>, using SEF environment <info>{{sef_env}}</info>, deploying app version <info>{{app_version}}</info>');
    }
);

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
        'composer-sef-deploy',
        'deploy:symlink',
        'deploy:unlock',
        'cleanup',
        'success',
    ]
);
// endregion
