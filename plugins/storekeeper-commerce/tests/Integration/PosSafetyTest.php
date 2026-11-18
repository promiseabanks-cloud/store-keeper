<?php

declare(strict_types=1);

namespace StoreKeeper\Commerce\Tests\Integration;

use PHPUnit\Framework\TestCase;
use StoreKeeper\Commerce\Core\Audit\Actor;
use StoreKeeper\Commerce\Pos\RateLimiter;
use StoreKeeper\Commerce\Tests\Support\TestStore;

/**
 * The two POS safety rails: device/sync endpoints are rate limited (D-041), and
 * the storefront subtracts `offline_buffer_units` at a location whose device has
 * gone quiet, so an offline tablet cannot oversell silently (§15.2).
 */
final class PosSafetyTest extends TestCase
{
    public function testDeviceRequestsAreCappedAndRecoverWithTheNextWindow(): void
    {
        $store = TestStore::create();

        for ($i = 1; $i <= RateLimiter::DEVICE_REQUESTS_PER_MINUTE; $i++) {
            self::assertNull($store->deviceGuard->deviceRequest(42), sprintf('Request %d should pass', $i));
        }

        $blocked = $store->deviceGuard->deviceRequest(42);

        self::assertNotNull($blocked);
        self::assertSame(RateLimiter::DEVICE_REQUESTS_PER_MINUTE, $blocked['limit']);
        self::assertSame(0, $blocked['remaining']);
        self::assertGreaterThan(0, $blocked['retry_after']);

        self::assertNull($store->deviceGuard->deviceRequest(43), 'A second device has its own budget');

        $store->clock->advance('61 seconds');

        self::assertNull($store->deviceGuard->deviceRequest(42), 'The next window starts fresh');
    }

    public function testFailedDeviceTokensAreCappedPerAddress(): void
    {
        $store = TestStore::create();

        for ($i = 1; $i <= RateLimiter::AUTH_FAILURES_PER_MINUTE; $i++) {
            self::assertNull($store->deviceGuard->authFailure('203.0.113.9'));
        }

        $blocked = $store->deviceGuard->authFailure('203.0.113.9');

        self::assertNotNull($blocked);
        self::assertSame(RateLimiter::AUTH_FAILURES_PER_MINUTE, $blocked['limit']);
        self::assertNull($store->deviceGuard->authFailure('198.51.100.4'), 'Another address is not affected');
    }

    public function testPruneDropsExpiredBuckets(): void
    {
        $store = TestStore::create();
        $store->rateLimiter->hit('pos:device:1', 5);

        self::assertSame(0, $store->rateLimiter->prune(), 'A live window is kept');

        $store->clock->advance('2 minutes');

        self::assertSame(1, $store->rateLimiter->prune(), 'The expired bucket is removed');
        self::assertSame(0, $store->rateLimiter->prune(), 'Pruning is idempotent');
    }

    public function testStorefrontAvailabilityDropsWhileADeviceIsStale(): void
    {
        $store = TestStore::create(offlineBufferUnits: 2, deviceStaleMinutes: 10);
        $store->seedGroups();
        $locationId = (int) $store->seedLocation()['id'];
        $variantId = (int) $store->seedProduct($locationId, 500000, 10)['variant']['id'];

        $registered = $store->deviceService->register(
            ['name' => 'Front counter', 'location_id' => $locationId],
            Actor::staff(1)
        );
        $deviceId = (int) $registered['device']['id'];

        self::assertSame(8, $store->inventory->availableOnline($variantId), 'A device that never synced is stale');
        self::assertSame(
            [$variantId => 8],
            array_map('intval', $store->inventory->availableOnlineMany([$variantId]))
        );

        $store->deviceRepository->touchSynced($deviceId);
        self::assertSame(10, $store->inventory->availableOnline($variantId), 'A fresh sync lifts the buffer');

        $store->clock->advance('11 minutes');
        self::assertSame(8, $store->inventory->availableOnline($variantId), 'The buffer returns once the device is stale');

        // A second sellable location without a device keeps its full level.
        $warehouseId = (int) $store->seedLocation('Back room')['id'];
        $store->inventory->opening($variantId, $warehouseId, 5, $store->actor());

        self::assertSame(13, $store->inventory->availableOnline($variantId));

        // A device that syncs again restores both display and cart availability.
        $store->deviceRepository->touchSynced($deviceId);
        self::assertSame(15, $store->inventory->availableOnline($variantId));

        $store->clock->advance('11 minutes');
        $store->deviceService->revoke((string) $registered['device']['uuid'], Actor::staff(1));
        self::assertSame(15, $store->inventory->availableOnline($variantId), 'A revoked device no longer hedges');
    }

    public function testAPullOnlyDeviceIsNotTreatedAsStale(): void
    {
        $store = TestStore::create(offlineBufferUnits: 2, deviceStaleMinutes: 10);
        $store->seedGroups();
        $locationId = (int) $store->seedLocation()['id'];
        $variantId = (int) $store->seedProduct($locationId, 500000, 10)['variant']['id'];

        $registered = $store->deviceService->register(
            ['name' => 'Shelf tablet', 'location_id' => $locationId],
            Actor::staff(1)
        );
        $deviceId = (int) $registered['device']['id'];

        self::assertSame(8, $store->inventory->availableOnline($variantId), 'A device that never synced is stale');

        // The tablet only reads: it pulls stock but never pushes a batch.
        $store->sync->pull($registered['device'], null);

        self::assertNotNull(
            $store->deviceRepository->find($deviceId)['last_sync_at'],
            'A successful pull refreshes the device sync time'
        );
        self::assertSame(10, $store->inventory->availableOnline($variantId), 'A pull lifts the buffer');

        $store->clock->advance('11 minutes');
        self::assertSame(8, $store->inventory->availableOnline($variantId), 'The buffer returns once the pull is old');

        $store->sync->pull($registered['device'], '0');
        self::assertSame(10, $store->inventory->availableOnline($variantId), 'The next pull lifts it again');
    }

    public function testStorefrontAvailabilityNeverGoesBelowZero(): void
    {
        $store = TestStore::create(offlineBufferUnits: 5);
        $store->seedGroups();
        $locationId = (int) $store->seedLocation()['id'];
        $variantId = (int) $store->seedProduct($locationId, 500000, 2)['variant']['id'];

        $store->deviceService->register(
            ['name' => 'Front counter', 'location_id' => $locationId],
            Actor::staff(1)
        );

        self::assertSame(0, $store->inventory->availableOnline($variantId));
    }
}
