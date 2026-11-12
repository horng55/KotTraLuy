# Stock and sale interface repair

## Root causes

- Tailwind's reset removed control borders. Existing field rules only supplied `border-color`, and the shared secondary button had the same problem. Both now specify actual border width/style.
- Dashboard and Sales mounted quick-action forms inside hidden layout containers. The old dialogs inherited those containers' styling and duplicated modal rules. Shared dialogs now render through a React portal, with separate header, scrollable body and action footer.
- The old product form used a `form-grid` class without a corresponding layout definition. New form groups explicitly define their spacing and column behavior.
- A global `body * { font-family: 'Koh Santepheap' !important }` rule overrode the existing Noto Sans Khmer setup. Typography now lives in the shared typography stylesheet; new controls use readable Khmer line heights and flexible heights.
- The Stock page had duplicate headings, a sparse list instead of a table, missing edit integration, and a waste button without an action. The app footer was fixed over content. These are now connected or repaired.

## Application behavior

Dashboard New Sale, Dashboard Voice Input, Sales New Sale and the legacy record entry all use the shared sale form. Product selection is searchable by name/SKU and displays actual recorded availability. Selecting a product uses its saved price while preserving manual price edits, including a zero price. Quantity, received payment, outstanding balance and total use numeric values. Notes remain available in an expandable section.

The form preserves the existing received-payment field. No payment methods were invented. Submission uses the existing authenticated APIs, a synchronous submission guard and one sale idempotency key for the dialog's lifetime. API field errors remain next to inputs; failed submissions retain values. Success closes only after the backend confirms the save and displays feedback outside the hidden action source.

Voice input retains the browser SpeechRecognition/webkitSpeechRecognition integration and Khmer recognition language. Listening, Stop, processing, review and recoverable error states are supported. Recognized product/SKU and explicit quantity/price values can populate the form for review. Ambiguous product matches are not selected. Failures do not erase manual entries, and speech never submits a sale automatically.

Stock uses actual workspace products for summaries, SKU/name search, status filtering, sortable columns and ten-row pagination. Owners can add/edit products. Details show actual batch information and connect to supported stock-in and waste APIs, with the product preselected. Staff do not see owner-only product controls. Staff workspace loading avoids the protected owner dashboard API while retaining authenticated workspace/currency data.

Total stock valuation is omitted because default unit cost is not reliable aggregate inventory valuation. Category filtering is omitted because the current product API does not supply a category catalog or names. Existing backend permissions, workspace scoping and financial calculations remain authoritative.

Shared dialogs trap focus, restore focus, make the background inert, lock document scrolling and handle Escape. Search dropdowns handle keyboard selection and Escape independently. Inputs have associated labels, focus indicators, disabled states and inline validation. Mobile tables scroll within their card rather than widening the page. Both themes use the existing semantic tokens and brand green.

## Verification

- Frontend production build and ESLint pass.
- Four Node tests pass for Khmer digits, zero-price recognition, ambiguous product matches, real saved-price selection and invalid spoken quantities.
- Full Laravel suite passes: 44 tests, 218 assertions. A pre-existing notification test added earlier in this session was made deterministic by locating the intended notification rather than assuming UUID sort order.
- Browser checks used a separate SQLite database and actual application APIs. Sale saved from Dashboard: $12.50 total, $5 received, $7.50 outstanding. Sale saved from Sales: $9 total and $9 received. A controlled HTTP 422 verified retained values and field errors before a successful retry.
- Created and edited a product through the interface; verified SKU search, low-stock filtering and pagination across eleven actual test database products (`1–10 / 11`, then `11–11 / 11`). A second empty workspace displayed the real empty state and Add First Product action. Staff lacked owner product controls and could open the sale form.
- Controlled browser speech callbacks verified listening, processing, success-for-review and failure recovery with manual quantity preserved. This does not certify microphone permission, audio recognition accuracy or the external speech service.
- Inspected rendered English/light desktop sale, Khmer/dark mobile sale and mobile stock screenshots. An earlier narrow viewport check below 320 CSS pixels showed no dialog horizontal overflow. Later screenshot captures use the integrated browser's actual viewport because it overrides requested emulated dimensions. Physical mobile keyboard behavior was not tested. Original comparison screenshots were not included in the attachment.

Screenshots: [desktop sale](docs/ui-verification/sale-desktop.png), [Khmer mobile sale](docs/ui-verification/sale-mobile-khmer.png), [Khmer mobile stock](docs/ui-verification/stock-mobile-khmer.png).

The shared preview at `http://127.0.0.1:5175/` is left running against the disposable verification database through the isolated backend at port 8015. It contains the API-created verification records described above, not the normal business database. Its temporary Vite configuration lives in the ignored dependency cache. The normal development configuration is unchanged.

## Files changed for this request

- [FormUI.jsx](frontend/src/components/FormUI.jsx): shared dialog, fields, inputs, product picker, buttons, errors and empty state.
- [QuickActionsPanel.jsx](frontend/src/components/QuickActionsPanel.jsx): actual sale, voice, purchase, expense and waste flows.
- [ProductDialog.jsx](frontend/src/components/ProductDialog.jsx): shared create/edit product form.
- [InventoryLivePage.jsx](frontend/src/components/InventoryLivePage.jsx): connected Stock interface.
- [HomePage.jsx](frontend/src/components/HomePage.jsx), [RecordsHub.jsx](frontend/src/components/RecordsHub.jsx), [App.jsx](frontend/src/App.jsx): entry points, language/currency propagation, legacy dialog integration and workspace loading.
- [workspace-forms.css](frontend/src/components/workspace-forms.css), [App.css](frontend/src/App.css), [brand.css](frontend/src/brand.css), [typography.css](frontend/src/typography.css), [theme.css](frontend/src/theme.css), [main.jsx](frontend/src/main.jsx): layout, borders, typography, tokens and style loading.
- [workspaceUI.js](frontend/src/lib/workspaceUI.js): structured English/Khmer labels and currency formatting.
- [useSaleVoice.js](frontend/src/lib/useSaleVoice.js): recognition lifecycle and conservative parsing.
- [api.js](frontend/src/lib/api.js): retain structured validation errors and HTTP status.
- [sale-voice.test.js](frontend/tests/sale-voice.test.js), [package.json](frontend/package.json): focused tests with Node's built-in runner; no dependency added.
- [NotificationIntegrationTest.php](backend/tests/Feature/NotificationIntegrationTest.php): deterministic assertion correction.
- This report and the three linked verification screenshots.
