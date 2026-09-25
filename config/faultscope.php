<?php

use FaultScope\Laravel\Support\ConfigHelpers;
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

    'dsn' => env('FAULTSCOPE_DSN'),

    'key' => env('FAULTSCOPE_KEY'),

    'enabled' => ConfigHelpers::bool(env('FAULTSCOPE_ENABLED'), true),

    'sample_rate' => (float) env('FAULTSCOPE_SAMPLE_RATE', 1.0),

    'ignored_exceptions' => ConfigHelpers::pipeList(
        env('FAULTSCOPE_IGNORED_EXCEPTIONS'),
        $defaultIgnored
    ),

    'send_default_pii' => ConfigHelpers::bool(env('FAULTSCOPE_SEND_PII'), false),

    'release' => env('FAULTSCOPE_RELEASE'),

    'offline_queue' => ConfigHelpers::bool(env('FAULTSCOPE_OFFLINE_QUEUE'), true),

    'geo_ip_enabled' => ConfigHelpers::bool(env('GEO_IP_ENABLED'), false),

    'telemetry' => ConfigHelpers::bool(env('FAULTSCOPE_TELEMETRY'), false),

    'slow_request_ms' => (float) env('FAULTSCOPE_SLOW_REQUEST_MS', 1000),

    'slow_query_ms' => (float) env('FAULTSCOPE_SLOW_QUERY_MS', 250),

    'retention_days' => (int) env('FAULTSCOPE_RETENTION_DAYS', 30),

    'before_send' => null,

    'code_context' => [
        'enabled' => ConfigHelpers::bool(env('FAULTSCOPE_CODE_CONTEXT'), true),
        'padding' => (int) env('FAULTSCOPE_CODE_CONTEXT_PADDING', 15),
    ],

    'local_variables' => [
        'enabled' => ConfigHelpers::bool(env('FAULTSCOPE_LOCAL_VARIABLES'), true),
        'max_depth' => (int) env('FAULTSCOPE_LOCAL_VARIABLES_DEPTH', 3),
        'max_array_items' => (int) env('FAULTSCOPE_LOCAL_VARIABLES_ARRAY_LIMIT', 10),
    ],

    'breadcrumbs' => [
        'enabled' => ConfigHelpers::bool(env('FAULTSCOPE_BREADCRUMBS'), true),
        'sql' => ConfigHelpers::bool(env('FAULTSCOPE_BREADCRUMBS_SQL'), true),
        'logs' => ConfigHelpers::bool(env('FAULTSCOPE_BREADCRUMBS_LOGS'), true),
        'cache' => ConfigHelpers::bool(env('FAULTSCOPE_BREADCRUMBS_CACHE'), true),
        'http' => ConfigHelpers::bool(env('FAULTSCOPE_BREADCRUMBS_HTTP'), true),
        'limit' => (int) env('FAULTSCOPE_BREADCRUMBS_LIMIT', 100),
    ],

    'security' => [
        'mask_default_pii' => ConfigHelpers::bool(env('FAULTSCOPE_MASK_PII'), true),
        'blacklist' => ConfigHelpers::pipeList(
            env('FAULTSCOPE_SECURITY_BLACKLIST'),
            $defaultSecurityBlacklist
        ),
        'whitelist' => ConfigHelpers::pipeList(env('FAULTSCOPE_SECURITY_WHITELIST'), []),
    ],

    'tracing' => [
        'enabled' => ConfigHelpers::bool(env('FAULTSCOPE_TRACING'), true),
    ],

    'logs' => [
        'ship' => ConfigHelpers::bool(env('FAULTSCOPE_SHIP_LOGS'), false),
    ],

    'javascript' => [
        'enabled' => ConfigHelpers::bool(env('FAULTSCOPE_JS_ENABLED'), true),
        'script_url' => env('FAULTSCOPE_JS_URL'),
        'auto_inject' => ConfigHelpers::bool(env('FAULTSCOPE_JS_AUTO_INJECT'), false),
        'tracing' => ConfigHelpers::bool(env('FAULTSCOPE_JS_TRACING'), false),
        'replay' => ConfigHelpers::bool(env('FAULTSCOPE_JS_REPLAY'), false),
        'replay_sample_rate' => (float) env('FAULTSCOPE_JS_REPLAY_SAMPLE_RATE', 0.1),
    ],

];

