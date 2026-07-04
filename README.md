# MovieBox Stream

A movie & TV streaming website built with **Laravel 12 + Sanctum**. It searches,
browses and streams content by talking directly to the MovieBox (*aoneroom*)
**signed mobile API** (`wefeed-mobile-bff` on the `api*.aoneroom.com` host pool) —
the same API used by the Python project
[`Simatwa/moviebox-api`](https://github.com/Simatwa/moviebox-api) v3, re-implemented
natively in PHP (including the HMAC request signing) so there is no Python
runtime dependency.

> [!IMPORTANT]
> **Why a PHP re-implementation?** `moviebox-api` is a Python **library/CLI**, not
> a REST API — a Laravel app cannot import it. Rather than run a separate Python
> service, the HTTP layer it relies on was reverse-engineered and rebuilt in PHP
> (`app/Services/MovieBox`). Everything runs inside a single Laravel app.

---

## Features

- **Categories** — Films, Séries & Émissions, Animation, Populaires (most-watched), and a Live TV page, with a mobile-responsive nav (hamburger menu)
- **MySQL persistence** — every catalog response is snapshotted to MySQL and served stale-if-error, so the site keeps working when the upstream API hiccups
- **French versions (VF)** — a Version/Langue selector plays French dubs, and separate "[Version française]" entries are auto-discovered
- **Browse & discover** — curated home rows, trending, "hot" and popular searches
- **Search** with live autocomplete suggestions and type filters (movies / series)
- **Detail pages** with metadata, cast, seasons & episodes, and recommendations
- **Streaming player** — MP4 with quality switching, HLS fallback via `hls.js`,
  and subtitle tracks (SRT is proxied and converted to WebVTT on the fly)
- **Accounts via Sanctum** — token-based auth (register / login / logout)
- **Personal library** — favorites ("My List") and resumable watch history
  ("Continue Watching")
- Response caching for catalog calls and a cached bearer-token bootstrap

## Tech stack

| Layer      | Choice |
|------------|--------|
| Backend    | Laravel 12, PHP 8.2+ |
| Auth       | Laravel Sanctum (personal access tokens) |
| Frontend   | Vanilla JS SPA (History API routing) + Vite |
| Player     | `hls.js` + native HTML5 video |
| Database   | SQLite by default (MySQL / PostgreSQL supported) |

---

## Architecture

```
Browser (JS SPA)
   │  fetch  (Bearer token in localStorage)
   ▼
Laravel API  (routes/api.php)
   ├─ AuthController      → Sanctum tokens
   ├─ CatalogController   → home / trending / search / suggest / discover / detail
   ├─ StreamController    → play / downloads / subtitle proxy
   └─ LibraryController   → favorites / watch history (auth:sanctum)
             │
             ▼
   App\Services\MovieBox\MovieBoxClient  (native PHP client)
             │  https
             ▼
   MovieBox / aoneroom "h5 BFF" backend
```

### The MovieBox client (`app/Services/MovieBox`)

- `MovieBoxClient` — bootstraps a bearer token (cached) from the tab-operating
  endpoint, then calls the mobile API for search, discovery, details, streaming
  and downloads. Requests are load-balanced across the host pool with automatic
  failover; responses (`{code, message, data}`) are unwrapped automatically.
- `Signer` — builds the `X-Client-Token` and HMAC-MD5 `x-tr-signature` headers
  required by every request (verified byte-for-byte against the reference impl).
- `SubjectType` — content-type enum (movies / tv-series / …).
- `MovieBoxException` — upstream errors are surfaced as HTTP `502`.

Configuration lives in `config/moviebox.php` (host, mirrors, timeouts, cache TTLs)
and is driven by `MOVIEBOX_*` variables in `.env`.

---

## Getting started

Requires **PHP 8.2+**, **Composer**, and **Node.js 18+**.

```bash
# 1. Install dependencies
composer install
npm install

# 2. Environment
cp .env.example .env
php artisan key:generate

# 3. Database (SQLite by default)
touch database/database.sqlite
php artisan migrate --seed        # seeds a demo@example.com / password account

# 4. Build front-end assets
npm run build                     # or: npm run dev (with a live Vite server)

# 5. Serve
php artisan serve                 # http://localhost:8000
```

> [!NOTE]
> The app must be able to reach the MovieBox backend (`h5.aoneroom.com`) over the
> network at runtime. If a mirror is blocked in your region, set `MOVIEBOX_HOST` /
> `MOVIEBOX_API_HOST` in `.env` or configure `MOVIEBOX_PROXY`.

---

## API reference

All endpoints are prefixed with `/api`. Catalog & streaming endpoints are public;
library endpoints require a Sanctum bearer token
(`Authorization: Bearer <token>`).

### Auth
| Method | Endpoint          | Body |
|--------|-------------------|------|
| POST   | `/auth/register`  | `name, email, password, password_confirmation` |
| POST   | `/auth/login`     | `email, password` → `{ token, user }` |
| POST   | `/auth/logout`    | — (auth) |
| GET    | `/auth/me`        | — (auth) |

### Catalog
| Method | Endpoint     | Query |
|--------|--------------|-------|
| GET    | `/home`      | — |
| GET    | `/trending`  | `page, perPage` |
| GET    | `/search`    | `q, type=(all\|movies\|tv-series), page` |
| GET    | `/suggest`   | `q` |
| GET    | `/discover`  | — |
| GET    | `/category`  | `tab=(films\|series\|animation)` |
| GET    | `/channels`  | — (live TV, best-effort) |
| GET    | `/detail`    | `subjectId, subjectType[, title, cover]` |

### Streaming
| Method | Endpoint      | Query |
|--------|---------------|-------|
| GET    | `/play`       | `subjectId, detailPath, season, episode` |
| GET    | `/downloads`  | `subjectId, detailPath, season, episode` |
| GET    | `/subtitle`   | `url` (returns `text/vtt`) |

### Library (auth)
| Method | Endpoint                 |
|--------|--------------------------|
| GET    | `/favorites`             |
| POST   | `/favorites`             |
| DELETE | `/favorites/{subjectId}` |
| GET    | `/history`               |
| POST   | `/history`               |
| DELETE | `/history/{subjectId}`   |

---

## Testing

```bash
php artisan test
```

Includes unit tests for the detail-page extractor and subject-type resolution
(no network access required).

---

## Disclaimer

This project stores, hosts and uploads **no** media of its own. All videos and
images are sourced from third-party providers on the internet and their
copyrights belong to their original creators. It is provided for **educational
and technical demonstration purposes only** — make sure your use complies with
the laws and terms of service applicable in your jurisdiction.
