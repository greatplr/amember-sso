<?php

return [
    /*
    |--------------------------------------------------------------------------
    | aMember API Configuration
    |--------------------------------------------------------------------------
    |
    | This package uses plutuss/amember-pro-laravel for API communication.
    | Configure AMEMBER_URL and AMEMBER_API_KEY in your .env file.
    | See: config/amember.php
    |
    */

    /*
    |--------------------------------------------------------------------------
    | SSO Configuration
    |--------------------------------------------------------------------------
    |
    | Configure SSO behavior including login/logout URLs and session handling.
    |
    */
    'sso' => [
        'enabled' => env('AMEMBER_SSO_ENABLED', true),
        'login_url' => env('AMEMBER_LOGIN_URL'),
        'logout_url' => env('AMEMBER_LOGOUT_URL'),
        'secret_key' => env('AMEMBER_SSO_SECRET'),

        // Redirect URLs after SSO actions
        'redirect_after_login' => env('AMEMBER_REDIRECT_AFTER_LOGIN', '/dashboard'),
        'redirect_after_logout' => env('AMEMBER_REDIRECT_AFTER_LOGOUT', '/'),

        // Session lifetime in minutes
        'session_lifetime' => env('AMEMBER_SESSION_LIFETIME', 120),
    ],

    /*
    |--------------------------------------------------------------------------
    | Authentication Guard
    |--------------------------------------------------------------------------
    |
    | Specify which Laravel guard should be used for SSO authentication.
    |
    */
    'guard' => env('AMEMBER_GUARD', 'web'),

    /*
    |--------------------------------------------------------------------------
    | User Model
    |--------------------------------------------------------------------------
    |
    | The User model that should be used for authentication.
    |
    */
    'user_model' => env('AMEMBER_USER_MODEL', 'App\\Models\\User'),

    /*
    |--------------------------------------------------------------------------
    | Webhook Configuration
    |--------------------------------------------------------------------------
    |
    | Configure webhook handling for subscription updates from aMember.
    | aMember uses camelCase event names (e.g., subscriptionAdded, not subscription.added)
    |
    */
    'webhook' => [
        'enabled' => env('AMEMBER_WEBHOOK_ENABLED', true),
        'secret' => env('AMEMBER_WEBHOOK_SECRET'),
        'route_prefix' => env('AMEMBER_WEBHOOK_PREFIX', 'amember/webhook'),

        // Queue Configuration
        'use_queue' => env('AMEMBER_WEBHOOK_USE_QUEUE', true),
        'queue_name' => env('AMEMBER_WEBHOOK_QUEUE', 'amember-webhooks'),

        // Retry Configuration
        'retry_failed' => env('AMEMBER_WEBHOOK_RETRY_FAILED', true),
        'max_retries' => env('AMEMBER_WEBHOOK_MAX_RETRIES', 3),
        'retry_delay' => env('AMEMBER_WEBHOOK_RETRY_DELAY', 60), // seconds

        // Events to listen for (aMember uses camelCase event names)
        'events' => [
            'subscriptionAdded',       // User gets new product subscription
            'subscriptionDeleted',     // User subscription expires
            'accessAfterInsert',       // Access record created (MAIN event for subscriptions)
            'accessAfterUpdate',       // Access record updated
            'accessAfterDelete',       // Access record deleted
            'paymentAfterInsert',      // Payment inserted (not for free)
            'invoicePaymentRefund',    // Payment refunded or chargebacked
            'userAfterInsert',         // New user created
            'userAfterUpdate',         // User record updated
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Access Control
    |--------------------------------------------------------------------------
    |
    | Configure product-based access control and subscription checking.
    |
    */
    'access_control' => [
        // Cache subscription data for performance
        'cache_enabled' => env('AMEMBER_CACHE_ENABLED', true),
        'cache_ttl' => env('AMEMBER_CACHE_TTL', 300), // 5 minutes

        // Sync user data from aMember
        'sync_user_data' => env('AMEMBER_SYNC_USER_DATA', true),

        // Fields to sync from aMember to local user
        'syncable_fields' => [
            'email',
            'name_f',
            'name_l',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | User Creation
    |--------------------------------------------------------------------------
    |
    | Configure automatic user creation from webhooks.
    |
    */
    'user_creation' => [
        // Automatically create users from webhooks
        'enabled' => env('AMEMBER_USER_CREATION_ENABLED', true),

        // Default password for created users ('random' = generate random password)
        'default_password' => env('AMEMBER_USER_DEFAULT_PASSWORD', 'random'),

        // Send email verification
        'send_verification' => env('AMEMBER_USER_SEND_VERIFICATION', false),

        // Fields to sync when creating user
        'syncable_fields' => [
            'email',
            'name_f',
            'name_l',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Database Tables
    |--------------------------------------------------------------------------
    |
    | Customize the database table names used by the package.
    |
    */
    'tables' => [
        'installations' => 'amember_installations',
        'subscriptions' => 'amember_subscriptions',
        'products' => 'amember_products',
        'webhook_logs' => 'amember_webhook_logs',
    ],

    /*
    |--------------------------------------------------------------------------
    | Models
    |--------------------------------------------------------------------------
    |
    | Customize the model classes used by the package.
    |
    */
    'models' => [
        'installation' => \Greatplr\AmemberSso\Models\AmemberInstallation::class,
        'subscription' => \Greatplr\AmemberSso\Models\AmemberSubscription::class,
    ],

    /*
    |--------------------------------------------------------------------------
    | Logging
    |--------------------------------------------------------------------------
    |
    | Configure logging for SSO and webhook events.
    |
    */
    'logging' => [
        'enabled' => env('AMEMBER_LOGGING_ENABLED', true),
        'channel' => env('AMEMBER_LOG_CHANNEL', 'stack'),

        // Debug mode - logs verbose webhook details to Laravel log
        'debug_webhooks' => env('AMEMBER_DEBUG_WEBHOOKS', false),
    ],
];
