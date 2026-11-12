# Telegram Mini App setup

KotTraLuy uses the existing web application inside Telegram. It does not create a second frontend or a second data store.

## Production configuration

Set these backend values in `backend/.env`:

```dotenv
TELEGRAM_BOT_TOKEN=your_bot_token
TELEGRAM_WEBHOOK_SECRET=long_random_secret
TELEGRAM_BOT_USERNAME=KotTraLuyBot
TELEGRAM_MINI_APP_URL=https://your-public-https-host
TELEGRAM_MINI_APP_INIT_DATA_TTL=600
```

The Mini App sends Telegram's original `WebApp.initData` to `POST /api/telegram/mini-app/auth`. The backend validates the HMAC signature, checks the timestamp, and only issues a session token for an active KotTraLuy user already linked to that Telegram account. `initDataUnsafe` is never used for authentication.

Configure the bot's Main Mini App URL in BotFather to the same HTTPS URL. Register the webhook against `/api/telegram/webhook` using the configured secret. Telegram requires HTTPS for production Mini Apps; localhost development remains available through the normal web login.

## Runtime behaviour

When Telegram provides the WebApp API, KotTraLuy calls `ready()` and `expand()`, applies Telegram's light/dark color scheme when the app theme is set to **System**, respects safe-area insets, uses the native BackButton for nested pages, and provides light navigation haptics where supported. The existing five-item mobile navigation is retained and gains bottom safe-area padding inside Telegram.

If a Telegram account is not linked to an authorized KotTraLuy workspace user, authentication is rejected and the normal sign-in flow remains available. Workspace permissions and backend authorization continue to apply to every request.

## Verification

The repository build and test commands cover the shared web/Mini App bundle:

```bash
cd frontend
npm test
npm run build
```

Device-specific Telegram WebView checks still require opening the deployed HTTPS URL from Telegram on Android or iOS. Browser development can exercise the same UI and the existing secure `/telegram/mini-app/auth` endpoint without bypassing backend verification.
