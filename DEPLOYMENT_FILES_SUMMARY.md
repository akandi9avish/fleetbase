# Fleetbase Railway Deployment Files Summary

## Overview

This directory contains all necessary files to deploy Fleetbase to Railway using a simplified Kubernetes-inspired architecture. Instead of deploying 8 separate docker-compose services, this approach uses 4 Railway services + 2 managed database plugins.

## Architecture Comparison

### Traditional Docker Compose (Not Railway Compatible)
```
8 Services:
├── cache (Redis container)
├── database (MySQL container)
├── socket (SocketCluster)
├── scheduler (Cron)
├── queue (Worker)
├── console (Ember UI)
├── application (Laravel API)
└── httpd (nginx proxy)

Cost: ~$40-80/month on Railway
```

### Simplified Railway Deployment (Kubernetes-Inspired)
```
6 Components:
├── MySQL Plugin (managed database)
├── Redis Plugin (managed cache)
├── fleetbase-main (API + nginx in single container)
├── fleetbase-worker (Queue worker)
├── fleetbase-scheduler (Cron scheduler)
└── fleetbase-socket (WebSocket server)

Cost: ~$20-30/month on Railway
```

## Created Files

### Dockerfiles

#### 1. `Dockerfile.railway-main`
**Purpose**: Main Fleetbase service combining API + nginx proxy
**Base Image**: `fleetbase/fleetbase-api:latest`
**Features**:
- Uses supervisord to run both PHP Octane and nginx
- nginx proxies port 80 → localhost:8000 (where API runs)
- Single container reduces Railway costs
- Health checks on nginx endpoint
- Automatic log management

**Services Managed**:
- `fleetbase-api`: PHP Octane (FrankenPHP) on port 8000
- `nginx`: HTTP proxy on port 80

**Replaces**: `application` + `httpd` + `console` from docker-compose

---

#### 2. `Dockerfile.railway-worker`
**Purpose**: Queue worker for background job processing
**Base Image**: `fleetbase/fleetbase-api:latest`
**Features**:
- Processes jobs from Redis queue
- Auto-retries failed jobs (3 attempts)
- 5-minute timeout per job
- Health checks via `queue:status`

**Command**: `php artisan queue:work --verbose --tries=3 --timeout=300`

**Replaces**: `queue` from docker-compose

---

#### 3. `Dockerfile.railway-scheduler`
**Purpose**: Cron scheduler for periodic tasks
**Base Image**: `fleetbase/fleetbase-api:latest`
**Features**:
- Uses go-crond for reliable cron execution
- Runs Laravel scheduler every minute
- Process-based health checks

**Command**: `go-crond --verbose root:./crontab`

**Replaces**: `scheduler` from docker-compose

---

#### 4. `Dockerfile.railway-socket`
**Purpose**: WebSocket server for real-time features
**Base Image**: `socketcluster/socketcluster:v17.4.0`
**Features**:
- Handles real-time WebSocket connections
- Configurable workers and brokers
- Uses Railway's PORT variable
- Health checks on /health endpoint

**Replaces**: `socket` from docker-compose

---

### Railway Configuration Files

#### 1. `railway-main.toml`
Configuration for main service deployment.

**Key Settings**:
- Dockerfile: `Dockerfile.railway-main`
- Restart Policy: ON_FAILURE (max 10 retries)
- Health Check: Path `/`, timeout 300s

**Required Environment Variables**:
- `DATABASE_URL` - From MySQL plugin
- `REDIS_URL` - From Redis plugin
- `APP_URL` - Railway public domain
- `SESSION_DOMAIN` - Domain without protocol

---

#### 2. `railway-worker.toml`
Configuration for queue worker deployment.

**Key Settings**:
- Dockerfile: `Dockerfile.railway-worker`
- Restart Policy: ALWAYS

**Required Environment Variables**:
- `DATABASE_URL`
- `REDIS_URL`
- `QUEUE_CONNECTION=redis`

---

#### 3. `railway-scheduler.toml`
Configuration for scheduler deployment.

**Key Settings**:
- Dockerfile: `Dockerfile.railway-scheduler`
- Restart Policy: ALWAYS

**Required Environment Variables**:
- `DATABASE_URL`
- `REDIS_URL`

---

#### 4. `railway-socket.toml`
Configuration for WebSocket server deployment.

**Key Settings**:
- Dockerfile: `Dockerfile.railway-socket`
- Restart Policy: ON_FAILURE (max 10 retries)
- Health Check: Path `/health`, timeout 30s

**Optional Environment Variables**:
- `SOCKETCLUSTER_WORKERS=10`
- `SOCKETCLUSTER_BROKERS=10`

---

### Deployment Scripts

#### 1. `deploy-railway-simplified.sh`
**Purpose**: Interactive deployment script with guidance
**Usage**: `./deploy-railway-simplified.sh`

**Features**:
- Railway CLI validation
- Project linking (existing or new)
- Database plugin setup instructions
- Environment variable configuration
- Service deployment guidance
- Post-deployment next steps

**What It Does**:
1. Validates Railway CLI installation
2. Checks for required Dockerfiles
3. Authenticates with Railway
4. Links to Railway project
5. Sets shared environment variables
6. Provides manual deployment instructions
7. Displays next steps for integration

---

#### 2. `deploy-to-railway.sh` (Legacy)
**Purpose**: Original docker-compose deployment script
**Status**: Deprecated - Railway doesn't support docker-compose
**Note**: Kept for reference, use `deploy-railway-simplified.sh` instead

---

### Documentation

#### 1. `RAILWAY_SIMPLIFIED_DEPLOYMENT.md`
**Purpose**: Complete deployment guide
**Contents**:
- Architecture overview
- Step-by-step deployment instructions
- Environment variable reference
- Deployment commands
- Advantages of simplified approach
- Next steps for REEUP integration

---

#### 2. `RAILWAY_DEPLOY.md` (Legacy)
**Purpose**: Original docker-compose deployment guide
**Status**: Deprecated
**Note**: Use `RAILWAY_SIMPLIFIED_DEPLOYMENT.md` instead

---

#### 3. `DEPLOYMENT_FILES_SUMMARY.md` (This File)
**Purpose**: Complete reference for all deployment files
**Contents**:
- File descriptions
- Architecture comparison
- Usage instructions
- Quick reference

---

## Quick Start Guide

### Prerequisites
1. Railway account
2. Railway CLI: `npm install -g @railway/cli`
3. Git repository with Fleetbase code

### Deployment Steps

```bash
cd /Users/avishkandi/Desktop/reeup_main/microservices/fleetbase-official

# Make script executable (if not already)
chmod +x deploy-railway-simplified.sh

# Run deployment script
./deploy-railway-simplified.sh
```

Follow the interactive prompts to:
1. Link to Railway project
2. Add MySQL and Redis plugins
3. Deploy the 4 services
4. Configure environment variables

### Manual Deployment (Alternative)

If you prefer manual deployment via Railway dashboard:

1. **Add Plugins**:
   - MySQL: "New" → "Database" → "MySQL"
   - Redis: "New" → "Database" → "Redis"

2. **Create Services** (for each service):
   - "New" → "Empty Service"
   - Connect to GitHub repo
   - Set Dockerfile path
   - Configure environment variables
   - Generate domain (Main and Socket only)

3. **Service Configuration**:

   | Service | Dockerfile | Public Domain |
   |---------|-----------|---------------|
   | fleetbase-main | Dockerfile.railway-main | Yes |
   | fleetbase-worker | Dockerfile.railway-worker | No |
   | fleetbase-scheduler | Dockerfile.railway-scheduler | No |
   | fleetbase-socket | Dockerfile.railway-socket | Yes (optional) |

---

## Environment Variables Reference

### Shared Variables (All Services)
```bash
APP_NAME="REEUP Fleetbase"
ENVIRONMENT="production"
DATABASE_URL=${{MYSQL_URL}}       # From MySQL plugin
REDIS_URL=${{REDIS_URL}}           # From Redis plugin
```

### Main Service Specific
```bash
APP_URL=${{RAILWAY_PUBLIC_DOMAIN}}
SESSION_DOMAIN=${{RAILWAY_PUBLIC_DOMAIN}}
CACHE_DRIVER=redis
QUEUE_CONNECTION=redis
BROADCAST_DRIVER=socketcluster
OSRM_HOST=https://router.project-osrm.org
REGISTRY_HOST=https://registry.fleetbase.io
REGISTRY_PREINSTALLED_EXTENSIONS=true
```

### Worker Service Specific
```bash
QUEUE_CONNECTION=redis
```

### Socket Service Specific
```bash
SOCKETCLUSTER_WORKERS=10
SOCKETCLUSTER_BROKERS=10
```

---

## Integration with REEUP

After Fleetbase is deployed, integrate it with REEUP:

### 1. Get Railway URLs
```bash
railway domain
```

Note the URLs for:
- `fleetbase-main`: Your Fleetbase API + Console URL
- `fleetbase-socket`: Your WebSocket URL (if deployed)

### 2. Configure REEUP Backend (Railway)

Add to backend environment variables:
```bash
FLEETBASE_API_URL=<fleetbase-main-url>
FLEETBASE_CONSOLE_URL=<fleetbase-main-url>
```

After Fleetbase admin setup, add:
```bash
FLEETBASE_API_KEY=<from-fleetbase-dashboard>
FLEETBASE_COMPANY_UUID=<from-fleetbase-dashboard>
```

### 3. Configure REEUP Frontend (Vercel)

```bash
cd /Users/avishkandi/Desktop/reeup_main/frontend

vercel env add NEXT_PUBLIC_FLEETBASE_API_URL
# Paste: <fleetbase-main-url>

vercel env add NEXT_PUBLIC_FLEETBASE_CONSOLE_URL
# Paste: <fleetbase-main-url>

vercel env add NEXT_PUBLIC_ENABLE_FLEETBASE
# Enter: true
```

### 4. Deploy Frontend
```bash
git add frontend/next.config.js frontend/src/app/api/fleetbase-config/route.ts
git commit -m "Configure Fleetbase with Railway URLs"
git push
```

### 5. Setup Fleetbase Admin
1. Navigate to Fleetbase Console (main service URL)
2. Create admin account
3. Login to Fleetbase
4. Go to Settings → API
5. Create API key for REEUP integration
6. Copy API key and Company UUID
7. Add to backend environment variables (see step 2)

---

## Troubleshooting

### Build Failures

**Issue**: Dockerfile build fails
**Solution**: Check that you're in the correct directory and all referenced files exist

```bash
# Verify Dockerfiles exist
ls -la Dockerfile.railway-*

# Verify docker dependencies
ls -la docker/httpd/vhost.conf
ls -la docker/crontab
```

### Service Won't Start

**Issue**: Service crashes on startup
**Solution**: Check logs and verify environment variables

```bash
railway logs --service <service-name>
```

Common issues:
- Missing DATABASE_URL or REDIS_URL
- Database plugin not provisioned
- Incorrect configuration syntax

### Connection Refused

**Issue**: Services can't connect to database/redis
**Solution**: Ensure plugins are fully provisioned

1. Check plugin status in Railway dashboard
2. Verify DATABASE_URL and REDIS_URL are set
3. Restart services after plugins are ready

### Health Check Failures

**Issue**: Railway shows service as unhealthy
**Solution**: Check service-specific health endpoints

- Main: `curl http://<domain>/`
- Worker: `php artisan queue:status`
- Scheduler: `pgrep go-crond`
- Socket: `curl http://<domain>/health`

---

## Cost Breakdown

### Railway Pricing (Approximate)

| Component | Type | Monthly Cost |
|-----------|------|--------------|
| MySQL Plugin | Managed Database | ~$5 |
| Redis Plugin | Managed Cache | ~$5 |
| fleetbase-main | Service | ~$5-10 |
| fleetbase-worker | Service | ~$5-10 |
| fleetbase-scheduler | Service | ~$5 |
| fleetbase-socket | Service | ~$5 (optional) |
| **Total** | | **~$20-30/month** |

Compare to docker-compose deployment: ~$40-80/month (8 services)

**Savings**: ~50% cost reduction

---

## File Checklist

Use this checklist to verify all files are in place before deployment:

- [ ] `Dockerfile.railway-main`
- [ ] `Dockerfile.railway-worker`
- [ ] `Dockerfile.railway-scheduler`
- [ ] `Dockerfile.railway-socket`
- [ ] `railway-main.toml`
- [ ] `railway-worker.toml`
- [ ] `railway-scheduler.toml`
- [ ] `railway-socket.toml`
- [ ] `deploy-railway-simplified.sh` (executable)
- [ ] `RAILWAY_SIMPLIFIED_DEPLOYMENT.md`
- [ ] `docker/httpd/vhost.conf`
- [ ] `docker/crontab`

---

## Support

For deployment issues:
1. Check Railway documentation: https://docs.railway.app
2. Review Fleetbase documentation: https://docs.fleetbase.io
3. Check RAILWAY_SIMPLIFIED_DEPLOYMENT.md for detailed instructions
4. Review Railway logs: `railway logs --service <service-name>`

---

## Summary

This deployment approach provides:
- ✅ Railway compatibility (no docker-compose required)
- ✅ 50% cost reduction vs traditional deployment
- ✅ Managed database services (MySQL, Redis)
- ✅ Simplified architecture (4 services vs 8)
- ✅ Production-ready configuration
- ✅ Health checks and monitoring
- ✅ Easy integration with REEUP platform

Ready to deploy Fleetbase to Railway with confidence!
