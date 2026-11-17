<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Enabled
    |--------------------------------------------------------------------------
    |
    | Master switch. Even when true, the editor only answers in the
    | environments listed below; everywhere else it returns 404.
    |
    */

    'enabled' => env('OPENAPI_STUDIO_ENABLED', true),

    /*
    |--------------------------------------------------------------------------
    | Allowed environments
    |--------------------------------------------------------------------------
    |
    | The editor writes files on disk, so it is meant for local development
    | only. Staging and production are blocked by default.
    |
    */

    'environments' => ['local'],

    /*
    |--------------------------------------------------------------------------
    | Spec directory
    |--------------------------------------------------------------------------
    |
    | Folder holding the OpenAPI files (*.yaml, *.yml, *.json). Commit it to
    | Git like any other source file. Backups are kept in ".backups" inside it.
    |
    */

    'directory' => base_path('docs/api'),

    'backups' => 10,

    /*
    |--------------------------------------------------------------------------
    | Route
    |--------------------------------------------------------------------------
    */

    'route' => [
        'prefix' => 'openapi-studio',
        'middleware' => ['web'],
    ],

];
