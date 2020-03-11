<?php

namespace Deployer;

require 'recipes/bootstrap_github_action.php';

set('shared_files', []);
set('shared_dirs', ['node_modules']); // can be shared, only needed for building
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

desc('Build');
task(
    'build',
    function () {
        run('cd {{release_path}} && yarn install --force');
        run('cd {{release_path}} && chmod 755 -R node_modules/.bin/*');
        run('cd {{release_path}} && NODE_ENV=production yarn run build');

        // @TODO: some files exists in public_html - so we cannot make a symlink to build
        run('cd {{release_path}} && cp -rf build/* public_html/');
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
//        'create-public-html-symlink',
        'deploy:symlink',
        'deploy:unlock',
        'cleanup',
        'success',
    ]
);
// endregion
