# MFD Dashboard Widget

> **A production-grade Gutenberg block for enterprise membership dashboards and client portals.**
>
> Securely aggregates live user telemetry from a custom database engine and exposes it through a nonce-protected REST API. All user data is fetched client-side — cache-layer agnostic by design.

[![PHP](https://img.shields.io/badge/PHP-8.1%2B-777bb3?logo=php)](https://php.net)
[![WordPress](https://img.shields.io/badge/WordPress-6.7%2B-21759b?logo=wordpress)](https://wordpress.org)
[![Block API](https://img.shields.io/badge/Block%20API-v3-00b9ae)](https://developer.wordpress.org/block-editor/)
[![License](https://img.shields.io/badge/license-GPL--2.0--or--later-blue)](LICENSE)

---

## Table of Contents

1. [Architecture Overview](#architecture-overview)
2. [Requirements](#requirements)
3. [Installation](#installation)
4. [Build System](#build-system)
5. [Plugin Structure](#plugin-structure)
6. [REST API Reference](#rest-api-reference)
7. [Database Schema](#database-schema)
8. [Block Attributes](#block-attributes)
9. [Security Model](#security-model)
10. [Filter Hooks](#filter-hooks)
11. [Git Branching & Deployment](#git-branching--deployment)
12. [Code Quality](#code-quality)

---

## Architecture Overview

```
Browser Request
    │
    ▼
WordPress serves cached HTML shell (render.php)
    │   ← No user data in HTML — safe for Varnish / Cloudflare
    ▼
frontend.js boots (React 18 createRoot)
    │
    ▼
XHR → /wp-json/custom-dashboard/v1/user-metrics
         │  ← X-MFD-Nonce header (server-signed, 24h TTL)
         ▼
    MetricsEndpoint::isAuthorized()
         │  ← is_user_logged_in() + wp_verify_nonce()
         ▼
    MetricsRepository::findByUserId()
         │  ← $wpdb->prepare() parameterized query
         ▼
    UserMetricsDTO::toApiArray()
         │  ← Sanitized, typed response
         ▼
    WP_REST_Response → JSON
         │
         ▼
frontend.js receives data
    │  ← DOMPurify.sanitize() on every string before DOM insertion
    ▼
React renders ProfileCard + MetricGrid + StrengthMeter + ActivityGrid
```

---

## Requirements

| Dependency | Version |
|---|---|
| PHP | ≥ 8.1 |
| WordPress | ≥ 6.7 |
| MySQL / MariaDB | MySQL ≥ 8.0 or MariaDB ≥ 10.5 |
| Node.js | ≥ 20 LTS |
| Composer | ≥ 2.0 |
| npm | ≥ 10 |

---

## Installation

### 1. Clone the repository

```bash
git clone https://github.com/Zubairmuhammad476/Gutenberg-blocks.git
cd Gutenberg-blocks
```

### 2. Install PHP dependencies

```bash
composer install --no-dev --optimize-autoloader
# Development (includes PHPCS/WPCS):
composer install
```

### 3. Install Node dependencies

```bash
npm install
```

### 4. Build frontend assets

```bash
# Development — watch mode with source maps:
npm run start

# Production — minified, no source maps:
npm run build
```

### 5. Activate the plugin

Upload or symlink the directory to `wp-content/plugins/mfd-dashboard-widget/`, then activate via **Plugins → Activate**.

On activation, `Installer::run()` automatically creates the `{prefix}mfd_user_metrics` database table via `dbDelta()`.

---

## Build System

| Command | Output |
|---|---|
| `npm run start` | Watch mode, source maps, unminified |
| `npm run build` | Production bundle in `/build` |
| `npm run lint:js` | ESLint via `@wordpress/scripts` |
| `npm run lint:css` | Stylelint via `@wordpress/scripts` |
| `npm run format` | Prettier via `@wordpress/scripts` |

**Compiled outputs** (all in `/build`):

| File | Loaded |
|---|---|
| `index.js` + `index.asset.php` | Gutenberg editor only |
| `index.css` | Editor styles |
| `style-index.css` | Frontend + editor (Tailwind, `mfd-` prefixed) |
| `frontend.js` + `frontend.asset.php` | Public page only |

> **Tailwind prefix:** All utility classes carry the `mfd-` prefix (e.g. `mfd-flex`, `mfd-text-sm`). This prevents any leakage into theme or other plugin styles.

---

## Plugin Structure

```
mfd-dashboard-widget/
├── mfd-dashboard-widget.php     Main plugin header, constants, hooks
├── composer.json                PSR-4 autoloader, PHPCS dev tooling
├── package.json                 @wordpress/scripts, Tailwind
├── webpack.config.js            Dual entry + PostCSS override
├── postcss.config.js            Tailwind + Autoprefixer pipeline
├── tailwind.config.js           mfd- prefix, HSL tokens, animations
├── phpcs.xml                    PHPCS ruleset (WordPress + PSR-12)
├── block.json                   Block API v3 manifest
├── templates/
│   └── render.php               Server-side HTML shell (cache-safe)
└── src/
    ├── Plugin.php               Singleton orchestrator
    ├── Database/
    │   ├── Installer.php        dbDelta migration, version gating
    │   ├── MetricsRepository.php $wpdb CRUD, upsert, atomic ops
    │   └── DTO/
    │       └── UserMetricsDTO.php Immutable typed value object
    ├── Api/
    │   ├── RestController.php   Route registrar (custom-dashboard/v1)
    │   └── MetricsEndpoint.php  Auth gate + request handlers
    └── Block/
        ├── BlockRegistrar.php   register_block_type_from_metadata
        └── RenderCallback.php   Deprecated — see templates/render.php
```

---

## REST API Reference

### Base URL

```
{site_url}/wp-json/custom-dashboard/v1/
```

### Authentication

All endpoints require:
1. An **authenticated WordPress session** (`is_user_logged_in()` = true).
2. A valid **`X-MFD-Nonce` header** containing a nonce created with `wp_create_nonce('mfd_rest_nonce')`.

The nonce is injected server-side via `wp_localize_script()` into `window.mfdDashboard.nonce` and is refreshed on each successful API response.

**Nonce lifecycle:**
- Created by: `wp_create_nonce('mfd_rest_nonce')` in `BlockRegistrar::localizeViewScript()`
- Verified by: `wp_verify_nonce( $nonce, 'mfd_rest_nonce' )` in `MetricsEndpoint::isAuthorized()`
- TTL: 24 hours (WordPress default). Stale nonces return HTTP 403.
- Rotation: A fresh nonce is returned in every successful `GET /user-metrics` response under `data.nonce`.

---

### `GET /custom-dashboard/v1/user-metrics`

Returns the full telemetry payload for the currently authenticated user.

**Request headers:**

```
X-MFD-Nonce: {nonce}
X-Requested-With: XMLHttpRequest
Content-Type: application/json
```

**Success response — `200 OK`:**

```json
{
  "success": true,
  "data": {
    "id": 42,
    "user_id": 7,
    "last_login": "2025-06-18 14:32:00",
    "profile_strength": 72,
    "download_count": 15,
    "activity_log": [],
    "created_at": "2025-01-01 00:00:00",
    "updated_at": "2025-06-18 14:32:00",
    "display_name": "Jane Smith",
    "avatar_url": "https://example.com/avatar.jpg",
    "email": "jane@example.com",
    "formatted_login": "2 hours ago",
    "nonce": "{refreshed_nonce}"
  }
}
```

**Error responses:**

| Status | Code | Cause |
|---|---|---|
| 401 | `mfd_rest_unauthenticated` | User not logged in |
| 403 | `mfd_rest_missing_nonce` | `X-MFD-Nonce` header absent |
| 403 | `mfd_rest_invalid_nonce` | Nonce invalid or expired |
| 404 | `mfd_user_not_found` | Cannot resolve WP_User from session |
| 500 | `mfd_db_error` | Database query failure |

---

### `GET /custom-dashboard/v1/user-metrics/activity`

Returns a paginated slice of the current user's activity log, most recent first.

**Query parameters:**

| Parameter | Type | Default | Constraints | Description |
|---|---|---|---|---|
| `page` | integer | `1` | `>= 1` | Page number |
| `per_page` | integer | `10` | `1–50` | Items per page |

**Success response — `200 OK`:**

```json
{
  "success": true,
  "data": {
    "items": [
      {
        "type": "login",
        "payload": { "username": "jsmith" },
        "timestamp": "2025-06-18 14:32:00"
      },
      {
        "type": "download",
        "payload": { "file": "report-q2.pdf", "size": "2.4MB" },
        "timestamp": "2025-06-17 09:15:00"
      }
    ],
    "total": 24,
    "pages": 3,
    "current_page": 1
  }
}
```

**Activity log constraints:**
- Maximum **50 events** stored per user (FIFO eviction — oldest discarded).
- `type` values are sanitized via `sanitize_key()` before storage.
- `payload` values are sanitized via `sanitize_text_field()` recursively.

---

### `POST /custom-dashboard/v1/user-metrics/strength`

Updates the profile completion strength score for the current user.

**Request body (JSON):**

```json
{ "score": 85 }
```

| Parameter | Type | Required | Constraints |
|---|---|---|---|
| `score` | integer | Yes | `0–100` (inclusive) |

**Success response — `200 OK`:**

```json
{
  "success": true,
  "data": {
    "profile_strength": 85,
    "updated_at": "2025-06-19 10:00:00"
  }
}
```

---

## Database Schema

### Table: `{prefix}mfd_user_metrics`

Created on plugin activation via `dbDelta()`. Non-destructive upgrades — safe to re-run.

| Column | Type | Nullable | Default | Description |
|---|---|---|---|---|
| `id` | `BIGINT UNSIGNED` | No | AUTO_INCREMENT | Primary key |
| `user_id` | `BIGINT UNSIGNED` | No | — | `wp_users.ID` reference. UNIQUE |
| `last_login` | `DATETIME` | Yes | NULL | UTC datetime of last login |
| `profile_strength` | `TINYINT UNSIGNED` | No | `0` | Completion score 0–100 |
| `download_count` | `INT UNSIGNED` | No | `0` | Cumulative download total |
| `activity_log` | `LONGTEXT` | Yes | NULL | JSON array of telemetry events |
| `created_at` | `DATETIME` | No | CURRENT_TIMESTAMP | Row creation UTC |
| `updated_at` | `DATETIME` | No | CURRENT_TIMESTAMP ON UPDATE | Row update UTC |

**Indexes:**

| Name | Type | Columns |
|---|---|---|
| `PRIMARY` | Primary | `id` |
| `user_id` | Unique | `user_id` |
| `idx_last_login` | Index | `last_login` |
| `idx_profile_str` | Index | `profile_strength` |

**Schema upgrades:** Increment `Installer::SCHEMA_VERSION` and modify `createOrUpgradeMetricsTable()`. `dbDelta()` will apply ADD COLUMN / ADD KEY changes non-destructively on the next plugin activation.

---

## Block Attributes

Configured via the Gutenberg Inspector Controls sidebar:

| Attribute | Type | Default | Description |
|---|---|---|---|
| `showLastLogin` | `boolean` | `true` | Toggle Last Login metric card |
| `showStrength` | `boolean` | `true` | Toggle Profile Strength meter |
| `showDownloads` | `boolean` | `true` | Toggle Downloads counter |
| `showActivity` | `boolean` | `true` | Toggle Recent Activity grid |
| `accentColor` | `string` | `#6366f1` | Brand accent (hex). Applied to ring stroke, bar fills, metric values |
| `containerWidth` | `string` (enum) | `full` | `full` \| `wide` \| `normal` — CSS max-width class |

---

## Security Model

| Layer | Mechanism |
|---|---|
| **Authentication** | `is_user_logged_in()` check in `MetricsEndpoint::isAuthorized()` |
| **Nonce verification** | `wp_verify_nonce( $header_nonce, 'mfd_rest_nonce' )` on every REST request |
| **Data ownership** | Users can only read/write their own `user_id` row (`get_current_user_id()`) |
| **SQL injection** | All queries use `$wpdb->prepare()` with typed placeholders (`%d`, `%s`) |
| **XSS — PHP output** | `esc_attr()`, `esc_html()`, `esc_url_raw()` at every echo point in render.php |
| **XSS — JS runtime** | `DOMPurify.sanitize()` on every API string before DOM insertion in frontend.js |
| **Input sanitization** | `sanitize_text_field()`, `sanitize_hex_color()`, `sanitize_key()`, `absint()` at REST arg level |
| **Output escaping** | `UserMetricsDTO::toApiArray()` → `WP_REST_Response` (WordPress escapes JSON context) |
| **Capability scoping** | All REST endpoints restricted to logged-in users — no public access |
| **Cache bypass** | Zero user data in server-rendered HTML — all data fetched client-side post-cache |

---

## Filter Hooks

### `mfd_view_script_data`

Allows third-party plugins to append additional data to `window.mfdDashboard`.

```php
add_filter( 'mfd_view_script_data', function( array $data ): array {
    $data['customKey'] = 'custom_value';
    return $data;
} );
```

---

## Git Branching & Deployment

### Branching model

```
main                  ← Production. Protected. Requires PR + 1 approval.
  └── staging         ← Pre-production mirror. Auto-deployed on merge.
        └── develop   ← Integration branch. Feature branches merge here.
              ├── feature/*    New features
              ├── fix/*        Bug fixes
              ├── chore/*      Tooling, deps, config
              └── hotfix/*     Critical production patches (branches from main)
```

### Conventional Commit format

```
<type>(<scope>): <imperative description>

type:  feat | fix | build | chore | docs | refactor | test | perf | ci
scope: api | block | db | frontend | editor | build | security (optional)
```

**Examples used in this project:**

```bash
feat: bootstrap plugin entry point with PSR-4 autoloader and activation hooks
feat: implement Plugin singleton orchestrator with subsystem boot and wp_login hook
feat: implement dbDelta migration engine, MetricsRepository, and UserMetricsDTO
feat: register custom-dashboard/v1 REST namespace with nonce-gated metrics endpoints
feat: register dynamic block via block.json with cache-safe server render and skeleton shell
build: configure @wordpress/scripts with dual entry points and mfd-prefixed Tailwind pipeline
feat: implement React 18 frontend hydration with REST fetch, SVG strength meter, and paginated activity grid
feat: add mfd-prefixed Tailwind component library and editor panel styles
feat: implement DOMPurify XSS sanitization across all REST API string outputs
feat: add pristine skeleton loading zones for metrics grid, strength meter, and activity matrix
build: add WordPress-Core/Docs/Extra phpcs ruleset with PSR-12 exclusions
docs: add full REST API reference, schema specification, and deployment workflow
```

### Phase 1 → Phase 2 merge workflow

```bash
# 1. Push Phase 1 feature branch
git checkout -b feature/plugin-core-scaffold
git push -u origin feature/plugin-core-scaffold
# → Open PR: feature/plugin-core-scaffold → develop

# 2. After Phase 1 PR is merged to develop, cut Phase 2 branch
git checkout develop && git pull origin develop
git checkout -b feature/phase2-frontend-hardening
git push -u origin feature/phase2-frontend-hardening

# 3. Commit Phase 2 files (atomic commits — one concern per commit)
git add templates/render.php
git commit -m "feat: add pristine skeleton loading zones for metrics grid, strength meter, and activity matrix"

git add src/js/frontend.js
git commit -m "feat: implement DOMPurify XSS sanitization across all REST API string outputs in React hydration runtime"

git add phpcs.xml
git commit -m "build: add WordPress-Core/Docs/Extra phpcs ruleset with mfd prefix enforcement and PSR-12 exclusions"

git add README.md
git commit -m "docs: add full REST API reference, database schema, security model, and git deployment workflow"

git push origin feature/phase2-frontend-hardening

# → Open PR: feature/phase2-frontend-hardening → develop
```

### Staging deployment

```bash
# After Phase 2 PR merges to develop:
git checkout staging && git pull origin staging
git merge --no-ff develop -m "chore: promote develop to staging for pre-production validation"
git push origin staging

# Run on staging server:
composer install --no-dev --optimize-autoloader
npm ci && npm run build

# Activate/update plugin via WP-CLI:
wp plugin activate mfd-dashboard-widget
wp cache flush
```

### Production release

```bash
# Tag the release from main after staging sign-off:
git checkout main && git pull origin main
git merge --no-ff staging -m "release: v1.0.0 — Phase 1 + Phase 2 complete"
git tag -a v1.0.0 -m "feat: initial production release of MFD Dashboard Widget"
git push origin main --tags
```

---

## Code Quality

### PHPCS

```bash
# Run full analysis against src/:
composer phpcs

# Auto-fix fixable violations:
composer phpcbf

# Summary report only:
composer phpcs:report
```

### JavaScript linting

```bash
npm run lint:js   # ESLint
npm run lint:css  # Stylelint
npm run format    # Prettier (auto-fix)
```

---

## License

This plugin is licensed under the [GNU General Public License v2.0 or later](https://www.gnu.org/licenses/gpl-2.0.html).
