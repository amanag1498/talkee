# Meta App Events release configuration

The SDK is enabled by default. Use `--dart-define=META_APP_EVENTS_ENABLED=false`
only when a build must suppress Meta app events.

1. In Meta for Developers, add Android package `com.techybugs.talkee` and iOS
   bundle ID `com.techybugs.talkee`, then link the Meta app to the production
   Business Portfolio and ad account.
2. Copy `android/meta.properties.example` to `android/meta.properties` and set
   the Meta App ID and Client Token.
3. Copy `ios/Flutter/Meta.xcconfig.example` to
   `ios/Flutter/Meta.xcconfig` and set the same values.
4. Build normally. No Meta-specific Dart define is required.
5. Verify a fresh store install and app activation in Meta Events Manager Test
   Events and Meta App Ads Helper. Use a physical device, uninstall the app
   first, follow an actual Meta ad to the store, install, and launch it.
6. Verify registration, login, ATT consent, and a confirmed recharge in Meta
   Events Manager and `/admin/meta-app-events`. The admin audit confirms the
   app/backend path only; it does not prove Meta campaign attribution.

In debug builds, Meta SDK diagnostics are enabled and the first activation is
flushed immediately. Android advertiser-ID collection is enabled before the
first activation. iOS keeps advertiser tracking disabled until ATT is already
authorized, and includes Meta's SKAdNetwork reporting configuration for
privacy-preserving install measurement.

The Laravel environment can expose non-secret integration health in the admin
Setup & Health tab:

```dotenv
META_APP_ID=
META_CLIENT_TOKEN=
META_AD_ACCOUNT_ID=
META_BUSINESS_ID=
```

Do not put the Meta App Secret in any mobile configuration file. The client
token is expected by the native SDK; the app secret stays in Meta/server-only
tooling.
