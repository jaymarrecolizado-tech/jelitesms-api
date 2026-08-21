# Examples

Sample integrations for JE Lite SMS API. Full tutorials: **Admin → Docs**.

| Folder | Stack | Type |
|--------|-------|------|
| [`plain-php/`](plain-php/) | Plain PHP 7.4+ | Runnable (`php send.php ...`) |
| [`laravel/`](laravel/) | Laravel 10/11 | Drop-in (service + job + config) |
| [`codeigniter/`](codeigniter/) | CodeIgniter 4 | Drop-in library |
| [`react-node-bff/`](react-node-bff/) | Node/Express BFF | Runnable (`npm start`) |

Rules that hold for every example:

- The API key lives in `.env` on the server — never in browser code.
- Use `client_ref` for idempotency on business-critical messages.
- Local base URL is `http://localhost/projects/jelite_sms_api`; swap the
  production URL when going live — nothing else changes.
