<?php

namespace Deployer;

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
                $sefEnv = 'production';
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

task(
    'download-revision',
    function () {

        $repositoryName = get('repository_name', 'xxx');
        $revision       = get('revision', 'xxx');
        $token          = get('github_token', 'xxx');

        run("cd {{release_path}} && curl -sS -H 'Authorization: token $token' --location --request GET 'https://api.github.com/repos/$repositoryName/tarball/$revision' > repo.tar.gz");
    }
);

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

task(
    'create-private-html-symlink',
    function () {
        run("cd {{release_path}} && ln -s public_html private_html");
    }
);

task(
    'composer-sef-deploy',
    function () {
        // make env configurable
        $sefEnv = get('sef_env', 'xxx');
        $result = run('cd {{release_path}} && {{bin/composer}} sef-deploy -- ' . $sefEnv);
        writeln($result);
    }
);

task(
    'deploy:info-stage',
    function () {
        writeln('Deployment target is <info>{{stage}}</info>, using SEF environment <info>{{sef_env}}</info>');
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
        'download-revision',
        'extract-revision',
        'deploy:copy_dirs',
        'create-private-html-symlink',
        'composer-sef-deploy',
        'deploy:symlink',
        'deploy:unlock',
        'cleanup',
        'success',
    ]
);

// @see https://deployer.org/recipes/slack.html
$slackWebhookToken = get('slack_webhook_token', null);
if ($slackWebhookToken !== null) {
    require 'recipes/slack.php';

    set('slack_webhook', $slackWebhookToken);
    set('slack_text', sprintf('_{{user}}_ deploying `{{branch}}` to *{{target}}*'));

    before('deploy', 'slack:notify');
    after('success', 'slack:notify:success');
}
// endregion
