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

## Mobile authentication API

The mobile OTP flow uses Laravel Sanctum bearer tokens and Melipayamak SMS:

- `POST /api/v1/auth/otp/send` accepts `phone` and `type` (`login` or `register`). The mobile flow always requests a `login` OTP; the same neutral response is returned for existing and new phone numbers.
- `POST /api/v1/auth/otp/verify` accepts the same phone/type, a six-digit `code`, a UUID `device_id`, and optional registration details. Existing users receive a session. For a new number, a valid OTP without a name returns `{"status":"profile_required"}`; send the same OTP with a name and optional unique email to complete registration and receive a session.
- Authenticated account management is available through `PATCH /api/v1/auth/profile`, `POST /api/v1/auth/profile/phone` (new phone plus a fresh login OTP), and `DELETE /api/v1/auth/account` (fresh OTP required). The account deletion endpoint also revokes tokens and removes the user's associated records.
- Each authenticated device session stores the operating-system platform, device name, and device model separately, and only one token is retained per device. At most three active device sessions are allowed. Tokens expire after 30 days without an authenticated request; authenticated API activity slides the expiry forward. Expired sessions are pruned hourly by the scheduler and opportunistically during session listing and new logins. A session is labeled stale after seven days without activity but remains revocable before expiration.
- Inactivity-based account deletion is opt-in and requires a verified phone number and verified email address. Users may choose 6, 12, or 18 months without activity. The API rejects activation until a real mail transport and Melipayamak SMS are configured; `log`/`array` mail transports, including as a failover destination, are not considered delivery. Set `MELIPAYAMAK_INACTIVITY_WARNING_BODY_ID` to a template whose first argument is the user's name and second argument is the 30-day recovery period. The scheduled processor sends an email and SMS warning; after both have been accepted by their configured delivery providers, a 30-day recovery period begins. Logging in or disabling the setting cancels the pending deletion. Failed delivery does not start the grace period and is retried by the scheduler.
- Laravel's scheduler must run for expiry cleanup and inactivity notifications/deletion. On a server, configure the standard once-per-minute `php artisan schedule:run` cron entry. The inactivity processor runs daily at 02:00 UTC; expired device sessions are pruned hourly. For local development, use `php artisan schedule:work`.
- Email verification uses `POST /api/v1/auth/profile/email/verification-code` and `POST /api/v1/auth/profile/email/verify`. Once an optional email address is registered, it cannot be removed, only edited. A replacement address is stored as pending and the current address and its verification remain unchanged until the replacement is verified. Codes are hashed, expire after ten minutes, and have bounded sending and verification attempts. Configure an SMTP mailer in `.env` for delivery; the default `MAIL_MAILER=log` writes the email content to the local Laravel log and does not deliver email.
- `POST /api/v1/auth/profile/photo` accepts an authenticated multipart `photo` upload (JPEG, PNG, or WebP; up to 5 MB).
- Authenticated routes: `GET /api/v1/auth/me`, `POST /api/v1/auth/logout`, `POST /api/v1/auth/logout-all`, `GET /api/v1/auth/devices`, and `DELETE /api/v1/auth/devices/{deviceId}`.

Apply the migrations with `php artisan migrate` and run `php artisan storage:link` once to serve profile images. For real SMS delivery, set `MELIPAYAMAK_ENABLED=true`, configure `MELIPAYAMAK_API_URL` with the tokenized shared API URL and set `MELIPAYAMAK_OTP_BODY_ID`; treat the complete URL as a secret.

While SMS is disabled, local authentication can be tested without sending a message. Set `APP_ENV=local`, `MELIPAYAMAK_ENABLED=false`, and `MELIPAYAMAK_LOCAL_LOG_ENABLED=true` in the backend `.env`, then restart the backend. The generated OTP is written to `storage/logs/laravel.log` and is not included in the API response. Read the latest log entries with `Get-Content storage\logs\laravel.log -Tail 30`. Register a new phone first, then use the same account to test login. Local OTP logging is ignored outside the `local` environment; do not enable it in shared or production environments.

OTP values are hashed in the database. Device tokens use a sliding 30-day inactivity expiration stored in each token's `expires_at`.

For local device access, `SERVER_HOST=0.0.0.0` and `SERVER_PORT=8000` are configured in `.env`, so start the API with `php artisan serve`. Point the mobile app's `EXPO_PUBLIC_API_URL` to the computer's reachable LAN address. These settings expose the development server to devices on the local network; do not use `artisan serve` as a production server.

## Learning Laravel

Laravel has the most extensive and thorough [documentation](https://laravel.com/docs) and video tutorial library of all modern web application frameworks, making it a breeze to get started with the framework.

In addition, [Laracasts](https://laracasts.com) contains thousands of video tutorials on a range of topics including Laravel, modern PHP, unit testing, and JavaScript. Boost your skills by digging into our comprehensive video library.

You can also watch bite-sized lessons with real-world projects on [Laravel Learn](https://laravel.com/learn), where you will be guided through building a Laravel application from scratch while learning PHP fundamentals.

## Agentic Development

Laravel's predictable structure and conventions make it ideal for AI coding agents like Claude Code, Cursor, and GitHub Copilot. Install [Laravel Boost](https://laravel.com/docs/ai) to supercharge your AI workflow:

```bash
composer require laravel/boost --dev

php artisan boost:install
```

Boost provides your agent 15+ tools and skills that help agents build Laravel applications while following best practices.

## Contributing

Thank you for considering contributing to the Laravel framework! The contribution guide can be found in the [Laravel documentation](https://laravel.com/docs/contributions).

## Code of Conduct

In order to ensure that the Laravel community is welcoming to all, please review and abide by the [Code of Conduct](https://laravel.com/docs/contributions#code-of-conduct).

## Security Vulnerabilities

If you discover a security vulnerability within Laravel, please send an e-mail to Taylor Otwell via [taylor@laravel.com](mailto:taylor@laravel.com). All security vulnerabilities will be promptly addressed.

## License

The Laravel framework is open-sourced software licensed under the [MIT license](https://opensource.org/licenses/MIT).
