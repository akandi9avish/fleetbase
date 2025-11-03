# Fleetbase Simplified Railway Deployment
## Using Official Docker Images (Kubernetes-style Architecture)

Based on Fleetbase's Helm charts, we can deploy a simplified version perfect for Railway.

## Architecture Overview

Instead of 8 docker-compose services, deploy **4 Railway services** + 2 plugins:

### Railway Services:
1. **fleetbase-main** - Main API + Console (single service)
2. **fleetbase-worker** - Queue worker
3. **fleetbase-scheduler** - Cron jobs
4. **fleetbase-socket** - WebSocket server (optional)

### Railway Plugins:
5. **MySQL Plugin** - Replaces docker MySQL container
6. **Redis Plugin** - Replaces docker Redis container

**Total Cost Estimate:** ~$20-30/month (4 services + 2 plugins)
**vs docker-compose:** Would be ~$40-80/month (8 services)

---

## Step-by-Step Deployment

### Prerequisites
1. Railway account
2. Railway CLI installed: `npm install -g @railway/cli`
3. Login: `railway login`

### Step 1: Add MySQL Plugin

```bash
# In your Railway project dashboard
1. Click "New"
2. Select "Database" → "MySQL"
3. Railway provisions a MySQL instance
4. Note the connection string
```

### Step 2: Add Redis Plugin

```bash
# In your Railway project dashboard
1. Click "New"
2. Select "Database" → "Redis"
3. Railway provisions a Redis instance
4. Note the connection string
```

### Step 3: Deploy Main Fleetbase Service

The main service combines Fleetbase API + nginx proxy in a single container using supervisord.

**Files Created:**
- `Dockerfile.railway-main` - Multi-process container with API + nginx
- `railway-main.toml` - Railway configuration

**Key Features:**
- Uses supervisord to run both PHP Octane (FrankenPHP) and nginx
- nginx proxies requests to localhost:8000 where the API runs
- Single container reduces Railway service costs
- Health checks on port 80 (nginx)

### Step 4: Deploy Queue Worker

Processes background jobs from Redis queue.

**Files Created:**
- `Dockerfile.railway-worker` - Queue worker container
- `railway-worker.toml` - Railway configuration

**Key Features:**
- Runs `php artisan queue:work` with production settings
- Auto-retries failed jobs up to 3 times
- 5-minute timeout per job
- Health checks via `queue:status` command

### Step 5: Deploy Scheduler

Runs Laravel scheduled tasks via cron.

**Files Created:**
- `Dockerfile.railway-scheduler` - Cron scheduler container
- `railway-scheduler.toml` - Railway configuration

**Key Features:**
- Uses go-crond for reliable cron job execution
- Runs Laravel scheduler every minute
- Processes tasks defined in Laravel's schedule
- Health checks via process monitoring

### Step 6: Deploy SocketCluster (Optional)

WebSocket server for real-time communications.

**Files Created:**
- `Dockerfile.railway-socket` - SocketCluster container
- `railway-socket.toml` - Railway configuration

**Key Features:**
- Handles real-time WebSocket connections
- Configured with 10 workers and 10 brokers
- Uses Railway's PORT environment variable
- Health checks on /health endpoint

---

## Deployment Commands

### Manual Deployment via Railway Dashboard

1. **Navigate to Railway Project**
   ```bash
   cd /Users/avishkandi/Desktop/reeup_main/microservices/fleetbase-official
   ```

2. **Add MySQL Plugin**
   - In Railway dashboard, click "New" → "Database" → "MySQL"
   - Note the connection URL from the plugin's variables

3. **Add Redis Plugin**
   - In Railway dashboard, click "New" → "Database" → "Redis"
   - Note the connection URL from the plugin's variables

4. **Deploy Each Service**

   For each service, create a new Railway service in the dashboard:

   **Main Service:**
   - Click "New" → "Empty Service"
   - Name: `fleetbase-main`
   - Settings → Source → Connect to GitHub repo
   - Settings → Build → Dockerfile Path: `Dockerfile.railway-main`
   - Settings → Environment Variables:
     ```
     APP_NAME=REEUP Fleetbase
     ENVIRONMENT=production
     APP_URL=${{RAILWAY_PUBLIC_DOMAIN}}
     DATABASE_URL=${{MYSQL_URL}}
     REDIS_URL=${{REDIS_URL}}
     CACHE_DRIVER=redis
     QUEUE_CONNECTION=redis
     BROADCAST_DRIVER=socketcluster
     OSRM_HOST=https://router.project-osrm.org
     REGISTRY_HOST=https://registry.fleetbase.io
     REGISTRY_PREINSTALLED_EXTENSIONS=true
     ```
   - Settings → Networking → Generate Domain

   **Worker Service:**
   - Click "New" → "Empty Service"
   - Name: `fleetbase-worker`
   - Settings → Source → Connect to same GitHub repo
   - Settings → Build → Dockerfile Path: `Dockerfile.railway-worker`
   - Settings → Environment Variables:
     ```
     DATABASE_URL=${{MYSQL_URL}}
     REDIS_URL=${{REDIS_URL}}
     QUEUE_CONNECTION=redis
     ```

   **Scheduler Service:**
   - Click "New" → "Empty Service"
   - Name: `fleetbase-scheduler`
   - Settings → Source → Connect to same GitHub repo
   - Settings → Build → Dockerfile Path: `Dockerfile.railway-scheduler`
   - Settings → Environment Variables:
     ```
     DATABASE_URL=${{MYSQL_URL}}
     REDIS_URL=${{REDIS_URL}}
     ```

   **Socket Service (Optional):**
   - Click "New" → "Empty Service"
   - Name: `fleetbase-socket`
   - Settings → Source → Connect to same GitHub repo
   - Settings → Build → Dockerfile Path: `Dockerfile.railway-socket`
   - Settings → Environment Variables:
     ```
     SOCKETCLUSTER_WORKERS=10
     SOCKETCLUSTER_BROKERS=10
     ```
   - Settings → Networking → Generate Domain

### Alternative: CLI Deployment

```bash
cd /Users/avishkandi/Desktop/reeup_main/microservices/fleetbase-official

# Login to Railway
railway login

# Link to existing project or create new one
railway link

# Deploy each service
# Note: You'll need to specify which service to deploy to
railway up -d
```

### Verify Deployment

```bash
# Check deployment status
railway status

# View logs for each service
railway logs --service fleetbase-main
railway logs --service fleetbase-worker
railway logs --service fleetbase-scheduler
railway logs --service fleetbase-socket

# Get public URLs
railway domain
```

---

## Environment Variables Summary

### Required (Set in Railway Dashboard):

**Main Service:**
- `APP_NAME`: "REEUP Fleetbase"
- `ENVIRONMENT`: "production"
- `DATABASE_URL`: From MySQL plugin
- `REDIS_URL`: From Redis plugin
- `APP_URL`: Your Railway public domain

**Optional:**
- `MAIL_MAILER`: smtp/ses/mailgun
- `MAIL_HOST`, `MAIL_PORT`, `MAIL_USERNAME`, `MAIL_PASSWORD`
- `TWILIO_SID`, `TWILIO_TOKEN` (for SMS)
- `GOOGLE_MAPS_API_KEY` (for mapping)

---

## Advantages of This Approach

✅ **Railway-Compatible**: Uses separate services instead of docker-compose
✅ **Cost-Effective**: 4-6 services instead of 8
✅ **Managed Database**: Railway MySQL plugin (automatic backups)
✅ **Managed Cache**: Railway Redis plugin (automatic scaling)
✅ **Simple Architecture**: Based on Fleetbase's own Kubernetes deployment
✅ **Official Images**: Uses `fleetbase/fleetbase-api:latest` and `fleetbase/fleetbase-console:latest`

---

## Next Steps

1. Create the Dockerfiles listed above
2. Deploy MySQL and Redis plugins in Railway
3. Deploy the 4 Fleetbase services
4. Get Railway URLs and configure REEUP backend
5. Test the integration

Would you like me to create these Dockerfiles and deployment files?
