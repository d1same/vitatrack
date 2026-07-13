# Health Connect sync — Android companion

Thrive can auto-import **steps, workouts, sleep, resting heart rate, blood
glucose/pressure and weight** from **Google Health Connect** — the on-device hub
that Samsung Health, Fitbit, Oura, Galaxy Ring, Garmin and most Android fitness
apps write into. Read Health Connect once and you capture almost any Android
user's data regardless of their device.

The **server half is built and tested**: a single normalized endpoint
(`sync_health`) that any connector can push to. What remains is the **native
Android reader**, which needs a real device and a Google Play health-data review
to finish — this document is the buildable spec for it.

---

## 1. How it fits together

```
Samsung Health / ring / watch ─┐
                               ├─▶ Health Connect (on device) ─▶ Thrive
Google Fit / Fitbit / Oura ────┘        (native read)          Android app
                                                                     │ HTTPS POST
                                                                     ▼
                                              https://<your-site>/api.php?action=sync_health
                                                                     │
                                                                     ▼
                                              biometrics + weights + workouts tables
                                                                     │
                                                                     ▼
                                              Progress → Health metrics (charts, automatic)
```

A pure TWA (the current Bubblewrap APK) **cannot** call Health Connect — that's
native API territory. The companion is a **Capacitor** wrapper: it runs the exact
same Thrive web UI, plus a native Health Connect plugin. Same server, same
accounts, same code — one extra native layer.

---

## 2. The sync contract (already live and tested)

`POST /api.php?action=sync_health` with the session cookie. Body:

```json
{
  "source": "health_connect",
  "metrics": [
    { "date": "2026-07-12", "type": "steps",  "value": 8432 },
    { "date": "2026-07-12", "type": "sleep",  "value": 7.4  },   // hours
    { "date": "2026-07-12", "type": "rhr",    "value": 57   },   // bpm
    { "date": "2026-07-12", "type": "glucose","value": 95   },   // mg/dL
    { "date": "2026-07-12", "type": "bp",     "value": 118, "value2": 78 },
    { "date": "2026-07-12", "type": "weight", "value": 96.1 }    // kg → weights table
  ],
  "workouts": [
    { "date": "2026-07-12", "name": "Running", "minutes": 32, "kcal": 340, "ext_id": "hc-abc123" }
  ]
}
```

Response: `{ "ok": true, "metrics_synced": 6, "workouts_synced": 1 }`.

**Idempotent by design** — safe to re-sync the same window on every app open:
- Metrics: one value per `(date, type, source)`; re-syncing a day **replaces**
  it, never duplicates. Verified.
- Workouts: deduped by `ext_id` (use Health Connect's record UID). Re-imports skip.
- `weight` is routed to the weights table (kg); everything else to biometrics.
- Accepted metric types: `steps`, `sleep`, `rhr`, `glucose`, `bp` (needs
  `value2` = diastolic), `ketones`.

Sync status is exposed at `?action=sync_status` and shown in **Settings → Health
sync**.

---

## 3. Building the companion (Capacitor)

Prerequisites: Node, Android Studio, the JDK (you already have all three).

```bash
# From the repo root
npm init -y
npm i @capacitor/core @capacitor/cli @capacitor/android
npx cap init Thrive us.mobasheri.health --web-dir=.
npx cap add android
# Add a maintained Health Connect plugin (verify the current one on npm):
npm i capacitor-health-connect     # e.g. @kiwi-health/capacitor-health-connect
npx cap sync
```

Point the app at the live site (so the APK loads the deployed Thrive, and
sync survives deploys) — in `capacitor.config.json`:

```json
{
  "appId": "us.mobasheri.health",
  "appName": "Thrive",
  "server": { "url": "https://health.mobasheri.us", "cleartext": false }
}
```

### Manifest permissions (`android/app/src/main/AndroidManifest.xml`)

```xml
<uses-permission android:name="android.permission.health.READ_STEPS"/>
<uses-permission android:name="android.permission.health.READ_HEART_RATE"/>
<uses-permission android:name="android.permission.health.READ_RESTING_HEART_RATE"/>
<uses-permission android:name="android.permission.health.READ_SLEEP"/>
<uses-permission android:name="android.permission.health.READ_EXERCISE"/>
<uses-permission android:name="android.permission.health.READ_WEIGHT"/>
<uses-permission android:name="android.permission.health.READ_BLOOD_GLUCOSE"/>
<uses-permission android:name="android.permission.health.READ_BLOOD_PRESSURE"/>
<!-- Health Connect availability + privacy-policy intent (required) -->
<queries><package android:name="com.google.android.apps.healthdata"/></queries>
```

### Reference sync code (runs inside the Capacitor app)

Add `assets/health-sync.js` and load it only when the native plugin exists.
Plugin method names vary — check your chosen plugin's docs — but the shape is:

```js
// Pull the last 7 days from Health Connect and push to Thrive's server.
async function syncHealthConnect() {
  const HC = window.Capacitor?.Plugins?.HealthConnect;
  if (!HC) return;                                   // not the native app → skip
  const avail = await HC.checkAvailability();
  if (avail.availability !== 'Available') return;

  const read = ['Steps', 'HeartRate', 'RestingHeartRate', 'SleepSession',
                'ExerciseSession', 'Weight', 'BloodGlucose', 'BloodPressure'];
  const granted = await HC.requestHealthPermissions({ read });
  if (!granted?.granted?.length) return;

  const end = new Date();
  const start = new Date(Date.now() - 7 * 864e5);
  const range = { startTime: start.toISOString(), endTime: end.toISOString() };

  const dayKey = t => new Date(t).toISOString().slice(0, 10);
  const metrics = [];
  const workouts = [];

  // Steps come as intervals — sum per day
  const steps = await HC.readRecords({ type: 'Steps', timeRangeFilter: range });
  const byDay = {};
  for (const r of steps.records) byDay[dayKey(r.startTime)] = (byDay[dayKey(r.startTime)] || 0) + r.count;
  for (const [date, value] of Object.entries(byDay)) metrics.push({ date, type: 'steps', value });

  const rhr = await HC.readRecords({ type: 'RestingHeartRate', timeRangeFilter: range });
  for (const r of rhr.records) metrics.push({ date: dayKey(r.time), type: 'rhr', value: r.beatsPerMinute });

  const sleep = await HC.readRecords({ type: 'SleepSession', timeRangeFilter: range });
  for (const r of sleep.records) metrics.push({ date: dayKey(r.startTime), type: 'sleep',
    value: +( (new Date(r.endTime) - new Date(r.startTime)) / 36e5 ).toFixed(1) });

  const weight = await HC.readRecords({ type: 'Weight', timeRangeFilter: range });
  for (const r of weight.records) metrics.push({ date: dayKey(r.time), type: 'weight', value: r.weight.value });

  const ex = await HC.readRecords({ type: 'ExerciseSession', timeRangeFilter: range });
  for (const r of ex.records) workouts.push({ date: dayKey(r.startTime), name: r.title || r.exerciseType || 'Activity',
    minutes: Math.round((new Date(r.endTime) - new Date(r.startTime)) / 6e4),
    kcal: r.activeCalories || 0, ext_id: r.metadata?.id });

  if (!metrics.length && !workouts.length) return;
  await fetch('api.php?action=sync_health', {
    method: 'POST', credentials: 'same-origin',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({ source: 'health_connect', metrics, workouts }),
  });
}

// Call on launch and when the app returns to foreground.
document.addEventListener('DOMContentLoaded', syncHealthConnect);
document.addEventListener('resume', syncHealthConnect);   // Capacitor App lifecycle
```

The web app already surfaces the result — no UI work needed. Synced points appear
in **Progress → Health metrics** and the last-sync line in **Settings → Health
sync**.

Build the APK with `npx cap open android` (Android Studio) → Build → Signed Bundle
/ APK, reusing the keystore in `android-app/` (or a new one — but keep it forever;
it's your app's identity).

---

## 4. Google Play requirements (the part that needs review)

Reading Health Connect is **sensitive** and gated:

1. A **privacy policy URL** that discloses what health data you read and why.
2. The Play Console **Health Connect declaration form** (per data type requested).
3. Health-data app review before public release. Sideloaded/internal-test APKs
   work immediately for yourself and testers without review.

For an open-source, self-hosted project the clean model is: **one official
published Thrive app** whose `server.url` can be pointed at any deployment, so
self-hosters don't each need their own Play listing. Document the alternative
(build your own APK) for those who want it.

---

## 5. Status

| Piece | State |
|---|---|
| `sync_health` ingestion endpoint | ✅ built, tested (idempotent, dedup verified) |
| `sync_status` + Settings card | ✅ built, tested |
| Synced data in Progress charts | ✅ automatic (shared biometrics/workouts tables) |
| Capacitor wrapper + Health Connect plugin | ⏳ needs a device to build & test |
| Play Store health-data review | ⏳ needs a privacy policy + submission |

Same contract works for a future **Apple HealthKit** companion (`source:
"apple_health"`) and cloud pullers like **Oura** (`source: "oura"`, server-side
on the existing cron) — they all POST the same shape.
