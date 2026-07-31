<?php

use Skywatch\Laravel\Support\ConfigHelpers;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\MethodNotAllowedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

$defaultIgnored = [
    NotFoundHttpException::class,
    ValidationException::class,
    AuthenticationException::class,
    MethodNotAllowedHttpException::class,
];

$defaultSecurityBlacklist = [
    'password', 'password_confirmation', 'token', 'key', 'secret',
    'authorization', 'cookie', 'xsrf-token', 'xsrf_token', 'api_key', 'apikey',
    'laravel_session', 'remember_web', 'session',
];

return [

    'dsn' => env('SKYWATCH_DSN', env('EXCEPTION_TRACKER_DSN')),

    'key' => env('SKYWATCH_KEY', env('EXCEPTION_TRACKER_KEY')),

    'enabled' => ConfigHelpers::bool(env('SKYWATCH_ENABLED', env('EXCEPTION_TRACKER_ENABLED')), true),

    'sample_rate' => (float) env('SKYWATCH_SAMPLE_RATE', env('EXCEPTION_TRACKER_SAMPLE_RATE', 1.0)),

    'ignored_exceptions' => ConfigHelpers::pipeList(
        env('SKYWATCH_IGNORED_EXCEPTIONS', env('EXCEPTION_TRACKER_IGNORED_EXCEPTIONS')),
        $defaultIgnored
    ),

    'send_default_pii' => ConfigHelpers::bool(env('SKYWATCH_SEND_PII', env('EXCEPTION_TRACKER_SEND_PII')), false),

    'release' => env('SKYWATCH_RELEASE', env('EXCEPTION_TRACKER_RELEASE')),

    'offline_queue' => ConfigHelpers::bool(env('SKYWATCH_OFFLINE_QUEUE', env('EXCEPTION_TRACKER_OFFLINE_QUEUE')), true),

    'geo_ip_enabled' => ConfigHelpers::bool(env('GEO_IP_ENABLED'), false),

    'telemetry' => ConfigHelpers::bool(env('SKYWATCH_TELEMETRY', env('EXCEPTION_TRACKER_TELEMETRY')), false),

    'slow_request_ms' => (float) env('SKYWATCH_SLOW_REQUEST_MS', env('EXCEPTION_TRACKER_SLOW_REQUEST_MS', 1000)),

    'slow_query_ms' => (float) env('SKYWATCH_SLOW_QUERY_MS', env('EXCEPTION_TRACKER_SLOW_QUERY_MS', 250)),

    'retention_days' => (int) env('SKYWATCH_RETENTION_DAYS', env('EXCEPTION_TRACKER_RETENTION_DAYS', 30)),

    'before_send' => null,

    'code_context' => [
        'enabled' => ConfigHelpers::bool(env('SKYWATCH_CODE_CONTEXT', env('EXCEPTION_TRACKER_CODE_CONTEXT')), true),
        'padding' => (int) env('SKYWATCH_CODE_CONTEXT_PADDING', env('EXCEPTION_TRACKER_CODE_CONTEXT_PADDING', 15)),
    ],

    'local_variables' => [
        'enabled' => ConfigHelpers::bool(env('SKYWATCH_LOCAL_VARIABLES', env('EXCEPTION_TRACKER_LOCAL_VARIABLES')), true),
        'max_depth' => (int) env('SKYWATCH_LOCAL_VARIABLES_DEPTH', env('EXCEPTION_TRACKER_LOCAL_VARIABLES_DEPTH', 3)),
        'max_array_items' => (int) env('SKYWATCH_LOCAL_VARIABLES_ARRAY_LIMIT', env('EXCEPTION_TRACKER_LOCAL_VARIABLES_ARRAY_LIMIT', 10)),
    ],

    'breadcrumbs' => [
        'enabled' => ConfigHelpers::bool(env('SKYWATCH_BREADCRUMBS', env('EXCEPTION_TRACKER_BREADCRUMBS')), true),
        'sql' => ConfigHelpers::bool(env('SKYWATCH_BREADCRUMBS_SQL', env('EXCEPTION_TRACKER_BREADCRUMBS_SQL')), true),
        'logs' => ConfigHelpers::bool(env('SKYWATCH_BREADCRUMBS_LOGS', env('EXCEPTION_TRACKER_BREADCRUMBS_LOGS')), true),
        'cache' => ConfigHelpers::bool(env('SKYWATCH_BREADCRUMBS_CACHE', env('EXCEPTION_TRACKER_BREADCRUMBS_CACHE')), true),
        'http' => ConfigHelpers::bool(env('SKYWATCH_BREADCRUMBS_HTTP', env('EXCEPTION_TRACKER_BREADCRUMBS_HTTP')), true),
        'limit' => (int) env('SKYWATCH_BREADCRUMBS_LIMIT', env('EXCEPTION_TRACKER_BREADCRUMBS_LIMIT', 100)),
    ],

    'security' => [
        'mask_default_pii' => ConfigHelpers::bool(env('SKYWATCH_MASK_PII', env('EXCEPTION_TRACKER_MASK_PII')), true),
        'blacklist' => ConfigHelpers::pipeList(
            env('SKYWATCH_SECURITY_BLACKLIST', env('EXCEPTION_TRACKER_SECURITY_BLACKLIST')),
            $defaultSecurityBlacklist
        ),
        'whitelist' => ConfigHelpers::pipeList(env('SKYWATCH_SECURITY_WHITELIST', env('EXCEPTION_TRACKER_SECURITY_WHITELIST')), []),
    ],

    'tracing' => [
        'enabled' => ConfigHelpers::bool(env('SKYWATCH_TRACING', env('EXCEPTION_TRACKER_TRACING')), true),
    ],

    'logs' => [
        'ship' => ConfigHelpers::bool(env('SKYWATCH_SHIP_LOGS', env('EXCEPTION_TRACKER_SHIP_LOGS')), false),
    ],

];
