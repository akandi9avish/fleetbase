# REEUP Custom IAM Roles Seeding

## Overview

This document explains the REEUP custom IAM (Identity and Access Management) roles implementation for the Fleetbase delivery platform integration.

## Problem Solved

**Issue:** Entity users (retailers, distributors, etc.) were getting authentication errors:
```
❌ Role 'Reeup Retailer' not found in Fleetbase
```

**Root Cause:** Fleetbase IAM roles weren't created for REEUP entity types.

## Solution Architecture

### 1. Custom Auth Schema (`api/app/Auth/Schemas/Reeup.php`)

Defines entity-type-specific roles following Fleetbase's IAM pattern:

```php
namespace App\Auth\Schemas;

class Reeup
{
    public string $name = 'reeup';
    public array $roles = [
        [
            'name' => 'Reeup Retailer',
            'description' => 'Role for retail dispensary operators...',
            'policies' => ['ReeupRetailOperations'],
        ],
        // ... more roles
    ];
}
```

### 2. Artisan Command (`api/app/Console/Commands/SeedReeupRoles.php`)

Command to seed roles during deployment:

```bash
php artisan reeup:seed-roles
```

### 3. Deployment Integration (`railway/init-api.sh`)

Automatically runs during Railway deployment:

```bash
# Line 24-25
echo "🌱 Seeding REEUP custom IAM roles..."
php artisan reeup:seed-roles
```

## Role Mapping

Maps REEUP entity types to Fleetbase IAM roles:

| Entity Type ID | Entity Type Name | Fleetbase Role | Capabilities |
|----------------|------------------|----------------|--------------|
| 1 | Cultivator | `Reeup Cultivator` | Track deliveries for product shipments |
| 2 | Processor | `Reeup Processor` | Track deliveries for processed products |
| 3 | Retailer | `Reeup Retailer` | Full delivery management + driver coordination |
| 4 | Distributor | `Reeup Distributor` | Full fleet management + vehicle tracking |
| 5 | Microbusiness | `Reeup Microbusiness` | Combined retail + cultivation operations |
| 6 | Admin | `Administrator` | Full system access (built-in role) |

## Files Created

```
microservices/fleetbase-official/
├── api/
│   ├── app/
│   │   ├── Auth/
│   │   │   └── Schemas/
│   │   │       └── Reeup.php                    # Auth schema definition
│   │   └── Console/
│   │       └── Commands/
│   │           └── SeedReeupRoles.php           # Seeding command
│   └── railway/
│       └── init-api.sh                          # Deployment script (modified)
└── REEUP_IAM_ROLES_SEEDING.md                  # This file
```

## Usage

### Manual Seeding (Development)

```bash
cd microservices/fleetbase-official/api

# Seed roles
php artisan reeup:seed-roles

# Reset and reseed
php artisan reeup:seed-roles --reset
```

### Automatic Seeding (Production)

Runs automatically during Railway deployment as part of `init-api.sh`:

1. Migrations run
2. Fleetbase permissions created (`fleetbase:create-permissions`)
3. **REEUP roles seeded** (`reeup:seed-roles`) ⬅️ **NEW**
4. Queue workers restarted
5. Application deployed

## Roles and Permissions

### Reeup Retailer
**Description:** Retail dispensary operators
**Permissions:**
- View delivery dashboard
- Manage orders
- Track deliveries
- View/List drivers

**Policies:**
- ReeupRetailOperations

---

### Reeup Distributor
**Description:** Distribution and logistics operators
**Permissions:**
- Full delivery management
- Driver assignment and tracking
- Vehicle management
- Fleet operations

**Policies:**
- ReeupDistributionOperations

---

### Reeup Cultivator
**Description:** Cannabis cultivation operators
**Permissions:**
- Track deliveries
- View delivery dashboard

**Policies:**
- ReeupCultivationOperations

---

### Reeup Processor
**Description:** Processing and manufacturing operators
**Permissions:**
- Track deliveries
- View delivery dashboard

**Policies:**
- ReeupProcessingOperations

---

### Reeup Microbusiness
**Description:** Integrated micro-operations
**Permissions:**
- Combined retail + cultivation permissions

**Policies:**
- ReeupRetailOperations
- ReeupCultivationOperations

## Integration with Backend API

The backend API (`backend_api/services/fleetbase_sync_service.py`) uses these roles:

```python
# Line 34-43
ENTITY_TYPE_ROLE_MAPPING = {
    1: 'Reeup Cultivator',
    2: 'Reeup Processor',
    3: 'Reeup Retailer',      # ← Now exists in Fleetbase!
    4: 'Reeup Distributor',
    5: 'Reeup Microbusiness',
    6: 'Administrator'
}
```

When a user logs in:
1. Backend API syncs user to Fleetbase
2. Looks up role UUID for entity type
3. **Role now exists** ✅ (previously failed with "not found")
4. Assigns role to user
5. User gets appropriate permissions

## Testing

### Verify Roles Were Created

```bash
# SSH into Railway container or run locally
php artisan tinker

# Check roles
>>> use Fleetbase\Models\Role;
>>> Role::where('service', 'reeup')->get()->pluck('name');

# Expected output:
[
  "Reeup Retailer",
  "Reeup Distributor",
  "Reeup Cultivator",
  "Reeup Processor",
  "Reeup Microbusiness"
]
```

### Verify User Assignment

```sql
-- Check role assignments in MySQL
SELECT
    users.name,
    users.email,
    roles.name as role_name
FROM users
JOIN model_has_roles ON users.uuid = model_has_roles.model_uuid
JOIN roles ON model_has_roles.role_id = roles.id
WHERE roles.service = 'reeup';
```

## Deployment

### Changes Required

**Files to Commit:**
```bash
git add microservices/fleetbase-official/api/app/Auth/Schemas/Reeup.php
git add microservices/fleetbase-official/api/app/Console/Commands/SeedReeupRoles.php
git add microservices/fleetbase-official/railway/init-api.sh
git add microservices/fleetbase-official/REEUP_IAM_ROLES_SEEDING.md
```

### Deployment Process

1. **Push to Git:**
   ```bash
   git commit -m "feat: Add REEUP custom IAM roles seeding for Fleetbase"
   git push origin master
   ```

2. **Railway Auto-Deploy:**
   - Detects push
   - Builds Fleetbase service
   - Runs `railway/init-api.sh`
   - Executes `php artisan reeup:seed-roles`
   - Roles created automatically

3. **Verify Deployment:**
   ```bash
   # Check Railway logs
   railway logs --service fleetbase

   # Look for:
   # 🌱 Seeding REEUP custom IAM roles...
   # ✅ REEUP roles seeded successfully!
   ```

## Expected Logs

### Success
```
🌱 Seeding REEUP custom IAM roles...
📋 Creating permissions...
  ✓ reeup view-delivery-dashboard
  ✓ reeup manage-orders
  ...
📜 Creating policies...
  ✓ ReeupRetailOperations (8 permissions)
  ...
👥 Creating roles...
  ✓ Reeup Retailer
    Policies: 1
    Direct Permissions: 4
  ✓ Reeup Distributor
    Policies: 1
    Direct Permissions: 5
  ...
✅ REEUP roles seeded successfully!
```

### Failure Indicators
```
❌ Failed to seed REEUP roles: [error message]
⚠️ REEUP roles seeding failed (may need manual intervention)
```

## Troubleshooting

### Role Not Found After Seeding

**Problem:** Backend still says "Role 'Reeup Retailer' not found"

**Solution:**
```bash
# SSH into Fleetbase container
railway shell --service fleetbase

# Manually run seeding
php artisan reeup:seed-roles --reset

# Check roles were created
php artisan tinker
>>> Role::where('service', 'reeup')->count();
```

### Permission Errors

**Problem:** User has role but can't access features

**Solution:**
1. Check role has correct policies assigned
2. Verify policies have permissions
3. Manually assign missing permissions:
   ```bash
   php artisan tinker
   >>> $role = Role::where('name', 'Reeup Retailer')->first();
   >>> $role->permissions; // Check what's assigned
   ```

### Deployment Skips Seeding

**Problem:** Seeding command doesn't run

**Solution:**
1. Check `railway/init-api.sh` has the command
2. Verify script has execute permissions:
   ```bash
   chmod +x railway/init-api.sh
   ```
3. Check Railway logs for errors

## Maintenance

### Adding New Roles

1. Edit `api/app/Auth/Schemas/Reeup.php`
2. Add role to `$roles` array
3. Define permissions and policies
4. Commit and deploy
5. Run `php artisan reeup:seed-roles` (automatic on deploy)

### Modifying Existing Roles

1. Edit role definition in schema
2. Run with `--reset` flag to recreate:
   ```bash
   php artisan reeup:seed-roles --reset
   ```
3. Existing user assignments will be updated

### Deleting Roles

```bash
php artisan tinker

# Remove specific role
>>> Role::where('name', 'Reeup OldRole')->delete();

# Remove all REEUP roles
>>> Role::where('service', 'reeup')->delete();
```

## References

- Fleetbase IAM Documentation: Roles and Policies
- Backend API Mapping: `backend_api/services/fleetbase_sync_service.py:34-43`
- Railway Deployment: `railway/init-api.sh:24-25`

---

**Created:** November 10, 2025
**Status:** ✅ READY FOR DEPLOYMENT
