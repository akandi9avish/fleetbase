# 🚂 Fleetbase Railway Deployment Guide

Complete guide for deploying Fleetbase on Railway.app for reeup.io

---

## 📋 Prerequisites

- Railway account ([railway.app](https://railway.app))
- GitHub account with forked Fleetbase repository
- Railway CLI installed (optional but recommended)

---

## ⚡ Quick Start (15 Minutes)

### Step 1: Generate APP_KEY (1 minute)

Run this command locally:

```bash
docker run --rm dunglas/frankenphp:1.5.0-php8.2 php -r "echo 'base64:'.base64_encode(random_bytes(32)).PHP_EOL;"
```

**Copy the output** (looks like: `base64:ABC123...`)

### Step 2: Create Railway Project (5 minutes)

1. **Create New Project**:
   - Go to [Railway Dashboard](https://railway.app/dashboard)
   - Click "+ New Project"
   - Select "Deploy from GitHub repo"
   - Choose your forked `fleetbase` repository

2. **Add Database Services**:
   - Click "+ New" → "Database" → "Add MySQL"
   - Name it: `MySQL`
   - Click "+ New" → "Database" → "Add Redis"
   - Name it: `Redis`

### Step 3: Create Three Application Services (5 minutes)

#### Service 1: Fleetbase API (Main)

1. Click "+ New" → "GitHub Repo" → Select your `fleetbase` fork
2. **Service Name**: `fleetbase-api`
3. **Settings** → **Build**:
   - Docker Build Command: (leave default)
   - Dockerfile Path: `Dockerfile.railway`
4. **Settings** → **Deploy**:
   - Start Command: (leave empty, uses Dockerfile CMD)
   - Health Check Path: `/health`

#### Service 2: Fleetbase Queue Worker

1. Click "+ New" → "GitHub Repo" → Select your `fleetbase` fork
2. **Service Name**: `fleetbase-queue`
3. **Settings** → **Build**:
   - Dockerfile Path: `Dockerfile.queue`
4. **Settings** → **Deploy**:
   - Start Command: (leave empty, uses Dockerfile CMD)

#### Service 3: Fleetbase Scheduler

1. Click "+ New" → "GitHub Repo" → Select your `fleetbase` fork
2. **Service Name**: `fleetbase-scheduler`
3. **Settings** → **Build**:
   - Dockerfile Path: `Dockerfile.scheduler`
4. **Settings** → **Deploy**:
   - Start Command: (leave empty, uses Dockerfile CMD)

### Step 4: Configure Environment Variables (4 minutes)

Copy these variables to **ALL THREE** services (`fleetbase-api`, `fleetbase-queue`, `fleetbase-scheduler`):

```bash
# Core Application
APP_NAME=Fleetbase
APP_ENV=production
APP_DEBUG=false
APP_KEY=<paste-your-generated-key-from-step-1>
APP_URL=https://${{fleetbase-api.RAILWAY_PUBLIC_DOMAIN}}

# Database (Railway MySQL)
DB_CONNECTION=mysql
DB_HOST=${{MySQL.RAILWAY_PRIVATE_DOMAIN}}
DB_PORT=${{MySQL.MYSQL_PORT}}
DB_DATABASE=${{MySQL.MYSQLDB}}
DB_USERNAME=${{MySQL.MYSQLUSER}}
DB_PASSWORD=${{MySQL.MYSQL_PASSWORD}}

# Cache & Queue (Railway Redis)
REDIS_HOST=${{Redis.RAILWAY_PRIVATE_DOMAIN}}
REDIS_PORT=6379
REDIS_PASSWORD=${{Redis.REDIS_PASSWORD}}
REDIS_CLIENT=phpredis

# Drivers
CACHE_DRIVER=redis
QUEUE_CONNECTION=redis
SESSION_DRIVER=redis
BROADCAST_DRIVER=socketcluster

# Logging
LOG_CHANNEL=stderr
LOG_LEVEL=info

# Session
SESSION_LIFETIME=120
SESSION_SECURE_COOKIE=true
SESSION_SAME_SITE=lax

# Octane
OCTANE_SERVER=frankenphp

# Google Maps (Required)
GOOGLE_MAPS_API_KEY=YOUR_GOOGLE_MAPS_KEY
GOOGLE_MAPS_LOCALE=en

# Mail (SendGrid Example)
MAIL_MAILER=smtp
MAIL_HOST=smtp.sendgrid.net
MAIL_PORT=587
MAIL_USERNAME=apikey
MAIL_PASSWORD=YOUR_SENDGRID_API_KEY
MAIL_ENCRYPTION=tls
MAIL_FROM_ADDRESS=noreply@reeup.io
MAIL_FROM_NAME=Fleetbase
```

### Step 5: Deploy! 🎉

Railway will automatically deploy all services. Monitor the deployment:

```bash
railway logs --service fleetbase-api
railway logs --service fleetbase-queue
railway logs --service fleetbase-scheduler
```

**Done!** Your Fleetbase instance should be running at the public URL shown in Railway dashboard.

---

## 📊 Architecture Overview

```
┌─────────────────────────────────────────────────────────────┐
│                    Railway Project                          │
│                                                             │
│  ┌──────────────┐  ┌──────────────┐  ┌──────────────┐    │
│  │ fleetbase-   │  │ fleetbase-   │  │ fleetbase-   │    │
│  │    api       │  │    queue     │  │  scheduler   │    │
│  │ (FrankenPHP) │  │ (Worker)     │  │  (Cron)      │    │
│  └──────┬───────┘  └──────┬───────┘  └──────┬───────┘    │
│         │                  │                  │            │
│         └──────────────────┼──────────────────┘            │
│                            │                               │
│         ┌──────────────────┴──────────────────┐            │
│         │                                     │            │
│    ┌────▼────┐                          ┌────▼────┐       │
│    │  MySQL  │                          │  Redis  │       │
│    │ (8.4)   │                          │ (7.2)   │       │
│    └─────────┘                          └─────────┘       │
│                                                            │
└────────────────────────────────────────────────────────────┘
```

### Service Responsibilities

| Service | Purpose | Health Check |
|---------|---------|--------------|
| **fleetbase-api** | Main HTTP API server, runs migrations, serves requests | `/health` endpoint |
| **fleetbase-queue** | Processes background jobs (emails, notifications, async tasks) | `php artisan queue:info` |
| **fleetbase-scheduler** | Runs Laravel scheduled tasks every minute | `pgrep go-crond` |

---

## 🔧 Configuration Details

### Files Added for Railway Deployment

| File | Purpose |
|------|---------|
| `Dockerfile.railway` | Main API server (bypasses AWS SSM, includes migrations) |
| `Dockerfile.queue` | Background queue worker |
| `Dockerfile.scheduler` | Cron job scheduler using go-crond |
| `railway.toml` | Railway platform configuration |
| `.dockerignore.railway` | Optimized Docker build context |
| `.env.railway.example` | Complete environment variable template |
| `RAILWAY_DEPLOYMENT.md` | This deployment guide |

### Key Differences from AWS Deployment

1. **No AWS SSM**: Railway Dockerfiles bypass `ssm-parent` by using standard `docker-php-entrypoint`
2. **Migrations**: Automatically run on `fleetbase-api` startup
3. **Private Networking**: Uses Railway's internal DNS (`${{Service.VARIABLE}}`)
4. **Logging**: All services log to `stderr` for Railway's log aggregation
5. **Health Checks**: Integrated into Dockerfiles for Railway's monitoring

---

## 🌐 Domain Configuration

### Option 1: Use Railway Subdomain (Default)

Railway provides: `fleetbase-api.up.railway.app`

Update environment variable:
```bash
APP_URL=https://fleetbase-api.up.railway.app
```

### Option 2: Custom Domain (Recommended for reeup.io)

1. **Railway Dashboard** → `fleetbase-api` service → **Settings** → **Domains**
2. Click "**Add Custom Domain**"
3. Enter: `fleetbase.reeup.io` (or your preferred subdomain)
4. Add the provided CNAME record to your DNS:
   ```
   CNAME fleetbase.reeup.io → fleetbase-api.up.railway.app
   ```
5. Update environment variable:
   ```bash
   APP_URL=https://fleetbase.reeup.io
   ```

---

## 🔒 Security Checklist

- [ ] Generate unique `APP_KEY`
- [ ] Set `APP_DEBUG=false` in production
- [ ] Use strong `REDIS_PASSWORD` (Railway auto-generates)
- [ ] Use strong `DB_PASSWORD` (Railway auto-generates)
- [ ] Enable `SESSION_SECURE_COOKIE=true`
- [ ] Configure proper CORS headers
- [ ] Set up Sentry for error tracking
- [ ] Enable rate limiting in Laravel
- [ ] Use HTTPS only (Railway provides free SSL)
- [ ] Restrict Railway project access to authorized team members

---

## 🧪 Testing the Deployment

### 1. Check API Health

```bash
curl https://your-domain.railway.app/health
```

Expected response: `200 OK` or health status JSON

### 2. Verify Database Migrations

```bash
railway run --service fleetbase-api php artisan migrate:status
```

Should show all migrations as "Ran"

### 3. Test Queue Worker

```bash
railway run --service fleetbase-api php artisan queue:info
```

Should show active queue connections

### 4. Verify Scheduler

```bash
railway logs --service fleetbase-scheduler
```

Should show cron jobs running every minute

---

## 📈 Monitoring & Maintenance

### View Logs

```bash
# API logs
railway logs --service fleetbase-api

# Queue worker logs
railway logs --service fleetbase-queue

# Scheduler logs
railway logs --service fleetbase-scheduler

# Follow logs in real-time
railway logs --service fleetbase-api --follow
```

### Database Management

```bash
# Connect to MySQL
railway connect MySQL

# Run database commands
railway run --service fleetbase-api php artisan db:show
```

### Restart Services

```bash
railway service restart fleetbase-api
railway service restart fleetbase-queue
railway service restart fleetbase-scheduler
```

### Scale Services

Railway Dashboard → Service → **Settings** → **Deploy** → **Replicas**

- API: Scale horizontally (2-5 instances for high traffic)
- Queue: Scale based on queue depth (1-3 instances)
- Scheduler: Keep at 1 instance (only one scheduler needed)

---

## 🔍 Troubleshooting

### Issue: Migrations Failing

**Error**: `SQLSTATE[42S01]: Base table or view already exists`

**Solution**: Database already has tables. If starting fresh:
```bash
railway connect MySQL
DROP DATABASE your_database_name;
CREATE DATABASE your_database_name;
```

Then restart `fleetbase-api` service.

### Issue: Queue Worker Crashing

**Error**: `Redis connection refused`

**Solution**: Check Redis configuration:
- Verify `REDIS_HOST=${{Redis.RAILWAY_PRIVATE_DOMAIN}}`
- Verify `REDIS_PORT=6379` (integer, not variable)
- Check Redis service is running

### Issue: Scheduler Not Running

**Error**: `go-crond not found`

**Solution**: Check Dockerfile.scheduler build logs:
```bash
railway logs --service fleetbase-scheduler --build
```

Ensure go-crond installed successfully.

### Issue: 500 Internal Server Error

**Check**:
1. API logs: `railway logs --service fleetbase-api`
2. Environment variables are set correctly
3. Database connection works
4. Redis connection works

**Debug**:
```bash
railway run --service fleetbase-api php artisan config:clear
railway run --service fleetbase-api php artisan cache:clear
railway service restart fleetbase-api
```

### Issue: File Upload Failing

**Solution**: Configure S3 or compatible object storage:

```bash
# Add to environment variables
FILESYSTEM_DISK=s3
AWS_ACCESS_KEY_ID=your_access_key
AWS_SECRET_ACCESS_KEY=your_secret_key
AWS_DEFAULT_REGION=us-east-1
AWS_BUCKET=your_bucket_name
AWS_URL=https://your-bucket.s3.amazonaws.com
```

Railway's filesystem is ephemeral - use S3, Cloudflare R2, or Backblaze B2 for permanent storage.

---

## 🚀 Performance Optimization

### 1. Enable OPcache

Add to environment variables:
```bash
OPCACHE_ENABLE=1
OPCACHE_MEMORY_CONSUMPTION=256
OPCACHE_MAX_ACCELERATED_FILES=20000
```

### 2. Optimize Laravel

```bash
railway run --service fleetbase-api php artisan config:cache
railway run --service fleetbase-api php artisan route:cache
railway run --service fleetbase-api php artisan view:cache
```

(These are already in the startup command)

### 3. Database Connection Pooling

Add to environment variables:
```bash
DB_POOL_MIN=2
DB_POOL_MAX=10
```

### 4. Redis Optimization

Use persistent Redis connections:
```bash
REDIS_CLIENT=phpredis
```

### 5. CDN for Static Assets

Use Cloudflare or CloudFront to cache:
- `/assets/*`
- `/images/*`
- `/js/*`
- `/css/*`

---

## 🔄 CI/CD Pipeline

Railway automatically deploys on every push to your GitHub repository.

### Disable Auto-Deploy (Optional)

Railway Dashboard → Service → **Settings** → **Deploy** → Disable "Auto Deploy"

### Manual Deploy via CLI

```bash
railway up --service fleetbase-api
railway up --service fleetbase-queue
railway up --service fleetbase-scheduler
```

### Rollback Deployment

Railway Dashboard → Service → **Deployments** → Click previous deployment → "Redeploy"

---

## 💰 Cost Estimation

Railway pricing (as of 2024):

- **Hobby Plan**: $5/month credit (good for development)
- **Pro Plan**: $20/month base + usage
  - MySQL: ~$5-10/month (depending on storage)
  - Redis: ~$3-5/month
  - 3 Services: ~$15-30/month (depending on CPU/RAM usage)

**Estimated Monthly Cost for Production**: $40-70/month

---

## 📚 Additional Resources

- [Fleetbase Documentation](https://docs.fleetbase.io)
- [Railway Documentation](https://docs.railway.app)
- [Laravel Octane Documentation](https://laravel.com/docs/octane)
- [FrankenPHP Documentation](https://frankenphp.dev)

---

## 🆘 Support

### Fleetbase Issues
- GitHub: [fleetbase/fleetbase](https://github.com/fleetbase/fleetbase/issues)
- Discord: [Fleetbase Community](https://discord.gg/fleetbase)

### Railway Issues
- Discord: [Railway Community](https://discord.gg/railway)
- Help: [Railway Help Center](https://help.railway.app)

### reeup.io Custom Configuration
Contact your development team for reeup.io-specific configurations.

---

**Built with ❤️ for reeup.io**
