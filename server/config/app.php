<?php

use Illuminate\Support\Facades\Facade;
use Illuminate\Support\ServiceProvider;

return [

    /*
    |--------------------------------------------------------------------------
    | Application Name
    |--------------------------------------------------------------------------
    |
    | This value is the name of your application. This value is used when the
    | framework needs to place the application's name in a notification or
    | any other location as required by the application or its packages.
    |
    */

    'name' => env('APP_NAME', 'Laravel'),

    /*
    |--------------------------------------------------------------------------
    | Application Environment
    |--------------------------------------------------------------------------
    |
    | This value determines the "environment" your application is currently
    | running in. This may determine how you prefer to configure various
    | services the application utilizes. Set this in your ".env" file.
    |
    */

    'env' => env('APP_ENV', 'production'),

    /*
    |--------------------------------------------------------------------------
    | Application Debug Mode
    |--------------------------------------------------------------------------
    |
    | When your application is in debug mode, detailed error messages with
    | stack traces will be shown on every error that occurs within your
    | application. If disabled, a simple generic error page is shown.
    |
    */

    'debug' => (bool) env('APP_DEBUG', false),

    /*
    |--------------------------------------------------------------------------
    | Application URL
    |--------------------------------------------------------------------------
    |
    | This URL is used by the console to properly generate URLs when using
    | the Artisan command line tool. You should set this to the root of
    | your application so that it is used when running Artisan tasks.
    |
    */

    'url' => env('APP_URL', 'http://localhost'),
    'firefly_url' => env('FIREFLY_URL', ''),
    // Sign in with Firefly III (firefly-pocket-stack, Setup UI step 11): Firefly III's public URL, where the browser
    // signs in, and the public OAuth client the stack registers for Pocket. Offered only once both are set
    'firefly_public_url' => env('FIREFLY_PUBLIC_URL', ''),
    'firefly_client_id' => env('FIREFLY_CLIENT_ID', ''),
    // Firefly rule groups (looked up by title) used by rule suggestions, see firefly-pocket-stack's docs/rule-suggestions.md
    'rule_group_suggestions' => env('RULE_GROUP_SUGGESTIONS', 'Suggestions'),
    'rule_group_categorization' => env('RULE_GROUP_CATEGORIZATION', 'Categorization'),
    // Bank connections are managed by the FireflyX importer fork; Pico's backend calls its internal API
    'importer_api_url' => env('IMPORTER_API_URL', ''),
    'importer_api_token' => env('IMPORTER_API_TOKEN', ''),
    // Local development only: fixed dummy exchange rates (VWCE included) instead of calling the rate APIs
    'exchange_rates_mock' => (bool) env('EXCHANGE_RATES_MOCK', false),
    // Web Push (docs/web-push.md). Generate the VAPID keys once; push is off until both are set
    'vapid_public_key' => env('VAPID_PUBLIC_KEY', ''),
    'vapid_private_key' => env('VAPID_PRIVATE_KEY', ''),
    'vapid_subject' => env('VAPID_SUBJECT') ?: env('APP_URL', 'http://localhost'),
    // Shared secret (32+ characters) of the FireflyX importer/suggester for POST /api/internal/notify
    'notify_api_token' => env('NOTIFY_API_TOKEN', ''),
    // The weekday of a weekly digest is read in this timezone (compose passes TZ)
    'notify_timezone' => env('NOTIFY_TIMEZONE') ?: env('TZ') ?: 'UTC',
    // Cache of Firefly reads (app/Services/ResponseCache.php). The store is a config/cache.php store; empty = CACHE_DRIVER.
    // Writes through Pocket, /api/internal/notify and Firefly's webhook start a new data version; the TTL is a safety net
    'response_cache' => (bool) env('RESPONSE_CACHE', true),
    'response_cache_store' => env('RESPONSE_CACHE_STORE') ?: null,
    'response_cache_ttl' => (int) env('RESPONSE_CACHE_TTL', 60 * 60 * 12),
    // Firefly's timezone: nothing is kept past its midnight (Pocket itself runs on UTC)
    'response_cache_timezone' => env('RESPONSE_CACHE_TIMEZONE') ?: env('TZ') ?: 'UTC',
    // Warm-up after a change (app/Services/CacheWarmUp.php): a Firefly personal access token of the same user, used only
    // to fetch again what Home asked for; empty = off. Runs WARMUP_DELAY seconds after the first change, on the queue
    'warmup_firefly_token' => env('WARMUP_FIREFLY_TOKEN', ''),
    'warmup_delay' => (int) env('WARMUP_DELAY', 60),
    // How long a token Firefly accepted is trusted without asking Firefly again (seconds, 0 = every request)
    'user_cache_ttl' => (int) env('USER_CACHE_TTL', 600),
    // The secret Firefly shows for the webhook that calls POST /api/internal/firefly-webhook
    'firefly_webhook_secret' => env('FIREFLY_WEBHOOK_SECRET', ''),
    // Server-Timing header on API responses (time spent in Pocket and in Firefly), and a log line per request
    'server_timing' => (bool) env('SERVER_TIMING', true),
    'server_timing_log' => (bool) env('SERVER_TIMING_LOG', false),
    // Folder with admin provided icons (svg, png, ...). In Docker, mount a volume here.
    'custom_icons_path' => env('CUSTOM_ICONS_PATH', storage_path('app/custom-icons')),
    'asset_url' => env('ASSET_URL'),

    /*
    |--------------------------------------------------------------------------
    | Application Timezone
    |--------------------------------------------------------------------------
    |
    | Here you may specify the default timezone for your application, which
    | will be used by the PHP date and date-time functions. We have gone
    | ahead and set this to a sensible default for you out of the box.
    |
    */

    'timezone' => 'UTC',

    /*
    |--------------------------------------------------------------------------
    | Application Locale Configuration
    |--------------------------------------------------------------------------
    |
    | The application locale determines the default locale that will be used
    | by the translation service provider. You are free to set this value
    | to any of the locales which will be supported by the application.
    |
    */

    'locale' => 'en',

    /*
    |--------------------------------------------------------------------------
    | Application Fallback Locale
    |--------------------------------------------------------------------------
    |
    | The fallback locale determines the locale to use when the current one
    | is not available. You may change the value to correspond to any of
    | the language folders that are provided through your application.
    |
    */

    'fallback_locale' => 'en',

    /*
    |--------------------------------------------------------------------------
    | Faker Locale
    |--------------------------------------------------------------------------
    |
    | This locale will be used by the Faker PHP library when generating fake
    | data for your database seeds. For example, this will be used to get
    | localized telephone numbers, street address information and more.
    |
    */

    'faker_locale' => 'en_US',

    /*
    |--------------------------------------------------------------------------
    | Encryption Key
    |--------------------------------------------------------------------------
    |
    | This key is used by the Illuminate encrypter service and should be set
    | to a random, 32 character string, otherwise these encrypted strings
    | will not be safe. Please do this before deploying an application!
    |
    */

    'key' => env('APP_KEY'),

    'cipher' => 'AES-256-CBC',

    /*
    |--------------------------------------------------------------------------
    | Maintenance Mode Driver
    |--------------------------------------------------------------------------
    |
    | These configuration options determine the driver used to determine and
    | manage Laravel's "maintenance mode" status. The "cache" driver will
    | allow maintenance mode to be controlled across multiple machines.
    |
    | Supported drivers: "file", "cache"
    |
    */

    'maintenance' => [
        'driver' => 'file',
        // 'store'  => 'redis',
    ],

    /*
    |--------------------------------------------------------------------------
    | Autoloaded Service Providers
    |--------------------------------------------------------------------------
    |
    | The service providers listed here will be automatically loaded on the
    | request to your application. Feel free to add your own services to
    | this array to grant expanded functionality to your applications.
    |
    */

    'providers' => ServiceProvider::defaultProviders()->merge([
        /*
         * Package Service Providers...
         */

        /*
         * Application Service Providers...
         */
        App\Providers\AppServiceProvider::class,
        App\Providers\AuthServiceProvider::class,
        // App\Providers\BroadcastServiceProvider::class,
        App\Providers\EventServiceProvider::class,
        App\Providers\HorizonServiceProvider::class,
        App\Providers\RouteServiceProvider::class,
    ])->toArray(),

    /*
    |--------------------------------------------------------------------------
    | Class Aliases
    |--------------------------------------------------------------------------
    |
    | This array of class aliases will be registered when this application
    | is started. However, feel free to register as many as you wish as
    | the aliases are "lazy" loaded so they don't hinder performance.
    |
    */

    'aliases' => Facade::defaultAliases()->merge([
        // 'Example' => App\Facades\Example::class,
    ])->toArray(),

];
