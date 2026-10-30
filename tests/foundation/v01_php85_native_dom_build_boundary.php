<?php

declare(strict_types=1);

use Allfather\Loopia\Tests\Assert;

/**
 * V01 post-freeze blocker regression: the official PHP 8.5 base already
 * contains native DOM/Lexbor, so rebuilding the DOM extension under the
 * Bookworm image fails if its Lexbor source headers are unavailable.
 *
 * This is a source-contract guard, NOT a deployed host acceptance check.
 */
$docker=(string)file_get_contents(dirname(__DIR__,2).'/Dockerfile');
Assert::contains('FROM php:8.5.12-apache-bookworm',$docker,
    'stage uses the exact pinned PHP 8.5 image');
Assert::true(str_contains($docker,'pdo_mysql mbstring curl'),
    'PDO MySQL and required supplemental extensions are still built');
Assert::false(str_contains($docker,'pdo_mysql mbstring curl dom'),
    'never rebuild built-in DOM without Lexbor headers on Bookworm');
Assert::true(str_contains($docker,'class_exists("Dom\\\\HTMLDocument")'),
    'native DOM HTML5 runtime capability must still fail closed when missing');
Assert::true(str_contains($docker,"extension_loaded(\"dom\")"),
    'DOM extension itself remains an explicit host capability requirement');
Assert::true(str_contains($docker,'apache2ctl -t'),
    'Apache configuration is still checked during image build');
$root=dirname(__DIR__,2);
$workflowFile=$root.'/.github/workflows/cr01-html5-native.yml';
if(is_file($workflowFile) && !is_link($workflowFile)){
    // Source checkout: the actual PR CI definition is mandatory.
    $workflow=(string)file_get_contents($workflowFile);
    Assert::true(str_contains($workflow,'staging-image-build:'),
        'CR01 pull request workflow must build the actual staging Docker image');
    Assert::true(str_contains($workflow,'docker build --pull --progress=plain'),
        'PHP lint cannot substitute for a real PHP8.5 staging Docker build');
    Assert::true(str_contains($workflow,'--network none --entrypoint php'),
        'image capabilities must be inspected without app start or network');
    Assert::true(str_contains($workflow,'host_runtime_acceptance'),
        'CI image build must expressly disclaim staging-host acceptance');
} else {
    // The qualification runner stages a CLOSED release projection into
    // <temporary>/qualification/rsync-contract-target. It retains the tracked
    // ci.yml but deliberately EXCLUDES all other workflow files. The host's
    // own .git/.github sentinels are protected from rsync deletion.
    // Only this exact synthetic shape may omit the Docker build workflow.
    $hostWorkflow=$root.'/.github/workflows/host-only.yml';
    $hostGit=$root.'/.git/config';
    Assert::true(
        basename($root)==='rsync-contract-target'
            && basename(dirname($root))==='qualification'
            && is_file($root.'/.github/workflows/ci.yml')
            && is_file($hostWorkflow)
            && trim((string)file_get_contents($hostWorkflow))==='HOST_GITHUB_SENTINEL'
            && is_file($hostGit)
            && trim((string)file_get_contents($hostGit))==='HOST_GIT_SENTINEL'
            && !file_exists($workflowFile),
        'only the CI rsync rehearsal with exact protected host sentinels may omit CR01');
}

