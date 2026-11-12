# Khmer and English localization audit

## Result

The existing keyed translation architecture is retained. English and Khmer each
contain **1,092 keys** (45 additional paired keys). There are no missing locale
keys, corrupt Khmer placeholders, interpolation mismatches or duplicate JSON keys.
Six corrupted Khmer entries were repaired and 21 existing entries were refined
for consistent business terminology. No business calculations were changed.

The initial audit found complete key parity but incomplete rendering: legacy DOM
translation, hardcoded metadata, raw enum values, double translation of already
translated headings, errors stored as rendered strings, and inline Khmer copies.
Key parity alone was insufficient to establish coverage.

## Implementation and files

- [i18n.js](../frontend/src/lib/i18n.js): one dictionary-based renderer, visible
  missing-key diagnostics, interpolation/plurals, persistent language preference,
  cross-tab updates, localized document title, native form validity, safe error
  codes and Khmer calendar formatting independent of browser ICU support.
- [en.json](../frontend/src/locales/en.json) and
  [km.json](../frontend/src/locales/km.json): errors, relative times, calendar
  months, accessible notification counts, action labels, and terminology repairs.
  The existing [sourceKeys.json](../frontend/src/locales/sourceKeys.json) remains
  a compatibility map into these dictionaries; it is not another translation system.
- [App.jsx](../frontend/src/App.jsx): keyed route labels/descriptions and removal
  of double translation in shared headings and empty states.
- [MorePage.jsx](../frontend/src/components/MorePage.jsx): keyed Settings groups,
  descriptions, categories and destination labels.
- [workspaceUI.js](../frontend/src/lib/workspaceUI.js),
  [financialReports.js](../frontend/src/lib/financialReports.js),
  [FinancialStatement.jsx](../frontend/src/components/FinancialStatement.jsx),
  [ReportsLivePage.jsx](../frontend/src/components/ReportsLivePage.jsx): keyed
  form/report labels and validation results.
- [InventoryLivePage.jsx](../frontend/src/components/InventoryLivePage.jsx),
  [InventoryAlertsPage.jsx](../frontend/src/components/InventoryAlertsPage.jsx),
  [InventoryAlertSummary.jsx](../frontend/src/components/InventoryAlertSummary.jsx),
  [ExpirationInventory.jsx](../frontend/src/components/ExpirationInventory.jsx):
  keyed stock and expiration labels, including alert condition names.
- [FormUI.jsx](../frontend/src/components/FormUI.jsx),
  [ProductDialog.jsx](../frontend/src/components/ProductDialog.jsx),
  [QuickActionsPanel.jsx](../frontend/src/components/QuickActionsPanel.jsx):
  render stored validation errors in the current language and preserve structured
  API failures instead of prematurely rendering their messages.
- [api.js](../frontend/src/lib/api.js): stable client error classifications;
  invalid login credentials retain their meaning without displaying server English.
- [notificationText.js](../frontend/src/lib/notificationText.js),
  [NotificationsPage.jsx](../frontend/src/components/NotificationsPage.jsx),
  [NotificationsPanel.jsx](../frontend/src/components/NotificationsPanel.jsx),
  [NotificationProvider.jsx](../frontend/src/components/NotificationProvider.jsx),
  [AppHeader.jsx](../frontend/src/components/AppHeader.jsx): translated heading,
  descriptions, relative times, category filters and accessible bell count.
  Notification categories now use actual category codes rather than array strings.
- [FinanceOptionsPage.jsx](../frontend/src/components/FinanceOptionsPage.jsx),
  [PreparationPacksPage.jsx](../frontend/src/components/PreparationPacksPage.jsx),
  [UserManagementPanel.jsx](../frontend/src/components/UserManagementPanel.jsx),
  [TelegramAccessPanel.jsx](../frontend/src/components/TelegramAccessPanel.jsx):
  translated source-review status, provider product labels, dates, actions and
  Telegram breadcrumb; translated financing options retain their original values.
- [typography.css](../frontend/src/typography.css) and
  [index.html](../frontend/index.html): Khmer line-height/wrapping, expanding
  controls, Khmer print font and saved language applied before React starts.
- [check-i18n.mjs](../frontend/scripts/check-i18n.mjs),
  [package.json](../frontend/package.json), [package-lock.json](../frontend/package-lock.json):
  AST-based completeness check integrated into the production build. Babel's parser
  is an explicit development dependency. `npm run i18n:audit` also reports potential
  unused keys in [i18n-audit.json](../frontend/scripts/i18n-audit.json).
- [i18n.test.js](../frontend/tests/i18n.test.js) and
  [financial-reports.test.js](../frontend/tests/financial-reports.test.js): language
  switching/persistence, missing diagnostics, original record preservation, error
  translation, native validation, plurals, Khmer dates and keyed report validation.

## Verification

- Production build passes, including the translation gate; ESLint passes.
- Frontend: **16 tests pass**, including existing Khmer voice parser tests.
- Backend regression suite: **56 tests / 313 assertions pass**.
- Visited every existing page destination in Khmer and English: `overview`,
  `records`, `inventory`, `reports`, `more`, `prepare`, `options`, `packs`,
  `documents`, `business`, `telegram`, `alerts`, `notifications`.
- Checked 117 Khmer route/layout/theme combinations: 13 destinations × three
  viewport widths (1440, 768, 390) × light, dark and system modes. No missing-key
  markers, document horizontal overflow or clipped heading/button boxes detected.
- Verified open Product dialog and its required-field error switch Khmer → English
  without reload. Checked create/edit/reset-password/deactivate team dialogs.
- Verified real invalid-login error translation, then successful authentication,
  registration form switching, and persisted language across logout/reload and login.
- Generated a real zero-activity financial report from an isolated verification
  database and checked Khmer statements, account labels and limitations.
- Verified staff Settings and shift UI separately. Permissions were not changed.

The browser used an isolated SQLite workspace with test owner/staff accounts.
No mock products, inventory alerts or sales were added to the application database.
Temporary verification data and servers are removed after testing.

## Intentional untranslated content and limits

Brand/provider names (KotTraLuy, Telegram, ABA Bank, ACLEDA Bank, FTB), currency
codes (USD/KHR), native language selector names, and original business records
remain unchanged. Unknown backend messages render a safe localized message rather
than exposing raw server text.

There are 146 potential unused keys in the static report. They are retained for
legacy helpers, backend enum aliases and compatibility, rather than deleting keys
on the basis of an approximate static analysis. Dynamic runtime translation cannot
be proven exhaustively by static checks; the report distinguishes candidates from
confirmed missing translations.

Browser verification covers existing pages, empty/zero-activity report states and
the listed dialogs, not every possible business-record combination. Live microphone
recognition and Telegram's external application were not exercised. There is no
public forgot-password route in this project; the existing owner reset-password
workflow was checked. The existing large-bundle build warning remains.
