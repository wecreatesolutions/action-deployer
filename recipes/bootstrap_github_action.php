<?php

namespace Deployer;

use Utils\ProjectUtils;
use Deployer\Utility\Httpie;

require realpath(__DIR__ . '/../../vendor/autoload.php');
require realpath(__DIR__ . '/../../vendor/deployer/recipes/autoload.php');
require 'recipe/common.php';

// region environment
$revision          = $_ENV['GITHUB_SHA'] ?? null;
$repositoryName    = $_ENV['GITHUB_REPOSITORY'] ?? null;
$token             = $_ENV['GITHUB_TOKEN'] ?? null;
$slackWebhookToken = $_ENV['SLACK_WEBHOOK_TOKEN'] ?? null;
$githubActor       = $_ENV['GITHUB_ACTOR'] ?? null;
$branch            = $result = preg_replace('%^refs/heads/(.*)%m', '\1', $_ENV['GITHUB_REF'] ?? '');

if ($token === null) {
    echo 'Missing GITHUB_TOKEN';
    exit(1);
}
if ($revision === null) {
    echo 'Missing GITHUB_SHA';
    exit(1);
}
if ($repositoryName === null) {
    echo 'Missing GITHUB_REPOSITORY';
    exit(1);
}
if ($branch === '') {
    echo 'Unable to determine branch';
    exit(1);
}

// region determine stage to deploy
$stage = 'staging';
if (in_array($branch, ['production', 'master'])) {
    $stage = 'production';
}
// endregion

// endregion

// set configuration
set('default_stage', $stage);
set('revision', $revision);
set('branch', $branch);
set('repository_name', $repositoryName);
set('user', $githubActor);
set('github_token', $token);
set('slack_webhook_token', $slackWebhookToken);
set('composer_auth_json', $_ENV['COMPOSER_AUTH_JSON'] ?? null);

set(
    'changelog',
    function () {

        $cmd       = "curl -sS -H 'Authorization: token {{github_token}}' --location --request GET 'https://raw.githubusercontent.com/{{repository_name}}/{{revision}}/CHANGELOG.md'";
        $changeLog = runLocally($cmd);

        $projectUtils = new ProjectUtils();

        return $projectUtils->parseChangelog($changeLog);
    }
);

set(
    'app_version_changelog',
    function () {
        $changelog  = get('changelog');
        $appVersion = get('app_version');
        $retValue   = '';

        if ($changelog !== null && $appVersion !== null && array_key_exists($appVersion, $changelog)) {
            $retValue = (new ProjectUtils())->changeLogVersionToString($changelog[$appVersion]);
        }

        return $retValue;
    }
);

set(
    'app_version',
    function () {
        return null;
    }
);

// default was acl - but not enabled by ips containers
set('writable_mode', 'chmod');

// set deploy path
set('deploy_path', '~/deployer');

// clear HOME
// https://github.com/deployphp/deployer/blob/master/src/Support/Unix.php - parseHomeDir uses HOME which is overridden by github
unset($_SERVER['HOME']);

// region tasks

// unlock after failure
after('deploy:failed', 'deploy:unlock');

desc('Update code - note: this overrides default deployer deploy:update_code');
task(
    'deploy:update_code',
    [
        'download-revision',
        'extract-revision',
        'deploy:copy_dirs',
        'create-private-html-symlink',
        'deploy:setup-permissions',
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
        run("cd {{release_path}} && (mv $directionName{*,.[^.]*,..?*} . 2>/dev/null || true)"); // move all files included dot files (when a project does not have dot files ignore error
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

desc('Setting auth for composer for private packages');
task(
    'composer-set-auth',
    function () {
        $composerAuthJson = json_decode(get('composer_auth_json', null), true);
        if ($composerAuthJson !== null && $composerAuthJson !== false) {
            foreach ($composerAuthJson['http-basic'] ?? [] as $host => $config) {
                $username = $config['username'] ?? null;
                $password = $config['password'] ?? null;
                if ($username !== null && $password !== null) {
                    run(sprintf('cd {{release_path}} && composer config http-basic.%1$s %2$s "%3$s"', $host, $username, $password));
                }
            }
        }
    }
);

desc('Setup permissions');
task(
    'deploy:setup-permissions',
    function () {
        // folders
        run("cd {{release_path}} && find . -type d -not \( -path './awstats*' -o -path './.git*' \) ! -perm 0755 -exec chmod 755 {} \;");

        // files
        run("cd {{release_path}} && find . -type f -not \( -path './awstats/*' -o -path './logs/*' -o -path './stats/*' -o -path './.git/*' \) ! -perm 0644 -exec chmod 644 {} \;");
    }
);

desc('Create release in github');
task(
    'github:create-release',
    function () {
        $stage = get('stage') ?? get('default_stage');
        if ($stage == 'production') {
            writeln('Creating new release <info>{{app_version}}</info>...');

            $result = createRelease(get('github_token'), get('repository_name'), get('app_version'), get('app_version_changelog'));

            if (array_key_exists('html_url', $result)) {
                // region notification on slack
                if (!get('slack_webhook', false)) {
                    return;
                }

                $url  = $result['html_url'];
                $name = $result['name'];

                set('slack_success_text', sprintf('Deployment to *{{target}}* with version `%1$s` successful - see <%2$s|release>', $name, $url));
            }
            // endregion
        }
    }
)->once()
 ->shallow()
 ->setPrivate();

after('success', 'github:create-release');

// @see https://deployer.org/recipes/slack.html
$slackWebhookToken = get('slack_webhook_token', null);
if ($slackWebhookToken !== null) {
    require 'recipe/slack.php';

    set('slack_webhook', $slackWebhookToken);
    set('slack_text', sprintf('_{{user}}_ deploying `{{branch}}` with version `{{app_version}}` to *{{target}}* - _{{stage}}_'));

    after('success', 'slack:notify:success');
    after('deploy:failed', 'slack:notify:failure');

    before('deploy:info', 'slack:notify');
}

// endregion
/**
 * Create release
 *
 * @param string $githubToken
 * @param string $repositoryName
 * @param string $version
 * @param string $changeLogVersionBody
 * @return array
 */
function createRelease(string $githubToken, string $repositoryName, string $version, string $changeLogVersionBody): array
{
    $curl = curl_init();

    $tagName     = ltrim($version, 'v');
    $releaseName = 'v' . $tagName;

    $data = [
        'tag_name'         => $tagName,
        'target_commitish' => 'master',
        'name'             => $releaseName,
        'body'             => $changeLogVersionBody,
        'draft'            => false,
        'prerelease'       => false,
    ];

    curl_setopt_array(
        $curl,
        [
            CURLOPT_URL            => 'https://api.github.com/repos/' . $repositoryName . '/releases',
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_ENCODING       => "",
            CURLOPT_MAXREDIRS      => 10,
            CURLOPT_TIMEOUT        => 0,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_HTTP_VERSION   => CURL_HTTP_VERSION_1_1,
            CURLOPT_CUSTOMREQUEST  => 'POST',
            CURLOPT_POSTFIELDS     => json_encode($data),
            CURLOPT_HTTPHEADER     => [
                'Content-Type: application/json',
                'User-Agent: deployer',
                'Authorization: Bearer ' . $githubToken,
            ],
        ]
    );

    $response = curl_exec($curl);

    curl_close($curl);

    return json_decode($response, true);
}
