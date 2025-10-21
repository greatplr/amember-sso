# Queue Setup for Webhook Processing

This package supports **background processing** of webhooks using Laravel queues (Horizon/Redis recommended).

## Why Use Queues?

**Without queues** (synchronous):
- Webhook waits ~50-200ms for DB operations
- aMember might timeout and retry
- Failures block the HTTP response
- No visibility into processing

**With queues** (async):
- ✅ Webhook responds in ~5-10ms
- ✅ Reliable retry logic (3 attempts with backoff)
- ✅ Monitor with Horizon dashboard
- ✅ Failed jobs can be manually retried
- ✅ Prevents aMember timeouts

## Quick Setup

### 1. Configure Environment

Add to your `.env`:

```env
# Enable queue processing (default: true)
AMEMBER_WEBHOOK_USE_QUEUE=true

# Queue name for webhooks (default: amember-webhooks)
AMEMBER_WEBHOOK_QUEUE=amember-webhooks

# Redis connection for queues
QUEUE_CONNECTION=redis
REDIS_CLIENT=phpredis
REDIS_HOST=127.0.0.1
REDIS_PASSWORD=null
REDIS_PORT=6379
```

### 2. Update config/queue.php

Add the webhook queue to your `config/queue.php`:

```php
'connections' => [
    'redis' => [
        'driver' => 'redis',
        'connection' => env('REDIS_QUEUE_CONNECTION', 'default'),
        'queue' => env('QUEUE_REDIS_QUEUE', 'default'),
        'retry_after' => 90,
        'block_for' => null,
        'after_commit' => false,
    ],
],
```

### 3. Install Horizon (Recommended)

```bash
composer require laravel/horizon
php artisan horizon:install
```

Update `config/horizon.php`:

```php
'defaults' => [
    'supervisor-1' => [
        'connection' => 'redis',
        'queue' => ['default', 'amember-webhooks'], // Add amember-webhooks
        'balance' => 'auto',
        'processes' => 3,
        'tries' => 3,
        'timeout' => 60,
    ],
],
```

### 4. Start Processing

```bash
# Development
php artisan horizon

# Production (use Supervisor)
sudo supervisorctl start horizon
```

## Monitoring with Horizon

Access Horizon dashboard at: `https://yourapp.com/horizon`

You'll see:
- ✅ Pending webhooks
- ✅ Processing webhooks in real-time
- ✅ Failed jobs with full error details
- ✅ Throughput metrics
- ✅ Retry failed jobs with one click

## Without Queues (Testing/Development)

To disable queues and process synchronously:

```env
AMEMBER_WEBHOOK_USE_QUEUE=false
```

No queue worker needed, webhooks process inline (slower response).

## Supervisor Configuration

For production, use Supervisor to keep Horizon running:

`/etc/supervisor/conf.d/horizon.conf`:

```ini
[program:horizon]
process_name=%(program_name)s
command=php /path/to/your/app/artisan horizon
autostart=true
autorestart=true
user=www-data
redirect_stderr=true
stdout_logfile=/path/to/your/app/storage/logs/horizon.log
stopwaitsecs=3600
```

Restart Supervisor:

```bash
sudo supervisorctl reread
sudo supervisorctl update
sudo supervisorctl start horizon
```

## Retry Configuration

The webhook job is configured to:
- **Tries**: 3 attempts
- **Backoff**: 60 seconds between retries
- **Timeout**: 60 seconds per attempt

If a webhook fails 3 times, it appears in Horizon's "Failed Jobs" for manual inspection/retry.

## Queue Names

By default, webhooks use the `amember-webhooks` queue. This separates webhook processing from other queue jobs.

**Benefits:**
- Dedicated workers for webhooks
- Won't block other jobs
- Easy to monitor webhook-specific metrics
- Can scale webhook workers independently

## Troubleshooting

### Webhooks Not Processing

1. **Check queue worker is running:**
   ```bash
   php artisan horizon:status
   # or
   php artisan queue:work --queue=amember-webhooks
   ```

2. **Check Redis connection:**
   ```bash
   php artisan tinker
   >>> Redis::ping()
   # Should return PONG
   ```

3. **Check webhook logs:**
   ```php
   DB::table('amember_webhook_logs')
       ->where('status', 'queued')
       ->orderBy('created_at', 'desc')
       ->get();
   ```

### Jobs Failing

1. **View failed jobs in Horizon:**
   - Navigate to `/horizon/failed`
   - Click job to see error details
   - Click "Retry" to reprocess

2. **Check Laravel logs:**
   ```bash
   tail -f storage/logs/laravel.log
   ```

3. **Manually retry all failed jobs:**
   ```bash
   php artisan queue:retry all
   ```

### Slow Processing

1. **Increase worker count** in `config/horizon.php`:
   ```php
   'processes' => 10, // More workers
   ```

2. **Check database performance:**
   - Ensure indexes on `amember_subscriptions` table
   - Check slow query log

3. **Monitor with Horizon:**
   - Look for high "Wait Time"
   - Check "Jobs per Minute" vs incoming rate

## Performance Comparison

### Synchronous (No Queue)
```
aMember sends webhook
    ↓ (50-200ms)
Create user + subscription in DB
    ↓
Clear cache
    ↓
Fire events
    ↓ (200ms total)
Return 200 OK to aMember
```

### Asynchronous (With Queue)
```
aMember sends webhook
    ↓ (5-10ms)
Push to Redis queue
    ↓
Return 200 OK to aMember (fast!)

(Meanwhile, in background worker:)
Process job from queue
    ↓
Create user + subscription
    ↓
Clear cache
    ↓
Fire events
```

## Current Webhook Examples

Based on your real webhook data:

### ✅ accessAfterInsert
Real example captured and documented in `WEBHOOK_PAYLOADS.md`

### ✅ subscriptionDeleted
Real example captured and documented in `WEBHOOK_PAYLOADS.md`

### ❌ accessAfterDelete
**Missing - please capture this webhook when you can:**
1. Process a refund in aMember
2. Check `amember_webhook_logs` table for the payload
3. Send us the full payload for documentation

This will help verify the queue processing works correctly for all your webhooks.

## Recommended: Test Queue Setup

1. **Install package with queues enabled**
2. **Start Horizon:** `php artisan horizon`
3. **Trigger test webhook** in aMember (or use test purchase)
4. **Watch Horizon dashboard** - you should see:
   - Job appear in "Pending"
   - Move to "Processing"
   - Complete successfully
5. **Check database** - subscription should be created
6. **View logs** - should show "queued" status

If all checks pass, your queue setup is working perfectly!
