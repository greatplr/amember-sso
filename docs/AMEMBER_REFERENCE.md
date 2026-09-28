# aMember behaviour this package depends on

Read from the aMember Pro 6.3.49 source (the `api` and `webhooks` modules) on
2026-09-28. aMember is commercial software, so its code is not in this repo: this
file records how it behaves, which is what the package has to match. Section
references name the aMember files, for checking against a newer version.

## REST API (`application/api`)

### Routing

- Base URL: `{amember_root}/api/{controller}[/{id}][/{action}]`.
  `{amember_root}` is where aMember is installed, e.g. `https://members.example.com`
  or `https://example.com/amember`, so an installation's `api_url` is `{root}/api`.
- Method selection (`controllers/ApiController.php::match`):
  - `GET /api/users` → `index` (list); `GET /api/users/12` → `get` (one record).
  - `POST /api/users` → `post` (create).
  - `PUT` / `DELETE /api/users/12` → update / delete. The id is required; PUT
    bodies are read as a url-encoded string.
  - A `_method` parameter overrides the HTTP method.
  - For controllers without records, the path segment after the controller is the
    action: `POST /api/check-access/by-login-pass` calls `byLoginPass`. Action
    names are kebab-case in the URL.
- Unknown controller → exception ("No API Action set"), not a JSON error body.

### Authentication (`Bootstrap.php::checkPermissions`)

- API key in header `X-API-Key`, or as the `_key` parameter. The header wins.
- Errors are exceptions rendered by aMember, so expect a non-2xx response with an
  HTML or text body, **not** JSON:
  - 10001: no key, or key shorter than 10 characters
  - 10002: key not found or disabled
  - 10003: key lacks permission for `{controller}-{method}`. Permissions are per
    controller *and* per action: each check-access action (`by-login-pass`,
    `by-login-pass-ip`, `by-login`, `by-email`, `send-pass`) is ticked separately,
    as are `index`, `get`, `post`, `put`, `delete` on table controllers.
  - 10004: the key has an IP allowlist and the caller's IP isn't on it.
    Allowlist entries are prefix matches on `REMOTE_ADDR`.
- A module can bypass the check through the `apiCheckPermissions` hook.

### Table controllers: `users`, `access`, `products`, `invoices`, ...

(`library/Am/ApiController/Table.php`)

Query parameters for `index`:

| Param | Meaning |
|---|---|
| `_filter[field]=value` | Exact match; a value containing `%` becomes `LIKE`. Several filters are ANDed. Field names are table columns. |
| `_count` | Page size. Default 20, **maximum 1000**. |
| `_page` | Zero-based page number. |
| `_sort`, `_order` | Sort column; `_order=desc` for descending. |
| `_nested[]=name` | Include related records (below). |
| `_format` | `json` (default), `xml` or `serialize`. |

List response (JSON): an object with `_total` (count ignoring paging) plus the
records under keys `0`, `1`, ... Because of the numeric keys it decodes to an array
like `['_total' => 2, 0 => [...], 1 => [...]]`. **Strip `_total` before treating the
rest as a list**, and don't assume `count()` is the number of records.

Single record (`GET /api/users/12`): the same shape, `[0 => [...]]` without
`_total`.

Nested records appear under each record's `nested` key:
`$record['nested']['access'][...]`. Only relations the controller declares are
allowed; asking for another throws "Nested relation [x] is not defined":

- `users`: `invoices`, `access`, `user-consent`
- `products`: `billing-plans` (included by default), `product-product-category`
- `access`: **none**. `GET /api/access?_nested[]=product` fails. To get products
  for access records, read `product_id` and fetch `/api/products` separately.

`users` records always have `pass` nulled.

### check-access (`library/Am/Api/CheckAccess.php`)

All actions read form parameters, so POST them `asForm()`:

| Action | Params | Notes |
|---|---|---|
| `by-login-pass` | `login`, `pass` | Real authentication. `login` may be the username **or** the email. |
| `by-login-pass-ip` | `login`, `pass`, `ip` | As above, plus account-sharing protection (below). `ip` must be the end user's real IP. |
| `by-login` | `login` | No password check. |
| `by-email` | `email` | No password check. |
| `send-pass` | `login` (username or email) | Emails a password reset link. Returns `{"ok": true, "msg": ...}` or `{"ok": false}`. |

The response is always JSON with HTTP 200, whether or not the user got in.

Success:

```json
{
  "ok": true,
  "user_id": 123,
  "name": "Jane Doe",
  "name_f": "Jane",
  "name_l": "Doe",
  "email": "jane@example.com",
  "login": "jane",
  "subscriptions": {"4": "2037-12-31", "7": "2026-10-31"},
  "categories": {"2": "2037-12-31"},
  "groups": [1, 3],
  "resources": ["<a href=...>...</a>"]
}
```

- `subscriptions` maps **active** product id → expiry date (`Y-m-d`). Lifetime
  access expires `2037-12-31`. Expired products are not listed. As JSON the keys
  are strings; with numeric keys PHP may re-index when decoding, so read it as a
  map, never as a list.
- `categories` maps product category id → the latest expiry among the user's
  active products in that category.
- `resources` holds rendered HTML links to protected content; ignore it.

Failure: `{"ok": false, "code": <int>, "msg": "<text>"}`. Codes
(`library/Am/Auth/Result.php`):

| Code | Meaning |
|---|---|
| -1 | Invalid input, or (from `by-login`/`by-email`) user not found |
| -2 | Wrong credentials |
| -3 | Internal error |
| -4 | Too many failed attempts. aMember's brute-force protection, keyed by the caller's IP, i.e. **our server's IP**, so one attacker hammering our login form can lock out every user logging in through it. |
| -5 | Account locked (by an admin, or by account-sharing protection) |
| -6 | User not found |
| -7 | Account not approved yet |

A user who authenticates but has no active products still gets `ok: true` with an
empty `subscriptions`. Having no access is not an authentication failure.

**Account-sharing protection with `by-login-pass-ip`** (`library/Am/Auth/User.php::checkUser`):
the `ip` is written to the user's access log, and if the number of distinct IPs
exceeds aMember's configured limit the account is **locked** (code -5). So:

- only ever send the visitor's real IP, never a placeholder, id or server IP
  (Link Controller currently sends its installation id here);
- behind Cloudflare/a proxy, resolve the real client IP first, or every login
  looks like one IP (harmless) or a wrong one;
- if unsure, use `by-login-pass`, which does no IP logging.

## Webhooks (`application/webhooks`)

### Delivery

- `POST` to the configured URL, **form-encoded** (not JSON). Nested objects are
  flattened one level: `user[email]=...`, `product[product_id]=...`. Laravel's
  `$request->input('user.email')` reads them normally.
- The URL is a template; `{placeholders}` from the payload are substituted.
- Queued and sent by aMember's cron (runs every minute, 50-second budget), unless
  `AM_WEBHOOKS_INSTANT` is defined. Expect up to a minute or two of delay.
- **Only HTTP 200 counts as delivered.** Anything else (including 201, 202 or 204)
  is a failure, retried every 5 minutes, up to 10 failures; after that the
  admin gets a "webhook failed" email if enabled. Retries resend the same payload
  with a new request, so handlers must be idempotent.
- Headers are the lines an admin types into the webhook's "Headers" field in
  aMember, sent verbatim. **aMember does not sign webhooks.** There is no HMAC,
  no timestamp signature and no secret, so `X-Amember-Signature` only exists if
  an admin typed it in as a fixed header.

Consequence for `WebhookController::verifyWebhookSignature`: it computes an
HMAC-SHA256 of the body and compares it with `X-Amember-Signature`. aMember never
produces that, so any installation with `webhook_secret` set rejects every
webhook with 403, and installations without one accept anything from an allowed
IP. The workable scheme is a shared secret in a fixed header: the admin adds
`X-Amember-Secret: <secret>` to the webhook in aMember, and the package compares
it with `hash_equals()`. Say so in the setup docs.

### Payload

Every webhook has:

| Field | Value |
|---|---|
| `am-webhooks-version` | `1.0` |
| `am-event` | Event id, camelCase, e.g. `subscriptionAdded` |
| `am-timestamp` | ISO 8601 |
| `am-root-url` | The aMember root URL. Useful for identifying the installation alongside the source IP. |

Plus objects per event. Each object is the record's database columns. `user` also
has `plain_password` when aMember knows it at that moment (e.g. signup, password
set), and never has `last_session`. `data.*` keys carry extra stored fields.

| Event (`am-event`) | Objects |
|---|---|
| `subscriptionAdded` | `user`, `product` |
| `subscriptionDeleted` | `user`, `product` |
| `accessAfterInsert` | `access`, `user` |
| `accessAfterUpdate` | `access`, `old`, `user` |
| `accessAfterDelete` | `access`, `user` |
| `invoiceAfterInsert` | `invoice`, `user` |
| `invoiceStarted` | `user`, `invoice`, `payment` |
| `invoiceStatusChange` | `invoice`, `status`, `oldStatus`, `user` |
| `invoiceAfterCancel` | `invoice`, `user` |
| `invoiceAfterDelete` | `invoice`, `user` |
| `invoicePaymentRefund` | `invoice`, `refund`, `user` |
| `paymentAfterInsert` | `invoice`, `payment`, `user`, `items` |
| `paymentWithAccessAfterInsert` | `invoice`, `payment`, `user`, `items` |
| `userAfterInsert` | `user` |
| `userAfterUpdate` | `user`, `oldUser` |
| `userAfterDelete` | `user` |
| `setPassword` | `user`, `password` |
| `userNoteAfterInsert` | `user`, `note` |

Event ids are the values of aMember's `Am_Event` constants; the camelCase forms
above match what this package's tests already post. Confirm against a real
delivery (aMember logs them under Webhooks → Queue) before relying on a new one.

`subscriptionAdded` / `subscriptionDeleted` fire when a user gains or loses access
to a product *overall*, not per payment or per access record. That makes them the
right pair for "grant / revoke features". `accessAfter*` fire per access period and
are noisier: a renewal inserts a new access record.
