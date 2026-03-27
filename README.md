# Portal Identity Bridge (Phase 5)

WordPress plugin for secure Shopify/SparkLayer -> WordPress identity bridging, customer mapping, protected portal access, SparkLayer launch links, partner materials delivery, backorders dashboards/exports, and operations hardening.

## Phase 5 Scope

- Signed handoff intake endpoint in WordPress.
- HS256 JWT verification with expiry + issuer checks.
- One-time replay protection via `jti` transient lock.
- Required stable customer identity claim (`shopify_customer_id` or `customer_id`).
- Shopify customer/company -> WordPress user mapping table.
- Auto-link/auto-create WordPress users from verified claims.
- Protected route guard with destination-aware sign-in redirect.
- Authorization helpers for customer/company scope checks.
- SparkLayer launch entry handler (`?pib_launch=1&dest=/...`).
- Launch-link shortcode for dashboard buttons.
- Partner materials custom content type with company-scoped access.
- Secure material download endpoint.
- Backorders dashboard shortcode with customer/company-scoped records.
- Backorders CSV export endpoint with same scope enforcement.
- External backorders API adapter + normalization layer.
- Backorders view/export audit hooks.
- Audit logging module + retention policy.
- Admin tools page for mappings and event logs.
- Managed session TTL + periodic revalidation.
- Local/coordinated logout handling.
- Auth intake rate-limiting by client IP.
- CSRF nonce enforcement for sensitive exports/downloads.

## Module Folders

Classes are organized by module under `includes/`:

- `core/` (plugin bootstrap, installer, settings)
- `auth/` (auth intake, JWT verification, sign-in, session policy)
- `security/` (destination and security helpers)
- `mapping/` (customer mapping and authorization)
- `access/` (protected route gating)
- `spark/` (SparkLayer launch entry)
- `materials/` (partner materials module)
- `backorders/` (backorders data source and dashboard)
- `admin/` (audit logs and admin tools)

## Installation

1. Copy `portal-identity-bridge` into `wp-content/plugins/`.
2. Activate **Portal Identity Bridge** in WordPress admin.
3. Go to `Settings -> Portal Bridge` and configure:
   - Shopify Login/Issuer URL
   - Shared Token Secret
   - Expected Issuer (`iss`)
   - Allowed Destination Prefixes

## Auth Intake Endpoint

WordPress endpoint:

`/wp-admin/admin-post.php?action=pib_auth_intake&token=...`

The same URL is shown in plugin settings as **Intake URL**.

## Expected Token Claims

Minimum required claims:

- `exp` (unix timestamp)
- `jti` (unique token id)
- `shopify_customer_id` or `customer_id` (stable customer identifier)
- `dest` (internal destination path, example `/dashboard/backorders?status=open`)

Optional/used in Phase 5:

- `iss` (checked if configured)
- `iat`, `nbf`
- `company_id` (recommended for account-level scoping)
- `wp_user_id` (optional explicit user hint)
- `email` (used for optional auto-link/auto-create behavior)

## Sign-In Shortcode

Use on portal login page:

`[portal_sign_in]`

Optional:

`[portal_sign_in label="Continue to Partner Portal"]`

`[portal_sign_in dest="/dashboard/backorders"]`

The shortcode sends users to Shopify issuer URL with:

- `dest` (validated internal destination)
- `intake` (WordPress intake callback URL)
- `source=partner_portal`

Logout shortcode:

`[portal_logout_link]`

## SparkLayer Launch

Launch URL pattern:

`https://partners.example.com/?pib_launch=1&dest=/materials`

If user is already authenticated and mapped, launch redirects directly to destination.
If not, launch redirects to Shopify sign-in handoff while preserving destination.

Launch helper shortcode:

`[portal_launch_link dest="/materials" label="Partner Materials"]`

## Mapping Table

Custom table: `{wp_prefix}pib_customer_map`

Core fields:

- `shopify_customer_id`
- `company_id`
- `wp_user_id`
- `email`
- `status`
- `created_at`, `updated_at`, `last_seen_at`

Unique key: `(shopify_customer_id, company_id)`

## New Settings (Phase 5)

- `Protected Route Prefixes`
- `Auto-create WordPress Users`
- `Auto-link Existing Users by Email`
- `New User Role`
- `Require Active Mapping on Protected Routes`
- `Enable SparkLayer Launch Entry`
- `Spark Launch Allowed Prefixes`
- `Managed Session TTL (hours)`
- `Enable Periodic Revalidation`
- `Revalidation Interval (minutes)`
- `Enable Coordinated Logout`
- `Upstream Logout URL`
- `Upstream Logout Return Param`
- `Auth Failure Limit`
- `Auth Failure Window (minutes)`
- `Enable Audit Logging`
- `Audit Log Retention (days)`

## Partner Materials

- Admin content type: `Partner Materials` (`pib_material`).
- Each material stores:
  - File URL
  - Allowed company IDs (optional, one per line)
- Frontend listing shortcode:
  - `[portal_materials]`
- Download endpoint:
  - `/wp-admin/admin-post.php?action=pib_material_download&material_id=<ID>`
  - Enforces login + active mapping + company entitlement.

## Backorders

- Dashboard shortcode:
  - `[portal_backorders]`
- Export endpoint:
  - `/wp-admin/admin-post.php?action=pib_backorders_export&pib_nonce=...`
  - Enforces login + active mapping + nonce.
- API adapter settings:
  - `Backorders API Endpoint`
  - `Backorders API Token`
  - `Backorders API Timeout (seconds)`
  - `Backorders Default Limit`
- Primary hook points:
  - `pib_backorders_source_records`
  - `pib_backorders_request_args`
  - `pib_backorders_view`
  - `pib_backorders_export`
  - `pib_backorders_error`

## Admin Tools

- WordPress admin page:
  - `Tools -> Portal Bridge Tools`
- Tabs:
  - `Mappings` (search/filter + activate/block actions)
  - `Audit Logs` (search/filter + clear action)
