<p align="center"><a href="https://laravel.com" target="_blank"><img src="https://raw.githubusercontent.com/laravel/art/master/logo-lockup/5%20SVG/2%20CMYK/1%20Full%20Color/laravel-logolockup-cmyk-red.svg" width="400" alt="Laravel Logo"></a></p>

<p align="center">
<a href="https://github.com/laravel/framework/actions"><img src="https://github.com/laravel/framework/workflows/tests/badge.svg" alt="Build Status"></a>
<a href="https://packagist.org/packages/laravel/framework"><img src="https://img.shields.io/packagist/dt/laravel/framework" alt="Total Downloads"></a>
<a href="https://packagist.org/packages/laravel/framework"><img src="https://img.shields.io/packagist/v/laravel/framework" alt="Latest Stable Version"></a>
<a href="https://packagist.org/packages/laravel/framework"><img src="https://img.shields.io/packagist/l/laravel/framework" alt="License"></a>
</p>

## About Laravel

Laravel is a web application framework with expressive, elegant syntax. We believe development must be an enjoyable and creative experience to be truly fulfilling. Laravel takes the pain out of development by easing common tasks used in many web projects, such as:

- [Simple, fast routing engine](https://laravel.com/docs/routing).
- [Powerful dependency injection container](https://laravel.com/docs/container).
- Multiple back-ends for [session](https://laravel.com/docs/session) and [cache](https://laravel.com/docs/cache) storage.
- Expressive, intuitive [database ORM](https://laravel.com/docs/eloquent).
- Database agnostic [schema migrations](https://laravel.com/docs/migrations).
- [Robust background job processing](https://laravel.com/docs/queues).
- [Real-time event broadcasting](https://laravel.com/docs/broadcasting).

Laravel is accessible, powerful, and provides tools required for large, robust applications.

## Learning Laravel

Laravel has the most extensive and thorough [documentation](https://laravel.com/docs) and video tutorial library of all modern web application frameworks, making it a breeze to get started with the framework. You can also check out [Laravel Learn](https://laravel.com/learn), where you will be guided through building a modern Laravel application.

If you don't feel like reading, [Laracasts](https://laracasts.com) can help. Laracasts contains thousands of video tutorials on a range of topics including Laravel, modern PHP, unit testing, and JavaScript. Boost your skills by digging into our comprehensive video library.

## Laravel Sponsors

We would like to extend our thanks to the following sponsors for funding Laravel development. If you are interested in becoming a sponsor, please visit the [Laravel Partners program](https://partners.laravel.com).

### Premium Partners

- **[Vehikl](https://vehikl.com)**
- **[Tighten Co.](https://tighten.co)**
- **[Kirschbaum Development Group](https://kirschbaumdevelopment.com)**
- **[64 Robots](https://64robots.com)**
- **[Curotec](https://www.curotec.com/services/technologies/laravel)**
- **[DevSquad](https://devsquad.com/hire-laravel-developers)**
- **[Redberry](https://redberry.international/laravel-development)**
- **[Active Logic](https://activelogic.com)**

## Contributing

Thank you for considering contributing to the Laravel framework! The contribution guide can be found in the [Laravel documentation](https://laravel.com/docs/contributions).

## Code of Conduct

In order to ensure that the Laravel community is welcoming to all, please review and abide by the [Code of Conduct](https://laravel.com/docs/contributions#code-of-conduct).

## Security Vulnerabilities

If you discover a security vulnerability within Laravel, please send an e-mail to Taylor Otwell via [taylor@laravel.com](mailto:taylor@laravel.com). All security vulnerabilities will be promptly addressed.

## License

The Laravel framework is open-sourced software licensed under the [MIT license](https://opensource.org/licenses/MIT).

## Test the Telegram bot locally

Telegram delivers bot messages to a public HTTPS webhook; signing in to Telegram alone does not connect the bot. For local testing:

1. Create a bot with Telegram's `@BotFather` and keep its token private.
2. Set `TELEGRAM_BOT_TOKEN` and a long random `TELEGRAM_WEBHOOK_SECRET` in `backend/.env`. Never commit either value.
3. Run `php artisan migrate` from `backend`, then start the API with `php artisan serve --host=0.0.0.0 --port=8000`.
4. Expose port 8000 using an HTTPS tunnel, for example ngrok or Cloudflare Tunnel. Use the tunnel's HTTPS URL as the webhook URL.
5. Register or log in to KotTraLuy in the frontend, choose **Connect Telegram**, open `@KotTraLuyBot` in a private chat, and send the displayed `/link <token>` command before the token expires.
6. Securely set `$env:TELEGRAM_BOT_TOKEN` in the PowerShell session you use for registration. Register the webhook once (replace the URL and secret with your own values):

   ```powershell
   $webhookUrl = "https://YOUR-HTTPS-TUNNEL/api/telegram/webhook"
   $webhookSecret = "YOUR_TELEGRAM_WEBHOOK_SECRET"
   Invoke-RestMethod -Method Post `
     -Uri "https://api.telegram.org/bot$env:TELEGRAM_BOT_TOKEN/setWebhook" `
     -Body @{ url = $webhookUrl; secret_token = $webhookSecret }
   ```

7. In the bot's private chat, send `/help`, `stock`, `today`, `start shift`, or a test operation such as `sale Milk 1 3.50`. Create and stock a product in BizTrack first. Check the dashboard and inventory afterward to confirm the bot wrote to the same business records. Group chats are ignored.

Link tokens are single-use and expire after 5 minutes. Webhook updates are deduplicated by Telegram update ID. To inspect webhook delivery, use Telegram's `getWebhookInfo` method and the Laravel application log. Automated tests cover linking, webhook-secret enforcement, and duplicate sale delivery without contacting Telegram.
