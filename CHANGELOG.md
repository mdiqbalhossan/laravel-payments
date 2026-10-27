# Changelog

All notable changes to `mdiqbal/laravel-payments` will be documented in this file.

## [Unreleased]

QA pass over all 21 gateways (see `QA_REPORT.md`). Most gateways were rewritten
against the providers' current APIs.

### Security
- Webhooks: signatures are verified per gateway against the raw body
  (Stripe, PayPal, Razorpay, Paystack, Flutterwave, Cashfree, Midtrans, PhonePe,
  SSLCommerz IPN, PayFast ITN, Skrill, senangPay, Mercado Pago, MyFatoorah);
  forged requests get 401 instead of being accepted
- Removed mock/fallback clients that returned fake successful payments and refunds
  (Cashfree, bKash, SSLCommerz, Paytm refund, Telr)
- PhonePe no longer sends the salt key in the X-VERIFY header; Telr no longer logs its auth key
- PayPal no longer treats an approved-but-uncaptured order as paid

### Added
- `PaymentResponse::matchesAmount()` and `errorCode`; amount/currency on verified responses
- `WebhookHandled` event; `AbstractGateway::handleWebhook()`
- QA and flow test suites (`tests/QA`)

### Changed
- Credentials are read from the `sandbox`/`live` config block for every gateway
- `AbstractGateway::makeRequest()` uses Laravel's HTTP client
- Zero- and three-decimal currencies are converted correctly
- Removed 11 unused gateway SDK dependencies (only stripe-php and razorpay remain)
- Gateways without a working refund integration report `supportsRefund() === false`
  (senangPay, PayFast, Skrill, Telr, Easypaisa)

### Fixed
- PhonePe and MyFatoorah classes could not be autoloaded
- Fatal errors from unknown `PaymentResponse` named arguments in 10 gateways

## [1.0.0] - 2024-12-14

### Added
- Core payment gateway interface (`PaymentGatewayInterface`)
- Abstract base class for gateway implementations (`AbstractGateway`)
- Payment manager and resolver classes
- Data Transfer Objects (PaymentRequest, PaymentResponse, WebhookPayload)
- Payment context for fluent interface usage
- Comprehensive configuration system
- Laravel service provider and facade integration
- Webhook handling controller
- Event system for payment lifecycle
- Exception classes for error handling
- Signature verification utilities

### Supported Gateways
- PayPal (partial implementation)
- Stripe (partial implementation)
- Razorpay (partial implementation)
- Paystack (stub)
- Paytm (stub)
- Flutterwave (stub)
- SSLCommerz (stub)
- Mollie (stub)
- Senangpay (stub)
- bKash (stub)
- Mercado Pago (stub)
- Cashfree (stub)
- Payfast (stub)
- Skrill (stub)
- PhonePe (stub)
- Telr (stub)
- Iyzico (stub)
- Pesapal (stub)
- Midtrans (stub)
- MyFatoorah (stub)
- EasyPaisa (stub)

### Features
- Sandbox/Live mode switching
- Gateway enable/disable configuration
- Webhook endpoint registration
- Payment event dispatching
- Refund support (where applicable)
- Comprehensive test suite

### Configuration
- Environment-based configuration
- Gateway-specific settings
- Webhook configuration options
- Mode (sandbox/live) settings

### Documentation
- Comprehensive README
- API documentation
- Installation guide
- Usage examples
- Security considerations
- Troubleshooting guide