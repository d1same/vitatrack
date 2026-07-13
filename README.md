# 🥑 VitaTrack — Your Health & Weight-Loss Companion

A complete, installable health web app: calorie counting, keto diet support, intermittent
fasting timer, AI food-photo scanning, water reminders, back-safe workouts, recipes,
weight/body-fat charts and multi-user accounts — all on plain **PHP 8 + SQLite**, so it
runs on any cPanel shared host with zero configuration.

## ✨ Features

- **Multi-user accounts** — secure registration/login (bcrypt password hashing, sessions)
- **Onboarding wizard** — age, sex, height, weight, body fat, activity, goal, health issues
- **Personal plan** — BMR/TDEE (Mifflin-St Jeor), calorie target, keto/low-carb/balanced macros, water goal, projected goal date
- **Food diary** — 150+ built-in foods, custom foods, per-meal logging, net-carb tracking with keto warnings
- **▮▯ Barcode scanner (free, no key, no AI)** — live camera scan on every phone (native detector on Chrome/Android, embedded ZXing decoder on iPhone Safari); nutrition comes from the free Open Food Facts database, and scanned items are remembered in "my foods"
- **Recent & frequent foods** — your usual foods appear instantly when adding, with one-tap "log again" (same portion as last time)
- **🌐 Online food search (free, no key)** — search millions of products on Open Food Facts right from the diary
- **📷 AI photo scan** (optional) — snap a photo of a cooked meal, Claude vision estimates calories/protein/carbs/fat, one-tap log
- **Intermittent fasting** — 16:8 / 18:6 / 20:4 / OMAD live timer with fasting-stage science and history
- **Water tracker** — animated progress, quick-add buttons, reminder notifications
- **Workout library** — 28 exercises flagged *back-safe* and *low-impact*, auto-filtered to your health issues; logging with calorie burn
- **21 keto recipes** — full macros, ingredients, instructions, one-tap diary logging
- **Food color system (Noom-style)** — every food gets a green/yellow/orange calorie-density dot, plus a daily color-mix bar
- **Extended nutrition (Cronometer-style)** — sugar, sodium & saturated fat tracked against daily limits ("More nutrition" in the Diary)
- **My Meals & Copy yesterday (MyFitnessPal-style)** — save any logged meal (⭐ in the Diary) and re-log it in one tap; ⧉ copies yesterday's meal
- **Daily lessons (Noom-style)** — a 28-day coaching course on habits, keto, fasting and mindset; one 2-minute lesson unlocks per day
- **Biometrics (Cronometer-style)** — log blood pressure, glucose, ketones, resting heart rate, sleep and steps, with charts
- **Health score & weekly insights** — a daily 0-100 score on the dashboard and a weekly summary with coaching feedback
- **Progress** — weight & body-fat line charts, 30-day calorie/carb/water charts, streaks, BMI
- **Daily coach** — meal + workout suggestion adapted to time of day and your conditions
- **Reminders** — drink water, meal times, morning weigh-in (browser notifications)
- **PWA** — installable on iPhone & Android home screens, dark/light theme, offline shell

## 🚀 Install on cPanel (5 minutes)

1. **Upload** everything in this folder to your host (e.g. `public_html/health/`).
   - If a local test database exists, delete `data/health.sqlite*` first (keep `data/.htaccess`) so you start fresh.
2. **PHP version**: in cPanel → *Select PHP Version*, pick PHP 8.1+ and make sure these
   extensions are on (they almost always are): `pdo_sqlite`, `sqlite3`, `curl`, `gd`.
3. **Permissions**: the app auto-creates `data/health.sqlite` on first visit. If you get a
   "unable to open database" error, set the `data/` folder to permission `755` (or `775`)
   and make sure it's owned by your account.
4. Visit `https://yourdomain.com/health/` — register the first account and go. 🎉
5. **HTTPS is required** for notifications and home-screen install (cPanel's free
   AutoSSL/Let's Encrypt is fine).

### 📱 Install on your phone
- **iPhone**: open the site in Safari → Share → **Add to Home Screen**. (Notifications on
  iOS only work for home-screen-installed web apps, iOS 16.4+.)
- **Android**: open in Chrome → menu → **Add to Home screen** (or tap the install banner).

### 🤖 Enable AI photo scanning
1. Create an API key at **console.anthropic.com** (each food scan costs a fraction of a cent).
2. In the app: **More → Settings → AI photo scan** → paste the key → Save.
   The key is stored server-side in your database and never sent back to browsers.

### 🔔 Background notifications (reminders when the app is closed)
1. Deploy, then in cPanel → **Cron Jobs** add (every 15 minutes):
   `*/15 * * * *  /usr/local/bin/php /home/YOURUSER/path-to-site/cron.php`
2. In the app: **Settings → Reminders** → pick your reminders → tap
   **Enable background notifications** and allow the permission prompt.
Reminders now arrive via Web Push even when the app isn't running.
(iPhone requires the app installed to the home screen, iOS 16.4+.)

### 🤖 Android app
A signed, installable **`vitatrack.apk`** ships in this repo (and deploys to your
site root, e.g. `https://your-site/vitatrack.apk`). To install: open that URL on an
Android phone, download, and allow "install from this source." It's a TWA — the
same VitaTrack web app in a native shell, sharing your server, accounts, and
background notifications. The matching `.well-known/assetlinks.json` (already
deployed) validates the signing key so the browser address bar is hidden and it
looks 100% native.

- **Rebuild it** (after a key change or to bump the version): the Bubblewrap
  project is in `android-app/` — `bubblewrap build`, then sign. The signing
  **`android.keystore` is gitignored — keep your own copy forever**; it's the
  app's permanent identity and Play Store won't accept a different one later.
- **Play Store** (optional, $25 one-time): build an `.aab` and upload; the same
  keystore signs it.

### ❤️ Health Connect sync (steps, workouts, sleep, heart rate)
The server can ingest health data from **Google Health Connect** — which aggregates
Samsung Health, rings, watches and most Android fitness apps — via a single
normalized endpoint (`sync_health`). The ingestion side is built and tested; the
native Android reader is a documented, buildable spec (needs a device + Play review).
See **[HEALTHCONNECT.md](HEALTHCONNECT.md)**. The same contract also fits an Apple
HealthKit companion or a server-side Oura puller.

## 🔄 Updating
Upload the new files over the old ones. **Never overwrite the `data/` folder** — that's
where all user data lives. Back it up by downloading `data/health.sqlite` occasionally.

## 🧱 Tech layout

| Path | Purpose |
|---|---|
| `index.php` | App shell (the SPA mounts here) |
| `api.php` | All JSON API endpoints |
| `includes/db.php` | SQLite schema + seed data (foods, recipes, exercises) |
| `includes/calc.php` | BMR/TDEE/macro math |
| `includes/auth.php` | Session auth |
| `includes/ai.php` | Claude vision photo analysis |
| `assets/app.js` / `app.css` | Frontend SPA + design system (no frameworks, no CDNs) |
| `manifest.json`, `sw.js`, `icons/` | PWA install + offline + notifications |
| `data/` | SQLite database (blocked from web access) |

## ⚠️ Disclaimer
VitaTrack provides general wellness guidance, not medical advice. Consult your doctor
before starting a new diet or exercise program, especially with existing conditions.
