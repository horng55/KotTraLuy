# Financial Reports repair and verification

Verified 11 November 2026.

## Root cause and request path

The shared browser still displayed a cached application at port 5175, but that preview and its isolated API service had stopped. Clicking Generate Report reproduced `net::ERR_CONNECTION_REFUSED` for `/api/reports/financial`. The browser's `Failed to fetch` was a transport failure, not a missing route, CORS rejection, invalid report parameter, or authentication rejection.

The frontend uses same-origin `/api` requests with the existing Sanctum bearer token. Vite proxies these requests to Laravel. The normal API health check succeeded at port 8000 and an unauthenticated report request correctly returned 401. The proxy now defaults to `127.0.0.1:8000`, matching the project's local server binding, and supports server-side `API_PROXY_TARGET`. Unavailable upstream services return a structured 503 where possible.

The authenticated endpoint remains:

`GET /api/reports/financial?from=2026-01-01&to=2026-11-11&currency=USD`

Workspace context comes exclusively from the authenticated active user's business. Date and currency validation also runs on the server.

## Accounting corrections

The old report checked currency coverage but still aggregated mixed currencies. Sales, expenses, payment receipts, receivables, and stock costs now respect the selected currency. No conversion is performed.

FIFO cost calculations are retained. Costs in another currency or missing cost data make COGS and profit unavailable. Historical receivables use payments received through the requested cutoff rather than today's outstanding balance. Inventory valuation requires matching product/batch quantities and is available only for the current date, because remaining batch quantities are not a historical stock ledger.

Complete balance sheets and cash flow statements remain unsupported by the existing records: opening cash, bank transfers, loans, owner movements, equipment accounts, and supplier/expense payment status are absent. These values are null and visibly incomplete. Customer receipts are shown without presenting them as net cash flow. Sales returns have no separate ledger and are also unavailable.

## UI and reliability

The configuration form validates actual ISO calendar dates, date order, and USD/KHR. It preserves selections on failure, displays field errors beside inputs, disables controls while generating, and supplies an error alert with Try Again. Requests time out after 25 seconds and abort when the report component unmounts. Returned period/currency are checked before rendering.

401 responses use the existing sign-in screen; password-change-required responses use the existing password panel. Stale unauthorized responses cannot clear a newer token. Debug logs exclude credentials and response bodies. Report UI, financial labels, limitations, and errors support English/Khmer; existing typography and Light/Dark/System themes are retained.

## Actual verification

Browser checks used the real Laravel API and a separate SQLite verification database, not the normal business database. A KHR sale and expense were recorded through the real business endpoints in that isolated database. Production code contains no fabricated report responses.

| Check | Result |
| --- | --- |
| Original failure | Browser request to stopped port 5175 reproduced connection refused |
| USD, 2026-01-01 through 2026-11-11 | HTTP 200; revenue 56.50, FIFO COGS 13.00, profit 43.50, receipts 14.00 |
| KHR, same period | HTTP 200; revenue 12,000, expenses 2,000, receipts 12,000; profit unavailable because recorded stock cost uses USD |
| Currency separation | USD result unchanged after recording KHR sale/expense |
| Empty January 2020 | HTTP 200; zero activity and visible empty-period notice |
| Reversed dates | Inline To Date error, zero report requests |
| Server/network failures | Controlled browser 500 and connection-refused checks displayed safe alert; selections retained; retry returned real HTTP 200 |
| Sensitive server message | Sentinel server trace not displayed |
| Loading | Disabled inputs/button and visible Generating report status observed |
| Expired authentication | Controlled 401 cleared token and returned existing sign-in screen, including Khmer recovery text |
| Languages | English and Khmer report forms/statements/limitations inspected |
| Themes | Light white panel, Dark forest-green panel, System resolved correctly; shared mobile viewport had no horizontal overflow |
| Backend tests | Full suite: 49 passed, 268 assertions; focused financial suite: 7 passed, 58 assertions |
| Frontend tests | 8 passed, including API failure/session race and financial parameter/response validation |
| Static/build checks | ESLint and Vite production build passed; edited report files had no editor diagnostics |

Failure injection was confined to browser verification; successful reports came from the real backend. Desktop visual inspection was limited by the shared browser viewport; responsive desktop rules are implemented but no separate desktop screenshot is claimed.

## Running the services

The verification frontend is at `http://127.0.0.1:5176/`, proxying to API port 8016. VS Code tasks **Reports: verification API** and **Reports: verification web** keep these services in task terminals. The API task uses an isolated, ignored database under `backend/storage/framework/testing`; it migrates a fresh file when needed. A fresh verification database contains no users; use normal workspace registration to populate it.

For ordinary development, run the existing backend on `127.0.0.1:8000` and the frontend dev command from its directory. Configure a different upstream using the example environment variable; do not point the frontend at a stopped preview. Services must remain running while using the browser.

## Files changed for this task

- [ReportController.php](backend/app/Http/Controllers/Api/ReportController.php): active workspace authorization and strict financial parameter validation.
- [ReportService.php](backend/app/Services/ReportService.php): delegate financial reports to the dedicated service; other report methods retained.
- [FinancialReportService.php](backend/app/Services/FinancialReportService.php): currency-safe reporting and explicit accounting data limitations.
- [FinancialReportsTest.php](backend/tests/Feature/FinancialReportsTest.php): reporting, cutoff, currency, authorization, and empty-data coverage.
- [ReportsLivePage.jsx](frontend/src/components/ReportsLivePage.jsx): validated configuration, generation states, retry, and real report results.
- [FinancialStatement.jsx](frontend/src/components/FinancialStatement.jsx): localized tables and unavailable values.
- [financial-reports.css](frontend/src/components/financial-reports.css): responsive report form, alert, loading, table, and theme styling.
- [financialReports.js](frontend/src/lib/financialReports.js): bilingual labels/limitations and report validation.
- [api.js](frontend/src/lib/api.js): safe typed transport/API errors and authentication recovery events.
- [App.jsx](frontend/src/App.jsx): existing authentication recovery and workspace-keyed report component.
- [i18n.js](frontend/src/lib/i18n.js): preserve explicitly localized React content and translate session recovery text.
- [main.jsx](frontend/src/main.jsx): report stylesheet import.
- [vite.config.js](frontend/vite.config.js): consistent upstream binding, configurable proxy, structured unavailable response.
- [.env.example](frontend/.env.example): document API proxy override.
- [financial-reports.test.js](frontend/tests/financial-reports.test.js): financial parameter and response validation.
- [api.test.js](frontend/tests/api.test.js): error sanitization and session race regression coverage.
- [tasks.json](.vscode/tasks.json): persistent isolated verification services.
- [FINANCIAL_REPORTS.md](FINANCIAL_REPORTS.md): this implementation and verification report.

The existing [typography.css](frontend/src/typography.css) was inspected and retained for this task. Other preexisting workspace changes belong to earlier work.
