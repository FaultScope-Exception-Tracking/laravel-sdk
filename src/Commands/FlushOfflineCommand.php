<?php

namespace Skywatch\Laravel\Commands;

use GuzzleHttp\Client;
use Illuminate\Console\Command;
use Skywatch\Laravel\SkywatchClient;

class FlushOfflineCommand extends Command
{
    protected $signature = 'skywatch:flush-offline';

    protected $description = 'Flush offline Skywatch queue';

    public function handle()
    {
        $path = storage_path('skywatch/offline');
        if (! is_dir($path)) {
            $this->info('No offline queue directory found.');

            return;
        }

        $files = glob($path.'/*.json');
        if (empty($files)) {
            $this->info('No offline payloads to flush.');

            return;
        }

        $dsn = config('skywatch.dsn') ?: env('SKYWATCH_DSN', env('EXCEPTION_TRACKER_DSN'));
        $key = config('skywatch.key') ?: env('SKYWATCH_KEY', env('EXCEPTION_TRACKER_KEY'));

        if (! $dsn || ! $key) {
            $this->error('DSN or Key not configured.');

            return;
        }

        $client = new Client([
            'timeout' => 5.0,
            'headers' => [
                'Authorization' => 'Bearer '.$key,
                'Content-Type' => 'application/json',
                'Accept' => 'application/json',
                'X-SDK-Version' => 'laravel/'.SkywatchClient::SDK_VERSION,
            ],
        ]);

        $this->info('Flushing '.count($files).' offline payloads...');

        foreach ($files as $file) {
            $payload = json_decode(file_get_contents($file), true);
            if ($payload) {
                try {
                    $client->post($dsn, [
                        'json' => $payload,
                    ]);
                    unlink($file); // Remove on success
                    $this->line('Sent: '.basename($file));
                } catch (\Throwable $e) {
                    $this->error('Failed to send: '.basename($file));
                }
            } else {
                unlink($file); // Invalid file
            }
        }

        $this->info('Done.');
    }
}
