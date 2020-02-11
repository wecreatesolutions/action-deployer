<?php

namespace Deployer;

use Utils\ProjectUtils;

require_once __DIR__ . '/bootstrap_github_action.php';

// ahared files/dirs between deploys
set('shared_files', []);
set('shared_dirs', []);

// copy dirs
set('copy_dirs', ['vendor']);

// writable dirs by web server
set('writable_dirs', []);

// set deploy path
set('deploy_path', '~/deployer');

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
after('deploy:failed', 'deploy:unlock');

desc('Update SEF code');
task(
    'deploy:update_code',
    [
        'download-revision',
        'extract-revision',
        'deploy:copy_dirs',
        'create-private-html-symlink',
    ]
);

desc('Download revision tar');
task(
    'download-revision',
    function () {

        $repositoryName = get('repository_name', 'xxx');
        $revision       = get('revision', 'xxx');
        $token          = get('github_token', 'xxx');

        run("cd {{release_path}} && curl -sS -H 'Authorization: token $token' --location --request GET 'https://api.github.com/repos/$repositoryName/tarball/$revision' > repo.tar.gz");
    }
);

desc('Extract downloaded revision tar');
task(
    'extract-revision',
    function () {
        $repositoryName = get('repository_name', 'xxx');
        $revision       = get('revision', 'xxx');

        $directionName = str_replace('/', '-', $repositoryName) . '-' . $revision . '/';
        run("cd {{release_path}} && tar -xvf repo.tar.gz  $directionName");
        run("cd {{release_path}} && mv $directionName* .");
        run("cd {{release_path}} && rm -rf $directionName");
        run("cd {{release_path}} && rm repo.tar.gz");
    }
);

desc('Set required symlink for private-html, used for https');
task(
    'create-private-html-symlink',
    function () {
        run("cd {{release_path}} && ln -s public_html private_html");
    }
);

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
        'deploy:info-stage',
        'deploy:info',
        'deploy:prepare',
        'deploy:lock',
        'deploy:release',
        'deploy:update_code',
        'composer-set-auth',
        'composer-sef-deploy',
        'deploy:symlink',
        'deploy:unlock',
        'cleanup',
        'success',
    ]
);

$slackWebhookToken = get('slack_webhook_token', null);
if ($slackWebhookToken !== null) {
    before('deploy', 'slack:notify');
}

// endregion
