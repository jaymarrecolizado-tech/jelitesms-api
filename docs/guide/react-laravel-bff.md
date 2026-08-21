# React + Laravel BFF

React (or any SPA) **never** calls the SMS API directly and never holds the
key. Your Laravel backend acts as a Backend-For-Frontend: the browser talks
to Laravel, Laravel talks to the SMS API.

```
React UI ──session/cookie──▶ Laravel BFF ──Bearer key──▶ JE Lite SMS API
```

## 1. Laravel route — `routes/api.php`

```php
use App\Services\SmsService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::middleware('auth:sanctum')->post('/notifications/sms', function (Request $request, SmsService $sms) {
    $data = $request->validate([
        'to'         => 'required|string',
        'message'    => 'required|string|max:320',
        'client_ref' => 'nullable|string|max:100',
    ]);

    try {
        $result = $sms->send($data['to'], $data['message'], $data['client_ref'] ?? null);
        return response()->json(['ok' => true, 'id' => $result['id']]);
    } catch (\Throwable $e) {
        // The UI sees a business error, never the key or gateway details.
        return response()->json(['ok' => false, 'error' => 'sms_unavailable'], 502);
    }
});
```

## 2. React component

```jsx
async function sendSms({ to, message, clientRef }) {
  const res = await fetch('/api/notifications/sms', {
    method: 'POST',
    headers: {
      'Content-Type': 'application/json',
      Accept: 'application/json',
      // auth via cookie/token middleware — NOT an SMS API key
    },
    body: JSON.stringify({ to, message, clientRef }),
  });

  const data = await res.json();
  if (!data.ok) throw new Error('Could not send SMS');
  return data.id; // show "notification sent" in the UI
}
```

## What the UI may and may not see

| OK to expose to the browser | Never expose |
|-----------------------------|--------------|
| `{ ok: true, id: 42 }` | The SMS API key |
| Business errors (`sms_unavailable`) | Gateway URL / password |
| Status derived from your backend | Raw gateway responses |

## Verifying delivery in the UI

Don't poll the SMS API from React. Add a BFF endpoint that wraps
`$sms->status($id)` and return only what the UI needs:

```php
Route::get('/notifications/sms/{id}', function (int $id, SmsService $sms) {
    $status = $sms->status($id);
    return response()->json(['state' => $status['status'] ?? 'unknown']);
});
```

Next: **React + Node BFF**.
