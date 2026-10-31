<?php

namespace Deployer;

use Utils\ProjectUtils;

/*
 * GitHub Action bootstrap for Deployer 8 (image >= 2.0.0), used by recipes/waf.php and recipes/symfony.php.
 * The Deployer 6 recipes (sef.php, wab-*.php) only exist in the 1.x images.
 *
 * Differences with the Deployer 6 bootstrap:
 * - hosts use labels instead of stages: host(...)->setLabels(['stage' => 'staging']), the stage of this deploy is
 *   selected via default_selector (stage=staging / stage=production, from the branch)
 * - tasks `cleanup` / `success` are `deploy:cleanup` / `deploy:success`
 * - slack comes from Deployer's own contrib/slack.php
 */

require_once __DIR__ . '/../Utils/ProjectUtils.php';
require 'recipe/common.php';

// region environment
$revision          = $_ENV['GITHUB_SHA'] ?? null;
$repositoryName    = $_ENV['GITHUB_REPOSITORY'] ?? null;
$token             = $_ENV['GITHUB_TOKEN'] ?? null;
$slackWebhookToken = $_ENV['SLACK_WEBHOOK_TOKEN'] ?? null;
$githubActor       = $_ENV['GITHUB_ACTOR'] ?? null;
$branch            = preg_replace('%^refs/heads/(.*)%m', '\1', $_ENV['GITHUB_REF'] ?? '');
// deployer 8 runs every task in its own worker process: keep the start time of the deploy itself (the master) for {{duration}}
$start = (float)(getenv('WAF_DEPLOY_START') ?: microtime(true));
putenv('WAF_DEPLOY_START=' . $start);
$_SERVER['WAF_DEPLOY_START'] = $_ENV['WAF_DEPLOY_START'] = (string)$start; // symfony process passes these to the workers

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
set('default_selector', 'stage=' . $stage);
set('revision', $revision);
set('branch', $branch);
set('repository_name', $repositoryName);
set('user', $githubActor);
set('github_token', $token);
set('slack_webhook_token', $slackWebhookToken);
set('composer_auth_json', $_ENV['COMPOSER_AUTH_JSON'] ?? null);

// stage of the current host (label), replaces the Deployer 6 {{stage}}
set('stage', function () {
    return currentHost()->getLabels()['stage'] ?? null;
});

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

// Deployer 7+ defaults to false, Deployer 6 was recursive
set('writable_recursive', true);

// Deployer 8 keeps 10 releases, Deployer 6 kept 5 (vendor and node_modules per release)
set('keep_releases', 5);

// clear HOME
// https://github.com/deployphp/deployer/blob/master/src/Support/Unix.php - parseHomeDir uses HOME which is overridden by github
unset($_SERVER['HOME']);

// region release name
// Deployer 7+ numbers releases via .dep/latest_release, Deployer 6 did not write it (also not when a project deploys
// with a 1.x image in between): continue after the highest of latest_release and the existing release dirs.
set('release_name', function () {
    return within('{{deploy_path}}', function () {
        $latest  = (int)run('cat .dep/latest_release 2>/dev/null || echo 0');
        $highest = (int)run("ls -1 releases 2>/dev/null | grep -E '^[0-9]+$' | sort -n | tail -n 1 || echo 0");

        return (string)(max($latest, $highest) + 1);
    });
});
// endregion

// region tasks

// unlock after failure
after('deploy:failed', 'deploy:unlock');

// Deployer 7+ does not allow replacing a task by a group task: remove the default deploy:update_code first
Deployer::get()->tasks->remove('deploy:update_code');
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

        $repositoryName = get('repository_name');
        $revision       = get('revision');

        // the token goes via stdin (printf is a shell builtin): not in the log, nor in the process list of the server
        run(
            "cd {{release_path}} && printf 'Authorization: token %s' %github_token% | curl -fsS -H @- --location 'https://api.github.com/repos/$repositoryName/tarball/$revision' -o repo.tar.gz",
            secrets: ['github_token' => quote(get('github_token'))],
        );
    }
);

desc('Extract downloaded revision tar');
task(
    'extract-revision',
    function () {
        $repositoryName = get('repository_name', 'xxx');
        $revision       = get('revision', 'xxx');

        $directionName = str_replace('/', '-', $repositoryName) . '-' . $revision . '/';
        run("cd {{release_path}} && tar -xf repo.tar.gz $directionName");
        run("cd {{release_path}} && (mv $directionName{*,.[^.]*,..?*} . 2>/dev/null || true)"); // move all files included dot files (when a project does not have dot files ignore error
        run("cd {{release_path}} && rm -rf $directionName");
        run("cd {{release_path}} && rm repo.tar.gz");

        // which commit runs in a release (dep releases, debugging on the server)
        run("cd {{release_path}} && echo '$revision' > REVISION");
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
        $composerAuthJson = json_decode(get('composer_auth_json', null) ?? '', true);
        if (is_array($composerAuthJson)) {
            foreach (array_diff(array_keys($composerAuthJson), ['http-basic']) as $unsupported) {
                warning("COMPOSER_AUTH_JSON: $unsupported is not supported, only http-basic");
            }

            foreach ($composerAuthJson['http-basic'] ?? [] as $host => $config) {
                $username = $config['username'] ?? null;
                $password = $config['password'] ?? null;
                if ($username !== null && $password !== null) {
                    // secrets: the password stays out of the deploy log
                    run(
                        sprintf('cd {{release_path}} && {{bin/composer}} config http-basic.%1$s %2$s %%composer_password%%', $host, $username),
                        secrets: ['composer_password' => quote($password)],
                    );
                }
            }
        }
    }
);

desc('Setup permissions');
task(
    'deploy:setup-permissions',
    function () {
        // vendor and node_modules come from the previous release (copy_dirs): skipped, so vendor/bin keeps its +x
        // folders
        run("cd {{release_path}} && find . -type d \( -path './vendor' -o -path './node_modules' \) -prune -o -type d -not \( -path './awstats*' -o -path './.git*' -o -path \"./var/cache*\" \) ! -perm 0755 -exec chmod 755 {} \;");

        // files
        run("cd {{release_path}} && find . -type d \( -path './vendor' -o -path './node_modules' \) -prune -o -type f -not \( -path './awstats/*' -o -path './logs/*' -o -path './stats/*' -o -path './.git/*' \) ! -perm 0644 -exec chmod 644 {} \;");
    }
);

desc('Create release in github');
task(
    'github:create-release',
    function () {
        if (get('stage') === 'production') {
            // after deploy:success: a failure here must not mark the (live) deploy as failed
            $appVersion = get('app_version');
            if ($appVersion === null) {
                warning('No app version found, no GitHub release created');

                return;
            }

            writeln('Creating new release <info>{{app_version}}</info>...');

            $result = createRelease(get('github_token'), get('repository_name'), $appVersion, get('app_version_changelog'), get('revision'));
            if (!array_key_exists('html_url', $result)) {
                warning('GitHub release not created: ' . ($result['message'] ?? 'no response'));
            }

            if (array_key_exists('html_url', $result)) {
                // region notification on slack
                if (!get('slack_webhook', false)) {
                    return;
                }

                $url  = $result['html_url'];
                $name = $result['name'];

                set('slack_success_text', sprintf('Deployment to *{{hostname}}* with version `%1$s` successful - see <%2$s|release> - {{duration}}', $name, $url));
            }
            // endregion
        }
    }
)->once();

after('deploy:success', 'github:create-release');

set(
    'duration',
    function () use ($start) {
        return sprintf('%1$.2fs', microtime(true) - $start);
    }
);

// @see https://deployer.org/docs/8.x/contrib/slack
if (!empty($slackWebhookToken)) {
    require 'contrib/slack.php';

    set('slack_webhook', $slackWebhookToken);
    // {{where}} is the stage in deployer 8, deployer 6 showed the host ({{target}}, now the git target)
    set('slack_text', '_{{user}}_ deploying `{{branch}}` with version `{{app_version}}` to *{{hostname}}* - _{{stage}}_');
    set('slack_success_text', 'Deployment to *{{hostname}}* with version `{{app_version}}` successful - {{duration}}');
    set('slack_failure_text', 'Deployment to *{{hostname}}* with version `{{app_version}}` failed - {{duration}}');

    before('deploy:info', 'slack:notify');
    after('deploy:success', 'slack:notify:success');
    after('deploy:failed', 'slack:notify:failure');
}

desc('Display additional info');
task(
    'deploy:info-stage',
    function () {
        writeln('Deployment target is <info>{{stage}}</info> deploying app version <info>{{app_version}}</info>');
    }
)->once()
 ->hidden();
before('deploy:info', 'deploy:info-stage');

// endregion

/**
 * Create release
 */
function createRelease(string $githubToken, string $repositoryName, string $version, string $changeLogVersionBody, string $revision): array
{
    $curl = curl_init();

    $tagName     = ltrim($version, 'v');
    $releaseName = 'v' . $tagName;

    $data = [
        'tag_name'         => $tagName,
        // the deployed commit, not the head of master (production branch, or master moved on during the deploy)
        'target_commitish' => $revision,
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
            CURLOPT_ENCODING       => '',
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

    return json_decode((string)$response, true) ?? [];
}
