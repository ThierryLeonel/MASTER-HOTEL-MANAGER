<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Default Filesystem Disk
    |--------------------------------------------------------------------------
    |
    | Here you may specify the default filesystem disk that should be used
    | by the framework. The "local" disk, as well as a variety of cloud
    | based disks are available to your application for file storage.
    |
    */

    'default' => env('FILESYSTEM_DISK', 'local'),

    /*
    |--------------------------------------------------------------------------
    | Filesystem Disks
    |--------------------------------------------------------------------------
    |
    | Below you may configure as many filesystem disks as necessary, and you
    | may even configure multiple disks for the same driver. Examples for
    | most supported storage drivers are configured here for reference.
    |
    | Supported drivers: "local", "ftp", "sftp", "s3"
    |
    */

    'disks' => [

        'local' => [
            'driver' => 'local',
            'root' => storage_path('app/private'),
            'serve' => true,
            'throw' => false,
            'report' => false,
        ],

        // Operational finding (CORRECTION_ROUND_34_DELIVERY.md §3.9): on
        // Laravel Cloud specifically, a plain local-disk write here is not
        // reliably persisted or servable — that platform expects an
        // attached Object Storage bucket, connected to the app via a
        // standard AWS_* env var set (their own docs: "Laravel Cloud
        // automatically injects FILESYSTEM_DISK and AWS S3-compatible
        // connection variables" — using AWS_ENDPOINT_URL/AWS_REGION
        // specifically, not this project's original AWS_ENDPOINT/
        // AWS_DEFAULT_REGION names, hence the fallback chain below).
        // Self-detecting rather than switched by a manual flag: when
        // AWS_BUCKET is present (a bucket has actually been attached),
        // this disk becomes S3/R2-backed automatically; every other
        // hosting target this project supports (shared hosting, this
        // sandbox, a bare VPS) has no AWS_BUCKET set and gets back
        // EXACTLY the original local-disk behaviour, unchanged.
        'public' => array_merge(
            ['visibility' => 'public', 'throw' => false, 'report' => false],
            env('AWS_BUCKET')
                ? [
                    'driver' => 's3',
                    'key' => env('AWS_ACCESS_KEY_ID'),
                    'secret' => env('AWS_SECRET_ACCESS_KEY'),
                    'region' => env('AWS_REGION', env('AWS_DEFAULT_REGION')),
                    'bucket' => env('AWS_BUCKET'),
                    'url' => env('AWS_URL'),
                    'endpoint' => env('AWS_ENDPOINT_URL', env('AWS_ENDPOINT')),
                    'use_path_style_endpoint' => env('AWS_USE_PATH_STYLE_ENDPOINT', false),
                ]
                : [
                    'driver' => 'local',
                    'root' => storage_path('app/public'),
                    'url' => env('APP_URL').'/storage',
                ]
        ),

        's3' => [
            'driver' => 's3',
            'key' => env('AWS_ACCESS_KEY_ID'),
            'secret' => env('AWS_SECRET_ACCESS_KEY'),
            'region' => env('AWS_REGION', env('AWS_DEFAULT_REGION')),
            'bucket' => env('AWS_BUCKET'),
            'url' => env('AWS_URL'),
            'endpoint' => env('AWS_ENDPOINT_URL', env('AWS_ENDPOINT')),
            'use_path_style_endpoint' => env('AWS_USE_PATH_STYLE_ENDPOINT', false),
            'throw' => false,
            'report' => false,
        ],

    ],

    /*
    |--------------------------------------------------------------------------
    | Symbolic Links
    |--------------------------------------------------------------------------
    |
    | Here you may configure the symbolic links that will be created when the
    | `storage:link` Artisan command is executed. The array keys should be
    | the locations of the links and the values should be their targets.
    |
    */

    'links' => [
        public_path('storage') => storage_path('app/public'),
    ],

];
