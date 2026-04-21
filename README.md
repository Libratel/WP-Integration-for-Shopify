# Portal Identity Bridge (Phase 6)

WordPress plugin for secure Shopify/SparkLayer -> WordPress identity bridging, customer mapping, protected portal access, SparkLayer launch links, partner materials delivery, backorders dashboards/exports, and operations hardening.

## Phase 6 Scope

- JWT-only handoff intake endpoint in WordPress.
- HS256 JWT verification with expiry + issuer checks.
- One-time replay protection via `jti` transient lock.
- Required stable customer identity claim (`shopify_customer_id` or `customer_id`).
- Shopify customer/company -> WordPress user mapping table.
- Guarded auto-link/auto-create WordPress users from verified claims.
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
- Operational health checks module with actionable status levels.
- Health diagnostics tab in admin tools.
- Downloadable support snapshot JSON for incident/debug workflows.

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
- `portal/` (extensible portal module registry + dashboard renderer)
- `admin/` (audit logs and admin tools)

## Module Lifecycle (Prompt 1)

The plugin now boots modules through `PIB_Module_Registry` (see `includes/core/class-pib-module-registry.php`) instead of hardcoded direct instantiation.

Built-in modules are still loaded in the same order:

1. settings
2. customer_mapping
3. protected_access
4. spark_launch
5. materials
6. backorders
7. portal_modules
8. audit_log
9. operations_health
10. session_policy
11. admin_tools
12. auth_intake
13. signin_link

Lifecycle hooks for extension modules:

- `pib_register_modules` (register or replace modules before boot)
- `pib_modules_registered` (all module definitions ready)
- `pib_before_modules_boot` (just before module factories run)
- `pib_module_booted` (fires per module)
- `pib_modules_booted` (all modules booted)

## Settings Architecture (Prompt 2)

Prompt 2 introduces a schema-driven settings contract:

- `includes/core/class-pib-settings-contract.php` is now the single source of truth for:
  - settings schema (key/type/default/section/metadata)
  - normalization/sanitization
  - validation and diagnostics payloads
- Runtime reads (`PIB_Plugin::get_settings()`) and admin saves now use this same contract path.
- Settings storage remains backward-compatible as a flat `pib_settings` option array.

Admin settings are grouped into these sections:

1. Identity/Auth Bridge
2. Routing & Access
3. User Mapping
4. Session & Logout
5. Backorders
6. Operations & Audit

Strict save policy:

- Critical misconfiguration blocks persistence for that save request (all-or-nothing).
- Warning-level checks still allow save and are shown via WordPress settings notices.

Diagnostics panel:

- Rendered directly on `Settings -> Portal Bridge`.
- Shows overall health (`Healthy`, `Warnings`, `Action Needed`), checklist results, computed references, and redacted effective values.

Extensibility hooks:

- `pib_settings_schema`
- `pib_settings_validation_results`
- `pib_settings_diagnostics_checks`

## Protected Route Management (Prompt 4)

- Route matching now goes through `PIB_Protected_Route_Policy` for both runtime enforcement and admin diagnostics.
- Protected access supports explicit unprotected prefixes (`Unprotected Route Prefixes`) to keep login/failure routes reachable.
- Route validation now flags misconfigurations such as protected failure destinations and global unprotected `/`.
- Admin tools include `Route Access` tab with:
  - effective policy overview
  - destination tester
  - sample route matrix

## Extensibility (Prompt 5)

- Portal features are now registered through `PIB_Portal_Modules`.
- Built-in modules (`materials`, `backorders`) register themselves via the same extension API used by custom modules.
- New dashboard shortcode:
  - `[portal_dashboard]`
- Core extension hooks:
  - `pib_register_portal_modules`
  - `pib_portal_modules_registered`
  - `pib_portal_module`
  - `pib_portal_modules`
  - `pib_portal_module_card_markup`
  - `pib_portal_dashboard_markup`

## Operational Health (Prompt 6)

- `PIB_Operations_Health` adds runtime health checks for:
  - settings diagnostics status
  - required table availability
  - cron retention schedule
  - required module registration/boot state
  - mapping population trends
  - auth success/failure signal in the last 24 hours
- Admin tools now include a `Health` tab with:
  - overall status (`Healthy`, `Warnings`, `Action Needed`)
  - check summary counters
  - detailed check matrix
  - read-only snapshot preview
  - one-click snapshot download (`JSON`)
- New extension hooks:
  - `pib_operations_health_checks`
  - `pib_operations_health_snapshot`

## Installation

1. Copy `portal-identity-bridge` into `wp-content/plugins/`.
2. Activate **Portal Identity Bridge** in WordPress admin.
3. Go to `Settings -> Portal Bridge` and configure:
   - Shopify Portal URL
   - Client ID
   - Shared Secret
   - Issuer (`iss`)
   - Require Company ID Claim
   - Allowed Destination Prefixes

## Auth Intake Endpoint

WordPress endpoint:

`/wp-admin/admin-post.php?action=pib_auth_intake&token=...`

The same URL is shown in plugin settings as **Intake URL**.

Legacy query-signature handoff (`customer_id/company_id/email/dest/ts/sig`) is disabled.

## Expected Token Claims

Minimum required claims:

- `exp` (unix timestamp)
- `jti` (unique token id)
- `iss` (trusted token issuer)
- `aud` (trusted token audience)
- `shopify_customer_id` or `customer_id` (stable customer identifier)
- `company_id` (required account/company identifier)
- `dest` (internal destination path, example `/dashboard/backorders?status=open`)

Optional/used in Phase 5:

- `iat`, `nbf`
- `wp_user_id` (optional explicit user hint)
- `email` (used for optional auto-link/auto-create behavior)

Mapping guardrails:

- Email-based auto-link is blocked when target WP user is already actively mapped to a different Shopify customer.
- Existing blocked/archived customer mappings are not automatically reactivated during auth intake.

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
- `Unprotected Route Prefixes`
- `Auto-create WordPress Users`
- `Auto-link Existing Users by Email`
- `New User Role`
- `Require Active Mapping on Protected Routes`
- `Require Managed Session on Protected Routes`
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

Session guardrails:

- Protected routes can require a bridge-managed WordPress session (`Require Managed Session on Protected Routes`).
- Managed sessions are identity-bound (customer/company) and expire if session identity no longer matches active mapping context.

Protected route management:

- Route policy is centralized in `PIB_Protected_Route_Policy`.
- Protected prefixes are evaluated first, then unprotected prefixes can explicitly bypass route gating.
- `Failure Destination` should resolve as public to avoid redirect loops.
- Extension hooks:
  - `pib_protected_route_prefixes`
  - `pib_unprotected_route_prefixes`
  - `pib_protected_route_evaluation`
  - `pib_is_destination_protected`
  - `pib_protected_route_samples`

Logout/session strategy:

- Explicit logout always clears local WordPress managed session markers and ends the WP session.
- Default local logout redirects to the configured failure/login destination with `?pib_logged_out=1`.
- Coordinated logout (optional) redirects to upstream logout and uses the local logged-out URL as the return target.
- Audit events are emitted for `logout_requested`, `logout_completed`, and `session_expired`.

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
  - `Mappings` (search/filter + activate/block/archive actions)
  - `Mapping Failures` (unmatched/failed mapping + auth resolution records with prefill links)
  - `Route Access` (route policy overview + destination tester + sample route matrix)
  - `Extensions` (plugin module lifecycle + registered portal extension modules)
  - `Health` (runtime checks + support snapshot preview/download)
  - `Audit Logs` (search/filter + clear action)
- Mapping operations:
  - Create/link/update mappings from admin form (customer/company -> WP user by ID/login/email)
  - Actionable prefill from failures tab into mapping create/link form

## Bridge Simulator (Prompt 1)

- In `Settings -> Portal Bridge`, admins can launch a short-lived test handoff token via **Bridge Simulator (Prompt 1)**.
- This simulates the Shopify bridge redirect into:
  - `wp-admin/admin-post.php?action=pib_auth_intake&token=...`
- It is intended for validating WordPress intake/mapping/session flow before external Shopify route wiring is complete.

## Bridge Contract (Prompt 2)

- `Settings -> Portal Bridge` now includes **Bridge Contract (Prompt 2)**.
- It shows:
  - canonical launch URL generated from configured settings
  - expected Shopify redirect output into WordPress intake
  - required JWT claims and expected values (`iss`, `aud`, `exp`, `jti`, `shopify_customer_id`, `company_id`, `dest`)
- Outbound sign-in URL now includes: `return_url`, `intake`, `dest`, `client_id`, and `source=partner_portal`.
