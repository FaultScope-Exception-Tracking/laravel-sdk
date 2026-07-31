# Skywatch Laravel SDK

Official Laravel package for sending exceptions and telemetry to [Skywatch](https://github.com/Skywatch-Exception-Tracking/Skywatch).

## Requirements

- PHP 8.1+
- Laravel 10, 11, 12, or 13

## Install

```bash
composer require skywatch/laravel
```

## Configure

Add to your `.env`:

```env
SKYWATCH_ENABLED=true
SKYWATCH_DSN=https://your-hub.example.com/api/ingest
SKYWATCH_KEY=et_ingest_your_key_here
```

Optional: publish config

```bash
php artisan vendor:publish --tag=skywatch-config
```

## Verify

```bash
php artisan skywatch:ping
```

## Features

- Auto-capture unhandled exceptions
- SQL, log, cache, and HTTP breadcrumbs
- PII masking and offline queue
- Request tracing middleware
- Queue job context in workers

## License

MIT
