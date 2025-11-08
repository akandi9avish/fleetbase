# Healthcheck Failure Analysis & Resolution

## Problem Summary

Railway deployment healthchecks are failing with "service unavailable" errors, causing deployment to terminate after 11 failed attempts.

## Root Cause

**Missing or Invalid `APP_KEY` Environment Variable**

The Laravel application crashes during bootstrap before it can handle any requests, including the `/health` endpoint used by Docker healthchecks.

### Evidence from Logs

```
ERROR: Unsupported cipher or incorrect key length.
Supported ciphers are: aes-128-cbc, aes-256-cbc, aes-128-gcm, aes-256-gcm.
File: /fleetbase/api/vendor/laravel/framework/src/Illuminate/Encryption/Encrypter.php:55
```

```
ERROR: worker script has not reached frankenphp_handle_request.
```

The error occurs when FrankenPHP workers try to initialize the Laravel application, but the Encrypter class cannot be instantiated without a valid APP_KEY.

## Required Solution

### Step 1: Set APP_KEY in Railway

Add the following environment variable to your Railway service:

```
APP_KEY=base64:PFFMWVo7+1myA9ejZt5VvVUp4TRIrj41qquXO/LvIHg=
```

**How to set in Railway:**

1. Go to your Railway project dashboard
2. Select the Fleetbase service
3. Click on "Variables" tab
4. Click "New Variable"
5. Set variable name: `APP_KEY`
6. Set value: `base64:PFFMWVo7+1myA9ejZt5VvVUp4TRIrj41qquXO/LvIHg=`
7. Click "Add"
8. Redeploy the service

### Step 2: Code Improvements (Already Applied)

Improved the `/health` route handler to be more robust:

**File:** `api/app/Providers/RouteServiceProvider.php:23`

**Change:** Added default value for `request_start_time` attribute to prevent potential errors during initialization.

```php
$startTime = $request->attributes->get('request_start_time', microtime(true));
```

**Commit:** f3da221

## How the Healthcheck Works

**Dockerfile Configuration (Lines 135-136):**
```dockerfile
HEALTHCHECK --interval=30s --timeout=10s --start-period=60s --retries=3 \
    CMD curl -f http://localhost:8000/health || curl -f http://localhost:8000 || exit 1
```

**Route Definition (api/app/Providers/RouteServiceProvider.php:20-31):**
```php
Route::get('/health', function (Request $request) {
    $startTime = $request->attributes->get('request_start_time', microtime(true));
    return response()->json([
        'status' => 'ok',
        'time' => microtime(true) - $startTime
    ]);
});
```

## Expected Behavior After Fix

1. Container starts and runs migrations successfully ✅ (already working)
2. Configuration, routes, and blade templates are cached ✅ (already working)
3. FrankenPHP Octane server starts on port 8000 ✅ (already working)
4. Laravel application bootstraps successfully (currently failing due to missing APP_KEY)
5. `/health` endpoint responds with `{"status":"ok","time":X}` (will work after APP_KEY is set)
6. Docker healthcheck succeeds
7. Railway marks service as healthy
8. Deployment completes successfully

## Current Status

✅ All migrations passing (180+ migrations successful)
✅ Server starting successfully
✅ `/health` route properly defined
✅ Healthcheck route improved to handle edge cases
❌ APP_KEY environment variable not set in Railway (ACTION REQUIRED)

## Next Steps

1. Set `APP_KEY` environment variable in Railway
2. Trigger a new deployment
3. Monitor logs to confirm healthcheck success
4. Verify application is accessible

## Additional Notes

- The migrations are all passing, which is excellent progress
- All UUID foreign key constraint issues have been resolved
- The only remaining blocker is the missing APP_KEY configuration
- Once APP_KEY is set, the deployment should succeed immediately
