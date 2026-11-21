# Family Typing App — Phase 1

A private, ad-free touch-typing tutor. Vanilla HTML/CSS/JS front end, PHP 8 JSON
API, MySQL. No build step, no CDN, no third-party scripts. Designed to deploy to
Hostinger shared hosting in a subfolder (e.g. `scoutthink.com/projects/typing/`)
using only relative paths.

## What Phase 1 does
- Family password → profile picker → 4-digit PIN (parent PIN ≥ 6), with server-side
  lockout after 5 wrong tries for 15 minutes.
- Placement check that picks a starting lesson and seeds weak keys.
- 24-lesson curriculum (home → top → bottom → capitals → punctuation → numbers →
  fluency). Accuracy-gated: 90% to pass, 1–3 stars for accuracy, speed shown as a
  personal best only — never a gate, never a countdown.
- On-screen keyboard with finger color-coding and next-key highlight (hideable).
- Adaptive “tricky keys” warm-up built from each learner’s own miss data.
- Every attempt saved to the server; per-learner progress page + a parent dashboard.
- Parents can add/edit profiles.

The Phase 2 tables (`content_items`, `topics`, `rewards`) are created now but no
Phase 2/3 features are built. The lesson engine (`assets/js/engine.js`) is
source-agnostic: the future game layer and AI passages feed it any target text and
read the same result object — scoring and storage don’t change.

## Deploy to Hostinger
1. In hPanel, create a MySQL database + user. Note the name, user and password.
2. Upload the contents of this folder into the subfolder (e.g. `public_html/projects/typing/`).
3. Visit `…/projects/typing/setup.php` in a browser. Fill in:
   - the database name/user/password (host `127.0.0.1`, port `3306`),
   - a **family password** (everyone types this to open the site),
   - the first **grown-up** name + PIN (6–10 digits).
   Setup builds the tables, loads the lessons, and creates the parent profile.
4. **Delete `setup.php`** from the server (it refuses to run again, but remove it anyway).
5. Open `index.html`. Log in, add the kids’ profiles from the grown-up area, and go.

Config secrets live in `api/config.php` (written by setup, **never committed**) and
are blocked from the web by `.htaccess`.

## Local testing (no MySQL needed)
The API is driver-aware and runs on SQLite for tests:
```
php -S 127.0.0.1:8099 -t .     # with TUTOR_CONFIG pointing at a sqlite config
```
See `db/schema.sql` for the canonical MySQL schema; `api/lib/migrate.php` builds the
same tables on either driver.

## Editing the curriculum
Lessons live in `data/curriculum.json`. Re-running `setup.php` (or the seed step)
upserts them by lesson order. Every typed string is constrained to keys unlocked by
that point — the same validation the server will apply to AI passages in Phase 2.

## Layout
```
index.html              SPA shell
assets/css/app.css       styles
assets/js/
  engine.js              source-agnostic typing engine (scoring, key stats)
  keyboard.js            on-screen keyboard, finger colors, next-key highlight
  api.js                 fetch helper (relative paths, cookie session)
  app.js                 router + every screen
api/
  index.php              JSON API front controller (?r=route)
  config.sample.php      template (setup.php writes config.php)
  lib/                   db, auth, helpers, migrate, seed
data/curriculum.json     the 24-lesson curriculum
db/schema.sql            canonical MySQL schema (reference)
setup.php                one-time installer (delete after running)
```
