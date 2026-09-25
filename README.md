# FaultScope Laravel SDK

Official Laravel package for full-stack exception and telemetry tracking to [FaultScope](https://github.com/FaultScope-Exception-Tracking/FaultScope).

Tracks **both PHP backend errors** and **frontend JavaScript errors** (including Vue and React) using **only this Laravel package**—no separate NPM package installation required!

---

## Requirements

- PHP 8.1+
- Laravel 10, 11, 12, or 13

---

## Installation

Install the package via Composer:

```bash
composer require faultscope/laravel
```

### Configure `.env`

Add your project ingest credentials:

```env
FAULTSCOPE_ENABLED=true
FAULTSCOPE_DSN=https://your-hub.example.com/api/ingest
FAULTSCOPE_KEY=et_ingest_your_key_here
```

### Publish Config (Optional)

```bash
php artisan vendor:publish --tag=faultscope-config
```

### Verify Backend Setup

Verify that the Laravel backend can communicate with your FaultScope hub:

```bash
php artisan faultscope:ping
```

---

## Frontend JavaScript Error Tracking (Zero NPM Required)

You do **not** need to install any `npm` package. The Laravel package handles frontend error tracking out of the box using your existing `.env` configuration.

### Method 1: Blade Directive (Recommended)

Add `@faultscopeScripts` (or `@faultscopeHead`) inside the `<head>` of your master Blade layout (e.g., `resources/views/layouts/app.blade.php`):

```blade
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>{{ config('app.name') }}</title>

    {{-- Automatically loads FaultScope browser tracker and configures credentials --}}
    @faultscopeScripts

    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body>
    @yield('content')
</body>
</html>
```

#### What `@faultscopeScripts` does automatically:
- Loads the lightweight browser script from your hub (`https://your-hub.example.com/sdk/faultscope.js`).
- Initializes `window.FaultScope` with your `FAULTSCOPE_KEY`, `FAULTSCOPE_DSN`, environment, and release.
- Automatically captures `window.onerror` and unhandled Promise rejections.
- Automatically attaches the authenticated Laravel user ID (`auth()->id()`) to browser errors.
- Provides batching, retry queue (`localStorage`), and page unload flush via `sendBeacon`.

#### Custom Directive Options
You can override settings directly from Blade:

```blade
@faultscopeScripts([
    'tracing' => true,                 // Enable fetch / XHR request duration tracing
    'replay' => true,                  // Enable session replay recording
    'replay_sample_rate' => 0.2,       // 20% session replay sample rate
    'nonce' => csp_nonce(),            // Optional CSP nonce
])
```

---

### Method 2: Automatic Middleware Injection (Zero Blade Changes)

If you prefer zero changes to your Blade templates, enable automatic HTML injection in `.env`:

```env
FAULTSCOPE_JS_AUTO_INJECT=true
```

The package will automatically inject the tracking scripts before `</head>` in all web HTML responses.

---

### Optional: Self-Hosting / Publishing Assets

If you prefer serving the browser tracker from your own web domain instead of loading it cross-origin from the hub:

```bash
php artisan vendor:publish --tag=faultscope-assets
```

This copies `faultscope.js` to `public/vendor/faultscope/faultscope.js`. `@faultscopeScripts` will automatically detect and load from this local asset!

---

## Tracking Errors in Vue Components

When using Vue inside Blade or Inertia.js, Vue's internal error handler catches component errors before they reach `window.onerror`. 

Connect Vue to `window.FaultScope` in your JavaScript bootstrap:

### Vue 3 Setup (`resources/js/app.js`)

```javascript
import { createApp } from 'vue';
import App from './App.vue';

const app = createApp(App);

// Option A: Use the built-in FaultScope Vue plugin (simplest)
if (window.FaultScope?.vuePlugin) {
    app.use(window.FaultScope.vuePlugin);
}

// Option B: Or register manually with custom metadata
app.config.errorHandler = (err, instance, info) => {
    window.FaultScope?.capture(err, {
        framework: 'vue',
        component_info: info,
        component_name: instance?.$options?.name || instance?.$options?.__name,
    });
};

app.mount('#app');
```

### Vue 2 Setup

```javascript
import Vue from 'vue';

Vue.config.errorHandler = function (err, vm, info) {
    window.FaultScope?.capture(err, {
        framework: 'vue',
        component_info: info,
    });
};
```

---

## Tracking Errors in React Components

In React, render and lifecycle errors in components are captured using a React **Error Boundary**.

### 1. Create an Error Boundary (`resources/js/components/FaultScopeErrorBoundary.jsx`)

```jsx
import React, { Component } from 'react';

export class FaultScopeErrorBoundary extends Component {
    constructor(props) {
        super(props);
        this.state = { hasError: false };
    }

    static getDerivedStateFromError() {
        return { hasError: true };
    }

    componentDidCatch(error, errorInfo) {
        // Send error and component stack trace to FaultScope
        if (window.FaultScope?.reportReactError) {
            window.FaultScope.reportReactError(error, errorInfo);
        } else {
            window.FaultScope?.capture(error, {
                framework: 'react',
                component_stack: errorInfo?.componentStack,
            });
        }
    }

    render() {
        if (this.state.hasError) {
            return this.props.fallback || (
                <div role="alert" style={{ padding: '1rem', color: '#b91c1c' }}>
                    <p>Something went wrong in this component.</p>
                </div>
            );
        }
        return this.props.children;
    }
}
```

### 2. Wrap your React Root or Components (`resources/js/app.jsx`)

```jsx
import { createRoot } from 'react-dom/client';
import App from './App';
import { FaultScopeErrorBoundary } from './components/FaultScopeErrorBoundary';

const container = document.getElementById('root');
if (container) {
    createRoot(container).render(
        <FaultScopeErrorBoundary>
            <App />
        </FaultScopeErrorBoundary>
    );
}
```

---

## Configuration Reference (`config/faultscope.php`)

| Environment Variable | Default | Description |
|---|---|---|
| `FAULTSCOPE_ENABLED` | `true` | Enable/disable all exception capture |
| `FAULTSCOPE_DSN` | `null` | Ingest URL (`https://hub.example.com/api/ingest`) |
| `FAULTSCOPE_KEY` | `null` | Ingest API key (`et_ingest_...`) |
| `FAULTSCOPE_RELEASE` | `null` | App release / version tag |
| `FAULTSCOPE_JS_ENABLED` | `true` | Enable frontend JavaScript error tracking |
| `FAULTSCOPE_JS_URL` | `null` | Custom script URL for `faultscope.js` (auto-derived if omitted) |
| `FAULTSCOPE_JS_AUTO_INJECT` | `false` | Automatically inject script into HTML response `<head>` |
| `FAULTSCOPE_JS_TRACING` | `false` | Enable fetch and XHR performance tracing in browser |
| `FAULTSCOPE_JS_REPLAY` | `false` | Enable session replay recording |
| `FAULTSCOPE_JS_REPLAY_SAMPLE_RATE` | `0.1` | Session replay sample rate (0.0 to 1.0) |
| `FAULTSCOPE_SAMPLE_RATE` | `1.0` | Backend sampling rate (0.0 to 1.0) |
| `FAULTSCOPE_OFFLINE_QUEUE` | `true` | Queue backend errors on disk when hub is unreachable |
| `FAULTSCOPE_BREADCRUMBS` | `true` | Auto-capture SQL queries, cache, logs, and HTTP breadcrumbs |

---

## License

MIT
