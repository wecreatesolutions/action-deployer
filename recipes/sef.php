<?php

namespace Deployer;

require_once __DIR__ . 'bootstrap_github_action.php';

// region setup configuration
set('default_stage', 'staging');

// ahared files/dirs between deploys
set('shared_files', []);
set('shared_dirs', []);

// copy dirs
set('copy_dirs', ['vendor']);

// writable dirs by web server
set('writable_dirs', []);

// set deploy path
set('deploy_path', '~/deployer');

// endregion

// region hosts
// region staging
// region plan4flex.test.are.builders
host('plan4task.test.are.builders')
    ->user('plan4ft')
    ->stage('staging')
    ->set('sef_env', 'acc_test');
// endregion
// region production
host('portal.plan4task.nl')
    ->user('kpuitzennl')
    ->stage('staging') //->stage('production')
    ->set('sef_env', 'prod_prod');
// endregion
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
    'deploy',
    [
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
// endregion
