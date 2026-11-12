# Workspace notifications

## Implementation

The shared header now renders an authenticated inbox, not the former static placeholder. The full inbox uses the application's existing internal page router (`notifications`) and is opened through **View All Notifications**. It supports category filters, unread filtering, pagination, read/unread, and mark all read. Clicking a sale or expense notification refreshes workspace records and opens the matching transaction details if it still exists. Deleted records open the transaction list.

Notification persistence, model observers and the existing inventory-alert service are reused. Notification rows store event keys and structured parameters; the React formatter renders English or Khmer, including localized currency and relative timestamps. No production demonstration data is inserted.

## Actual supported events

- Staff sale recorded; sale edited (notes/date) or deleted; outstanding balance created; sale payment received.
- Low stock, out of sellable stock, expiring soon, expires today and expired stock, based on real product/batch conditions.
- Staff expense recorded.
- Team member created, activated or deactivated.
- Telegram linked/unlinked, sent privately to the affected account without chat identifiers or linking credentials.
- Preparation pack created; missing business name/activity/province detected when the profile is updated or an owner account is created.
- Password changed/reset, privately to the affected account.

Owners receive workspace business alerts; participating staff receive only events explicitly addressed to them, not other staff members' activity. Owner-only history becomes inaccessible after demotion. All queries additionally constrain business, recipient morph type/id and active account status. Links are resolved from a server allowlist, never arbitrary URLs.

Unsupported: overdue payments (no due-date model), cancellation (deletion is reported as deletion), unsupported general security events, and unverifiable financing eligibility. There is no historical backfill or fabricated notification history. Existing alert episodes do not generate repeated notifications on polling or refresh; resolved/recurrent conditions create new episodes.

## APIs

All require Sanctum authentication and completion of any mandatory password change:

- `GET /api/notifications?unread=1&category=inventory&page=1&per_page=20` (maximum 50 per page)
- `GET /api/notifications/unread-count`
- `PATCH /api/notifications/{id}/read`
- `PATCH /api/notifications/{id}/unread`
- `POST /api/notifications/read-all`

The frontend polls every 30 seconds while visible, refreshes on focus/online, and remounts notification state when user/workspace/role changes. Read mutations return authoritative unread counts. Requests are aborted on unmount and stale polling responses cannot overwrite a newer read count.

## Deployment

Run `php artisan migrate` in the backend on deployment. The notification migration has already been applied to the local development database. The existing Laravel scheduler runs `inventory:refresh-alerts` daily at 00:05 Asia/Phnom_Penh; production must invoke `php artisan schedule:run` every minute (or run `schedule:work`). Product/batch saves also refresh inventory conditions. No WebSocket server or queue worker is introduced.

## Files involved

Frontend additions: `src/lib/NotificationContext.js`, `src/lib/notificationText.js`, `src/components/NotificationProvider.jsx`, `src/components/NotificationsPanel.jsx`, `src/components/NotificationsPage.jsx`, `src/components/notifications.css`.

Frontend integration changes: `src/App.jsx`, `src/main.jsx`, `src/components/AppHeader.jsx`, `src/components/RecordsHub.jsx`.

Backend reused foundation: `WorkspaceNotification`, `WorkspaceNotifier`, `BusinessNotificationObserver`, `InventoryNotificationObserver`, `InventoryAlertService`, notification migration and observer registration. Backend API changes: `app/Http/Controllers/Api/NotificationController.php`, `routes/api.php` (mark-unread support).

Tests: `backend/tests/Feature/NotificationIntegrationTest.php` exercises genuine business API/model events with an isolated SQLite database, including sale amount finalization, stock thresholds, duplicate retries, authorization, read persistence, personal events without credentials, and transaction rollback. These are not live workspace mutations or an external Telegram delivery test.

Verification completed: the full Laravel suite passes (44 tests, 217 assertions), including 10 notification integration tests (68 assertions). The frontend production build and ESLint pass, and editor diagnostics report no errors in the checked notification files.

Authenticated browser checks used actual APIs with a disposable SQLite database: created a staff account, changed its temporary password, recorded a $35 sale for seven units, and verified the owner received the sale, outstanding-balance and low-stock notifications with three units remaining. Verified individual read, mark-all-read, badge removal, read persistence after refresh, unread empty state, the full inbox, and navigation into the actual sale details. English and Khmer titles, descriptions and relative timestamps were checked; light and dark panel colors were checked, and the mobile panel measured 351px wide and 480px high. Workspace reassignment, different recipients/roles, expiry transitions, duplicate processing and rollback are covered by backend tests. No external Telegram delivery or production scheduler execution was performed.

Additional hardening: sale notifications hydrate database-default currency values, Khmer relative timestamps work even when browser ICU data lacks Khmer, batch dates and stock quantities use localized formatting, pagination accessibility labels are bilingual, the empty state uses a bell-check icon, the dropdown includes its border within the 480px limit, and internal page selection survives refresh.

During browser setup, Laravel's development server discarded database environment overrides without `--no-reload`. Three verification-only registrations briefly reached the normal local database; all three were removed by exact email and workspace-name checks. No sales or inventory were created there. Subsequent business-event browser checks used the explicitly isolated database and server.
