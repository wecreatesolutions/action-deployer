<?php

namespace Deployer;

require 'recipe/common.php';

// region environment
$revision          = $_ENV['GITHUB_SHA'] ?? null;
$repositoryName    = $_ENV['GITHUB_REPOSITORY'] ?? null;
$token             = $_ENV['GITHUB_TOKEN'] ?? null;
$slackWebhookToken = $_ENV['SLACK_WEBHOOK_TOKEN'] ?? null;
$githubAuthor      = $_ENV['GITHUB_ACTOR'] ?? null;
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
writeln(sprintf('Deployment target is <info>%1$s</info>', $stage));
set('default_stage', $stage);
set('revision', $revision);
set('branch', $branch);
set('repository_name', $repositoryName);
set('user', $githubAuthor);
set('github_token', $token);
set('slack_webhook_token', $slackWebhookToken);

// clear HOME
// https://github.com/deployphp/deployer/blob/master/src/Support/Unix.php - parseHomeDir uses HOME which is overridden by github
unset($_SERVER['HOME']);
