<?php

/*
|--------------------------------------------------------------------------
| Suppress PHP Deprecation Warnings (FrankenPHP Compatibility)
|--------------------------------------------------------------------------
|
| FrankenPHP emits "ERROR unknown error" for each PHP deprecation warning.
| Suppress these to keep logs clean. Vendor code (fleetbase/core-api) has
| deprecations that won't be fixed upstream.
|
| The warnings are in vendor/fleetbase/core-api:
| - HasApiModelBehavior.php:600 - optional param before required
| - Arr.php:85, Arr.php:112 - optional param before required
|
*/
error_reporting(E_ALL & ~E_DEPRECATED & ~E_STRICT);

use Illuminate\Contracts\Http\Kernel;
use Illuminate\Http\Request;

define('LARAVEL_START', microtime(true));

/*
|--------------------------------------------------------------------------
| Global Error Handler (FrankenPHP/Octane Fix)
|--------------------------------------------------------------------------
|
| Catch all exceptions and errors at the earliest possible point to ensure
| error messages appear in logs instead of generic "ERROR unknown error"
| from FrankenPHP worker crashes.
|
*/

// Helper function to safely write to stderr (handles FrankenPHP where STDERR may not exist)
function safe_stderr_write($msg) {
    $stderr = defined('STDERR') ? STDERR : @fopen('php://stderr', 'w');
    if ($stderr) {
        @fwrite($stderr, $msg);
        if (!defined('STDERR')) {
            @fclose($stderr);
        }
    }
}

set_exception_handler(function (\Throwable $e) {
    $msg = sprintf(
        "[FATAL] %s: %s in %s:%d\n%s\n",
        get_class($e),
        $e->getMessage(),
        $e->getFile(),
        $e->getLine(),
        $e->getTraceAsString()
    );
    safe_stderr_write($msg);
    throw $e; // Re-throw to allow normal handling
});

set_error_handler(function ($severity, $message, $file, $line) {
    if (!(error_reporting() & $severity)) {
        return false;
    }
    $msg = sprintf("[ERROR] %s in %s:%d\n", $message, $file, $line);
    safe_stderr_write($msg);
    throw new \ErrorException($message, 0, $severity, $file, $line);
});

register_shutdown_function(function () {
    $error = error_get_last();
    if ($error !== null && in_array($error['type'], [E_ERROR, E_CORE_ERROR, E_COMPILE_ERROR, E_PARSE])) {
        $msg = sprintf(
            "[FATAL ERROR] %s in %s:%d\n",
            $error['message'],
            $error['file'],
            $error['line']
        );
        safe_stderr_write($msg);
    }
});

/*
|--------------------------------------------------------------------------
| Check If The Application Is Under Maintenance
|--------------------------------------------------------------------------
|
| If the application is in maintenance / demo mode via the "down" command
| we will load this file so that any pre-rendered content can be shown
| instead of starting the framework, which could cause an exception.
|
*/

if (file_exists($maintenance = __DIR__.'/../storage/framework/maintenance.php')) {
    require $maintenance;
}

/*
|--------------------------------------------------------------------------
| Register The Auto Loader
|--------------------------------------------------------------------------
|
| Composer provides a convenient, automatically generated class loader for
| this application. We just need to utilize it! We'll simply require it
| into the script here so we don't need to manually load our classes.
|
*/

require __DIR__.'/../vendor/autoload.php';

/*
|--------------------------------------------------------------------------
| Run The Application
|--------------------------------------------------------------------------
|
| Once we have the application, we can handle the incoming request using
| the application's HTTP kernel. Then, we will send the response back
| to this client's browser, allowing them to enjoy our application.
|
*/

$app = require_once __DIR__.'/../bootstrap/app.php';

$kernel = $app->make(Kernel::class);

$response = $kernel->handle(
    $request = Request::capture()
)->send();

$kernel->terminate($request, $response);
