# Create an API key

Every consumer app gets **one named API key**. The name is how your app
appears in Usage and Reports, so pick something recognizable.

## Steps

1. Open the admin UI: `http://localhost/projects/jelite_sms_api/admin`
2. Log in with the admin credentials.
3. Go to **API Keys** → fill in:
   - **Name** — your app's name, e.g. `HRMIS Notifications`
   - **Rate limit** — messages per minute (default 30)
4. Click **Create key**.

## Copy the key now — you will not see it again

The plaintext key is shown **exactly once**, right after creation:

```
jek_1a2b3c4d5e6f7g8h...
```

Store it in your app's `.env` (never in code, never in git):

```ini
SMS_API_URL=http://localhost/projects/jelite_sms_api
SMS_API_KEY=jek_1a2b3c4d5e6f7g8h...
```

Only a SHA-256 hash is stored server-side — if you lose the plaintext,
revoke the key and create a new one.

## Verify it works

```bash
curl -H "Authorization: Bearer YOUR_KEY" http://localhost/projects/jelite_sms_api/api/v1/health
```

A `200` response means the key authenticated. (`401` means the key is wrong
or revoked — see [Troubleshooting](?page=troubleshooting).)

Next: **Your first SMS**.
