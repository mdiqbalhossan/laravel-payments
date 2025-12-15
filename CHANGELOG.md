# Changelog

All notable changes to `mdiqbal/laravel-payments` will be documented in this file.

## [Unreleased]

### Added
- Initial package structure
- Support for 21 payment gateways
- Unified payment interface

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