# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

**🇯🇵 常に日本語を使用してください。このプロジェクトについてユーザーとコミュニケーションする際は、すべて日本語で対応してください。**

## Project Overview

**野球やろうぜ！** — a Little League baseball judgment training simulator.

- **Type**: Progressive Web App (PWA) with PHP backend
- **Frontend**: Static HTML/CSS/JavaScript + Service Worker
- **Backend**: PHP APIs
- **Current Production Version**: v1065-production (public version tracked in index.html meta tag)
- **Current Cache Version**: v1061
- **No build step required** — runs directly with `php -S localhost:8000`

---

## Quick Start

### Local Development

```powershell
php -S localhost:8000
```

Then open `http://localhost:8000/` in a browser.

### PHP Syntax Check

```powershell
php -l path/to/file.php
```

Run for all modified `.php` files in `api/` before committing.

### Verify JSON Validity

```powershell
# Windows PowerShell: parse JSON
Get-Content data/questions.json | ConvertFrom-Json
Get-Content data/game_config.json | ConvertFrom-Json
```

### Composer Dependencies

```powershell
composer install
```

(Only needed if `composer.json` changes; `vendor/` contains web-push for notifications)

---

## Architecture

### Static Files

| Path | Role |
|------|------|
| `index.html` | Main PWA, game screen, tutorial, top page, rating, my page |
| `admin.html` | Admin console (highest-level admins only) |
| `delete.html` | Account deletion request form |
| `manifest.webmanifest` | PWA manifest for "Add to Home Screen" |
| `service-worker.js` | PWA cache, push notifications, offline support |
| `assets/app.js` | Game logic, scoring, UI switching, ranking, my page, mistake review |
| `assets/styles.css` | User-facing styles |

### Backend APIs

| Path | Role |
|------|------|
| `api/*.php` | Game, scoring, ranking, auth, admin, notifications, account deletion, etc. |
| `api/admin_api.php` | Admin console backend |
| `api/user_delete_*.php` | Account deletion workflow |

### Data & Configuration

| Path | Role |
|------|------|
| `data/questions.json` | 600 problems (227 attack, 325 defense, 48 basic) |
| `data/game_config.json` | Game rules, positions, grade levels, rendering config |
| `data/quiz_master_questions.json` | Baseball Master Challenge (323 problems) |
| `data/quiz_master_titles.json` | Titles/ranks for Baseball Master |
| `version.json` | Internal app version, cache version, public release version |

### Production Data (DO NOT MODIFY)

| Path | Contents |
|------|----------|
| `scores/` | Live player registry, scores, settings, logs, ID codes |
| `requests/` | User requests, deletion workflows, audit logs |
| `vendor/` | Composer dependencies (web-push) |

---

## Critical Operational Rules

### 🔴 Absolute Prohibitions

**Never modify, delete, or reinitialize**:

- `scores/` directory (all)
- `requests/` directory (all)
- `vendor/` (unless explicitly updating Composer)
- Production user registration data
- Score logs, audit logs, access logs
- Invite ID / Admin ID registries
- `scores/release_versions.json` (use admin console instead)
- `.htaccess` in production (`/baseball/`)

**Before making exceptions**: always explain the reason, impact scope, and recovery method — and get user confirmation.

### PWA Cache Consistency

**When updating frontend files, MUST verify these stay in sync**:

```
index.html:
  - assets/styles.css?v=...
  - assets/app.js?v=...
  - window.YAKYU_CACHE_VERSION (matches version.json cache_version)

service-worker.js:
  - CACHE_VERSION (matches version.json cache_version)
  - STATIC_ASSETS queries match index.html

version.json:
  - app_version
  - cache_version
  - public_version
```

**Mismatches cause**: old JS/CSS to be served from cache, old questions to display, admin UI to disagree with live cache.

### Problem Data Rules

- **ID is immutable** — used for mistake-review history and audit.
- **outs / outs_scope**: `attack` and `defense` require either `outs: 0|1|2` OR `outs_scope: "common"` (never both).
- **No missing `visual` fields** — `batter_runner`, `ball_path`, `runners`, `holder`, `target_position`, `play` must be populated.
- **visual.batter_runner** must be explicit `true` or `false` (e.g., catcher fly-out = `false`).
- **Grade limits**: `min_grade` and `max_grade` take precedence over default (grade +1 rule).
- **Never edit a single problem in isolation** — always cross-check same theme/system/grade for consistency.

---

## Development Workflow

### Branch & PR Structure

```powershell
git switch -c codex/<task-name>
# Work...
git status --short
git diff
# Commit with clear message explaining *why*, not just *what*
```

### Pre-Commit Checks

**Before creating a PR**:

1. ✅ `scores/` and `requests/` untouched
2. ✅ PHP lint: `php -l api/file.php` (all modified files)
3. ✅ JSON validity: `Get-Content data/questions.json | ConvertFrom-Json`
4. ✅ No problem ID duplicates or gaps
5. ✅ PWA cache consistency (index.html ↔ service-worker.js ↔ version.json)
6. ✅ Game tested: start game (3yr+, each position), verify out order (0→1→2), check no duplicate IDs per game
7. ✅ Scores save, ranking loads, my page shows, mistake review works
8. ✅ Admin console accessible and functional

### PR Description Must Include

- Why this change (not just what changed)
- Affected scope
- How to verify
- PWA version alignment confirmation

---

## Common Fixes

| Symptom | Check |
|---------|-------|
| Old screen appears | PWA/browser cache, `service-worker.js`, `version.json` |
| PWA shows different behavior than web | Service Worker, cache storage, `app.js?v=...` and `CACHE_VERSION` match |
| Game won't start | `data/questions.json`, question fetch API, PHP errors in logs |
| Scores won't save | `api/save_score.php`, `scores/score_log.csv` permissions |
| Ranking empty | `api/get_ranking.php`, `scores/score_log.csv` |
| Mistake review missing | `scores/mistake_review.json`, `assets/app.js`, admin mode enabled |
| Problem content wrong | Search problem ID in `questions.json`, check all cross-linked problems |
| Invite/Admin ID broken | `scores/issue_link_settings.json`, `api/issue_link_status.php`, manifest |
| Notifications fail | `vendor/` (web-push), push subscriptions, browser permissions |

---

## Environments

- **Production**: `/baseball/`
- **Test**: `/baseball_test/`
- **Local**: `http://localhost:8000/`

Keep production and test `scores/` and `requests/` separate.

---

## Recent Modifications (2026-06-26)

### CSS Fixes & Styling Updates

**Ranking Page Title Alignment**
- Fixed `.quiz-master-ranking-page-card` selector (added missing comma between multiple selectors)
- Added `text-align:center;` to `.quiz-master-result-card h2` for mobile and desktop alignment
- Commit: f80b1ce

**Ranking Score Display Fix**
- Fixed `.quiz-master-ranking li span` selector (added missing comma)
- Increased ranking score font size by 2px using `calc(1em + 2px)` on `.rank-score`
- Improves visibility and fixes CSS syntax error
- Commit: 0fa41b8

**Cache & Version Updates**
- Updated Service Worker CACHE_VERSION from v1060 → v1061
- Updated CSS reference version in service-worker.js and index.html to ?v=1061
- Commit: 2195c28

### Test Mode Management
- Temporarily disabled daily life limit (QUIZ_MASTER_DAILY_LIMIT_ENABLED = false) for testing
- Restored daily life limit after testing completed (QUIZ_MASTER_DAILY_LIMIT_ENABLED = true)
- Updated cache versions accordingly during testing cycle

---

## Important Files to Read First

- `README.md` — comprehensive operational guide (in Japanese)
- `data/game_config.json` — game rules, positions, grades, rendering config
- `assets/app.js` — main game logic entry point
- `api/admin_api.php` — admin console backend

---

## When to Ask User Confirmation

Do not proceed without confirmation:

- Modifying any file in `scores/` or `requests/`
- Deleting or renaming problems
- Force-pushing or rewriting history
- Anything that touches `vendor/` or Composer
- Changing production URLs or `.htaccess`
- Modifying `scores/release_versions.json`

---

## Version Numbering

- `app_version`: Internal tracking (e.g., v1016)
- `cache_version`: Service Worker cache invalidation key
- `public_version`: User-facing release version (e.g., v1.1.67)

All three **must stay in sync** and be documented in PRs.
