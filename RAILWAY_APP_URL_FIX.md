# Railway APP_URL Configuration Fix

## Problem
The container is failing with: `Invalid URI: Host is malformed` because the `APP_URL` environment variable in Railway is set to the literal string:
```
APP_URL=https://${RAILWAY_PUBLIC_DOMAIN}
```

Instead of the actual domain value.

## Root Cause
The `.env.railway.example` file shows `APP_URL=https://${RAILWAY_PUBLIC_DOMAIN}` as a **template** to indicate where the domain should go. This was copied literally into Railway's environment variables instead of being replaced with the actual domain.

## Solution

### Option 1: Delete APP_URL (Recommended)
1. Go to Railway Dashboard
2. Select the Fleetbase service
3. Go to Variables tab
4. **Delete** the `APP_URL` variable
5. Redeploy

The Dockerfile will automatically construct `APP_URL` from `RAILWAY_PUBLIC_DOMAIN`.

### Option 2: Set Correct Value
1. Go to Railway Dashboard
2. Select the Fleetbase service
3. Go to Variables tab
4. Find the `APP_URL` variable
5. Change it from: `https://${RAILWAY_PUBLIC_DOMAIN}`
6. To your actual domain: `https://YOUR-ACTUAL-DOMAIN.up.railway.app`
7. Redeploy

## How Railway Environment Variables Work

Railway provides these automatically:
- `RAILWAY_PUBLIC_DOMAIN` = `your-service-name.up.railway.app` (the actual domain)
- `RAILWAY_STATIC_URL` = `https://your-service-name.up.railway.app` (full URL)

The `.env.railway.example` file uses shell variable syntax `${VAR}` as a **placeholder** for documentation purposes only. These are NOT Railway template variables.

## Current Dockerfile Logic
The updated Dockerfile (commit `e8805aa`) will:
1. Check if `RAILWAY_PUBLIC_DOMAIN` exists
2. Construct `APP_URL=https://$RAILWAY_PUBLIC_DOMAIN`
3. Fall back to `RAILWAY_STATIC_URL` if PUBLIC_DOMAIN not set
4. Default to `http://localhost:8000` if neither is available

But this only works if Railway hasn't already set a malformed `APP_URL` value.

## Verification
After fixing, the container should start successfully with migrations running. Check logs for:
```
   INFO  Running migrations.
```

Instead of:
```
Invalid URI: Host is malformed.
```
