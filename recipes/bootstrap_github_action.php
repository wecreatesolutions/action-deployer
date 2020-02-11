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
// @TODO: when a release is created we also might to deploy to production
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

// clear HOME
// https://github.com/deployphp/deployer/blob/master/src/Support/Unix.php - parseHomeDir uses HOME which is overridden by github
unset($_SERVER['HOME']);

// region tasks
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

desc('Create release in github');
task(
    'github:create-release',
    function () {
        $stage = get('stage') ?? get('default_stage');
        if ($stage == 'production') {
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
