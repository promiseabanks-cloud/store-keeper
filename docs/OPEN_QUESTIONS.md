# Open questions for the Owner

Carried from §24 of the brief. Nothing here blocks Phase 1; each has a safe placeholder in code.

| # | Question | Current placeholder in code | Blocks |
|---|---|---|---|
| Q-001 | **"GoPay"** — which provider exactly (OPay, another)? | No third adapter is implemented. `Payments\GatewayAdapter` is the slot. | Phase 3 |
| Q-002 | **Product name and platform domain.** Working name "Store Keeper" is used everywhere via `Core/Brand.php`. | `Brand::NAME = 'Store Keeper'` | Launch |
| Q-003 | **Plan prices, setup fees and limits** (§17.3). | `plans.monthly_kobo`/`setup_fee_kobo` stay `TBD-OWNER`; limits are seeded as configurable JSON, no amounts invented. | Phase 6 |
| Q-004 | **Pilot merchant(s)** and what they receive for piloting. | — | Phase 2 gate |
| Q-005 | **WhatsApp API route:** direct Meta Cloud API on each merchant's number, or a BSP? Who pays Meta? | `Notifications` adapter slot only. | Phase 3 |
| Q-006 | **SMS provider** choice (Termii or similar). | SMS adapter slot only. | Phase 3 |
| Q-007 | **Hosting provider and region.** | `docker-compose.yml` is local only; `docs/HOSTING.md` is written in Phase 6. | Phase 2 pilot |
| Q-008 | **Legal:** merchant terms, privacy notice template, data processing terms (§19.5). | Storefront privacy page template not yet written. | Phase 4 |
| Q-009 | **Trial period** length, if any. | `tenants.trial_ends_at` nullable, no default length. | Phase 6 |
| Q-010 | **Self-serve signup** at launch, or Owner-reviewed applications only? | Default is reviewed only; a feature flag exists on plans. | Phase 6 |
| Q-011 | **Bumpa export format** — need a sample file from the pilot merchant (§11.3). | Generic importer only; Bumpa preset is a mapping on top. | Phase 2 |
