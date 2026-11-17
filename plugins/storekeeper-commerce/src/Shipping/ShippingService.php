<?php

declare(strict_types=1);

namespace StoreKeeper\Commerce\Shipping;

use StoreKeeper\Commerce\Core\Audit\Actor;
use StoreKeeper\Commerce\Core\Audit\AuditLogger;
use StoreKeeper\Commerce\Core\Exception\NotFound;
use StoreKeeper\Commerce\Core\Exception\ValidationFailed;
use StoreKeeper\Commerce\Core\Money;

/**
 * Delivery zones and the methods they offer (§13.3).
 *
 * A zone covers whole states and/or single LGAs. Zones are an ordered list and
 * the first one that matches the address wins; a zone with no areas is the
 * catch-all and is normally sorted last. A method's fee is computed here from
 * its type and config, never from the browser: the checkout re-quotes the
 * chosen method on submit and stores that amount on the order.
 *
 * `weight_band` fees use the cart's total grams. A product with no recorded
 * weight contributes zero, so a merchant who has not filled weights in gets
 * the first band rather than a silent free delivery.
 */
final class ShippingService
{
    public const FLAT = 'flat';
    public const WEIGHT_BAND = 'weight_band';
    public const FREE_OVER = 'free_over';
    public const PICKUP = 'pickup';
    public const QUOTE_LATER = 'quote_later';

    public const TYPES = [self::FLAT, self::WEIGHT_BAND, self::FREE_OVER, self::PICKUP, self::QUOTE_LATER];

    public function __construct(
        private readonly ShippingRepository $shipping,
        private readonly AuditLogger $audit,
    ) {
    }

    /** @return list<array<string,mixed>> zones with their areas */
    public function zones(): array
    {
        $zones = $this->shipping->zones();

        foreach ($zones as $index => $zone) {
            $zones[$index]['areas'] = $this->shipping->areas((int) $zone['id']);
        }

        return $zones;
    }

    /** @return list<array<string,mixed>> */
    public function methods(bool $activeOnly = true): array
    {
        $methods = $this->shipping->methods($activeOnly);

        foreach ($methods as $index => $method) {
            $methods[$index]['config'] = self::config($method);
            $methods[$index]['group_ids'] = self::groupIds($method);
        }

        return $methods;
    }

    public function hasMethods(): bool
    {
        return $this->shipping->methodCount() > 0;
    }

    /**
     * @param array<string,mixed> $input name, sort_order, areas[state,lga]
     * @return array<string,mixed>
     */
    public function createZone(array $input, Actor $actor): array
    {
        $name = trim((string) ($input['name'] ?? ''));

        if ($name === '') {
            throw new ValidationFailed('A shipping zone needs a name.', ['name' => 'Required.']);
        }

        $areas = [];

        foreach (($input['areas'] ?? []) as $area) {
            $state = trim((string) ($area['state'] ?? ''));

            if ($state === '') {
                throw new ValidationFailed('Every zone area needs a state.', ['areas' => 'State required.']);
            }

            $lga = trim((string) ($area['lga'] ?? ''));
            $areas[] = ['state' => $state, 'lga' => $lga === '' ? null : $lga];
        }

        $sortOrder = isset($input['sort_order'])
            ? (int) $input['sort_order']
            : $this->shipping->maxZoneSortOrder() + 10;

        $zoneId = $this->shipping->createZone($name, $areas, $sortOrder);
        $zone = $this->shipping->zone($zoneId) ?? [];
        $zone['areas'] = $this->shipping->areas($zoneId);

        $this->audit->log($actor, 'shipping.zone_created', 'shipping_zone', $zoneId, null, [
            'name' => $name,
            'areas' => $areas,
        ]);

        return $zone;
    }

    /**
     * @param array<string,mixed> $input zone_id, name, type, config, group_ids, is_active, sort_order
     * @return array<string,mixed>
     */
    public function createMethod(array $input, Actor $actor): array
    {
        $data = $this->methodData($input, null, $actor);
        $methodId = $this->shipping->createMethod($data);
        $method = $this->shipping->method($methodId) ?? [];

        $this->audit->log($actor, 'shipping.method_created', 'shipping_method', $methodId, null, [
            'name' => $data['name'],
            'type' => $data['type'],
            'zone_id' => $data['zone_id'],
        ]);

        return $method;
    }

    /**
     * @param array<string,mixed> $changes
     * @return array<string,mixed>
     */
    public function updateMethod(int $methodId, array $changes, Actor $actor): array
    {
        $existing = $this->shipping->method($methodId);

        if ($existing === null) {
            throw new NotFound('shipping method', $methodId);
        }

        $before = [
            'zone_id' => $existing['zone_id'] === null ? null : (int) $existing['zone_id'],
            'name' => (string) $existing['name'],
            'type' => (string) $existing['type'],
            'config' => self::config($existing),
            'group_ids' => self::groupIds($existing),
            'is_active' => (int) $existing['is_active'] === 1,
            'sort_order' => (int) $existing['sort_order'],
        ];

        $data = $this->methodData([...$before, ...$changes], $methodId, $actor);
        $this->shipping->updateMethod($methodId, $data);

        $method = $this->shipping->method($methodId) ?? [];

        $this->audit->log($actor, 'shipping.method_updated', 'shipping_method', $methodId, $before, $data);

        return $method;
    }

    /**
     * The first zone whose areas cover the address; a zone without areas is the
     * catch-all (§13.3).
     *
     * @return array<string,mixed>|null
     */
    public function resolveZone(?string $state, ?string $lga): ?array
    {
        $state = self::normalise($state);
        $lga = self::normalise($lga);

        if ($state === null) {
            return null;
        }

        $fallback = null;

        foreach ($this->shipping->zones() as $zone) {
            $areas = $this->shipping->areas((int) $zone['id']);

            if ($areas === []) {
                $fallback ??= $zone;
                continue;
            }

            foreach ($areas as $area) {
                if (self::normalise((string) $area['state']) !== $state) {
                    continue;
                }

                $areaLga = self::normalise($area['lga']);

                if ($areaLga === null || $areaLga === $lga) {
                    $zone['areas'] = $areas;

                    return $zone;
                }
            }
        }

        return $fallback;
    }

    /**
     * The fee a method charges for an order (§13.3).
     *
     * @param array<string,mixed> $method
     * @return array{amount_kobo:int,quoted_later:bool,description:string}
     */
    public function quote(array $method, int $subtotalKobo, int $weightGrams): array
    {
        $type = (string) ($method['type'] ?? self::FLAT);
        $config = is_array($method['config'] ?? null) ? $method['config'] : self::config($method);

        return match ($type) {
            self::FLAT => [
                'amount_kobo' => max(0, (int) ($config['amount_kobo'] ?? 0)),
                'quoted_later' => false,
                'description' => 'Fixed delivery fee.',
            ],
            self::FREE_OVER => [
                'amount_kobo' => $subtotalKobo >= (int) ($config['threshold_kobo'] ?? 0)
                    ? 0
                    : max(0, (int) ($config['else_amount_kobo'] ?? 0)),
                'quoted_later' => false,
                'description' => 'Free over ' . (new Money(max(0, (int) ($config['threshold_kobo'] ?? 0))))->format() . '.',
            ],
            self::WEIGHT_BAND => [
                'amount_kobo' => $this->weightFee($config, max(0, $weightGrams)),
                'quoted_later' => false,
                'description' => 'Fee depends on the weight of your order.',
            ],
            self::PICKUP => [
                'amount_kobo' => 0,
                'quoted_later' => false,
                'description' => trim((string) ($config['instructions'] ?? '')) !== ''
                    ? (string) $config['instructions']
                    : 'Collect from the shop.',
            ],
            self::QUOTE_LATER => [
                'amount_kobo' => 0,
                'quoted_later' => true,
                'description' => trim((string) ($config['message'] ?? '')) !== ''
                    ? (string) $config['message']
                    : 'The store will confirm the delivery fee.',
            ],
            default => throw new ValidationFailed('Unknown shipping method type.', ['type' => $type]),
        };
    }

    /**
     * Every method the shopper may choose. Pickup methods are always offered;
     * delivery methods only once the address resolves to their zone.
     *
     * @return list<array<string,mixed>>
     */
    public function options(
        ?string $state,
        ?string $lga,
        ?int $groupId,
        int $subtotalKobo,
        int $weightGrams,
    ): array {
        $zone = $this->resolveZone($state, $lga);
        $options = [];

        foreach ($this->shipping->methods(true) as $method) {
            if (!self::allowedForGroup($method, $groupId)) {
                continue;
            }

            $zoneId = $method['zone_id'] === null ? null : (int) $method['zone_id'];

            if ($zoneId === null) {
                if ((string) $method['type'] !== self::PICKUP) {
                    continue;
                }
            } elseif ($zone === null || (int) $zone['id'] !== $zoneId) {
                continue;
            }

            $quote = $this->quote($method, $subtotalKobo, $weightGrams);

            $options[] = [
                'id' => 'method:' . (int) $method['id'],
                'method_id' => (int) $method['id'],
                'label' => (string) $method['name'],
                'amount_kobo' => $quote['amount_kobo'],
                'description' => $quote['description'],
                'type' => (string) $method['type'],
                'pickup' => (string) $method['type'] === self::PICKUP,
                'quoted_later' => $quote['quoted_later'],
                'zone_id' => $zoneId,
            ];
        }

        return $options;
    }

    /**
     * A fresh store needs one way to receive an order. This creates the
     * catch-all zone and a free pickup method; delivery fees are the merchant's
     * to set because only they know their costs.
     *
     * @return array{zone_id:?int,method_id:?int}
     */
    public function seedDefaults(Actor $actor): array
    {
        $zoneId = null;
        $methodId = null;

        if ($this->shipping->zoneCount() === 0) {
            $zone = $this->createZone([
                'name' => 'Rest of Nigeria',
                'sort_order' => 100,
                'areas' => [],
            ], $actor);
            $zoneId = (int) $zone['id'];
        }

        if ($this->shipping->methodCount() === 0) {
            $method = $this->createMethod([
                'zone_id' => null,
                'name' => 'Pick up at the shop',
                'type' => self::PICKUP,
                'config' => ['instructions' => 'We will let you know when your order is ready.'],
                'sort_order' => 0,
            ], $actor);
            $methodId = (int) $method['id'];
        }

        return ['zone_id' => $zoneId, 'method_id' => $methodId];
    }

    public static function typeLabel(string $type): string
    {
        return match ($type) {
            self::FLAT => 'Flat fee',
            self::WEIGHT_BAND => 'By weight',
            self::FREE_OVER => 'Free over a threshold',
            self::PICKUP => 'Pickup',
            self::QUOTE_LATER => 'Quote later',
            default => ucfirst(str_replace('_', ' ', $type)),
        };
    }

    /** @return array<string,mixed> */
    public static function config(array $method): array
    {
        $config = $method['config'] ?? [];

        if (is_string($config)) {
            $decoded = json_decode($config, true);
            $config = is_array($decoded) ? $decoded : [];
        }

        return is_array($config) ? $config : [];
    }

    /** @return list<int> */
    public static function groupIds(array $method): array
    {
        $groups = $method['group_ids'] ?? null;

        if (is_string($groups)) {
            $decoded = json_decode($groups, true);
            $groups = is_array($decoded) ? $decoded : [];
        }

        if (!is_array($groups)) {
            return [];
        }

        return array_values(array_map('intval', $groups));
    }

    /** @param array<string,mixed> $config */
    private function weightFee(array $config, int $weightGrams): int
    {
        $bands = is_array($config['bands'] ?? null) ? $config['bands'] : [];

        if ($bands === []) {
            return 0;
        }

        usort($bands, static fn (array $a, array $b): int => (int) $a['max_grams'] <=> (int) $b['max_grams']);

        $last = null;

        foreach ($bands as $band) {
            $last = $band;

            if ($weightGrams <= (int) $band['max_grams']) {
                return max(0, (int) $band['amount_kobo']);
            }
        }

        $overPerKg = max(0, (int) ($config['over_per_kg_kobo'] ?? 0));
        $overGrams = max(0, $weightGrams - (int) $last['max_grams']);
        $overKg = (int) ceil($overGrams / 1000);

        return max(0, (int) $last['amount_kobo']) + ($overKg * $overPerKg);
    }

    /** @param array<string,mixed> $method */
    private static function allowedForGroup(array $method, ?int $groupId): bool
    {
        $groups = self::groupIds($method);

        if ($groups === []) {
            return true;
        }

        return $groupId !== null && in_array($groupId, $groups, true);
    }

    /**
     * @param array<string,mixed> $input
     * @return array<string,mixed>
     */
    private function methodData(array $input, ?int $methodId, Actor $actor): array
    {
        $name = trim((string) ($input['name'] ?? ''));

        if ($name === '') {
            throw new ValidationFailed('A shipping method needs a name.', ['name' => 'Required.']);
        }

        $type = (string) ($input['type'] ?? '');

        if (!in_array($type, self::TYPES, true)) {
            throw new ValidationFailed('Unknown shipping method type.', ['type' => implode(', ', self::TYPES)]);
        }

        $zoneId = isset($input['zone_id']) && (int) $input['zone_id'] > 0 ? (int) $input['zone_id'] : null;

        if ($type === self::PICKUP) {
            $zoneId = null;
        } elseif ($zoneId === null) {
            throw new ValidationFailed('A delivery method belongs to a zone.', ['zone_id' => 'Required.']);
        } elseif ($this->shipping->zone($zoneId) === null) {
            throw new NotFound('shipping zone', $zoneId);
        }

        $config = $input['config'] ?? [];
        if (!is_array($config)) {
            throw new ValidationFailed('The method configuration must be an object.', ['config' => 'Invalid.']);
        }

        $this->validateConfig($type, $config);

        $groups = $input['group_ids'] ?? [];
        if (!is_array($groups)) {
            throw new ValidationFailed('Restricted groups must be a list.', ['group_ids' => 'Invalid.']);
        }

        $groups = array_values(array_filter(array_map('intval', $groups), static fn (int $id): bool => $id > 0));

        $data = [
            'zone_id' => $zoneId,
            'name' => $name,
            'type' => $type,
            'config' => json_encode($config, JSON_THROW_ON_ERROR),
            'group_ids' => $groups === [] ? null : json_encode($groups, JSON_THROW_ON_ERROR),
            'is_active' => array_key_exists('is_active', $input) ? (bool) $input['is_active'] : true,
            'sort_order' => (int) ($input['sort_order'] ?? 0),
        ];

        $data['is_active'] = $data['is_active'] ? 1 : 0;

        return $data;
    }

    /** @param array<string,mixed> $config */
    private function validateConfig(string $type, array $config): void
    {
        $error = static fn (string $field, string $why): ValidationFailed => new ValidationFailed(
            'That shipping method configuration is incomplete.',
            ['config.' . $field => $why]
        );

        match ($type) {
            self::FLAT => (int) ($config['amount_kobo'] ?? -1) < 0
                ? throw $error('amount_kobo', 'Required.')
                : null,
            self::FREE_OVER => (int) ($config['threshold_kobo'] ?? 0) <= 0
                ? throw $error('threshold_kobo', 'Must be more than zero.')
                : null,
            self::WEIGHT_BAND => $this->validateBands($config)
                ? null
                : throw $error('bands', 'At least one band is required.'),
            self::PICKUP, self::QUOTE_LATER => null,
            default => throw new ValidationFailed('Unknown shipping method type.'),
        };
    }

    /** @param array<string,mixed> $config */
    private function validateBands(array $config): bool
    {
        $bands = $config['bands'] ?? null;

        if (!is_array($bands) || $bands === []) {
            return false;
        }

        foreach ($bands as $band) {
            if (!is_array($band) || (int) ($band['max_grams'] ?? 0) <= 0 || (int) ($band['amount_kobo'] ?? -1) < 0) {
                return false;
            }
        }

        return true;
    }

    private static function normalise(mixed $value): ?string
    {
        $text = strtolower(trim((string) ($value ?? '')));

        return $text === '' ? null : preg_replace('/\s+/', ' ', $text);
    }
}
