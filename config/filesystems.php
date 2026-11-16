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

    'public_images' => env('PUBLIC_IMAGES_DISK'),

    'publishing_disk' => env('MEDIA_PUBLISHING_DISK', env('PUBLIC_IMAGES_DISK')),

    'archive_disk' => env('MEDIA_ARCHIVE_DISK', env('GOOGLE_DRIVE_ARCHIVE_ENABLED', false) ? 'google' : null),

    /*
    |--------------------------------------------------------------------------
    | Filesystem Disks
    |--------------------------------------------------------------------------
    |
    | Below you may configure as many filesystem disks as necessary, and you
    | may even configure multiple disks for the same driver. Examples for
    | most supported storage drivers are configured here for reference.
    |
    | Supported drivers: "local", "ftp", "sftp", "s3", "google"
    |
    */

    'disks' => [

        'local' => [
            'driver' => 'local',
            'root' => storage_path('app/private'),
            'url' => '/private',
            'serve' => true,
            'throw' => false,
            'report' => false,
        ],

        'public' => [
            'driver' => 'local',
            'root' => storage_path('app/public'),
            // Host-relative so uploaded media resolves against whatever origin
            // the app is served from (localhost, 127.0.0.1:8000, a domain, …),
            // rather than a fixed APP_URL that may not match the dev server.
            'url' => '/storage',
            'visibility' => 'public',
            'serve' => true,
            'throw' => false,
            'report' => false,
        ],

        's3' => [
            'driver' => 's3',
            'key' => env('AWS_ACCESS_KEY_ID'),
            'secret' => env('AWS_SECRET_ACCESS_KEY'),
            'region' => env('AWS_DEFAULT_REGION'),
            'bucket' => env('AWS_BUCKET'),
            'url' => env('AWS_URL'),
            'endpoint' => env('AWS_ENDPOINT'),
            'use_path_style_endpoint' => env('AWS_USE_PATH_STYLE_ENDPOINT', false),
            'throw' => true,
            'report' => true,
        ],

        'r2' => [
            'driver' => 's3',
            'key' => env('R2_ACCESS_KEY_ID', env('AWS_ACCESS_KEY_ID')),
            'secret' => env('R2_SECRET_ACCESS_KEY', env('AWS_SECRET_ACCESS_KEY')),
            'region' => env('R2_DEFAULT_REGION', env('AWS_DEFAULT_REGION', 'auto')),
            'bucket' => env('R2_BUCKET', env('PUBLIC_IMAGES_BUCKET', env('AWS_BUCKET'))),
            'url' => env('R2_URL', env('PUBLIC_IMAGES_URL', env('AWS_URL'))),
            'endpoint' => env('R2_ENDPOINT', env('AWS_ENDPOINT')),
            'use_path_style_endpoint' => env('R2_USE_PATH_STYLE_ENDPOINT', env('AWS_USE_PATH_STYLE_ENDPOINT', true)),
            'visibility' => 'public',
            'throw' => true,
            'report' => true,
        ],

        'public-images' => [
            'driver' => 's3',
            'key' => env('AWS_ACCESS_KEY_ID'),
            'secret' => env('AWS_SECRET_ACCESS_KEY'),
            'region' => env('AWS_DEFAULT_REGION'),
            'bucket' => env('PUBLIC_IMAGES_BUCKET', env('AWS_BUCKET')),
            'url' => env('PUBLIC_IMAGES_URL', env('AWS_URL')),
            'endpoint' => env('AWS_ENDPOINT'),
            'use_path_style_endpoint' => env('AWS_USE_PATH_STYLE_ENDPOINT', false),
            'visibility' => 'public',
            'throw' => true,
            'report' => true,
        ],

        'google' => [
            'driver' => 'google',
            'client_id' => env('GOOGLE_DRIVE_CLIENT_ID', env('GOOGLE_CLIENT_ID')),
            'client_secret' => env('GOOGLE_DRIVE_CLIENT_SECRET', env('GOOGLE_CLIENT_SECRET')),
            'refresh_token' => env('GOOGLE_DRIVE_REFRESH_TOKEN'),
            'access_token' => env('GOOGLE_DRIVE_ACCESS_TOKEN'),
            'root_folder_id' => env('GOOGLE_DRIVE_ROOT_FOLDER_ID', 'root'),
            'root_folder_name' => env('GOOGLE_DRIVE_ROOT_FOLDER_NAME', 'SocialMesh'),
            'allow_delete' => false,
            'visibility' => 'private',
            'throw' => true,
            'report' => true,
        ],

        'ebin_cloud' => [
            'driver' => 'ebin_cloud',
            'url' => env('EBIN_CLOUD_URL', 'http://172.19.0.1:3010'),
            'service_key' => env('EBIN_CLOUD_SERVICE_KEY', ''),
            'app_id' => env('EBIN_CLOUD_APP_ID', 'socialmesh'),
            'allow_delete' => (bool) env('EBIN_CLOUD_ALLOW_DELETE', false),
            'cache_dir' => env('EBIN_CLOUD_CACHE_DIR', storage_path('app/cache/ebin-cloud')),
            'cache_max_bytes' => (int) env('EBIN_CLOUD_CACHE_MAX_BYTES', 268435456),
            'visibility' => 'private',
            'throw' => true,
            'report' => true,
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
