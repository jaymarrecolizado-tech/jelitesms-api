# Next steps

You have a working integration. Here's how to keep it healthy.

## Production checklist (for your app)

- [ ] Key stored in server-side env only — never in git, never in the browser
- [ ] `client_ref` used for every business-critical message
- [ ] 401/422/429 handled explicitly (see your stack chapter)
- [ ] Status polled with backoff, or delivery checked in Admin → Reports
- [ ] Your app's key name matches the app so Usage/Reports stay meaningful

## Operational notes

- **Rate limits** are per key, per minute. If your app sends bursts, ask the
  admin to raise the limit in Admin → API Keys.
- **Message length** defaults to 320 chars; longer messages need admin
  approval (`SMS_MAX_MESSAGE_LENGTH`).
- **Delivery states**: `sent` = gateway accepted; `delivered` = handset
  confirmed. Treat `delivered` as the source of truth for "did it arrive".

## Where to look things up

| Need | Go to |
|------|-------|
| Full API contract + copy-paste helpers | `docs/CONSUMERS.md` (repo) |
| Live status of a specific message | Admin → Messages |
| Delivery outcomes per app | Admin → Reports (+ CSV export) |
| Reproduce an API response without code | Admin → Test |
| Sample projects | `examples/` in the repo |

## That's the guide

You know how to create keys, send, track, and debug. If something behaves
unexpectedly, start at [Troubleshooting](?page=troubleshooting).
