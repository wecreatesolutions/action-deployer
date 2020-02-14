<?php

namespace Deployer;

use Utils\ProjectUtils;

require_once __DIR__ . '/bootstrap_github_action.php';

require 'recipe/symfony4.php';

// copy the vendor from the previous release to speed up composer install
set('copy_dirs', ['vendor']);

// region sf4 app version
set(
    'app_version',
    function () {

        $cmd            = "curl -sS -H 'Authorization: token {{github_token}}' --location --request GET 'https://raw.githubusercontent.com/{{repository_name}}/{{revision}}/config/packages/app.yaml'";
        $configContents = runLocally($cmd);

        return (new ProjectUtils())->parseAppVersion(ProjectUtils::APP_TYPE_SF4, $configContents);
    }
);
// endregion

// region tasks
// set up composer auth
before('deploy:vendors', 'composer-set-auth');

// we need to make sure .env are set correctly in SHARED FILES
before('deploy:symlink', 'database:migrate');
// endregion
