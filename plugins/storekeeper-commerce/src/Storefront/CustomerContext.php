<?php

declare(strict_types=1);

namespace StoreKeeper\Commerce\Storefront;

use StoreKeeper\Commerce\Core\Brand;

/**
 * Who is browsing (§11.5). Storefront customers are not WordPress users (§7.4);
 * once phone + OTP sign-in lands it sets the session and answers here, and every
 * price on the shop resolves through that customer's group.
 *
 * Until then the filter is the seam: returning null means "guest", and guests are
 * priced from the default (retail) group. Nothing caches a wholesale price into a
 * guest response because there is no wholesale price to cache.
 */
final class CustomerContext
{
    /** @param (\Closure():mixed)|null $resolver */
    public function __construct(private readonly ?\Closure $resolver = null)
    {
    }

    /** @var array<string,mixed>|null */
    private ?array $customer = null;

    private bool $resolved = false;

    /** @return array<string,mixed>|null a row from {p}customers */
    public function current(): ?array
    {
        if ($this->resolved) {
            return $this->customer;
        }

        $this->resolved = true;

        $candidate = $this->resolver !== null
            ? ($this->resolver)()
            : apply_filters(Brand::hook('storefront/customer'), null);
        if (is_array($candidate) && isset($candidate['id'], $candidate['group_id'])) {
            $this->customer = $candidate;
        }

        return $this->customer;
    }

    public function isSignedIn(): bool
    {
        return $this->current() !== null;
    }

    /** Group rows are cached per request by the pricing repository. */
    public function groupId(): ?int
    {
        $customer = $this->current();

        return $customer === null ? null : (int) $customer['group_id'];
    }
}
