# Telegram integration changes and verification

The account connection card now appears first, identifies @KotTraLuyBot and the
current account/workspace, and shares backend connection status with the test
button. Status reads the authenticated user's fresh database record, disables
caching, and exposes recipient eligibility without exposing credentials. Focus
refresh and existing link polling update both badges and test eligibility.
An alert received by another workspace recipient does not connect this account.
The previous UI used the initial user snapshot for the test button while badges
used a separate status response. This mismatch is fixed; the original screenshot's
specific account/workspace mismatch cannot be established without that session.

Settings retain the existing database storage and owner permissions. Notification
language remains a per-user preference, used for each recipient's messages.
Reminder frequency is persisted; this change does not introduce a new reminder
scheduler or change stable event keys. Expires-today alerts use the expiring-soon
preference. The working token, webhook, linking flow, and inline Mini App URL
construction are preserved.

Countdowns use a shared backend formatter in Asia/Phnom_Penh. A timestamp keeps
its precise instant; a date-only value expires at 23:59:59.999999 Cambodia time.
This follows the existing policy that stock expiring today remains sellable
throughout that date, documented in [Product expiration](./PRODUCT_EXPIRATION.md).
Durations show days and hours, or hours and minutes below one day. Exact zero is
expired. Khmer durations use Khmer digits. Telegram HTML escapes dynamic fields.

## Modified files

- [ExpirationCountdown.php](../backend/app/Services/ExpirationCountdown.php): shared countdown and date-only policy.
- [TelegramInventoryNotifier.php](../backend/app/Services/TelegramInventoryNotifier.php): translated alert formatting and expires-today preference.
- [InventoryAlertService.php](../backend/app/Services/InventoryAlertService.php): integer calendar-day compatibility data without mixed timezone fractions.
- [TelegramLinkController.php](../backend/app/Http/Controllers/Api/TelegramLinkController.php): fresh scoped status, identity, eligibility, cache control, and active-account test validation.
- [ExpirationController.php](../backend/app/Http/Controllers/Api/ExpirationController.php) and [ProductController.php](../backend/app/Http/Controllers/Api/ProductController.php): centralized countdowns in API responses.
- [TelegramAccessPanel.jsx](../frontend/src/components/TelegramAccessPanel.jsx): layout, identity, status refresh and shared eligibility.
- [TelegramNotificationSettings.jsx](../frontend/src/components/TelegramNotificationSettings.jsx): switch, language names, translated controls, visible test button and disabled explanation.
- [integration-pages.css](../frontend/src/components/integration-pages.css): spacing, responsive grids, controls and theme variables.
- [ExpirationInventory.jsx](../frontend/src/components/ExpirationInventory.jsx) and [InventoryAlertsPage.jsx](../frontend/src/components/InventoryAlertsPage.jsx): backend countdown display.
- [en.json](../frontend/src/locales/en.json) and [km.json](../frontend/src/locales/km.json): labels and explanations.
- [ExpirationCountdownTest.php](../backend/tests/Unit/ExpirationCountdownTest.php): duration and language edge cases.
- [TelegramInventoryNotificationTest.php](../backend/tests/Feature/TelegramInventoryNotificationTest.php): persistence, account scope, eligibility, message escaping and Mini App URL.
- [BizTrackTelegramBotTest.php](../backend/tests/Feature/BizTrackTelegramBotTest.php) and [TelegramMiniAppTest.php](../backend/tests/Feature/TelegramMiniAppTest.php): isolate HTTP/queue effects to prevent live sends during regressions.
- This verification report.

## Verified

- Focused backend suite: 33 tests and 208 assertions pass, covering Telegram linking and sales,
  Mini App authentication, expiration inventory, settings persistence,
  recipient/workspace isolation, notification deduplication and countdowns.
- Frontend production build and translation validation pass. Existing bundle
  size advisory remains. Focused ESLint and editor diagnostics pass.
- Browser component checks with mock account responses: 1440px, 390px and 320px,
  light and dark surfaces, no horizontal overflow, 24px section gaps, 24px desktop
  padding and 20px mobile padding, two desktop notification columns and one mobile
  column. Khmer setting labels and selected language names render correctly.
- Mock disconnected-to-connected refresh enables the test button and updates
  both badges. A simulated Telegram environment at mobile width has no overflow.

## Remaining live verification

Actual delivery to an identified linked account and rendering inside a real
Telegram client require a signed-in session and designated recipient. The browser
was signed out; no live notification was deliberately sent by these checks.
HTTP delivery assertions use fakes and do not prove Telegram accepted a live
message. No production alerts were regenerated for testing. The attached
read-only snapshot was inspected and left untouched.
