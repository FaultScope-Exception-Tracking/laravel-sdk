# FaultScope Laravel SDK

Official Laravel package for sending exceptions and telemetry to [FaultScope](https://github.com/FaultScope-Exception-Tracking/FaultScope).

## Requirements

- PHP 8.1+
- Laravel 10, 11, 12, or 13

## Install

```bash
composer require faultscope/laravel
```

## Configure

Add to your `.env`:

```env
FAULTSCOPE_ENABLED=true
FAULTSCOPE_DSN=https://your-hub.example.com/api/ingest
FAULTSCOPE_KEY=et_ingest_your_key_here
```

Optional: publish config

```bash
php artisan vendor:publish --tag=faultscope-config
```

## Verify

```bash
php artisan faultscope:ping
```

## Features

- Auto-capture unhandled exceptions
- SQL, log, cache, and HTTP breadcrumbs
- PII masking and offline queue
- Request tracing middleware
- Queue job context in workers

## License

MIT
