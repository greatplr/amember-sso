# Multi-Installation Support

This package supports **multiple aMember installations**, allowing you to manage users and subscriptions from different aMember sites in a single Laravel application.

## Overview

Each aMember installation is configured in the `amember_installations` table with:
- Unique API credentials
- IP address (for webhook identification)
- Login URL (for SSO redirects)
- Webhook secret (for security)

##

 Answers to Your Questions

### 1. What happens if users don't have a local account?

**Webhooks create them automatically!**

When a subscription webhook arrives:
1. Package checks if user exists by `amember_user_id` + `installation_id`
2. Falls back to email if not found
3. **Creates new user** if still not found with:
   - Email from webhook
   - Random secure password
   - `amember_user_id` and `amember_installation_id` set
   - Unique username (handles conflicts automatically)

```php
// Webhook receives purchase event
// → findOrCreateUser() called
// → User created if doesn't exist
// → Subscription added
// → User can now login
```

### 2. Login from different aMember sites?

**Yes! Users are matched by `amember_user_id` + `installation_id`**

- Same user can exist across multiple installations
- Each gets a unique `(amember_user_id, installation_id)` pair
- Login matches them correctly

Example:
```
User john@example.com:
- Installation A (id=1): amember_user_id=123
- Installation B (id=2): amember_user_id=456

Both stored in same users table, differentiated by installation_id
```

### 3. Admin Interface?

**Multiple options:**

**Option A: Use Filament** (like your link-controller)
- Package provides models and migrations
- You add Filament resources (see example below)
- Full CRUD interface for installations

**Option B: Custom Admin**
- Use provided `AmemberInstallation` model
- Build your own forms/tables

**Option C: Manual Database**
- Insert installations directly via migration/seeder

## Database Schema

### amember_installations
```php
- id
- name (e.g., "Main Site")
- slug (e.g., "main-site")
- api_url (e.g., "https://example.com/api")
- ip_address (for webhook detection)
- login_url (for SSO redirects)
- api_key
- webhook_secret
- is_active
- notes
```

### users (additions)
```php
- amember_user_id (their ID in aMember)
- amember_installation_id (which installation)
```

### amember_subscriptions (additions)
```php
- installation_id (which installation)
```

## Setup

### 1. Run Migrations

```bash
php artisan migrate
```

### 2. Add Installation Records

**Option A: Via Seeder**

```php
use Greatplr\AmemberSso\Models\AmemberInstallation;

AmemberInstallation::create([
    'name' => 'Main Site',
    'slug' => 'main-site',
    'api_url' => 'https://main.example.com/api',
    'ip_address' => '192.168.1.100',
    'login_url' => 'https://main.example.com/login',
    'api_key' => 'your-api-key',
    'webhook_secret' => 'your-webhook-secret',
    'is_active' => true,
]);

AmemberInstallation::create([
    'name' => 'Partner Site',
    'slug' => 'partner-site',
    'api_url' => 'https://partner.example.com/api',
    'ip_address' => '192.168.1.101',
    'login_url' => 'https://partner.example.com/login',
    'api_key' => 'partner-api-key',
    'webhook_secret' => 'partner-webhook-secret',
    'is_active' => true,
]);
```

**Option B: Via Filament** (see below)

### 3. Configure Webhooks in Each aMember

For each aMember installation, configure:

**URL:** `https://your-laravel-app.com/amember/webhook`
**Method:** POST
**Secret:** (same as `webhook_secret` in database)
**Header:** `X-Amember-Signature: <hmac-sha256>`

Events to send:
- subscription.added
- subscription.updated
- subscription.deleted
- payment.completed
- payment.refunded

### 4. Configure Each Webhook Payload

aMember should send these fields:
```json
{
  "event": "subscription.added",
  "data": {
    "user_id": 123,
    "email": "user@example.com",
    "username": "johndoe",
    "name_f": "John",
    "name_l": "Doe",
    "product_id": 1,
    "access_id": 456,
    "begin_date": "2024-01-01",
    "expire_date": "2025-01-01"
  }
}
```

## How It Works

### Webhook Flow

```
1. aMember sends webhook from 192.168.1.100
   ↓
2. Package finds installation by IP address
   ↓
3. Verifies signature using installation's webhook_secret
   ↓
4. Finds or creates user with:
   - amember_user_id from webhook
   - amember_installation_id = installation.id
   ↓
5. Creates/updates subscription linked to installation
   ↓
6. User can now login
```

### Login Flow

```
1. User submits email/password
   ↓
2. App calls: AmemberSso::authenticateByLoginPass(email, pass)
   ↓
3. Checks against aMember (you need to specify which installation)
   ↓
4. Matches local user by amember_user_id + installation_id
   ↓
5. Logs user into Laravel
```

### Multi-Installation Login

If you have multiple installations, you need to know which one to authenticate against:

**Option 1: User selects installation**
```php
$installation = AmemberInstallation::find($request->input('installation_id'));

// Use installation-specific API client
$client = $installation->getApiClient();
// ... authenticate
```

**Option 2: Try all active installations**
```php
$installations = AmemberInstallation::active()->get();

foreach ($installations as $installation) {
    // Try to authenticate against this installation
    $accessData = /* check-access call to this installation */;

    if ($accessData && $accessData['ok']) {
        // Found! Login with this installation
        $user = $this->findLocalUser(
            $accessData['user_id'],
            $email,
            $installation->id
        );
        break;
    }
}
```

**Option 3: Subdomain routing**
```php
// main.yourapp.com → Installation 1
// partner.yourapp.com → Installation 2

$subdomain = request()->getHost();
$installation = AmemberInstallation::bySlug($subdomain)->first();
```

## Filament Admin Interface (Optional)

Create a Filament resource to manage installations:

```php
// app/Filament/Resources/AmemberInstallationResource.php

namespace App\Filament\Resources;

use Greatplr\AmemberSso\Models\AmemberInstallation;
use Filament\Forms;
use Filament\Resources\Resource;
use Filament\Tables;

class AmemberInstallationResource extends Resource
{
    protected static ?string $model = AmemberInstallation::class;

    protected static ?string $navigationIcon = 'heroicon-o-server-stack';

    protected static ?string $navigationGroup = 'Settings';

    public static function form(Forms\Form $form): Forms\Form
    {
        return $form
            ->schema([
                Forms\Components\TextInput::make('name')
                    ->required()
                    ->maxLength(100),

                Forms\Components\TextInput::make('slug')
                    ->required()
                    ->unique(ignoreRecord: true)
                    ->maxLength(100),

                Forms\Components\TextInput::make('api_url')
                    ->label('API URL')
                    ->required()
                    ->url()
                    ->placeholder('https://example.com/api'),

                Forms\Components\TextInput::make('ip_address')
                    ->label('IP Address')
                    ->placeholder('192.168.1.100')
                    ->helperText('For webhook identification'),

                Forms\Components\TextInput::make('login_url')
                    ->label('Login URL')
                    ->url()
                    ->placeholder('https://example.com/login'),

                Forms\Components\TextInput::make('api_key')
                    ->label('API Key')
                    ->required()
                    ->password()
                    ->revealable(),

                Forms\Components\TextInput::make('webhook_secret')
                    ->password()
                    ->revealable(),

                Forms\Components\Toggle::make('is_active')
                    ->default(true),

                Forms\Components\Textarea::make('notes')
                    ->rows(3),
            ]);
    }

    public static function table(Tables\Table $table): Tables\Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('name')
                    ->searchable()
                    ->sortable(),

                Tables\Columns\TextColumn::make('slug')
                    ->searchable(),

                Tables\Columns\TextColumn::make('ip_address')
                    ->label('IP'),

                Tables\Columns\IconColumn::make('is_active')
                    ->boolean(),

                Tables\Columns\TextColumn::make('created_at')
                    ->dateTime()
                    ->sortable(),
            ])
            ->filters([
                Tables\Filters\TernaryFilter::make('is_active'),
            ]);
    }
}
```

## Testing

### Test Webhook Reception

```bash
curl -X POST https://your-app.com/amember/webhook \
  -H "Content-Type: application/json" \
  -H "X-Amember-Signature: <calculated-hmac>" \
  -d '{
    "event": "subscription.added",
    "data": {
      "user_id": 123,
      "email": "test@example.com",
      "product_id": 1,
      "access_id": 456
    }
  }'
```

### Check Logs

```php
// Check webhook logs
DB::table('amember_webhook_logs')
    ->orderBy('created_at', 'desc')
    ->limit(10)
    ->get();

// Check created users
User::whereNotNull('amember_installation_id')->get();

// Check subscriptions
DB::table('amember_subscriptions')
    ->where('installation_id', 1)
    ->get();
```

## Security

### Webhook IP Whitelisting

The package identifies installations by IP address. Make sure:

1. **Static IPs:** Each aMember installation has a static IP
2. **Firewall Rules:** Only allow webhooks from known IPs
3. **Signature Verification:** Always set `webhook_secret`

### API Key Security

- Store API keys encrypted (use Laravel's encryption)
- Never expose in logs or error messages
- Rotate periodically

## Troubleshooting

### Webhook from unknown IP

```
aMember webhook from unknown IP: 192.168.1.200
```

**Solution:** Add installation with that IP to `amember_installations` table

### User not found on login

```
User not found locally. They need to be created via webhook first
```

**Solution:**
1. Check if webhook was received and processed
2. Check `amember_webhook_logs` table
3. Manually trigger a subscription event in aMember
4. Or manually create user with correct `amember_user_id` and `installation_id`

### Duplicate users

If you have duplicate users (same email, different installations):

```php
// Find duplicates
User::select('email', DB::raw('COUNT(*) as count'))
    ->groupBy('email')
    ->having('count', '>', 1)
    ->get();
```

This is expected behavior if the same person subscribes to multiple installations. They're differentiated by `installation_id`.

## Migration from Single Installation

If you're migrating from a single-installation setup:

```php
// 1. Create your first installation
$installation = AmemberInstallation::create([...]);

// 2. Update existing users
User::whereNotNull('amember_user_id')
    ->whereNull('amember_installation_id')
    ->update(['amember_installation_id' => $installation->id]);

// 3. Update existing subscriptions
DB::table('amember_subscriptions')
    ->whereNull('installation_id')
    ->update(['installation_id' => $installation->id]);
```

## Summary

✅ **Multiple Installations:** Manage unlimited aMember sites
✅ **Auto User Creation:** Webhooks create users automatically
✅ **IP Detection:** Identifies installation by IP address
✅ **Secure:** Per-installation webhook secrets
✅ **Filament Ready:** Optional admin interface
✅ **Flexible Login:** Support various authentication flows
