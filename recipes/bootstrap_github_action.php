<?php

namespace Deployer;

require 'recipe/common.php';

// region environment
$revision          = $_ENV['GITHUB_SHA'] ?? null;
$repositoryName    = $_ENV['GITHUB_REPOSITORY'] ?? null;
$token             = $_ENV['GITHUB_TOKEN'] ?? null;
$slackWebhookToken = $_ENV['SLACK_WEBHOOK_TOKEN'] ?? null;

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
// endregion

// set configuration
set('revision', $revision);
set('repository_name', $repositoryName);
set('github_token', $token);
set('slack_webhook_token', $slackWebhookToken);


// clear HOME
unset($_SERVER['HOME']);
