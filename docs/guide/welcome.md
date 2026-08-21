# Welcome & prerequisites

This guide walks you from zero to sending and tracking SMS from your app —
pick the chapter for your stack (plain PHP, Laravel, CodeIgniter, or React
with a backend-for-frontend) and follow it start to finish.

## What this API is

JE Lite SMS API is a small HTTP service that queues SMS and delivers them
through an Android phone running the
[SMS Gateway for Android](https://sms-gate.app) app. Your app talks to this
API with a **Bearer API key**; you never talk to the gateway yourself.

```
Your app ──Bearer key──▶ JE Lite SMS API ──queue──▶ worker ──▶ Android gateway ──▶ recipient
```

## What you need before starting

| Requirement | Where to get it |
|-------------|-----------------|
| An API key | Admin → **API Keys** (next chapter) |
| The API base URL | Ask the admin. Locally: `http://localhost/projects/jelite_sms_api` |
| A server-side language/runtime | PHP, Laravel, CodeIgniter, or Node — anything that can make HTTP POSTs |
| 5 minutes | That's genuinely it |

> The examples use the local base URL `http://localhost/projects/jelite_sms_api`.
> When this goes live on a server, swap in the production URL — nothing else changes.

## The one rule

**Never put your API key in browser code.** No React components, no
`NEXT_PUBLIC_*`/Vite client env vars, no mobile bundles. Keys live only on
servers. If your frontend needs to send SMS, your backend does it (chapters
7–8).

## How the guide works

Each chapter ends with a working result. Chapters build in order, but if you
already have a key and just want your stack's recipe, jump straight to
chapter 4–8. Ready? Go to **Create an API key**.
