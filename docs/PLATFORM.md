# Platform layer (the Owner's business)

Phase 6 of the brief (§17, §22.7). Code lives in
`plugins/storekeeper-platform/src` with the `StoreKeeper\Platform` namespace and
network-scoped tables (`{n}plans`, `{n}applications`, `{n}tenants`, …).

## What exists

| Piece | Where |
|---|---|
| Plans: create, edit, duplicate, limits, feature flags | `Plans/PlanRepository`, `Plans/PlanService` |
| Plan enforcement | `Plans/PlanGate` (throws `PlanLimit`, HTTP 402) |
| Usage counters per tenant/metric/month | `Plans/UsageRepository` |
| Applications: submit, list, status | `Applications/ApplicationService` |
| Tenant records | `Tenants/TenantRepository` |
| Provisioning with a step log | `Provisioning/ProvisioningService`, `SiteProvisioner` |
| WordPress provisioning | `Provisioning/WordPressProvisioner` |
| Invoices and manual payment | `Billing/BillingService` |
| Dunning ladder | `BillingService::runDunning()` (hourly cron) |
| Paystack platform webhook | `Http/WebhookController`, `storekeeper-platform/v1/webhook` |
| Owner console | `Console/OwnerConsole` (network admin → Store Keeper) |
| Impersonation with both audit trails | `OwnerConsole::impersonate()` / `stopImpersonation()` |

## Plans and enforcement

A plan row carries `limits` and `features` JSON (§17.3 shape). The Owner enters
every amount and limit in the console — nothing is seeded, because plan prices are
Q-003. A limit of `0` means uncapped.

`PlanGate` takes the tenant row and the **current count** from the caller and
refuses the change when adding would exceed a hard limit. Keeping the count out of
the gate is what lets the same class run in a subsite request (where the store
tables are reachable) and in a network request.

Usage is recorded per tenant, metric and `YYYY-MM` period and is safe to increment
concurrently (`INSERT … ON DUPLICATE KEY UPDATE`).

## Applications and provisioning

An application arrives through `ApplicationService::submit()` (form not built —
the service and console inbox are). The Owner approves it in the console, then
provisions it with a plan, a WordPress owner user and a slug:

1. `site` — `wpmu_create_blog()` on the network domain.
2. `tenant` — a `tenants` row, status `trial`, trial end from
   `storekeeper_platform_trial_days` (default 14; Q-009).
3. `store` — switch to the blog, run the store migrations, seed shipping
   defaults, set the store mode/plan options, activate the storefront theme.
4. `owner` — `add_user_to_blog()` as `storekeeper_owner`, onboarding checklist.
5. `welcome` — **skipped**, because no WhatsApp/SMS adapter exists (Q-005/Q-006).

Every step writes `{n}platform_jobs`. If the seed fails, the tenant stays
`provisioning` and the console shows the failed step; nothing is presented as a
working store.

Custom domains and SSL are not built (Q-019). `tenants.primary_domain` exists but
nothing verifies DNS or maps a domain.

## Billing and dunning

- The Owner creates an invoice (`setup`, `subscription`, `addon`, `service`,
  `message_overage`) and either marks it paid manually (bank transfer) or lets a
  verified Paystack event do it.
- The webhook verifies `x-paystack-signature` = HMAC-SHA512(raw body, secret)
  where the secret is the network option
  `storekeeper_platform_paystack_secret`. With no secret the endpoint answers
  `503` rather than accepting unverified events.
- `charge.success` with a matching `provider_reference` marks the invoice paid and
  the tenant `active`.
- `invoice.payment_failed` / `charge.failed` / `subscription.disable` start
  dunning: status `past_due`, `grace_ends_at = now + 7 days`
  (`storekeeper_platform_grace_days`).
- The hourly job moves `past_due → read_only` when grace has passed, and
  `read_only → suspended` 14 days after that. Nothing is deleted; data stays
  exportable, as §17.4 requires.

**Not built:** initiating a Paystack transaction or creating a Paystack Plan /
Subscription from the console. The engine can record and settle invoices, but the
first charge has to be created in the Paystack dashboard (or added later). This is
why no platform checkout button exists yet.

## Owner console

Under **Network Admin → Store Keeper**:

- **Tenants** — business, blog id, plan/status change, open store, impersonate.
- **Applications** — status and notes, then provision with plan/owner/slug/mode.
- **Plans** — list and create (limits and feature checkboxes).
- **Billing** — invoices per tenant, create invoice, mark paid.

Every handler is `admin-post` with `check_admin_referer()` and
`current_user_can('manage_network')`.

Impersonation requires a reason. It writes `platform_audit_log` and the store's
`audit_log` (`actor_type = owner_impersonation`), sets a signed, HTTP-only
`sk_owner_return` cookie for one hour, switches the auth cookie to the merchant
and drops the Owner into that store's admin. A notice on every admin screen
carries the “return to the owner account” link, which restores the original user.

## Tests

`tests/Platform/PlatformTest.php` (8) covers plan validation and the gate,
usage counters, application → tenant provisioning (including the failed-seed
path), manual and webhook payment, the dunning ladder, and unknown events.

## Honest gaps

- Paystack transaction/plan creation, revenue dashboards, platform health, service
  requests and global announcements from §17.5 are not built.
- Provisioning has not been run against a live multisite in this build; the
  workflow is tested with a fake provisioner.
- The webhook has not received a real Paystack event.
