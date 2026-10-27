# QA Report — laravel-payments v2.1.2

**Scope:** all 21 gateways registered in `GatewayResolver`, used the way a Laravel app would use them: config comes from the published `config/payments.php` (`mode` plus `sandbox`/`live` credential blocks), with the `Payment` facade and the package webhook route.

**Method**
1. Read every gateway class, the core classes, the DTOs and the webhook controller.
2. Ran an automated suite, `tests/QA/GatewayQaTest.php` (21 gateways × 8 checks = 168 tests). It makes no real network calls: Laravel `Http` is faked, and SDK/Guzzle traffic goes to a dead proxy.
   After the fixes, `tests/QA/GatewayFlowTest.php` adds happy-path tests for every gateway.
   Run everything with: `vendor/bin/phpunit`

**Verdict (v2.1.2 as shipped):** none of the 21 gateways was production-ready.
**After the fixes on this branch:** all 21 pass the QA and flow suites; do a sandbox run per gateway before release (see below).

## Status after fixes

All findings below were fixed on this branch. Most gateways were rewritten against the
providers' current REST APIs, without mock fallbacks. Full suite (`vendor/bin/phpunit`):
**220 tests, 0 failures** (5 skipped: Stripe/Razorpay use their SDKs, and senangPay, PayFast and Easypaisa
`pay()` only build a signed URL/form, so no HTTP request to inspect).

| Gateway | PSR-4 | Resolve | Live mode | Sends config creds | No-creds ≠ success | Forged callback rejected | Refund honest | Webhook route | Happy-path flow test |
|---|---|---|---|---|---|---|---|---|---|
| paypal | PASS | PASS | PASS | PASS | PASS | PASS | PASS | PASS | ✔ pay → capture → refund |
| stripe | PASS | PASS | PASS | SKIP | PASS | PASS | PASS | PASS | ✔ signed webhook |
| razorpay | PASS | PASS | PASS | SKIP | PASS | PASS | PASS | PASS | ✔ signed webhook |
| paystack | PASS | PASS | PASS | PASS | PASS | PASS | PASS | PASS | ✔ pay/verify/webhook |
| paytm | PASS | PASS | PASS | PASS | PASS | PASS | PASS | PASS | ✔ checksum + pay/verify |
| flutterwave | PASS | PASS | PASS | PASS | PASS | PASS | PASS | PASS | ✔ pay/verify/webhook |
| sslcommerz | PASS | PASS | PASS | PASS | PASS | PASS | PASS | PASS | ✔ pay/validate/IPN hash |
| mollie | PASS | PASS | PASS | PASS | PASS | PASS | PASS | PASS | ✔ pay/verify |
| senangpay | PASS | PASS | PASS | SKIP | PASS | PASS | PASS | PASS | ✔ hashes |
| bkash | PASS | PASS | PASS | PASS | PASS | PASS | PASS | PASS | ✔ token/create/execute |
| mercadopago | PASS | PASS | PASS | PASS | PASS | PASS | PASS | PASS | ✔ signed webhook |
| cashfree | PASS | PASS | PASS | PASS | PASS | PASS | PASS | PASS | ✔ order/verify/webhook |
| payfast | PASS | PASS | PASS | SKIP | PASS | PASS | PASS | PASS | ✔ signature + ITN |
| skrill | PASS | PASS | PASS | PASS | PASS | PASS | PASS | PASS | ✔ md5sig |
| phonepe | PASS | PASS | PASS | PASS | PASS | PASS | PASS | PASS | ✔ X-VERIFY/status/callback |
| telr | PASS | PASS | PASS | PASS | PASS | PASS | PASS | PASS | ✔ create/check |
| iyzico | PASS | PASS | PASS | PASS | PASS | PASS | PASS | PASS | ✔ IYZWSv2/retrieve |
| pesapal | PASS | PASS | PASS | PASS | PASS | PASS | PASS | PASS | ✔ token/submit/status |
| midtrans | PASS | PASS | PASS | PASS | PASS | PASS | PASS | PASS | ✔ snap/notification |
| myfatoorah | PASS | PASS | PASS | PASS | PASS | PASS | PASS | PASS | ✔ send/status |
| easypaisa | PASS | PASS | PASS | SKIP | PASS | PASS | PASS | PASS | ✔ hash/inquiry |

### What the tests do and don't prove

The tests run against faked provider responses. They prove that the package works correctly
as Laravel code: config is read, requests are signed, forged callbacks are rejected, nothing
crashes, and refunds are reported honestly. They **cannot** prove that a provider accepts every
request field. Run each gateway you enable once in its sandbox before going live. Confidence
in the request formats:

- **High** (stable, well-documented APIs and signature schemes): Stripe, PayPal, Razorpay,
  Paystack, Flutterwave, Mollie, Midtrans, SSLCommerz, bKash, Cashfree, Mercado Pago, PayFast,
  Paytm (port of the official PaytmChecksum).
- **Check carefully in sandbox**: PhonePe (implements the salt-key `/pg/v1` API; PhonePe is
  moving merchants to its OAuth v2 API), iyzico (buyer/basket fields), MyFatoorah (webhook
  signature format), Pesapal (refund), Telr, Skrill (MQI response parsing), senangPay (needs
  hash type SHA256), Easypaisa (hosted-checkout hash and inquiry endpoint).

Not supported via API (`supportsRefund() === false`): senangPay, PayFast, Skrill, Telr, Easypaisa.

---

## Original findings (before fixes)


## Result matrix

`PASS` = behaved correctly · `FAIL` = wrong behaviour · `ERR` = PHP fatal error · `SKIP` = check not applicable (the gateway uses an SDK rather than Laravel `Http`)

| Gateway | PSR-4 | Resolve | Live mode | Sends config creds | No-creds ≠ success | Forged callback rejected | Refund honest | Webhook route |
|---|---|---|---|---|---|---|---|---|
| paypal | PASS | PASS | PASS | SKIP | PASS | **FAIL** | PASS | **FAIL** |
| stripe | PASS | PASS | PASS | SKIP | PASS | **FAIL** | PASS | **FAIL** |
| razorpay | PASS | PASS | PASS | SKIP | PASS | PASS | PASS | **FAIL** |
| paystack | PASS | PASS | PASS | PASS | PASS | PASS | PASS | **FAIL** |
| paytm | PASS | PASS | PASS | SKIP | PASS | PASS | **FAIL** | **FAIL** |
| flutterwave | PASS | **FAIL** | PASS | **FAIL** | PASS | **FAIL** | ERR | **FAIL** |
| sslcommerz | PASS | PASS | **FAIL** | PASS | PASS | **FAIL** | **FAIL** | **FAIL** |
| mollie | PASS | PASS | **FAIL** | SKIP | PASS | **FAIL** | **FAIL** | **FAIL** |
| senangpay | PASS | PASS | **FAIL** | **FAIL** | **FAIL** | **FAIL** | **FAIL** | **FAIL** |
| bkash | PASS | PASS | **FAIL** | SKIP | **FAIL** | PASS | PASS | **FAIL** |
| mercadopago | PASS | PASS | PASS | SKIP | **FAIL** | PASS | PASS | **FAIL** |
| cashfree | PASS | PASS | PASS | **FAIL** | **FAIL** | PASS | **FAIL** | **FAIL** |
| payfast | PASS | PASS | PASS | **FAIL** | **FAIL** | **FAIL** | **FAIL** | **FAIL** |
| skrill | PASS | PASS | PASS | **FAIL** | **FAIL** | **FAIL** | **FAIL** | **FAIL** |
| phonepe | **FAIL** | **FAIL** | ERR | SKIP | PASS* | PASS* | ERR | **FAIL** |
| telr | PASS | PASS | PASS | **FAIL** | **FAIL** | PASS | PASS | **FAIL** |
| iyzico | PASS | PASS | PASS | **FAIL** | **FAIL** | PASS | PASS | **FAIL** |
| pesapal | PASS | PASS | PASS | **FAIL** | **FAIL** | PASS | PASS | **FAIL** |
| midtrans | PASS | PASS | PASS | **FAIL** | **FAIL** | PASS | PASS | **FAIL** |
| myfatoorah | **FAIL** | **FAIL** | ERR | SKIP | PASS* | PASS* | ERR | **FAIL** |
| easypaisa | PASS | PASS | PASS | **FAIL** | **FAIL** | **FAIL** | **FAIL** | **FAIL** |

\* PhonePe and MyFatoorah cannot be loaded at all, so these PASS results mean nothing.

Many "PASS" cells in the forged/refund columns pass only because the call failed early, for example because credentials were never read. They show the gateway is not unsafe, not that it works.

Existing test suite: `vendor/bin/phpunit` gives **27 tests, 10 errors**. The tests pass constructor arguments that don't exist (`customerEmail:`, `callbackUrl:`). `test_gateways.php` only runs `php -l`, so it reports "all passed" for code that crashes at runtime.

---

## 1. Cross-cutting defects (affect every app)

| # | Severity | Defect | Where |
|---|---|---|---|
| C1 | **Critical** | **The webhook route crashes when any `X-Signature`/`Signature` header is present.** `$request->headers->all()` returns arrays, and `WebhookPayload` expects `?string`, so the call ends in a TypeError and HTTP 500. Without the header, signature checks are skipped entirely. | `Http/Controllers/WebhookController.php`, `DTO/WebhookPayload.php` |
| C2 | **Critical** | **The webhook route accepts forged Stripe and PayPal events with HTTP 200.** It also fires no event (`PaymentSucceeded` etc.), so an app has no way to react to webhooks anyway. | `WebhookController::handle` |
| C3 | **Critical** | **Most gateways ignore the published config shape.** 14 of 21 read top-level keys (`api_key`, `server_key`, `app_id`, …) or `config('services.*')` instead of `sandbox.*` / `live.*`. Credentials set in `.env` per the README never reach them. | Mollie, bKash, SSLCommerz, Senangpay, Cashfree, Telr, Iyzico, Pesapal, Midtrans, MyFatoorah, EasyPaisa, Payfast, Skrill, PhonePe |
| C4 | **Critical** | **Silent mock fallbacks in production code.** When credentials or the SDK are missing, Cashfree, bKash and SSLCommerz switch to fake clients that return success, including "Completed" payments and "VALID" validations. Telr substitutes `test_store_id`. | see per-gateway notes |
| C5 | High | **`PaymentResponse` is called with named arguments it doesn't have** (`gatewayTransactionId:`, `errorCode:`, `code:`), which is a fatal `Error`. Because callers catch `\Exception`, not `\Throwable`, these errors escape. | SSLCommerz, Mollie, bKash, Senangpay, Telr, Iyzico, Pesapal, Midtrans, MyFatoorah, EasyPaisa |
| C6 | High | **Live mode is ignored** by gateways that don't call `parent::__construct()`. `getMode()` always returns `sandbox`. Telr and Iyzico derive the mode from a non-existent `test_mode` key, so they stay in test mode forever. | SSLCommerz, Mollie, bKash, Senangpay, Telr, Iyzico, Pesapal, Midtrans, MyFatoorah |
| C7 | High | **Webhook secrets are never found.** `webhook_secret` is top-level in the config, but Stripe and Mercado Pago look it up with `getModeConfig()`, so signature verification is skipped. Stripe also verifies a `json_encode`d array instead of the raw body, so it would fail even when the secret is found. | Stripe, Mercado Pago |
| C8 | High | **No amount/currency check on verify.** `verify()` never compares the paid amount with the order amount, so an attacker can pay 1 INR for a 10,000 INR order and still get `success: true`. Flutterwave, Paystack and SSLCommerz explicitly require this check. | all |
| C9 | Medium | Amounts are always multiplied by 100, which is wrong for zero-decimal currencies (JPY, KRW, IDR on some APIs) and three-decimal currencies (KWD, BHD, OMR). | Stripe, Razorpay, Paystack, Paytm, … |
| C10 | Medium | `AbstractGateway::getConfig(?string $default)` throws a TypeError for non-string defaults (`true`, arrays). | `Core/AbstractGateway.php` |
| C11 | Medium | `SignatureVerifier::verifyPhonePe()` uses an undefined `$signature` variable. `verifyStripe()` ignores the `t=` timestamp, so Stripe's signature can never match. | `Core/SignatureVerifier.php` |
| C12 | Low | Fallbacks to `route('payment.success')` etc. throw `RouteNotFoundException` unless the app defines exactly those route names, and this isn't documented. | PayPal, Stripe, Paystack, Flutterwave, EasyPaisa |

---

## 2. Per-gateway findings

### PayPal — *near usable after fixes*
- `CHECKOUT.ORDER.APPROVED` is treated as `completed`, but money is captured only after `capturePayment()`. An approved-but-uncaptured order looks paid.
- Webhook verification reads `PAYPAL-TRANSACTION-ID` as the signature (it should be `PAYPAL-TRANSMISSION-SIG`). It sends no `Authorization` header to `verify-webhook-signature`, and `getallheaders()` keys are case-sensitive. As a result every real webhook is rejected when `webhook_secret` is set, and every forged one is accepted when it isn't.
- Refunds hard-code the `USD` currency. The `order-` prefix check never matches real order IDs, so `refund()` needs a capture ID that `pay()` never returns.

### Stripe — *near usable after fixes*
- Webhook signature is never checked (C7), so forged `payment_intent.succeeded` events are accepted.
- `charges` was removed from the PaymentIntent API, so `retrievePayment()` always returns `charges: []`.

### Razorpay — *best of the set*
- Payment-callback signature check (`order_id|payment_id`) is correct and the payment is re-fetched.
- The package webhook route calls `verify()`, which expects checkout callback fields. Real Razorpay webhooks therefore always get 500, and `processWebhook()` does not check `X-Razorpay-Signature`.

### Paystack — *best of the set*
- `verify()` re-queries `/transaction/verify`, which is good.
- Customer email comes only from `metadata['email']` and ignores `$request->email`, so every customer becomes `customer@example.com`.
- Refund hard-codes `NGN`, so refunds fail for ZAR, GHS and USD.
- `getPublicKey()` "derives" `pk_` from `sk_`, which is wrong. Use the configured `public_key`.

### Paytm — *broken*
- **`refund()` makes no API call and returns `true` (fake refund).**
- The checksum is a home-made SHA-256. Paytm requires the AES-based `PaytmChecksum`, and the signature belongs in `head.signature`. Initiate-transaction will fail and every real callback will fail verification.
- `amount` is sent in paise as a flat field. Paytm expects `txnAmount: {value: "100.00", currency}` in rupees. The callback amount is also divided by 100.
- `$request->getCallbackUrl()` doesn't exist, which is a fatal error when `returnUrl` is null.

### Flutterwave — *broken*
- Can't be instantiated: the SDK needs `symfony/cache` (`Psr16Cache`), which isn't required by the package.
- The SDK methods called (`payment()->initiate`, `createPaymentLink`, `refund()->create`, …) don't match the v3 SDK. `createPayment()` is declared `array` but returns an object.
- `verify()` reads `$transaction->data['id']` (the key doesn't exist) and uses an undefined `$request`. There is no amount/currency check.
- Webhook check uses HMAC-SHA512. Flutterwave sends `verif-hash`, which must equal your secret hash.

### SSLCommerz — *broken*
- `verify()` is a fatal error (C5). The validation call uses `tran_id`, but the SSLCommerz validator requires `val_id`.
- If the `raziul` SDK class isn't found, a mock `orderValidate()` returns `VALID` for any transaction.
- `refund()` sends the order ID as `bank_tran_id`, and the mock refund returns success.
- `getTransactionStatus()` passes a string to `verify(array)`. `getGatewayConfig()` calls an undefined `getGatewayName()`.

### Mollie — *broken*
- Ignores `sandbox.api_key` (C3).
- `pay()` uses an undefined `$data` and `$paymentRequest->redirect_url` (it should be `returnUrl`).
- `verify()` calls `payments->page(['metadata'…])` (TypeError), uses a bad named argument, and references an undefined `$response`.
- Refund calls the non-existent `refunds->createFor()` and hard-codes `EUR`.

### bKash — *broken / unsafe*
- Reads `services.bkash.*` instead of the package config. With the SDK class missing, a mock returns a `Completed` payment with `success: true`.
- `verify()` depends on `getPaymentIdFromTransaction()`, a placeholder, and uses a bad named argument.
- Currency conversion uses hard-coded exchange rates.
- `refund()` always returns `false`.

### Mercado Pago — *mostly OK*
- `getAccessToken(): string` throws a TypeError when the token isn't configured.
- `authorized` counts as success even though it isn't captured.
- The sandbox checkout URL is hard-coded to `.com.ar`. Use `sandbox_init_point` / `init_point`.

### Cashfree — *broken / unsafe*
- `getConfig('app_id')` is top-level, so the gateway always runs **mock mode** and returns fake `mock_order_*` redirects.
- `buildOrderRequest()` uses unimported SDK classes, which is a fatal error. The mock has no `getOrderDetails()`.
- `verify()` returns `PaymentResponse::success` whatever the order status is.

### PayFast — *broken*
- `$this->config->getMerchantId()` is a method call on an array, so `pay()` and `verify()` are fatal errors. `Log` isn't imported.
- `verify()` would return success for any `payment_status`.
- `supportsRefund()` returns `true`, but `refund()` always returns `false`.

### Skrill — *broken*
- Same `$this->config->…` fatal error. `buildAccountHolder()` gets the wrong argument type. `Log` isn't imported.
- `verify()` returns success whatever the status is. The "PSD2 session" API used doesn't match Skrill Quick Checkout (`status_url` + `md5sig`).

### PhonePe — *broken / security issue*
- Can't be autoloaded: the namespace is `…\Phonepe\` but the resolver expects `…\PhonePe\PhonePeGateway`. Composer warns about this.
- **X-VERIFY sends `hash###<secret salt key>`, which leaks the salt key in every request.** The correct form is `sha256(base64(payload) + "/pg/v1/pay" + saltKey) + "###" + saltIndex`, and the body must be `{request: base64}`.
- Uses `$this->config->…` on an array, the same fatal error as PayFast and Skrill.

### Telr — *broken*
- Reads `auth_key`, but the config key is `store_auth_key`. It falls back to placeholder credentials, and the live and test endpoints are identical with `test` always on.
- **Logs `auth_key` in plain text** (`Log::info('Telr verification request', ['query' => …])`).
- Bad named argument (C5).

### Iyzico — *broken*
- Config keys are ignored (C3).
- The `IYZWSv2` header is wrong. Iyzico expects base64 of `apiKey:…&randomKey:…&signature:<hex hmac(randomKey+uriPath+body)>` plus `x-iyzi-rnd`, so every call gets 401.
- Bad named argument (C5).

### Pesapal — *structure OK, unusable as shipped*
- Config keys are ignored (C3), and the mode comes from `test_mode`.
- Bad named argument (C5).
- `ipn_id` from the config is never used.

### Midtrans — *structure OK, unusable as shipped*
- `server_key` is read top-level (C3), so it sends `Basic base64(":")`.
- Bad named argument (C5).
- Webhook signature is checked only if `signature_key` is present; omitting it skips the check.

### MyFatoorah — *broken*
- Can't be autoloaded: the namespace is `…\Myfatoorah\` but the resolver expects `…\MyFatoorah\MyFatoorahGateway`.
- Config keys are ignored, and there is a bad named argument.

### EasyPaisa — *broken*
- Calls undefined `logError()` and `logInfo()`, a bad named argument (`code:`), config keys that don't match the published config, and endpoints that look invented (`sandbox.easypaisa.com.pk/epg/mobile-account`).

### Senangpay — *broken*
- Reads `services.senangpay.*`, so the secret is `null` and the hash function hits a TypeError.
- `$paymentRequest['customer']` uses array access on an object.
- `verify()` is a stub that always returns `pending`.
- Hard-coded exchange rates.

---

## 3. Recommended fix order

1. **Core:** fix `WebhookController` (raw body, per-gateway signature header, dispatch events, 4xx on rejection) and `WebhookPayload`. Make every gateway read credentials through `getModeConfig()` and always call `parent::__construct()`.
2. **Remove every mock/fallback client from `src/`.** Throw `PaymentException` on missing config instead.
3. Fix the `PaymentResponse` named-argument misuse, or add a factory, and catch `\Throwable` at the manager boundary.
4. Add amount/currency verification to `verify()` (accept an expected amount, or return it so the app can compare).
5. Fix or rewrite the gateways in this order: Stripe → PayPal → Razorpay → Paystack → SSLCommerz → bKash. Mark any gateway that hasn't been reworked as *experimental* in the README, or remove it from the resolver.
6. Fix the class and file names for PhonePe and MyFatoorah. Add `symfony/cache` for Flutterwave. Fix the existing unit tests and replace `test_gateways.php` with `phpunit -c phpunit.qa.xml` in CI.
7. Before tagging a release, do a manual sandbox run with real test keys for each gateway that is kept.
