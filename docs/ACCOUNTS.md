# Accounts & sign-in — the core

This is how user accounts are engineered in Store Keeper, and how a wholesale
sign-up differs from a retail one. It covers the change from **phone + one-time
code** to **email + password + Google**.

## Two separate kinds of account

Store Keeper has two completely separate account systems. They never mix.

1. **Staff accounts** — the shop owner and their staff. These *are* WordPress
   users, with roles (`storekeeper_owner`, `storekeeper_manager`,
   `storekeeper_sales`, `storekeeper_cashier`, `storekeeper_uploader`,
   `storekeeper_dispatcher`). They sign in at the clean `/signin` URL and land on
   the Store Keeper back-office dashboard — never the raw WordPress dashboard.
   The platform owner (super admin) keeps full WordPress + Network access.

2. **Customer accounts** — the people who shop. These are **not** WordPress
   users. They are rows in the engine's own `customers` table, and they sign in
   on the storefront. This separation is deliberate (§7.4): a shop can have tens
   of thousands of customers without ever touching the WordPress users table, and
   a customer can never reach wp-admin.

Everything below is about **customer** accounts.

## What a customer row holds

A customer is a row in `{prefix}customers`. The columns that matter for sign-in:

| Column | Purpose |
| --- | --- |
| `email` | The sign-in identifier. |
| `password_hash` | bcrypt hash of the password. Never the password itself. |
| `google_sub` | The Google account's subject id, when they use "Continue with Google". |
| `phone_e164` | Optional now. Kept for delivery, no longer used to sign in. |
| `group_id` | Which **customer group** prices this customer sees. |
| `requested_group_id` | A wholesale group awaiting approval. |
| `approval_status` | `approved`, `pending`, or `rejected`. |

A signed-in customer holds an HTTP-only session cookie (`skf_session`); the
database stores only a SHA-256 of the token. Sessions slide forward while in use
and can be revoked ("sign out everywhere"). This part is unchanged — only *how
you prove who you are* changed, from a phone code to a password or Google.

## Retail vs wholesale — the split

The difference is **not** in the account; it is in the **customer group** the
account joins. Provisioning seeds two self-registerable groups:

- **Retail** — the default group, `approval = auto`. A shopper who picks it is
  approved instantly and shops at retail prices.
- **Wholesale** — `approval` is the owner's choice:
  - `auto`: approved instantly, wholesale prices apply right away.
  - `manual`: the shopper is parked in the **retail** group with
    `approval_status = pending`, and `requested_group_id` points at Wholesale.
    They can shop at retail prices meanwhile. A staff member approves them from
    the back office (Customers → pending), which moves them into the Wholesale
    group and switches on trade prices.

Wholesale sign-up additionally requires a **business name**. Retail does not.

So "wholesale vs retail" is one sign-up form with a "How are you buying?" choice,
and the group's rule does the rest. Pricing always resolves through the group, so
a guest or a pending shopper simply sees retail prices — there is no wholesale
price to leak.

## The three ways to sign in

1. **Register with email + password** — name, email, password, group choice,
   (business name if wholesale), optional phone. Creates the customer row and
   opens a session. (`EmailAuth::register`)
2. **Sign in with email + password** — looks the customer up by email and
   verifies the hash. A wrong password and an unknown email give the *same*
   message, so the form never reveals which emails have accounts.
   (`EmailAuth::login`)
3. **Continue with Google** — standard OAuth 2.0 authorization-code flow. Google
   returns a signed token; we read the subject id, email and name. We link by
   Google id first, then by matching email, otherwise create a new **retail**
   account. (`GoogleAuth`) Wholesale still goes through the form so a business
   name and approval can be captured.

### Turning on Google

Google sign-in is hidden until the owner adds credentials. In the Google Cloud
Console, create an OAuth 2.0 **Web application** client, then set two options:

- `storekeeper_google_client_id`
- `storekeeper_google_client_secret`

The **authorized redirect URI** to register in Google is this site's
`wp-admin/admin-post.php?action=storekeeper_google_callback`.

Until both are set, `GoogleAuth::isConfigured()` is false and the button does not
appear — email + password works on its own.

## Where the code lives

- `src/Customers/EmailAuth.php` — register / login / set-password (engine).
- `src/Storefront/GoogleAuth.php` — the Google OAuth flow (storefront layer).
- `src/Storefront/EmailAuthActions.php` — the nonce-checked form handlers.
- `src/Customers/CustomerService.php` — creates the customer row and applies the
  group's approval rule (shared by storefront, staff and POS).
- `templates/storefront/sign-in.php`, `register.php` — the pages.
- `migrations/store/0014_email_auth.php` — adds `password_hash`, `google_sub`,
  and makes `phone_e164` optional.

## What did not change

- Customers are still not WordPress users.
- Sessions, the cart-claim-on-sign-in, and group pricing are untouched.
- Staff sign-in, POS, and the back office are untouched.
- The old phone + OTP code still exists in the engine but is no longer wired to
  any storefront form; it can be removed once this is proven live.
