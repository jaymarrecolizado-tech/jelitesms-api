# React + Node BFF example

Minimal Express backend-for-frontend. The SMS API key stays on the server;
the React app calls only this BFF.

## Run

```bash
npm install
cp .env.example .env   # then edit SMS_API_KEY
npm start
```

## Try it

```bash
curl -X POST http://localhost:3000/api/notifications/sms \
  -H "Content-Type: application/json" \
  -d "{\"to\":\"09171234567\",\"message\":\"Hello via Node BFF\"}"
```

## From React

```js
const res = await fetch('/api/notifications/sms', {
  method: 'POST',
  headers: { 'Content-Type': 'application/json' },
  body: JSON.stringify({ to, message, clientRef }),
});
const data = await res.json();
// { ok: true, id: 42 } — or { ok: false, error: 'sms_unavailable' }
```

Full tutorial: **Admin → Docs → React + Node BFF**.
