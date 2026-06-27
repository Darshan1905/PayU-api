# PayU UPI Middleware API

Laravel middleware between external clients and PayU UPI (via WordPress proxy on pavokart.com).

## Flow (proxy mode)

```
Client → POST /api/payu/initiate (X-API-Key + IP whitelist)
      → WordPress /wp-json/payu/v1/initiate-payment
      → PayU api.payu.in/v2/payments
      → intentUrl returned to client
Customer pays via UPI
PayU → middleware /webhook/payu → client callbackUrl
```

## Local setup

```bash
cd payu-api
composer install
cp .env.example .env
php artisan key:generate
touch database/database.sqlite
php artisan migrate
php artisan db:seed
php artisan serve
```

## Environment

| Variable | Description |
|----------|-------------|
| `API_ALLOWED_IPS` | Comma-separated IPs allowed to call `/api/payu/*` |
| `PAYU_PROXY_URL` | WordPress site (`https://pavokart.com`) |
| `PAYU_PROXY_SECRET` | Must match WP **Middleware proxy secret** |
| `PAYU_CALLBACK_URL` | Public URL for PayU callbacks (`/webhook/payu`) |

## Demo API key (after seed)

`payu-pavokart-demo-key`

## Endpoints

- `POST /api/payu/initiate`
- `POST /api/payu/status`
- `GET /api/payu/notifications`
- `POST /webhook/payu` (PayU callback — no API key)

See `middleware-documentation.html` and `postman_collection.json`.
