# Building the Thrive iOS app (Capacitor)

The iOS platform is scaffolded and ready. It loads the live web app
(`https://health.mobasheri.us`) and adds the same native plugins as Android:
**Apple HealthKit** (steps + workouts, via `capacitor-health`) and **local
notifications** (via `@capacitor/local-notifications`). Nothing about the web
UI changes — the app auto-detects native and uses these plugins.

## Requirements (all Apple-imposed)
- A **Mac with Xcode** (iOS apps can only be built on macOS). No Windows path.
- An **Apple Developer account** ($99/year) to run on a real iPhone and to
  distribute via TestFlight / the App Store.
- No Mac? Use a cloud macOS builder — **Codemagic**, **EAS Build**, or a
  **GitHub Actions `macos` runner**. You still need the Apple Developer account.

## First-time setup (on the Mac)
```bash
cd capacitor-app
npm install
npx cap sync ios      # resolves Swift Package plugins + copies config
npx cap open ios      # opens the project in Xcode
```

## In Xcode
1. Select the **App** target → **Signing & Capabilities**.
2. Set **Team** to your Apple Developer team, and confirm the **Bundle
   Identifier** (e.g. `us.mobasheri.health`).
3. Click **+ Capability** → add **HealthKit**. (This wires up the entitlement
   in `App/App/App.entitlements` and enables HealthKit on your App ID.)
   - Local notifications need **no** capability.
4. Optional: set the app icon (App/App/Assets.xcassets/AppIcon) to the Thrive
   heart, and the launch screen.
5. **Product → Run** on a device/simulator to test, or **Product → Archive** →
   Distribute for TestFlight / App Store.

## Notes
- `Info.plist` already has `NSHealthShareUsageDescription` /
  `NSHealthUpdateUsageDescription` (shown when Thrive asks for Health access).
- The web app reaches the plugins through the Capacitor bridge exactly like on
  Android — `capCall('HealthPlugin', …)` and `capCall('LocalNotifications', …)`.
- Bump `MARKETING_VERSION` / `CURRENT_PROJECT_VERSION` in Xcode for each release.
- To re-generate the iOS project from scratch: `npx cap add ios` (then redo the
  Info.plist Health keys + entitlements + HealthKit capability).
