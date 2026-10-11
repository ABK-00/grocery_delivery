<?php

/*
|--------------------------------------------------------------------------
| APPLICATION BASE URL
|--------------------------------------------------------------------------
|
| Works with:
|
| http://localhost/somame_ent
| http://somame_ent.test
|
|--------------------------------------------------------------------------
*/

if (!defined('BASE_URL')) {

    $documentRoot = realpath(
        $_SERVER['DOCUMENT_ROOT'] ?? ''
    );

    $projectRoot = realpath(
        dirname(__DIR__)
    );

    $documentRoot = str_replace(
        '\\',
        '/',
        $documentRoot ?: ''
    );

    $projectRoot = str_replace(
        '\\',
        '/',
        $projectRoot ?: ''
    );

    $baseUrl = '';

    if (
        $documentRoot !== ''
        && $projectRoot !== ''
        && str_starts_with(
            strtolower($projectRoot),
            strtolower($documentRoot)
        )
    ) {

        $relativePath = substr(
            $projectRoot,
            strlen($documentRoot)
        );

        $relativePath = trim(
            str_replace('\\', '/', $relativePath),
            '/'
        );

        if ($relativePath !== '') {
            $baseUrl = '/' . $relativePath;
        }
    }

    define(
        'BASE_URL',
        $baseUrl
    );
}
