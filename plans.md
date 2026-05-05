# Plan: Registry Email Notifications

## Goal
Email the registry owner when an item is marked as purchased, including the purchaser's name if provided. Give owners control over notification preferences from a dedicated section on their account management page.

## Scope
- Purchase notifications only (invite emails already exist)
- Purchaser name is optional (anonymous purchases remain allowed)
- No purchaser email capture for now
- One registry per user assumed; preferences stored as user meta

## Out of Scope
- Digest emails / batched notifications
- "Registry is nearly complete" alerts
- View tracking
- Requiring purchaser login

---

## Changes

### 1. Upgrade the "Mark as Purchased" modal
**File**: `public/js/restart-registry-public.js`, `public/class-restart-registry-public.php`

Replace the `prompt()` dialog with a proper modal:
- Name field (text input, optional, with nudge copy: "Let them know who it's from!")
- Note/message field (textarea, optional, placeholder "Leave a message for the registry owner (optional)")
- "Mark as Purchased" confirm button
- Cancel button
- Submits via the existing `restart_registry_mark_purchased` AJAX action with `purchaser_name`, `purchaser_note`, and `is_anonymous` fields

### 2. Send notification email on purchase
**File**: `includes/class-restart-registry-controller.php`

- Add `private function send_purchase_notification(array $item, string $purchaser_name, string $purchaser_note): void`
  - Looks up the registry post and owner
  - Checks owner's `restart_notify_on_purchase` user meta (default: true)
  - Sends `wp_mail()` to owner with:
    - Warm intro: "Great news! Someone just marked a gift as purchased from your registry."
    - Item name + quantity purchased
    - Purchaser name (or "Someone" if anonymous)
    - Purchaser note (if provided, displayed as a blockquote-style indented block)
    - Closing copy + registry link: "Head to your registry to see what's still needed."
- Update `mark_item_purchased()` signature to accept `$purchaser_note` and call `send_purchase_notification()` after a successful Lambda update

### 3. Notification preferences section on the manage page
**File**: `public/class-restart-registry-public.php`

Add a "Notification Preferences" section rendered within `render_manage_registry()` (below the items list). Initially one setting:
- "Email me when items are purchased" (checkbox, default on)

Persisted as WordPress user meta key `restart_notify_on_purchase` (bool).

### 4. AJAX handler to save notification preferences
**File**: `public/class-restart-registry-public.php`

- Add `ajax_update_notification_prefs()` handler
- Register on `wp_ajax_restart_registry_update_notification_prefs`
- Validates nonce + login, saves user meta

### 5. JS for notification prefs
**File**: `public/js/restart-registry-public.js`

- Wire up the notification prefs checkbox to call the new AJAX handler on change
- Show a brief success/error notice inline

---

## Todo

- [x] Add "Mark as Purchased" modal HTML to guest view (name field with nudge copy, note textarea)
- [x] Replace `prompt()` in JS with modal open + form submit, pass `purchaser_note`
- [x] Add `purchaser_note` param to `ajax_mark_purchased()` and `mark_item_purchased()`
- [x] Add `send_purchase_notification()` to controller
- [x] Call `send_purchase_notification()` from `mark_item_purchased()` after successful update
- [x] Add Notification Preferences section HTML to `render_manage_registry()`
- [x] Add `ajax_update_notification_prefs()` to public class + register hook
- [x] Wire up prefs checkbox in JS
- [ ] Manual test: mark item purchased as guest → owner receives email
- [ ] Manual test: opt out via prefs → no email sent
- [ ] Manual test: anonymous purchase (no name) → email says "Someone"

---

# Plan: Unified Dev Environment

## Goal
One command (`make up` or `docker compose up`) starts WordPress, the Lambda API, the Restart theme, and the plugin together.

## Approach
Replace `docker-compose.yml` in the registry repo with an all-in-one file. Docker Compose resolves `../` sibling paths to absolute paths at runtime — no symlinks or monorepo restructuring needed.

## Services
| Service | Image | Port | Notes |
|---------|-------|------|-------|
| `nginx` | nginx:1.15.12-alpine | 8083 | WordPress frontend |
| `wordpress` | wordpress:6.9.1-fpm-alpine | — | WP FPM |
| `database` | mysql:8.0 | — | internal only |
| `lambda` | python:3.14-slim | 5000 | uvicorn direct; no lambda nginx proxy in dev |

## Mounts
- Plugin: `.` → `wp-content/plugins/restart-registry` (existing)
- Theme: `../the-restart-theme` → `wp-content/themes/theRestart` (new, in nginx + wordpress)
- Lambda code: `../restart_lambda` → `/app` (new, live-reload via `--reload`)
- Lambda data: `lambda_data` named volume → `/data` (SQLite persistence)

## Env wiring
- WordPress: `RESTART_LAMBDA_URL=http://lambda:5000`
- Lambda: `WP_BASE_URL=http://nginx`

## Files
- `docker-compose.yml` — replace existing (standalone lambda compose unchanged)
- `.env.example` — documents vars; actual `.env` gitignored
- `Makefile` — `up`, `down`, `logs`, `reset` targets

## Todo
- [x] Write new `docker-compose.yml`
- [x] Write `.env.example`
- [x] Write `Makefile`
- [x] Verify `.env` in `.gitignore` (already present)
