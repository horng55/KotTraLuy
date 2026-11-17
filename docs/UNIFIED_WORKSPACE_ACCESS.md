# Unified workspace interface and authorization

All authenticated roles now use the existing Owner shell and HomePage dashboard.
Sidebar, header, logo, account menu, notifications, Sales, Stock, forms, theme
controls and mobile navigation share the same components. The separate staff
dashboard was removed. Dashboard cards, actions and Business Hub modules are
filtered by effective permissions; remaining cards fill the available grid.

## Permissions and workspace context

The existing RolePermissions identifiers are reused. No parallel permission
system or role-specific translation dictionaries were introduced. Member is
recognized as an operational role with the existing Staff defaults. Existing
Owner, Manager, Staff, cashier/job-role values and memberships are preserved.

| Access | Default behavior |
| --- | --- |
| Owner | All features in the current authorized workspace. |
| Manager | Shared operational dashboard, workspace sales, stock management, expenses and operational reports. Business Hub appears only when an authorized management module is granted. |
| Staff / Member | Shared dashboard with personal sales and authorized stock; no default financial reports or Business Hub. |

Owners edit supported grants through Business Hub → Team Management → the
member's permission shield. Nullable membership overrides inherit existing role
defaults; an explicit empty list removes optional grants. Existing role defaults
are not silently replaced during migration. Dashboard and personal Telegram
connection remain available. Sales creation requires inventory reading and an
own-shift permission; stock operations imply inventory reading. Workspace-wide
sales reading implies personal sales reading; shift management implies own shifts.

Staff and Member cannot obtain financing, team administration or workspace
Telegram settings through an override. Authorized Managers see only individually
granted Business Hub modules. With team.manage, Managers can manage operational
details/status for ordinary members and invite Staff, Member or Cashier accounts.
Role changes, permission assignment, password resets, direct account creation,
and invitations for Managers remain Owner-only. Owners retain their existing
account-administration controls.

The existing business_user table now stores nullable permission overrides, status,
assigned shift and Telegram sales enablement. A workspace switch issues a Sanctum
session scoped to an authorized membership; it does not rewrite the person's
primary business or global role. Each authenticated request reloads membership
context. The frontend clears business data, closes dialogs, invalidates old loads,
reloads permissions and returns to Dashboard during switching. Other device
sessions keep their own context. Existing account invitations can add another
workspace membership after verifying the invited email/phone.

## Backend protections

- Permission middleware and workspace context protect API requests independently
  of navigation visibility, including Mini App sessions.
- Sales lists and direct record operations enforce personal/workspace sales scope.
- Restricted costs, margins, profits and workspace notification configuration are
  removed from JSON responses before transmission. Expenses require the relevant
  expense/financial permission. Login and Mini App authentication responses also
  apply visibility rules.
- Product editors omit withheld cost values, preserving existing costs on ordinary
  edits. Changing default product costs requires financial permission. Historical
  purchase reads require purchase management plus financial permission; recording
  receipts retains the purchase-management permission.
- Financial Excel/PDF generation is permission-protected; temporary PDF downloads
  re-check current membership and financial permission, including revocation.
  Operational CSV output applies the same financial-field filtering.
- Operational reports honor the selected currency and personal/workspace sales
  scope across range, daily, monthly and export endpoints.
- Inboxes and pending Telegram deliveries re-check current authorization. Ordinary
  staff no longer receive other members' confidential sales notifications.
- Personal Telegram linking is available from the shared profile menu without
  Business Hub access. Workspace inventory notification configuration requires
  telegram.settings. Recipient validation supports active secondary memberships.
  The bot token, official identity, webhook and inventory deduplication keys are
  unchanged.

The additive migration was applied successfully to the configured local database.
It adds columns only; no existing business records, roles or memberships were
deleted or rewritten. Other environments must apply the same migration before
running the updated backend.

## Verification

- Full backend suite: 91 tests and 614 assertions pass, including workspace roles,
  scoped reports, exports, invitation membership, cost protection and Telegram
  regressions.
- Frontend Node suite: 22 tests pass, including shared navigation, manager grants,
  manipulated staff grants and workspace permission replacement.
- Production build, translation validation and focused ESLint pass. The existing
  large-bundle advisory remains.
- Browser fixtures exercised Owner, Manager, Staff and Member separately at
  1440px, 768px and 390px in light/dark mode: one shared dashboard, appropriate
  navigation/card visibility and no horizontal overflow.
- Browser switching from Owner to Member removed Reports, Business Hub and
  financial cards; a manipulated Business Hub history entry returned to Dashboard.
- Personal Telegram access stayed available without workspace settings. English
  and Khmer navigation/role labels were checked. All four roles used the shared
  layout in a simulated Telegram Mini App environment at mobile width.
- A Manager product-edit browser check confirmed the cost input was hidden and
  default_cost was absent from submitted FormData; backend tests confirm existing
  costs survive and unauthorized cost updates return 403.
- Existing Telegram linking, bot commands, expiration formatting and inventory
  notification/deduplication regressions pass with HTTP/queue isolation. No live
  Telegram messages were generated for this task.

Browser role checks use in-memory fixtures; backend tests use isolated database
accounts. A native Telegram client and production-user sessions were not used.

## Files modified for this request

### Backend

- [AuthController.php](../backend/app/Http/Controllers/Api/AuthController.php)
- [DashboardController.php](../backend/app/Http/Controllers/Api/DashboardController.php)
- [InvitationController.php](../backend/app/Http/Controllers/Api/InvitationController.php)
- [NotificationController.php](../backend/app/Http/Controllers/Api/NotificationController.php)
- [PdfExportController.php](../backend/app/Http/Controllers/Api/PdfExportController.php)
- [ProductController.php](../backend/app/Http/Controllers/Api/ProductController.php)
- [ReportController.php](../backend/app/Http/Controllers/Api/ReportController.php)
- [SaleController.php](../backend/app/Http/Controllers/Api/SaleController.php)
- [ShiftController.php](../backend/app/Http/Controllers/Api/ShiftController.php)
- [TelegramBotController.php](../backend/app/Http/Controllers/Api/TelegramBotController.php)
- [TelegramLinkController.php](../backend/app/Http/Controllers/Api/TelegramLinkController.php)
- [UserManagementController.php](../backend/app/Http/Controllers/Api/UserManagementController.php)
- [WorkspaceController.php](../backend/app/Http/Controllers/Api/WorkspaceController.php) — new.
- [WorkspaceContext.php](../backend/app/Http/Middleware/WorkspaceContext.php) — new.
- [WorkspaceDataVisibility.php](../backend/app/Services/WorkspaceDataVisibility.php) — new.
- [User.php](../backend/app/Models/User.php)
- [RolePermissions.php](../backend/app/Services/RolePermissions.php)
- [ReportService.php](../backend/app/Services/ReportService.php)
- [WorkspaceNotifier.php](../backend/app/Services/WorkspaceNotifier.php)
- [SendTelegramInventoryNotification.php](../backend/app/Jobs/SendTelegramInventoryNotification.php)
- [app.php](../backend/bootstrap/app.php)
- [api.php](../backend/routes/api.php)
- [Membership migration](../backend/database/migrations/2026_11_17_000001_add_workspace_permission_overrides.php) — new.

### Frontend

- [App.jsx](../frontend/src/App.jsx)
- [permissions.js](../frontend/src/lib/permissions.js) — new centralized navigation and permission helpers.
- [PermissionEditor.jsx](../frontend/src/components/PermissionEditor.jsx) — new.
- [AppHeader.jsx](../frontend/src/components/AppHeader.jsx)
- [HomePage.jsx](../frontend/src/components/HomePage.jsx)
- [MorePage.jsx](../frontend/src/components/MorePage.jsx)
- [QuickActionsPanel.jsx](../frontend/src/components/QuickActionsPanel.jsx)
- [InventoryLivePage.jsx](../frontend/src/components/InventoryLivePage.jsx)
- [ExpirationInventory.jsx](../frontend/src/components/ExpirationInventory.jsx)
- [ProductDialog.jsx](../frontend/src/components/ProductDialog.jsx)
- [RecordsHub.jsx](../frontend/src/components/RecordsHub.jsx)
- [ReportsLivePage.jsx](../frontend/src/components/ReportsLivePage.jsx)
- [ShiftPanel.jsx](../frontend/src/components/ShiftPanel.jsx)
- [UserManagementPanel.jsx](../frontend/src/components/UserManagementPanel.jsx)
- [TeamMemberDialog.jsx](../frontend/src/components/TeamMemberDialog.jsx)
- [InvitationDialog.jsx](../frontend/src/components/InvitationDialog.jsx)
- [TelegramAccessPanel.jsx](../frontend/src/components/TelegramAccessPanel.jsx)
- [TelegramNotificationSettings.jsx](../frontend/src/components/TelegramNotificationSettings.jsx)
- [dashboard.css](../frontend/src/components/dashboard.css)
- [en.json](../frontend/src/locales/en.json) and [km.json](../frontend/src/locales/km.json)

### Tests and documentation

- [UnifiedWorkspaceAccessTest.php](../backend/tests/Feature/UnifiedWorkspaceAccessTest.php) — new.
- [RoleBasedAccessTest.php](../backend/tests/Feature/RoleBasedAccessTest.php)
- [BizTrackMvpTest.php](../backend/tests/Feature/BizTrackMvpTest.php)
- [NotificationIntegrationTest.php](../backend/tests/Feature/NotificationIntegrationTest.php)
- [TelegramInventoryNotificationTest.php](../backend/tests/Feature/TelegramInventoryNotificationTest.php)
- [permissions.test.js](../frontend/tests/permissions.test.js) — new.
- This report. Earlier Telegram changes remain in place and are documented in the
  [Telegram verification report](./TELEGRAM_INTEGRATION_VERIFICATION.md).
