# Webhook Logging Guide

The package automatically logs all incoming webhooks to help with debugging and monitoring.

## Database Logging (Always On)

Every webhook is logged to the `amember_webhook_logs` table:

```sql
CREATE TABLE amember_webhook_logs (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    event_type VARCHAR(255),        -- e.g., "accessAfterInsert"
    status VARCHAR(50),              -- "received", "queued", "processed", "failed", "error"
    payload TEXT,                    -- Full JSON payload from aMember
    message TEXT,                    -- Additional context/error message
    ip_address VARCHAR(45),          -- Source IP address
    created_at TIMESTAMP,
    updated_at TIMESTAMP
);
```

### Status Values

| Status | Meaning |
|--------|---------|
| `received` | Webhook received and validated successfully |
| `queued` | Dispatched to queue for background processing |
| `processed` | Successfully processed (synchronous mode) |
| `failed` | Failed validation (unknown IP, invalid signature, missing event) |
| `error` | Exception occurred during processing |
| `ignored` | Unknown event type (logged but not processed) |

## Viewing Webhook Logs

### Via Database Query

```sql
-- See all recent webhooks
SELECT
    id,
    event_type,
    status,
    message,
    ip_address,
    created_at
FROM amember_webhook_logs
ORDER BY created_at DESC
LIMIT 20;

-- See failed webhooks only
SELECT * FROM amember_webhook_logs
WHERE status IN ('failed', 'error')
ORDER BY created_at DESC;

-- View full payload for specific webhook
SELECT
    event_type,
    status,
    JSON_PRETTY(payload) as formatted_payload,
    message,
    created_at
FROM amember_webhook_logs
WHERE id = 123;

-- Count webhooks by type
SELECT
    event_type,
    COUNT(*) as total,
    SUM(CASE WHEN status = 'error' THEN 1 ELSE 0 END) as errors
FROM amember_webhook_logs
GROUP BY event_type
ORDER BY total DESC;

-- Recent errors
SELECT
    event_type,
    message,
    created_at
FROM amember_webhook_logs
WHERE status = 'error'
ORDER BY created_at DESC
LIMIT 10;
```

### Via Tinker

```bash
php artisan tinker
```

```php
// Get recent webhooks
DB::table('amember_webhook_logs')
    ->orderBy('created_at', 'desc')
    ->limit(10)
    ->get();

// Get failed webhooks
DB::table('amember_webhook_logs')
    ->whereIn('status', ['failed', 'error'])
    ->orderBy('created_at', 'desc')
    ->get();

// View specific webhook payload
$log = DB::table('amember_webhook_logs')->find(123);
json_decode($log->payload);

// Count by status
DB::table('amember_webhook_logs')
    ->select('status', DB::raw('count(*) as total'))
    ->groupBy('status')
    ->get();
```

### Via Filament (Optional)

If you're using Filament admin, you can create a resource to view webhook logs:

```php
// app/Filament/Resources/WebhookLogResource.php
class WebhookLogResource extends Resource
{
    protected static ?string $model = null;

    public static function table(Table $table): Table
    {
        return $table
            ->query(DB::table('amember_webhook_logs'))
            ->columns([
                Tables\Columns\TextColumn::make('event_type'),
                Tables\Columns\BadgeColumn::make('status')
                    ->colors([
                        'success' => ['received', 'queued', 'processed'],
                        'danger' => ['failed', 'error'],
                        'warning' => 'ignored',
                    ]),
                Tables\Columns\TextColumn::make('ip_address'),
                Tables\Columns\TextColumn::make('created_at')->dateTime(),
            ])
            ->defaultSort('created_at', 'desc');
    }
}
```

## Debug Mode (Verbose Logging)

For detailed debugging, enable debug mode to log all webhook details to Laravel's log file.

### Enable Debug Mode

Add to your `.env`:

```env
AMEMBER_DEBUG_WEBHOOKS=true
```

### What Gets Logged

When debug mode is enabled, each webhook logs to `storage/logs/laravel.log`:

```
[2025-10-20 19:00:00] local.DEBUG: aMember Webhook Debug {
    "event": "accessAfterInsert",
    "installation": "Main Site",
    "installation_id": 1,
    "ip": "23.226.68.98",
    "headers": {
        "content-type": ["application/json"],
        "user-agent": ["aMember PRO/6.3.35 (https://www.amember.com)"],
        "x-real-ip": ["23.226.68.98"]
    },
    "payload": {
        "am-webhooks-version": "1.0",
        "am-event": "accessAfterInsert",
        "access": {
            "access_id": "3252",
            "product_id": "5",
            ...
        },
        "user": {
            "user_id": "302",
            "email": "user@example.com",
            ...
        }
    }
}
```

### View Debug Logs

```bash
# Tail the log file
tail -f storage/logs/laravel.log

# Filter for webhook logs only
tail -f storage/logs/laravel.log | grep "aMember Webhook"

# View last 50 webhook debug logs
grep "aMember Webhook" storage/logs/laravel.log | tail -50
```

### When to Use Debug Mode

✅ **Use debug mode when:**
- Setting up webhooks for the first time
- Troubleshooting webhook failures
- Verifying payload structure
- Testing new webhook events

❌ **Disable in production** (generates large log files):
```env
AMEMBER_DEBUG_WEBHOOKS=false
```

## Monitoring Webhook Health

### Check for Recent Activity

```sql
-- Should see webhooks within last hour (if site is active)
SELECT COUNT(*) as recent_webhooks
FROM amember_webhook_logs
WHERE created_at > DATE_SUB(NOW(), INTERVAL 1 HOUR);

-- If 0, webhooks might not be configured in aMember
```

### Check Error Rate

```sql
SELECT
    COUNT(*) as total,
    SUM(CASE WHEN status IN ('failed', 'error') THEN 1 ELSE 0 END) as errors,
    ROUND(SUM(CASE WHEN status IN ('failed', 'error') THEN 1 ELSE 0 END) / COUNT(*) * 100, 2) as error_rate
FROM amember_webhook_logs
WHERE created_at > DATE_SUB(NOW(), INTERVAL 24 HOUR);

-- Error rate should be < 5%
```

### Check by Installation

```sql
SELECT
    ip_address,
    COUNT(*) as total,
    MAX(created_at) as last_webhook
FROM amember_webhook_logs
GROUP BY ip_address
ORDER BY total DESC;
```

## Troubleshooting

### No Webhooks Being Logged

1. **Check aMember webhook configuration**
   - Admin → Setup/Configuration → Webhooks
   - Verify URL is correct: `https://yourapp.com/amember/webhook`
   - Check events are enabled

2. **Check firewall**
   ```bash
   # Test if webhook endpoint is reachable
   curl -X POST https://yourapp.com/amember/webhook \
     -H "Content-Type: application/json" \
     -d '{"am-event":"test"}'
   ```

3. **Check webhook_logs table exists**
   ```bash
   php artisan migrate
   ```

### Webhooks Logged but Status = 'failed'

Check the `message` field:

```sql
SELECT message, COUNT(*) as occurrences
FROM amember_webhook_logs
WHERE status = 'failed'
GROUP BY message;
```

Common failures:
- **"Unknown installation IP"** - Add installation's IP to `amember_installations` table
- **"Invalid signature"** - Check `webhook_secret` matches aMember config
- **"No am-event field"** - aMember not sending correct payload format

### Webhooks Logged but Status = 'error'

Check Laravel logs for exceptions:

```bash
tail -f storage/logs/laravel.log | grep "Webhook processing failed"
```

Common errors:
- Database connection issues
- Missing user fields (email required)
- Invalid data types

## Log Retention

The webhook logs table can grow large. Consider adding log rotation:

### Manual Cleanup

```sql
-- Delete logs older than 30 days
DELETE FROM amember_webhook_logs
WHERE created_at < DATE_SUB(NOW(), INTERVAL 30 DAY);

-- Keep only failed/error logs older than 7 days
DELETE FROM amember_webhook_logs
WHERE created_at < DATE_SUB(NOW(), INTERVAL 7 DAY)
AND status NOT IN ('failed', 'error');
```

### Automated Cleanup (Scheduled Task)

Add to `app/Console/Kernel.php`:

```php
protected function schedule(Schedule $schedule)
{
    // Clean up old webhook logs every week
    $schedule->call(function () {
        DB::table('amember_webhook_logs')
            ->where('created_at', '<', now()->subDays(30))
            ->delete();
    })->weekly();
}
```

Or create a custom command:

```bash
php artisan make:command CleanWebhookLogs
```

```php
public function handle()
{
    $days = $this->option('days', 30);

    $deleted = DB::table('amember_webhook_logs')
        ->where('created_at', '<', now()->subDays($days))
        ->delete();

    $this->info("Deleted {$deleted} webhook logs older than {$days} days");
}
```

## Summary

✅ **Already logging:**
- All webhooks to `amember_webhook_logs` table
- Event type, status, full payload, IP, timestamp
- Always enabled (can't be disabled)

✅ **Optional debug mode:**
- Set `AMEMBER_DEBUG_WEBHOOKS=true`
- Logs verbose details to Laravel log file
- Great for development/troubleshooting
- Disable in production

✅ **Monitor with:**
- Direct SQL queries
- Tinker for quick checks
- Filament admin (optional)
- Horizon for queue jobs

✅ **Clean up with:**
- Scheduled tasks
- Manual SQL deletes
- Custom artisan commands
