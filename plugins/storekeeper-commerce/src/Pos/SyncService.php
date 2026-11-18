<?php

declare(strict_types=1);

namespace StoreKeeper\Commerce\Pos;

use StoreKeeper\Commerce\Core\Audit\Actor;
use StoreKeeper\Commerce\Core\Audit\AuditLogger;
use StoreKeeper\Commerce\Core\Clock;
use StoreKeeper\Commerce\Core\Database\Connection;
use StoreKeeper\Commerce\Core\Exception\StoreKeeperException;
use StoreKeeper\Commerce\Core\Exception\ValidationFailed;
use StoreKeeper\Commerce\Customers\CustomerRepository;
use StoreKeeper\Commerce\Customers\CustomerService;
use StoreKeeper\Commerce\Orders\OrderRepository;

/**
 * The offline sync protocol (§15.3).
 *
 * Pull sends the catalog, price table and this device's stock levels every time
 * (a store's catalog is small enough that correctness beats delta size); the
 * change feed carries stock, customer and order deltas since the device's
 * cursor. A cursor older than the retention window gets a full resync.
 *
 * Push is idempotent twice over: the batch UUID stores its whole result, and
 * each operation's client UUID is the order idempotency key.
 */
final class SyncService
{
    public function __construct(
        private readonly Connection $db,
        private readonly SyncRepository $sync,
        private readonly ChangeLog $changeLog,
        private readonly CounterService $counter,
        private readonly CustomerService $customers,
        private readonly CustomerRepository $customerRepository,
        private readonly OrderRepository $orders,
        private readonly DeviceService $devices,
        private readonly AuditLogger $audit,
        private readonly Clock $clock,
    ) {
    }

    /**
     * @param array<string,mixed> $device
     * @param array<string,mixed> $settings
     * @return array<string,mixed>
     */
    public function pull(array $device, ?string $cursor, array $settings = [], int $limit = 500): array
    {
        $parsed = is_numeric($cursor) ? max(0, (int) $cursor) : 0;
        $latest = $this->changeLog->latestId();
        $locationId = (int) $device['location_id'];

        $changes = $parsed > 0 && !$this->changeLog->tooOld($parsed)
            ? $this->changeLog->since($parsed, $limit)
            : [];

        $fullResync = $parsed <= 0 || $this->changeLog->tooOld($parsed) || ($changes === [] && $parsed < $latest);

        $stock = [];
        $customers = [];
        $orders = [];
        $catalogChanges = [];

        foreach ($changes as $change) {
            $payload = $change['payload'] === null ? [] : (json_decode((string) $change['payload'], true) ?: []);
            $type = (string) $change['entity_type'];

            if ($type === 'stock') {
                $stock[(int) ($payload['variant_id'] ?? 0)] = true;
            } elseif ($type === 'customer') {
                $customers[(int) ($change['entity_id'] ?? 0)] = true;
            } elseif ($type === 'order') {
                $orders[(int) ($change['entity_id'] ?? 0)] = true;
            } elseif ($type === 'catalog') {
                $catalogChanges[] = [
                    'action' => (string) $change['action'],
                    'entity_id' => $change['entity_id'] === null ? null : (int) $change['entity_id'],
                    'payload' => $payload,
                ];
            }
        }

        $stockLevels = $this->sync->levels($locationId);
        if (!$fullResync && $stock !== []) {
            $stockLevels = array_values(array_filter(
                $stockLevels,
                static fn (array $level): bool => isset($stock[(int) $level['variant_id']])
            ));
        }

        $customerRows = $this->sync->customers();
        if (!$fullResync && $customers !== []) {
            $customerRows = array_values(array_filter(
                $customerRows,
                static fn (array $customer): bool => isset($customers[(int) $customer['id']])
            ));
        }

        $orderRows = [];
        if (!$fullResync && $orders !== []) {
            foreach (array_keys($orders) as $orderId) {
                $order = $this->orders->order((int) $orderId);
                if ($order !== null) {
                    $orderRows[] = [
                        'order_id' => (int) $order['id'],
                        'number' => (string) $order['number'],
                        'status' => (string) $order['status'],
                        'payment_status' => (string) $order['payment_status'],
                        'fulfilment_status' => (string) $order['fulfilment_status'],
                        'total_kobo' => (int) $order['total_kobo'],
                        'updated_at' => (string) $order['updated_at'],
                    ];
                }
            }
        }

        // A pull means the device is reachable and now holds current stock, so
        // it counts as a sync even when it has not pushed anything yet. Without
        // this a tablet that only reads would stay "stale" forever and the
        // storefront would keep reserving a buffer it no longer needs.
        $this->devices->touchSynced((int) $device['id']);

        return [
            'cursor' => (string) $latest,
            'full_resync_required' => $fullResync,
            'catalog_changes' => $catalogChanges,
            'catalog' => $this->sync->catalog(),
            'pack_units' => $this->sync->packUnits(),
            'prices' => $this->sync->prices(),
            'groups' => $this->sync->groups(),
            'customer_prices' => $fullResync || $customers !== [] ? $this->sync->customerPrices() : [],
            'stock_levels' => $stockLevels,
            'customer_changes' => $customerRows,
            'order_changes' => $orderRows,
            'settings' => [
                'currency' => 'NGN',
                'location_id' => $locationId,
                'location_name' => (string) ($device['location_name'] ?? ''),
                'device_name' => (string) $device['name'],
                ...$settings,
            ],
            'server_time' => $this->clock->nowString(),
        ];
    }

    /**
     * @param array<string,mixed> $device
     * @param array<string,mixed> $batch
     * @return array<string,mixed>
     */
    public function push(array $device, array $batch): array
    {
        $batchUuid = trim((string) ($batch['batch_uuid'] ?? ''));
        if ($batchUuid === '' || mb_strlen($batchUuid) > 36) {
            throw new ValidationFailed('A sync batch needs a batch UUID.', ['batch_uuid' => 'Required, at most 36 characters.']);
        }

        $stored = $this->db->first(
            sprintf('SELECT * FROM %ssync_batches WHERE batch_uuid = ?', $this->db->prefix()),
            [$batchUuid]
        );

        if ($stored !== null) {
            return [
                'batch_uuid' => $batchUuid,
                'replayed' => true,
                'results' => json_decode((string) $stored['result'], true) ?: [],
                'stock_levels' => $this->sync->levels((int) $device['location_id']),
            ];
        }

        $operations = $batch['operations'] ?? [];
        if (!is_array($operations) || $operations === []) {
            throw new ValidationFailed('A sync batch needs at least one operation.', ['operations' => 'Required.']);
        }

        usort($operations, static function (array $a, array $b): int {
            return strcmp((string) ($a['client_created_at'] ?? ''), (string) ($b['client_created_at'] ?? ''));
        });

        $results = [];
        $affected = [];
        $deviceActor = Actor::device((int) $device['id']);

        foreach ($operations as $operation) {
            if (!is_array($operation)) {
                $results[] = [
                    'client_uuid' => null,
                    'status' => 'rejected',
                    'order_number' => null,
                    'warnings' => [],
                    'error' => 'The operation is not an object.',
                ];
                continue;
            }

            $clientUuid = (string) ($operation['client_uuid'] ?? '');
            $cashierId = (int) ($operation['cashier_user_id'] ?? 0);
            $actor = $cashierId > 0 ? Actor::staff($cashierId) : $deviceActor;

            try {
                $applied = $this->applyOperation($operation, $device, $actor);
                $results[] = [
                    'client_uuid' => $clientUuid,
                    'op' => (string) ($operation['op'] ?? ''),
                    'status' => !empty($applied['duplicate']) ? 'duplicate' : 'applied',
                    'order_number' => $applied['order_number'] ?? null,
                    'warnings' => $applied['warnings'] ?? [],
                    'error' => null,
                ];

                foreach ($applied['variant_ids'] ?? [] as $variantId) {
                    $affected[(int) $variantId] = true;
                }
            } catch (StoreKeeperException $error) {
                $results[] = [
                    'client_uuid' => $clientUuid,
                    'op' => (string) ($operation['op'] ?? ''),
                    'status' => 'rejected',
                    'order_number' => null,
                    'warnings' => [],
                    'error' => $error->getMessage(),
                    'code' => $error->errorCode(),
                ];
            }
        }

        $this->db->insert($this->db->prefix() . 'sync_batches', [
            'device_id' => (int) $device['id'],
            'batch_uuid' => $batchUuid,
            'item_count' => count($operations),
            'result' => json_encode($results, JSON_THROW_ON_ERROR),
            'received_at' => $this->clock->nowString(),
        ]);

        $this->devices->touchSynced((int) $device['id']);

        $levels = $this->sync->levels((int) $device['location_id']);
        if ($affected !== []) {
            $levels = array_values(array_filter(
                $levels,
                static fn (array $level): bool => isset($affected[(int) $level['variant_id']])
            ));
        }

        $this->audit->log($deviceActor, 'sync.pushed', 'device', (int) $device['id'], null, [
            'batch_uuid' => $batchUuid,
            'operations' => count($operations),
            'rejected' => count(array_filter($results, static fn (array $r): bool => $r['status'] === 'rejected')),
        ]);

        return [
            'batch_uuid' => $batchUuid,
            'replayed' => false,
            'results' => $results,
            'stock_levels' => $levels,
        ];
    }

    /**
     * @param array<string,mixed> $operation
     * @param array<string,mixed> $device
     * @return array<string,mixed>
     */
    private function applyOperation(array $operation, array $device, Actor $actor): array
    {
        $op = (string) ($operation['op'] ?? '');

        return match ($op) {
            'counter_sale' => $this->applySale($operation, $device, $actor),
            'counter_return' => $this->applyReturn($operation, $device, $actor),
            'cash_up' => $this->applyCashUp($operation, $device, $actor),
            'customer_create' => $this->applyCustomer($operation, $actor),
            default => throw new ValidationFailed('Unknown sync operation.', ['op' => 'counter_sale, counter_return, cash_up or customer_create.']),
        };
    }

    /** @return array<string,mixed> */
    private function applySale(array $operation, array $device, Actor $actor): array
    {
        $result = $this->counter->sale([
            'client_uuid' => (string) ($operation['client_uuid'] ?? ''),
            'cashier_user_id' => (int) ($operation['cashier_user_id'] ?? 0),
            'customer_id' => $operation['customer_id'] ?? null,
            'discount_kobo' => (int) ($operation['discount_kobo'] ?? 0),
            'client_created_at' => $operation['client_created_at'] ?? null,
            'note' => $operation['note'] ?? null,
            'lines' => $operation['lines'] ?? [],
            'payments' => $operation['payments'] ?? [],
        ], $device, $actor);

        return [
            'duplicate' => (bool) $result['duplicate'],
            'order_number' => (string) ($result['order']['number'] ?? ''),
            'warnings' => $result['warnings'],
            'variant_ids' => array_map(static fn (array $item): int => (int) $item['variant_id'], $result['items']),
        ];
    }

    /** @return array<string,mixed> */
    private function applyReturn(array $operation, array $device, Actor $actor): array
    {
        $result = $this->counter->counterReturn([
            'client_uuid' => (string) ($operation['client_uuid'] ?? ''),
            'order_uuid' => $operation['order_uuid'] ?? null,
            'order_number' => $operation['order_number'] ?? null,
            'lines' => $operation['lines'] ?? [],
            'restock' => $operation['restock'] ?? true,
            'reason' => $operation['reason'] ?? '',
            'method' => $operation['method'] ?? 'cash',
        ], $device, $actor);

        return [
            'duplicate' => (bool) $result['duplicate'],
            'order_number' => (string) ($result['order']['number'] ?? ''),
            'warnings' => [],
            'variant_ids' => [],
        ];
    }

    /** @return array<string,mixed> */
    private function applyCashUp(array $operation, array $device, Actor $actor): array
    {
        $this->counter->cashUp([
            'cashier_user_id' => (int) ($operation['cashier_user_id'] ?? 0),
            'business_date' => $operation['business_date'] ?? null,
            'opening_float_kobo' => (int) ($operation['opening_float_kobo'] ?? 0),
            'counted_cash_kobo' => (int) ($operation['counted_cash_kobo'] ?? 0),
            'note' => $operation['note'] ?? null,
        ], $device, $actor);

        return ['duplicate' => false, 'order_number' => null, 'warnings' => [], 'variant_ids' => []];
    }

    /** @return array<string,mixed> */
    private function applyCustomer(array $operation, Actor $actor): array
    {
        $phone = (string) ($operation['phone'] ?? '');
        $existing = $this->customerRepository->customerByPhone(\StoreKeeper\Commerce\Core\Phone::normalise($phone));

        if ($existing !== null) {
            return [
                'duplicate' => true,
                'order_number' => null,
                'warnings' => [],
                'variant_ids' => [],
                'customer_uuid' => (string) $existing['uuid'],
            ];
        }

        $customer = $this->customers->create([
            'name' => (string) ($operation['name'] ?? ''),
            'business_name' => $operation['business_name'] ?? null,
            'phone' => $phone,
            'email' => $operation['email'] ?? null,
            'group_id' => $operation['group_id'] ?? null,
        ], $actor, 'pos');

        return [
            'duplicate' => false,
            'order_number' => null,
            'warnings' => [],
            'variant_ids' => [],
            'customer_uuid' => (string) $customer['uuid'],
        ];
    }
}
