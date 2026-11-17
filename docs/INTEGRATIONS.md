# Integrations still to be connected

Everything the shop can do today works without a third-party account. The items
below are the only places where a credential or an owner decision is still
missing; each one already has a seam, so connecting it is configuration plus one
adapter, not a redesign. Never commit a credential: store values as site options
(set in the back office or with `wp option update`) or environment variables.

## 1. OTP delivery — WhatsApp, falling back to SMS (Q-016)

**Affects:** storefront sign-in (`/sign-in/`) and sign-up (`/register/`).

**Now:** `Storefront\Otp\LogOtpSender` writes the code to the PHP error log and
shows it on a preview screen when both switches in `PREVIEW.md` are on. No
message reaches a shopper.

**Seam:** implement `Storefront\Otp\OtpSender` (`send(string $phoneE164, string
$code, string $purpose): void`) for the chosen provider and bind it in
`Core\Plugin` where `OtpSender::class` is registered. `purpose` is `login` or
`register`; a WhatsApp authentication template needs one approved template per
purpose.

**Needed from the owner:** provider (Meta WhatsApp Cloud API direct, or a
provider such as Twilio/360dialog), sender number / WABA ID, template names, and
whether SMS fallback is wanted and through whom. Questions Q-005, Q-006, Q-016.

## 2. Transactional email sender (Q-017)

**Affects:** order confirmation, staff new-order alert, any future email.

**Now:** `wp_mail()` with the site's default From address. Order mail is queued
and dispatched hourly; a preview host uses the `log` transport.

**Seam:** `Notifications\EmailChannel`. Either set the site's From address and
SMTP plugin/provider, or swap the channel for one that calls a transactional
email API. The outbox, retries and templates do not change.

**Needed from the owner:** From address and domain, and whether a transactional
provider is wanted for deliverability.

## 3. Payment gateways (Q-001b for OPay)

**Affects:** checkout card/transfer options.

**Now:** Paystack and Flutterwave adapters and signed webhooks are implemented;
OPay cannot go live without its merchant ID. No real charge has been made.

**Seam:** `Payments\Gateway\GatewayRegistry` plus per-gateway settings. Keys are
read from store settings, never from code.

**Needed from the owner:** live/test keys per gateway, OPay merchant ID, and the
webhook URL registered with each gateway.

## 4. Shipping rates

**Affects:** checkout delivery fee.

**Now:** one flat delivery fee per store (`CheckoutService::shippingOptions()`).

**Seam:** the same method is where zones/methods will be read from once the
shipping-zone tables and screens are built. No credential is needed — this one is
a build item, listed here so it is not mistaken for a finished feature.

## 5. WhatsApp order desk (Q-005)

**Affects:** staff sending an order summary or tracking link to a customer.

**Now:** orders exist and notifications are email-only.

**Seam:** a WhatsApp channel next to `EmailChannel` for the outbox, plus
tap-to-send links from the back office (no API needed for the manual case).

**Needed from the owner:** the same provider decision as OTP, and the merchant's
WhatsApp number.
