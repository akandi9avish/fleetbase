# 🚂 Fleetbase on Railway - Quick Reference

Railway-optimized deployment configuration for Fleetbase logistics platform.

---

## 🎯 What's Included

This fork includes Railway-specific configuration files for easy deployment:

| File | Purpose |
|------|---------|
| `Dockerfile.railway` | Main API server |
| `Dockerfile.queue` | Background queue worker |
| `Dockerfile.scheduler` | Cron scheduler |
| `Dockerfile.console` | Ember.js frontend (optional) |
| `railway.toml` | Railway configuration |
| `.env.railway.example` | Environment template |
| `RAILWAY_DEPLOYMENT.md` | Complete deployment guide |
| `RAILWAY_CHANGES.md` | List of all modifications |

---

## ⚡ Quick Deploy (5 Minutes)

### 1. Generate APP_KEY
```bash
docker run --rm dunglas/frankenphp:1.5.0-php8.2 php -r "echo 'base64:'.base64_encode(random_bytes(32)).PHP_EOL;"
```

### 2. Deploy to Railway
1. Go to [Railway Dashboard](https://railway.app/dashboard)
2. Click "+ New Project" → "Deploy from GitHub repo"
3. Select this forked repository
4. Add MySQL and Redis databases

### 3. Create Services
Create three services from the same repository:
- **fleetbase-api** (Dockerfile: `Dockerfile.railway`)
- **fleetbase-queue** (Dockerfile: `Dockerfile.queue`)
- **fleetbase-scheduler** (Dockerfile: `Dockerfile.scheduler`)

### 4. Set Environment Variables
Copy from `.env.railway.example` to all three services.

### 5. Deploy!
Railway auto-deploys on push. Monitor logs:
```bash
railway logs --service fleetbase-api
```

---

## 📊 Architecture

```
┌─────────────────────────────────────────────┐
│           Railway Project                   │
│                                             │
│  ┌──────────┐  ┌──────────┐  ┌──────────┐ │
│  │   API    │  │  Queue   │  │Scheduler │ │
│  │(FrankenPHP)│ │ (Worker) │  │ (Cron)   │ │
│  └─────┬────┘  └────┬─────┘  └────┬─────┘ │
│        │            │              │       │
│        └────────────┴──────────────┘       │
│                     │                      │
│            ┌────────┴────────┐             │
│            │                 │             │
│       ┌────▼────┐      ┌────▼────┐        │
│       │  MySQL  │      │  Redis  │        │
│       └─────────┘      └─────────┘        │
└─────────────────────────────────────────────┘
```

---

## 🔧 Key Features

- ✅ **No AWS Dependencies**: Removes AWS SSM requirement
- ✅ **Auto Migrations**: Database migrations run automatically
- ✅ **Complete Packages**: All PHP extensions and Node.js packages included
- ✅ **Health Checks**: Built-in monitoring for all services
- ✅ **Private Networking**: Secure internal communication
- ✅ **Auto Deploy**: Push to GitHub → automatic deployment
- ✅ **Zero Code Changes**: Original Fleetbase code unchanged

---

## 📚 Documentation

- **Deployment Guide**: [RAILWAY_DEPLOYMENT.md](./RAILWAY_DEPLOYMENT.md)
- **Change Log**: [RAILWAY_CHANGES.md](./RAILWAY_CHANGES.md)
- **Environment Template**: [.env.railway.example](./.env.railway.example)

---

## 🆘 Need Help?

1. **Deployment Issues**: Check [RAILWAY_DEPLOYMENT.md](./RAILWAY_DEPLOYMENT.md) → Troubleshooting
2. **Configuration**: Review [.env.railway.example](./.env.railway.example)
3. **Changes**: See [RAILWAY_CHANGES.md](./RAILWAY_CHANGES.md) for all modifications

---

## 💡 Pro Tips

1. **Monitor Logs**: `railway logs --service <service-name> --follow`
2. **Run Commands**: `railway run --service fleetbase-api php artisan migrate:status`
3. **Database Access**: `railway connect MySQL`
4. **Restart Service**: `railway service restart fleetbase-api`
5. **Scale Up**: Railway Dashboard → Service → Settings → Deploy → Replicas

---

## 🔒 Security

- Use unique `APP_KEY` for each environment
- Set `APP_DEBUG=false` in production
- Enable `SESSION_SECURE_COOKIE=true`
- Use Railway's private networking for database/Redis
- Restrict Railway project access to team members only

---

## 💰 Cost Estimate

- **Development**: ~$5-10/month (Hobby plan)
- **Production**: ~$40-70/month (Pro plan with databases)

---

## 🚀 Built for reeup.io

This Railway configuration is optimized for reeup.io's cannabis retail operations.

---

**Quick Links**:
- [Railway Dashboard](https://railway.app/dashboard)
- [Fleetbase Docs](https://docs.fleetbase.io)
- [Railway Docs](https://docs.railway.app)

**Maintained by**: reeup.io development team
**Last Updated**: 2025-11-03
