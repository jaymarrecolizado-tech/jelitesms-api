# React + Node BFF

Same pattern as the Laravel BFF, with Express. A runnable drop-in lives in
[`examples/react-node-bff/`](../../../examples/react-node-bff/).

## 1. Server — `server.js`

```js
require('dotenv').config();

const express = require('express');
const app = express();

app.use(express.json());

const SMS_API_URL = process.env.SMS_API_URL ?? 'http://localhost/projects/jelite_sms_api';
const SMS_API_KEY = process.env.SMS_API_KEY; // server-side only — never sent to the browser

async function smsSend(to, message, clientRef) {
  const res = await fetch(`${SMS_API_URL}/api/v1/sms/send`, {
    method: 'POST',
    headers: {
      Authorization: `Bearer ${SMS_API_KEY}`,
      'Content-Type': 'application/json',
    },
    body: JSON.stringify({ to, message, clientRef }),
  });

  if (res.status === 202 || res.status === 200) return res.json();
  throw new Error(`SMS API HTTP ${res.status}`);
}

app.post('/api/notifications/sms', async (req, res) => {
  const { to, message, clientRef } = req.body ?? {};
  if (!to || !message) {
    return res.status(400).json({ ok: false, error: 'to and message are required' });
  }

  try {
    const result = await smsSend(to, message, clientRef);
    res.json({ ok: true, id: result.id });
  } catch (e) {
    res.status(502).json({ ok: false, error: 'sms_unavailable' });
  }
});

app.listen(3000, () => console.log('BFF on http://localhost:3000'));
```

## 2. Environment — `.env`

```ini
SMS_API_URL=http://localhost/projects/jelite_sms_api
SMS_API_KEY=jek_your_key_here
```

## 3. Run it

```bash
cd examples/react-node-bff
npm install
npm start

curl -X POST http://localhost:3000/api/notifications/sms \
  -H "Content-Type: application/json" \
  -d "{\"to\":\"09171234567\",\"message\":\"Hello via Node BFF\"}"
```

The React app calls `/api/notifications/sms` on your own origin; the key
never leaves the server.

Next: **Troubleshooting**.
