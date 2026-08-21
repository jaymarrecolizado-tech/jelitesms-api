# prod/ — deploy staging (Phase 6)

`jelite_sms_api/` is **generated** — do not edit it or commit it. Rebuild from
the repo root before every upload:

```
C:\xampp\php\php.exe bin\export-prod.php
```

## Upload

1. Upload the **contents** of `prod/jelite_sms_api/` into the Hostinger docroot:
   `/home/dictr2-jelitesmsapi/domains/jelitesmsapi.dictr2.cloud/public_html`
2. On the server, create `.env` from `.env.example` (`chmod 600 .env`). See
   `docs/ops/DEPLOY.md` for the exact values.
3. Run permissions: `bash set-permissions.sh /path/to/public_html`
4. Apply schema: `php bin/setup.php`
5. Add cron for `bin/worker.php` + `bin/sync-delivery.php` (see DEPLOY.md).

## set-permissions.sh

Run on the VPS (Windows cannot set Unix modes). Usage:

```bash
bash set-permissions.sh ~/domains/jelitesmsapi.dictr2.cloud/public_html
```

Sets directories 755, files 644, `bin/*.php` 755, `storage/` 775, `.env` 600.
