<?php

namespace Deployer;

require 'recipes/bootstrap_github_action.php';

set('shared_files', ['.env.local', '.env']);
set('shared_dirs', []); // can be shared, only needed for building
set('copy_dirs', []);
set('writable_dirs', []);
set(
    'app_version',
    function () {

        // @TODO: we can use the package.json to read the version

        $cmd            = "curl -sS -H 'Authorization: token {{github_token}}' --location --request GET 'https://raw.githubusercontent.com/{{repository_name}}/{{revision}}/CHANGELOG.md'";
        $configContents = runLocally($cmd);
        if (preg_match('/^#\s+v?(.*)\s*$/m', $configContents, $matches)) {
            $appVersion = 'v' . $matches[1];
        } else {
            $appVersion = 'vX.Y.Z';
        }

        return $appVersion;
    }
);

desc('Set symlink');
task(
    'create-public-html-symlink',
    function () {
        run("cd {{release_path}} && ln -s build public_html");
    }
);



desc('Download revision tar');
task(
    'download-revision',
    function () {

        $repositoryName = get('repository_name', 'xxx');
        $revision       = get('revision', 'xxx');
        $token          = get('github_token', 'xxx');

        runLocally("curl -sS -H 'Authorization: token $token' --location --request GET 'https://api.github.com/repos/$repositoryName/tarball/$revision' > repo.tar.gz");
    }
);

desc('Extract downloaded revision tar');
task(
    'extract-revision',
    function () {
        $repositoryName = get('repository_name', 'xxx');
        $revision       = get('revision', 'xxx');

        $directionName = str_replace('/', '-', $repositoryName) . '-' . $revision . '/';
        runLocally("tar -xvf repo.tar.gz  $directionName");
        runLocally("(mv $directionName{*,.[^.]*,..?*} . 2>/dev/null || true)"); // move all files included dot files (when a project does not have dot files ignore error
        runLocally("rm -rf $directionName");
        runLocally("rm repo.tar.gz");
    }
);

desc('Build');
task(
    'build',
    function () {
        runLocally('ls -slah');
        runLocally('yarn install --force');
        runLocally('NODE_ENV=production yarn run build');
    }
);

desc('Display additional info');
task(
    'deploy:info-stage',
    function () {
        writeln('Deployment target is <info>{{stage}}</info>, deploying app version <info>{{app_version}}</info>');
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
        'deploy:shared',
        'build',
        'create-public-html-symlink',
        'create-private-html-symlink',
        'deploy:setup-permissions',
        'deploy:symlink',
        'deploy:unlock',
        'cleanup',
        'success',
    ]
);
// endregion
