<?php

namespace Skywatch\Laravel;

use Skywatch\Laravel\Commands\FlushOfflineCommand;
use Skywatch\Laravel\Commands\TestSkywatchCommand;
use Illuminate\Cache\Events\CacheHit;
use Illuminate\Cache\Events\CacheMiss;
use Illuminate\Cache\Events\KeyForgotten;
use Illuminate\Cache\Events\KeyWritten;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Http\Client\Events\ConnectionFailed;
use Illuminate\Http\Client\Events\ResponseReceived;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;
use Throwable;

class SkywatchServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->mergeConfigFrom(
            __DIR__.'/../config/skywatch.php', 'skywatch'
        );

        $this->app->singleton('skywatch', function () {
            return new SkywatchClient;
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Publish configuration
        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__.'/../config/skywatch.php' => config_path('skywatch.php'),
            ], 'skywatch-config');

            $this->commands([
                TestSkywatchCommand::class,
                FlushOfflineCommand::class,
            ]);
        }

        // Register Breadcrumb Listeners
        if (config('skywatch.breadcrumbs.enabled', true)) {
            // DB Queries
            if (config('skywatch.breadcrumbs.sql', true)) {
                try {
                    DB::listen(function ($query) {
                        SkywatchClient::recordBreadcrumb(
                            'sql',
                            $query->sql,
                            'info',
                            [
                                'connection' => $query->connectionName,
                                'time_ms' => $query->time,
                            ]
                        );
                    });
                } catch (Throwable $e) {
                }
            }

            // App logs
            if (config('skywatch.breadcrumbs.logs', true)) {
                try {
                    $this->app->make('log')->listen(function (...$args) {
                        if (count($args) === 1 && is_object($args[0])) {
                            $level = $args[0]->level ?? 'info';
                            $message = $args[0]->message ?? '';
                            $context = $args[0]->context ?? [];
                        } else {
                            $level = $args[0] ?? 'info';
                            $message = $args[1] ?? '';
                            $context = $args[2] ?? [];
                        }

                        if (str_contains(is_string($message) ? $message : '', 'Skywatch')) {
                            return;
                        }
                        SkywatchClient::recordBreadcrumb(
                            'log',
                            is_string($message) ? $message : json_encode($message),
                            is_string($level) ? $level : 'info',
                            is_array($context) ? $context : []
                        );
                    });
                } catch (Throwable $e) {
                }
            }

            // Cache Operations
            if (config('skywatch.breadcrumbs.cache', true)) {
                try {
                    Event::listen([
                        CacheHit::class,
                        CacheMiss::class,
                        KeyWritten::class,
                        KeyForgotten::class,
                    ], function ($event) {
                        $message = '';
                        $metadata = ['key' => $event->key];
                        if ($event instanceof CacheHit) {
                            $message = "Cache hit: {$event->key}";
                        } elseif ($event instanceof CacheMiss) {
                            $message = "Cache miss: {$event->key}";
                        } elseif ($event instanceof KeyWritten) {
                            $message = "Cache key written: {$event->key}";
                            $metadata['seconds'] = $event->seconds;
                        } elseif ($event instanceof KeyForgotten) {
                            $message = "Cache key forgotten: {$event->key}";
                        }
                        if ($message) {
                            SkywatchClient::recordBreadcrumb(
                                'cache',
                                $message,
                                'info',
                                $metadata
                            );
                        }
                    });
                } catch (Throwable $e) {
                }
            }

            // HTTP Client requests
            if (config('skywatch.breadcrumbs.http', true)) {
                try {
                    Event::listen(ConnectionFailed::class, function ($event) {
                        SkywatchClient::recordBreadcrumb(
                            'http',
                            "Failed request: {$event->request->method()} {$event->request->url()}",
                            'error'
                        );
                    });
                    Event::listen(ResponseReceived::class, function ($event) {
                        SkywatchClient::recordBreadcrumb(
                            'http',
                            "Sent request: {$event->request->method()} {$event->request->url()}",
                            'info',
                            [
                                'status' => $event->response->status(),
                            ]
                        );
                    });
                } catch (Throwable $e) {
                }
            }

            // Queue job context (for exceptions thrown inside workers)
            try {
                Event::listen(JobProcessing::class, function (JobProcessing $event) {
                    SkywatchClient::$currentJob = [
                        'name' => $event->job->resolveName(),
                        'queue' => $event->job->getQueue(),
                        'connection' => $event->connectionName ?? null,
                        'id' => $event->job->getJobId(),
                        'attempts' => method_exists($event->job, 'attempts') ? $event->job->attempts() : null,
                    ];
                });
                Event::listen([JobProcessed::class, JobFailed::class], function () {
                    SkywatchClient::$currentJob = null;
                });
            } catch (Throwable $e) {
            }
        }

        // Hook into the exception handler to report errors
        if ($this->app->bound(ExceptionHandler::class)) {
            $handler = $this->app->make(ExceptionHandler::class);

            if (method_exists($handler, 'reportable')) {
                $handler->reportable(function (Throwable $e) {
                    $this->app->make('skywatch')->capture($e);
                });
            }
        }

        if (! $this->app->runningInConsole() && config('skywatch.tracing.enabled', true)) {
            $this->app->make(\Illuminate\Contracts\Http\Kernel::class)
                ->pushMiddleware(\Skywatch\Laravel\Http\Middleware\TraceSpanMiddleware::class);
        }

        if (config('skywatch.logs.ship', false)) {
            \Illuminate\Support\Facades\Log::listen(function ($message) {
                try {
                    app(SkywatchClient::class)->shipLog(
                        strtolower($message->level),
                        (string) $message->message,
                        $message->context ?? []
                    );
                } catch (Throwable) {
                }
            });
        }
    }
}
