<?php

namespace Skywatch\Laravel;

use GuzzleHttp\Client;
use ReflectionFunction;
use ReflectionMethod;
use SplFileObject;
use Throwable;

class SkywatchClient
{
    public const SDK_VERSION = '1.3.0';

    protected ?string $dsn;

    protected ?string $key;

    protected bool $enabled;

    protected Client $client;

    public static array $breadcrumbs = [];

    public static array $userContext = [];

    /** @var array<string, mixed>|null Active queue job when exception occurs in a worker */
    public static ?array $currentJob = null;

    public function __construct()
    {
        $this->dsn = config('skywatch.dsn') ?: env('SKYWATCH_DSN', env('EXCEPTION_TRACKER_DSN'));
        $this->key = config('skywatch.key') ?: env('SKYWATCH_KEY', env('EXCEPTION_TRACKER_KEY'));
        $this->enabled = (bool) (config('skywatch.enabled') ?? env('SKYWATCH_ENABLED', env('EXCEPTION_TRACKER_ENABLED', true)));

        $this->client = new Client([
            'timeout' => 3.0,
            'headers' => [
                'Authorization' => 'Bearer '.$this->key,
                'Content-Type' => 'application/json',
                'Accept' => 'application/json',
                'X-SDK-Version' => 'laravel/'.self::SDK_VERSION,
            ],
        ]);
    }

    /**
     * Record a breadcrumb in memory.
     */
    public static function recordBreadcrumb(string $category, string $message, string $level = 'info', array $metadata = []): void
    {
        $limit = config('skywatch.breadcrumbs.limit', 100);
        self::$breadcrumbs[] = [
            'category' => $category,
            'message' => $message,
            'level' => $level,
            'timestamp' => microtime(true),
            'metadata' => $metadata,
        ];

        if (count(self::$breadcrumbs) > $limit) {
            array_shift(self::$breadcrumbs);
        }
    }

    /**
     * Set explicit user context (merged into the user block on capture).
     */
    public static function setUser(array $data): void
    {
        self::$userContext = $data;
    }

    /**
     * Capture and send a throwable with full diagnostic context to the hub.
     */
    public function capture(Throwable $e): void
    {
        if (! $this->enabled || ! $this->dsn || ! $this->key) {
            return;
        }

        foreach (config('skywatch.ignored_exceptions', []) as $ignoredClass) {
            if ($e instanceof $ignoredClass) {
                return;
            }
        }

        $sampleRate = (float) config('skywatch.sample_rate', 1.0);
        if ($sampleRate < 1.0 && (mt_rand() / mt_getrandmax()) > $sampleRate) {
            return;
        }

        $payload = null;
        try {
            $stackFrames = $this->collectStackFrames($e);
            $isConsole = app()->runningInConsole();
            $severity = $this->resolveSeverity($e);

            $payload = [
                'type' => 'exception',
                'message' => $e->getMessage(),
                'exception_class' => get_class($e),
                'exception_code' => $e->getCode(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
                'stack_trace' => $e->getTraceAsString(),
                'severity' => $severity,
                'response_status' => $this->resolveResponseStatus($e),
                'environment' => app()->environment(),
                'context_type' => $this->resolveContextType($isConsole),
                'url' => $isConsole ? 'artisan' : $this->safeRequest(fn () => request()->fullUrl(), 'artisan'),
                'method' => $isConsole ? 'CLI' : $this->safeRequest(fn () => request()->method(), 'CLI'),
                'ip_address' => $this->resolveIpAddress($isConsole),
                'user_agent' => $isConsole ? 'CLI' : $this->safeRequest(fn () => request()->userAgent(), 'CLI'),
                'timestamp' => microtime(true),
                'release' => config('skywatch.release') ?: $this->getGitCommit(),
                'git_commit' => $this->getGitCommit(),
                'git_branch' => $this->getGitBranch(),
                'sdk_version' => 'laravel/'.self::SDK_VERSION,
                'request_id' => $this->resolveRequestId($isConsole),
                'session_id' => $this->resolveSessionId($isConsole),
                'user' => $this->buildUserContext(),
                'stack_frames' => $stackFrames,
                'culprit' => $this->resolveCulprit($stackFrames),
                'frame_variables' => $this->collectLocalVariables($e),
                'breadcrumbs' => self::$breadcrumbs,
                'queries' => $this->extractSqlQueriesFromBreadcrumbs(),
                'cache_events' => $this->extractCacheEventsFromBreadcrumbs(),
                'request' => $this->collectRequestDetails(),
                'files' => $this->collectUploadedFiles(),
                'auth' => $this->collectAuthDetails(),
                'session' => $this->collectSessionDetails(),
                'application' => $this->collectApplicationDetails(),
                'server' => $this->collectServerDetails(),
                'runtime' => $this->collectRuntimeContext($isConsole),
                'drivers' => $this->collectDrivers(),
                'php_runtime' => $this->collectPhpRuntime(),
                'database' => $this->collectDatabaseSummary(),
                'cache' => $this->collectCacheSummary(),
                'previous_exception' => $this->collectPreviousException($e),
                'exception_chain' => $this->collectExceptionChain($e),
                'performance_profile' => $this->collectPerformanceProfile(),
                'tags' => $this->collectTags(),
            ];

            $payload = $this->sanitizePayload($payload);

            $beforeSend = config('skywatch.before_send');
            if (is_callable($beforeSend)) {
                $payload = $beforeSend($payload);
                if ($payload === null) {
                    return;
                }
            }

            $this->client->post($this->dsn, [
                'json' => $payload,
            ]);
        } catch (Throwable $err) {
            if ($payload && config('skywatch.offline_queue', true)) {
                $this->storeOffline($payload);
            }
        }
    }

    protected function safeRequest(callable $callback, mixed $default = null): mixed
    {
        try {
            return $callback();
        } catch (Throwable $e) {
            return $default;
        }
    }

    protected function resolveIpAddress(bool $isConsole): string
    {
        if (! config('skywatch.send_default_pii', false)) {
            return '[REDACTED]';
        }

        if ($isConsole) {
            return '127.0.0.1';
        }

        return $this->safeRequest(fn () => request()->ip() ?? '[REDACTED]', '[REDACTED]');
    }

    protected function buildUserContext(): array
    {
        $user = array_merge([
            'id' => $this->safeRequest(fn () => auth()->id()),
        ], self::$userContext);

        if (! config('skywatch.send_default_pii', false)) {
            unset($user['email'], $user['name'], $user['username']);
        }

        return array_filter($user, fn ($value) => $value !== null && $value !== '');
    }

    protected function resolveContextType(bool $isConsole): string
    {
        if (self::$currentJob !== null) {
            return 'queue';
        }

        return $isConsole ? 'console' : 'web';
    }

    protected function resolveSeverity(Throwable $e): string
    {
        if ($e instanceof \Symfony\Component\HttpKernel\Exception\HttpExceptionInterface) {
            $status = $e->getStatusCode();
            if ($status >= 500) {
                return 'error';
            }
            if ($status >= 400) {
                return 'warning';
            }
        }

        if ($e instanceof \Error) {
            return 'critical';
        }

        return 'error';
    }

    protected function resolveResponseStatus(Throwable $e): ?int
    {
        if ($e instanceof \Symfony\Component\HttpKernel\Exception\HttpExceptionInterface) {
            return $e->getStatusCode();
        }

        return null;
    }

    protected function resolveRequestId(bool $isConsole): ?string
    {
        if ($isConsole) {
            return null;
        }

        return $this->safeRequest(function () {
            $request = request();

            return $request->header('X-Request-Id')
                ?? $request->header('X-Correlation-Id')
                ?? $request->header('X-Trace-Id');
        });
    }

    protected function resolveSessionId(bool $isConsole): ?string
    {
        if ($isConsole) {
            return null;
        }

        return $this->safeRequest(fn () => session()->getId());
    }

    protected function resolveCulprit(array $stackFrames): ?array
    {
        foreach ($stackFrames as $frame) {
            if (! ($frame['is_vendor'] ?? false)) {
                return $frame;
            }
        }

        return $stackFrames[0] ?? null;
    }

    protected function collectExceptionChain(Throwable $e): array
    {
        $chain = [];
        $current = $e->getPrevious();
        $depth = 0;

        while ($current && $depth < 10) {
            $chain[] = [
                'class' => get_class($current),
                'message' => $current->getMessage(),
                'file' => $current->getFile(),
                'line' => $current->getLine(),
                'code' => $current->getCode(),
            ];
            $current = $current->getPrevious();
            $depth++;
        }

        return $chain;
    }

    protected function collectPerformanceProfile(): array
    {
        $executionMs = defined('LARAVEL_START')
            ? round((microtime(true) - LARAVEL_START) * 1000, 2)
            : null;

        return array_filter([
            'memory_usage_mb' => round(memory_get_usage(true) / 1024 / 1024, 2),
            'peak_memory_mb' => round(memory_get_peak_usage(true) / 1024 / 1024, 2),
            'execution_time_ms' => $executionMs,
        ], fn ($v) => $v !== null);
    }

    protected function collectRuntimeContext(bool $isConsole): array
    {
        $runtime = [];

        if (self::$currentJob !== null) {
            $runtime['queue'] = self::$currentJob;
            $runtime['context'] = 'queue_worker';
        }

        if ($isConsole) {
            $argv = $_SERVER['argv'] ?? [];
            $runtime['context'] = $runtime['context'] ?? 'console';
            $runtime['command'] = implode(' ', array_slice($argv, 0, 4));
            $runtime['argv'] = array_slice($argv, 1, 8);
        }

        return $runtime;
    }

    protected function collectDrivers(): array
    {
        return array_filter([
            'database' => config('database.default'),
            'cache' => config('cache.default'),
            'queue' => config('queue.default'),
            'session' => config('session.driver'),
            'mail' => config('mail.default'),
            'broadcast' => config('broadcasting.default'),
            'filesystem' => config('filesystems.default'),
        ]);
    }

    protected function collectPhpRuntime(): array
    {
        return [
            'memory_limit' => ini_get('memory_limit') ?: null,
            'max_execution_time' => ini_get('max_execution_time') ?: null,
            'upload_max_filesize' => ini_get('upload_max_filesize') ?: null,
            'post_max_size' => ini_get('post_max_size') ?: null,
            'display_errors' => ini_get('display_errors') ?: null,
            'opcache_enabled' => function_exists('opcache_get_status') ? (bool) @opcache_get_status(false) : null,
        ];
    }

    protected function collectUploadedFiles(): array
    {
        if (app()->runningInConsole()) {
            return [];
        }

        return $this->safeRequest(function () {
            $files = request()->allFiles();
            if (empty($files)) {
                return [];
            }

            return $this->summarizeFiles($files);
        }, []);
    }

    protected function summarizeFiles(array $files, string $prefix = ''): array
    {
        $summary = [];
        foreach ($files as $key => $file) {
            $path = $prefix !== '' ? "{$prefix}.{$key}" : $key;
            if (is_array($file)) {
                $summary = array_merge($summary, $this->summarizeFiles($file, $path));
                continue;
            }
            if (! is_object($file)) {
                continue;
            }
            $summary[$path] = array_filter([
                'name' => method_exists($file, 'getClientOriginalName') ? $file->getClientOriginalName() : null,
                'size' => method_exists($file, 'getSize') ? $file->getSize() : null,
                'mime' => method_exists($file, 'getMimeType') ? $file->getMimeType() : null,
                'extension' => method_exists($file, 'getClientOriginalExtension') ? $file->getClientOriginalExtension() : null,
            ]);
        }

        return $summary;
    }

    protected function extractCacheEventsFromBreadcrumbs(): array
    {
        $events = [];
        foreach (self::$breadcrumbs as $crumb) {
            if (($crumb['category'] ?? '') !== 'cache') {
                continue;
            }
            $message = $crumb['message'] ?? '';
            $operation = 'unknown';
            if (str_starts_with($message, 'Cache hit')) {
                $operation = 'hit';
            } elseif (str_starts_with($message, 'Cache miss')) {
                $operation = 'miss';
            } elseif (str_starts_with($message, 'Cache key written')) {
                $operation = 'write';
            } elseif (str_starts_with($message, 'Cache key forgotten')) {
                $operation = 'forget';
            }
            $events[] = [
                'operation' => $operation,
                'key' => $crumb['metadata']['key'] ?? null,
                'message' => $message,
                'timestamp' => $crumb['timestamp'] ?? null,
            ];
        }

        return $events;
    }

    protected function collectTags(): array
    {
        return array_filter([
            'env' => app()->environment(),
            'php' => PHP_VERSION,
            'laravel' => app()->version(),
            'release' => config('skywatch.release') ?: $this->getGitCommit(),
            'sdk' => 'laravel/'.self::SDK_VERSION,
        ]);
    }

    protected function collectPreviousException(Throwable $e): ?array
    {
        $previous = $e->getPrevious();
        if (! $previous) {
            return null;
        }

        return [
            'class' => get_class($previous),
            'message' => $previous->getMessage(),
            'file' => $previous->getFile(),
            'line' => $previous->getLine(),
        ];
    }

    protected function extractSqlQueriesFromBreadcrumbs(): array
    {
        $queries = [];
        foreach (self::$breadcrumbs as $crumb) {
            $category = $crumb['category'] ?? '';
            if (! in_array($category, ['sql', 'query'], true)) {
                continue;
            }
            $queries[] = [
                'sql' => $crumb['message'] ?? '',
                'time_ms' => $crumb['metadata']['time_ms'] ?? $crumb['time_ms'] ?? null,
                'connection' => $crumb['metadata']['connection'] ?? null,
            ];
        }

        return $queries;
    }

    /**
     * Store payload to a local offline queue.
     */
    protected function storeOffline(array $payload): void
    {
        try {
            $path = storage_path('skywatch/offline');
            if (! is_dir($path)) {
                mkdir($path, 0755, true);
            }
            $filename = $path.'/'.microtime(true).'-'.uniqid().'.json';
            file_put_contents($filename, json_encode($payload));
        } catch (Throwable $e) {
        }
    }

    protected function isVendorPath(string $file): bool
    {
        $normalized = str_replace('\\', '/', $file);

        return str_contains($normalized, '/vendor/');
    }

    protected function relativePath(string $file): string
    {
        $base = str_replace('\\', '/', base_path()).'/';
        $normalized = str_replace('\\', '/', $file);

        return str_starts_with($normalized, $base)
            ? substr($normalized, strlen($base))
            : $normalized;
    }

    /**
     * Parse and build detailed stack frame items.
     */
    protected function collectStackFrames(Throwable $e): array
    {
        $frames = [];
        $rawTrace = $e->getTrace();

        array_unshift($rawTrace, [
            'file' => $e->getFile(),
            'line' => $e->getLine(),
            'class' => null,
            'function' => null,
            'type' => null,
        ]);

        foreach ($rawTrace as $frame) {
            if (! isset($frame['file']) || ! file_exists($frame['file'])) {
                continue;
            }

            $frames[] = [
                'file' => $frame['file'],
                'relative_path' => $this->relativePath($frame['file']),
                'line' => $frame['line'] ?? 0,
                'class' => $frame['class'] ?? null,
                'function' => $frame['function'] ?? null,
                'type' => $frame['type'] ?? null,
                'is_vendor' => $this->isVendorPath($frame['file']),
                'code_snippet' => config('skywatch.code_context.enabled', true)
                    ? $this->getCodeSnippet($frame['file'], $frame['line'] ?? 0)
                    : [],
            ];
        }

        return $frames;
    }

    protected function getCodeSnippet(string $file, int $line): array
    {
        $snippet = [];
        if ($line <= 0) {
            return $snippet;
        }

        try {
            $padding = config('skywatch.code_context.padding', 15);
            $fileObj = new SplFileObject($file);
            $start = max(1, $line - $padding);
            $end = $line + $padding;

            $fileObj->seek($start - 1);
            $lineNum = $start;

            while ($lineNum <= $end && ! $fileObj->eof()) {
                $snippet[$lineNum] = rtrim($fileObj->current());
                $fileObj->next();
                $lineNum++;
            }
        } catch (Throwable $e) {
        }

        return $snippet;
    }

    /**
     * Inspect variables and arguments inside trace frames (indexed to match stack_frames).
     */
    protected function collectLocalVariables(Throwable $e): array
    {
        if (! config('skywatch.local_variables.enabled', true)) {
            return [];
        }

        $variableMap = [];
        $frames = $e->getTrace();
        $depthLimit = config('skywatch.local_variables.max_depth', 3);
        $framesToInspect = array_slice($frames, 0, $depthLimit);

        // stack_frames[0] is the crash site; trace frame args align from index 1 onward.
        foreach ($framesToInspect as $index => $frame) {
            $stackIndex = $index + 1;
            $variables = [];
            if (isset($frame['args'])) {
                $paramNames = $this->getParameterNames($frame);
                foreach ($frame['args'] as $argIndex => $argVal) {
                    $name = $paramNames[$argIndex] ?? "param_{$argIndex}";
                    $variables[$name] = $this->exportVariable($argVal);
                }
            }
            if ($variables !== []) {
                $variableMap[$stackIndex] = $variables;
            }
        }

        return $variableMap;
    }

    protected function getParameterNames(array $frame): array
    {
        try {
            if (isset($frame['class'])) {
                $reflector = new ReflectionMethod($frame['class'], $frame['function']);
            } elseif (isset($frame['function'])) {
                $reflector = new ReflectionFunction($frame['function']);
            } else {
                return [];
            }

            return array_map(fn ($param) => $param->getName(), $reflector->getParameters());
        } catch (Throwable $e) {
            return [];
        }
    }

    protected function exportVariable($value, int $depth = 0): array
    {
        $maxDepth = config('skywatch.local_variables.max_depth', 3);
        if ($depth > $maxDepth) {
            return ['type' => 'recursion', 'value' => '[MAX DEPTH REACHED]'];
        }

        if (is_null($value)) {
            return ['type' => 'null', 'value' => null];
        }

        if (is_bool($value)) {
            return ['type' => 'boolean', 'value' => $value];
        }

        if (is_numeric($value)) {
            return ['type' => 'numeric', 'value' => $value];
        }

        if (is_string($value)) {
            return ['type' => 'string', 'value' => mb_strimwidth($value, 0, 100, '...')];
        }

        if (is_array($value)) {
            $formatted = [];
            $count = count($value);
            $limit = config('skywatch.local_variables.max_array_items', 10);
            $index = 0;
            foreach ($value as $k => $v) {
                if ($index++ >= $limit) {
                    $formatted['_extra_items_'] = ($count - $limit).' more items hidden';
                    break;
                }
                $formatted[$k] = $this->exportVariable($v, $depth + 1);
            }

            return ['type' => 'array', 'count' => $count, 'value' => $formatted];
        }

        if (is_object($value)) {
            return [
                'type' => 'object',
                'class' => get_class($value),
                'properties' => method_exists($value, 'toArray') ? '[Object Details Available]' : '[Inspect Class Properties]',
            ];
        }

        return ['type' => 'unknown', 'value' => '[Unserializable]'];
    }

    protected function collectRequestDetails(): array
    {
        if (app()->runningInConsole()) {
            return [];
        }

        $request = request();
        $route = $request->route();

        return [
            'method' => $request->method(),
            'url' => $request->url(),
            'full_url' => $request->fullUrl(),
            'host' => $request->getHost(),
            'scheme' => $request->getScheme(),
            'port' => $request->getPort(),
            'protocol' => $request->getProtocolVersion(),
            'route_name' => $route ? $route->getName() : null,
            'route_uri' => $route ? ($route->uri() ?? null) : null,
            'route_action' => $route ? ($route->getActionName() ?: null) : null,
            'route_parameters' => $route ? $route->parameters() : [],
            'controller' => $route ? (is_string($route->getAction('controller')) ? $route->getAction('controller') : 'Closure') : null,
            'middleware' => $route ? array_values($route->gatherMiddleware()) : [],
            'headers' => $request->headers->all(),
            'query_params' => $request->query(),
            'body' => $request->except(['password', 'password_confirmation', '_token']),
            'cookies' => $request->cookies->all(),
            'content_type' => $request->header('Content-Type'),
            'content_length' => $request->header('Content-Length'),
            'referer' => $request->header('referer'),
            'accept' => $request->header('Accept'),
            'locale' => $request->getLocale(),
            'is_xhr' => $request->ajax(),
            'is_json' => $request->expectsJson(),
            'is_secure' => $request->isSecure(),
            'forwarded_for' => $request->header('X-Forwarded-For'),
            'request_id' => $request->header('X-Request-Id') ?? $request->header('X-Correlation-Id'),
        ];
    }

    protected function collectAuthDetails(): array
    {
        $auth = [];
        try {
            if (app()->bound('auth') && auth()->check()) {
                $user = auth()->user();
                $auth = [
                    'id' => $user->getKey(),
                    'email' => config('skywatch.send_default_pii', false) ? ($user->email ?? null) : '[REDACTED]',
                    'guard' => auth()->getDefaultDriver(),
                ];
            }
        } catch (Throwable $e) {
        }

        return $auth;
    }

    protected function collectSessionDetails(): array
    {
        if (app()->runningInConsole()) {
            return [];
        }

        $session = [];
        try {
            if (app()->bound('session') && session()->isStarted()) {
                $session = [
                    'id' => session()->getId(),
                    'all' => session()->all(),
                ];
            }
        } catch (Throwable $e) {
        }

        return $session;
    }

    protected function collectApplicationDetails(): array
    {
        return [
            'laravel_version' => app()->version(),
            'php_version' => PHP_VERSION,
            'app_name' => config('app.name'),
            'timezone' => config('app.timezone'),
            'locale' => config('app.locale'),
            'debug_mode' => config('app.debug'),
            'sdk_version' => 'laravel/'.self::SDK_VERSION,
        ];
    }

    protected function collectServerDetails(): array
    {
        return [
            'hostname' => gethostname() ?: 'unknown',
            'os' => PHP_OS,
            'php_sapi' => PHP_SAPI,
            'memory_usage' => round(memory_get_usage(true) / 1024 / 1024, 2).' MB',
            'peak_memory' => round(memory_get_peak_usage(true) / 1024 / 1024, 2).' MB',
        ];
    }

    protected function collectDatabaseSummary(): array
    {
        $queries = array_filter(self::$breadcrumbs, fn ($b) => in_array($b['category'] ?? '', ['sql', 'query'], true));

        return [
            'query_count' => count($queries),
            'default_connection' => config('database.default'),
        ];
    }

    protected function collectCacheSummary(): array
    {
        $cacheBreadcrumbs = array_filter(self::$breadcrumbs, fn ($b) => ($b['category'] ?? '') === 'cache');

        return [
            'cache_operation_count' => count($cacheBreadcrumbs),
        ];
    }

    protected function sanitizePayload(array $payload): array
    {
        $blacklist = config('skywatch.security.blacklist', [
            'password', 'password_confirmation', 'token', 'key', 'secret',
            'authorization', 'cookie', 'xsrf-token', 'xsrf_token', 'api_key', 'apikey',
            'laravel_session', 'remember_web', 'session',
        ]);

        return $this->maskRecursive($payload, $blacklist);
    }

    protected function maskRecursive(array $data, array $blacklist): array
    {
        foreach ($data as $key => $value) {
            if (is_array($value)) {
                $data[$key] = $this->maskRecursive($value, $blacklist);
            } else {
                foreach ($blacklist as $blockedKey) {
                    if (stripos((string) $key, $blockedKey) !== false) {
                        $data[$key] = '********';
                        break;
                    }
                }
            }
        }

        return $data;
    }

    protected function getGitCommit(): ?string
    {
        return $this->runGitCommand('rev-parse --short HEAD');
    }

    protected function getGitBranch(): ?string
    {
        return $this->runGitCommand('rev-parse --abbrev-ref HEAD');
    }

    /**
     * Run a git subcommand safely (Windows + Unix) with in-process caching.
     */
    protected function runGitCommand(string $args): ?string
    {
        static $cache = [];

        if (array_key_exists($args, $cache)) {
            return $cache[$args];
        }

        if (! function_exists('shell_exec')) {
            return $cache[$args] = null;
        }

        $gitPath = base_path('.git');
        if (! is_dir($gitPath) && ! is_file($gitPath)) {
            return $cache[$args] = null;
        }

        $nullDevice = PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null';
        $output = @shell_exec('git '.$args.' 2>'.$nullDevice);

        if (! is_string($output)) {
            return $cache[$args] = null;
        }

        $trimmed = trim($output);

        return $cache[$args] = ($trimmed !== '' ? $trimmed : null);
    }

    /**
     * Flush collected breadcrumbs as spans at end of request (distributed tracing).
     */
    public function flushSpans(string $traceId, float $rootDurationMs, $request, int $statusCode): void
    {
        if (! $this->enabled || ! $this->dsn || ! $this->key) {
            return;
        }

        if (! config('skywatch.tracing.enabled', true)) {
            return;
        }

        $spans = [[
            'trace_id' => $traceId,
            'span_id' => 'root',
            'parent_span_id' => '',
            'operation_name' => $request->method().' '.$request->path(),
            'service_name' => config('app.name', 'laravel'),
            'duration_ms' => round($rootDurationMs, 3),
            'status' => $statusCode >= 500 ? 'error' : 'ok',
            'tags' => ['http.status_code' => $statusCode],
        ]];

        foreach (self::$breadcrumbs as $index => $crumb) {
            if (($crumb['category'] ?? '') === 'sql' || ($crumb['category'] ?? '') === 'http') {
                $spans[] = [
                    'trace_id' => $traceId,
                    'span_id' => 'crumb_'.$index,
                    'parent_span_id' => 'root',
                    'operation_name' => $crumb['category'].': '.substr($crumb['message'] ?? '', 0, 120),
                    'service_name' => config('app.name', 'laravel'),
                    'duration_ms' => (float) ($crumb['metadata']['time_ms'] ?? 0),
                    'status' => ($crumb['level'] ?? 'info') === 'error' ? 'error' : 'ok',
                    'tags' => ['category' => $crumb['category'] ?? ''],
                ];
            }
        }

        try {
            $ingestUrl = preg_replace('#/api/ingest.*$#', '/api/ingest/spans', rtrim($this->dsn, '/'));
            if (! str_contains($ingestUrl, '/api/ingest/spans')) {
                $ingestUrl = rtrim($this->dsn, '/').'/spans';
            }

            $this->client->post($ingestUrl, [
                'json' => ['spans' => $spans],
            ]);
        } catch (\Throwable) {
            // Swallow — tracing must not affect app stability
        }
    }

    public function shipLog(string $level, string $message, array $context = []): void
    {
        if (! $this->enabled || ! $this->dsn || ! $this->key) {
            return;
        }

        try {
            $ingestUrl = preg_replace('#/api/ingest.*$#', '/api/ingest/logs', rtrim($this->dsn, '/'));
            if (! str_contains($ingestUrl, '/api/ingest/logs')) {
                $ingestUrl = rtrim($this->dsn, '/').'/logs';
            }

            $this->client->post($ingestUrl, [
                'json' => [
                    'entries' => [[
                        'level' => $level,
                        'message' => $message,
                        'context' => $context,
                        'logged_at' => now()->toIso8601String(),
                    ]],
                ],
            ]);
        } catch (\Throwable) {
        }
    }
}
