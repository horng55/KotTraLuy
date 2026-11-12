# Product expiration

Expiration extends the existing `stock_batches`, `inventory_alerts`, sale allocations,
workspace notifications and waste records. A business is the workspace boundary.
No expiry dates are assigned to legacy inventory. Before its first batch receipt or
sale, undated legacy stock receives a traceable `LEGACY-<product id>` batch.

## Database

Migration `2026_11_12_000001_strengthen_batch_expiration` changes the default warning
window to 30 days for new businesses, preserves existing configured windows, adds
the workspace/expiry/quantity index, links disposals to batches, and adds an audit
table for batch metadata edits. Historical sales are unchanged. Product deletion
through the API now deactivates the product to preserve inventory history.

## Operations

Run `php artisan migrate --force` from `backend`. The migration was applied during
implementation. Configure the host to run `php artisan schedule:run` every minute,
or run `php artisan schedule:work` as a supervised process. Expiration checks run
hourly and use each business's timezone. Inventory expiration and notification
access also reconcile alerts on the server, including the notification bell.

`php artisan inventory:refresh-alerts` can trigger an immediate check.

## API

- `GET /api/inventory-expiration?page=1`: distinct product counts, filter IDs and
  ten expiration alert batches per page, sorted by date.
- `PUT /api/inventory-expiration/settings`: owner-only `days`, 1–3650.
- `PATCH /api/stock-batches/{batch}`: owner-only optional `expiry_date` and required
  `batch_code`; saves before/after metadata and acting user.
- `POST /api/stock-batches/{batch}/dispose`: owner-only positive whole `quantity`
  and required `reason`; atomically records waste, movement and batch deduction.
- Product creation accepts optional `expiry_date` and `batch_code` for opening
  stock. Stock-in adds additional batches through the existing purchase endpoint.

Sale transactions lock the business, product and eligible batch inventory, allocate
FEFO, revalidate explicit selections and reject expired or insufficient stock with
HTTP 422. The business date at confirmation determines eligibility. Stock expiring
today remains eligible. Undated inventory sorts after dated eligible batches.
Telegram transactions use the same controllers. General waste also deducts batches.

## Notifications

Persistent `inventory.expiring_soon`, `inventory.expires_today`, and
`inventory.expired` events notify active workspace owners through the existing
inbox, bell, read controls and translations. Stable keys contain batch, date,
condition and configured threshold; refreshing does not create duplicates.
Quantities and names are refreshed, and superseded conditions are marked resolved
and read while retaining their history. Notifications open the relevant product.

## Verification and operational limits

Backend tests cover timezone boundaries, today, 7/30-day windows, multiple batches,
undated stock, empty batches, notification deduplication and resolution, FEFO,
explicit expired allocations, disposal, permissions, workspace isolation, audited
batch editing and legacy receipts. Frontend tests cover translation and notification
formatting. An isolated empty workspace is used for actual browser checks; no mock
products or expiration notifications are inserted into the application database.

The host scheduler must be enabled for unattended checks. Existing configured
warning windows remain intact. This application has owner/staff roles, so owners
receive inventory alerts and manage disposal; no new manager role is introduced.
Parallel MySQL load testing is separate from the SQLite automated tests. Existing
historical stock/batch discrepancies are not automatically rewritten; financial
report reconciliation continues to flag them. The existing frontend bundle size
warning remains.
