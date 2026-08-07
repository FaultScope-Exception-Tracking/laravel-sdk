<?php

namespace FaultScope\Laravel;

use FaultScope\Laravel\Commands\FlushOfflineCommand;
use FaultScope\Laravel\Commands\TestFaultScopeCommand;
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

class FaultScopeServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->mergeConfigFrom(
            __DIR__.'/../config/faultscope.php', 'faultscope'
        );

        $this->app->singleton('faultscope', function () {
            return new FaultScopeClient;
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__.'/../config/faultscope.php' => config_path('faultscope.php'),
            ], 'faultscope-config');

            $this->commands([
                TestFaultScopeCommand::class,
                FlushOfflineCommand::class,
            ]);
        }

        if (config('faultscope.breadcrumbs.enabled', true)) {
            if (config('faultscope.breadcrumbs.sql', true)) {
                try {
                    DB::listen(function ($query) {
                        FaultScopeClient::recordBreadcrumb(
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

            if (config('faultscope.breadcrumbs.logs', true)) {
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

                        if (str_contains(is_string($message) ? $message : '', 'faultscope')) {
                            return;
                        }
                        FaultScopeClient::recordBreadcrumb(
                            'log',
                            is_string($message) ? $message : json_encode($message),
                            is_string($level) ? $level : 'info',
                            is_array($context) ? $context : []
                        );
                    });
                } catch (Throwable $e) {
                }
            }

            if (config('faultscope.breadcrumbs.cache', true)) {
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
                            FaultScopeClient::recordBreadcrumb(
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

            if (config('faultscope.breadcrumbs.http', true)) {
                try {
                    Event::listen(ConnectionFailed::class, function ($event) {
                        FaultScopeClient::recordBreadcrumb(
                            'http',
                            "Failed request: {$event->request->method()} {$event->request->url()}",
                            'error'
                        );
                    });
                    Event::listen(ResponseReceived::class, function ($event) {
                        FaultScopeClient::recordBreadcrumb(
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

            try {
                Event::listen(JobProcessing::class, function (JobProcessing $event) {
                    FaultScopeClient::$currentJob = [
                        'name' => $event->job->resolveName(),
                        'queue' => $event->job->getQueue(),
                        'connection' => $event->connectionName ?? null,
                        'id' => $event->job->getJobId(),
                        'attempts' => method_exists($event->job, 'attempts') ? $event->job->attempts() : null,
                    ];
                });
                Event::listen([JobProcessed::class, JobFailed::class], function () {
                    FaultScopeClient::$currentJob = null;
                });
            } catch (Throwable $e) {
            }
        }

        if ($this->app->bound(ExceptionHandler::class)) {
            $handler = $this->app->make(ExceptionHandler::class);

            if (method_exists($handler, 'reportable')) {
                $handler->reportable(function (Throwable $e) {
                    $this->app->make('faultscope')->capture($e);
                });
            }
        }

        if (! $this->app->runningInConsole() && config('faultscope.tracing.enabled', true)) {
            $this->app->make(\Illuminate\Contracts\Http\Kernel::class)
                ->pushMiddleware(\FaultScope\Laravel\Http\Middleware\TraceSpanMiddleware::class);
        }

        if (config('faultscope.logs.ship', false)) {
            \Illuminate\Support\Facades\Log::listen(function ($message) {
                try {
                    app(FaultScopeClient::class)->shipLog(
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
