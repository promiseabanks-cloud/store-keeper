<?php

declare(strict_types=1);

namespace StoreKeeper\Platform\Billing;

use StoreKeeper\Commerce\Core\Clock;
use StoreKeeper\Commerce\Core\Database\Connection;
use StoreKeeper\Commerce\Core\Dates;
use StoreKeeper\Commerce\Core\Exception\NotFound;
use StoreKeeper\Commerce\Core\Exception\ValidationFailed;
use StoreKeeper\Commerce\Core\Uuid;
use StoreKeeper\Platform\Audit\PlatformAudit;
use StoreKeeper\Platform\Tenants\TenantRepository;

/**
 * Platform invoices, payments and dunning (§17.4).
 *
 * The Owner's Paystack account is the provider. This service records invoices
 * and applies verified webhook events; it never charges a card itself, and no
 * amount is invented — the Owner sets it on the invoice.
 */
final class BillingService
{
    public const KINDS = ['setup', 'subscription', 'addon', 'service', 'message_overage'];

    public function __construct(
        private readonly Connection $db,
        private readonly TenantRepository $tenants,
        private readonly PlatformAudit $audit,
        private readonly Clock $clock,
        private readonly int $graceDays = 7,
        private readonly int $suspendAfterGraceDays = 14,
    ) {
    }

    /**
     * @return array<string,mixed>
     */
    public function createInvoice(int $tenantId, string $kind, string $description, int $amountKobo, ?string $dueAt, int $actorId, ?string $providerReference = null): array
    {
        if ($this->tenants->find($tenantId) === null) {
            throw new NotFound('tenant', $tenantId);
        }

        if (!in_array($kind, self::KINDS, true)) {
            throw new ValidationFailed('Unknown invoice kind.', ['kind' => implode(', ', self::KINDS)]);
        }

        if ($amountKobo < 0) {
            throw new ValidationFailed('An invoice cannot be negative.', ['amount_kobo' => 'Zero or more.']);
        }

        $id = $this->db->insert($this->db->networkPrefix() . 'platform_invoices', [
            'uuid' => Uuid::v4(),
            'tenant_id' => $tenantId,
            'kind' => $kind,
            'description' => $description,
            'amount_kobo' => $amountKobo,
            'status' => 'open',
            'provider_reference' => $providerReference,
            'due_at' => $dueAt,
            'paid_at' => null,
            'created_at' => $this->clock->nowString(),
        ]);

        $this->audit->log($actorId, 'invoice.created', $tenantId, $id, null, [
            'kind' => $kind,
            'amount_kobo' => $amountKobo,
        ]);

        return $this->invoice($id) ?? [];
    }

    /** @return array<string,mixed>|null */
    public function invoice(int $invoiceId): ?array
    {
        $row = $this->db->first(
            sprintf('SELECT * FROM %splatform_invoices WHERE id = ?', $this->db->networkPrefix()),
            [$invoiceId]
        );

        return $row === null ? null : $this->hydrateInvoice($row);
    }

    /** @return list<array<string,mixed>> */
    public function invoices(int $tenantId): array
    {
        return array_map([$this, 'hydrateInvoice'], $this->db->select(
            sprintf('SELECT * FROM %splatform_invoices WHERE tenant_id = ? ORDER BY created_at DESC, id DESC', $this->db->networkPrefix()),
            [$tenantId]
        ));
    }

    public function markPaid(int $invoiceId, int $actorId, ?string $provider = null): array
    {
        $invoice = $this->invoice($invoiceId);
        if ($invoice === null) {
            throw new NotFound('invoice', $invoiceId);
        }

        if ($invoice['status'] === 'paid') {
            return $invoice;
        }

        $now = $this->clock->nowString();
        $this->db->update($this->db->networkPrefix() . 'platform_invoices', [
            'status' => 'paid',
            'paid_at' => $now,
        ], ['id' => $invoiceId]);

        $this->tenants->update((int) $invoice['tenant_id'], [
            'status' => 'active',
            'grace_ends_at' => null,
        ]);

        $this->audit->log($actorId, 'invoice.paid', (int) $invoice['tenant_id'], $invoiceId, [
            'status' => $invoice['status'],
        ], ['status' => 'paid', 'provider' => $provider]);

        return $this->invoice($invoiceId) ?? [];
    }

    /**
     * Apply a verified Paystack event (§17.4). Unknown events are ignored and
     * reported, so the endpoint stays idempotent.
     *
     * @param array<string,mixed> $event
     * @return array{handled:bool,action:string}
     */
    public function applyPaystackEvent(array $event): array
    {
        $type = (string) ($event['event'] ?? '');
        $data = is_array($event['data'] ?? null) ? $event['data'] : [];

        if ($type === 'charge.success') {
            $reference = (string) ($data['reference'] ?? '');
            $invoice = $reference === '' ? null : $this->db->first(
                sprintf('SELECT * FROM %splatform_invoices WHERE provider_reference = ?', $this->db->networkPrefix()),
                [$reference]
            );

            if ($invoice === null) {
                return ['handled' => false, 'action' => 'invoice_not_found'];
            }

            $this->markPaid((int) $invoice['id'], 0, 'paystack');

            return ['handled' => true, 'action' => 'invoice_paid'];
        }

        if ($type === 'invoice.payment_failed' || $type === 'charge.failed') {
            $reference = (string) ($data['reference'] ?? ($data['invoice']['reference'] ?? ''));
            $invoice = $reference === '' ? null : $this->db->first(
                sprintf('SELECT * FROM %splatform_invoices WHERE provider_reference = ?', $this->db->networkPrefix()),
                [$reference]
            );

            if ($invoice === null) {
                return ['handled' => false, 'action' => 'invoice_not_found'];
            }

            $this->startDunning((int) $invoice['tenant_id']);

            return ['handled' => true, 'action' => 'dunning_started'];
        }

        if ($type === 'subscription.disable') {
            $tenantId = (int) ($data['metadata']['tenant_id'] ?? 0);
            if ($tenantId > 0) {
                $this->startDunning($tenantId);

                return ['handled' => true, 'action' => 'dunning_started'];
            }

            return ['handled' => false, 'action' => 'tenant_not_found'];
        }

        return ['handled' => false, 'action' => 'ignored'];
    }

    public function startDunning(int $tenantId): array
    {
        $tenant = $this->tenants->find($tenantId);
        if ($tenant === null) {
            throw new NotFound('tenant', $tenantId);
        }

        $this->tenants->update($tenantId, [
            'status' => 'past_due',
            'grace_ends_at' => Dates::addMinutes($this->clock->nowString(), $this->graceDays * 1440),
        ]);

        $this->audit->log(0, 'tenant.past_due', $tenantId, null, ['status' => $tenant['status']], [
            'status' => 'past_due',
            'grace_days' => $this->graceDays,
        ]);

        return $this->tenants->find($tenantId) ?? [];
    }

    /**
     * Move tenants along the dunning ladder: past_due → read_only after the
     * grace period, read_only → suspended after a further 14 days. Nothing is
     * ever deleted and exports stay available (§17.4).
     *
     * @return list<array{tenant_id:int,from:string,to:string}>
     */
    public function runDunning(): array
    {
        $now = $this->clock->nowString();
        $changes = [];

        foreach ($this->tenants->all('past_due') as $tenant) {
            if (($tenant['grace_ends_at'] ?? null) !== null && (string) $tenant['grace_ends_at'] <= $now) {
                $this->tenants->update((int) $tenant['id'], ['status' => 'read_only']);
                $changes[] = ['tenant_id' => (int) $tenant['id'], 'from' => 'past_due', 'to' => 'read_only'];
                $this->audit->log(0, 'tenant.read_only', (int) $tenant['id']);
            }
        }

        $suspendedBefore = Dates::addMinutes($now, -$this->suspendAfterGraceDays * 1440);

        foreach ($this->tenants->all('read_only') as $tenant) {
            if (($tenant['grace_ends_at'] ?? null) !== null && (string) $tenant['grace_ends_at'] <= $suspendedBefore) {
                $this->tenants->update((int) $tenant['id'], ['status' => 'suspended']);
                $changes[] = ['tenant_id' => (int) $tenant['id'], 'from' => 'read_only', 'to' => 'suspended'];
                $this->audit->log(0, 'tenant.suspended', (int) $tenant['id']);
            }
        }

        return $changes;
    }

    /**
     * @param array<string,mixed> $row
     * @return array<string,mixed>
     */
    private function hydrateInvoice(array $row): array
    {
        $row['id'] = (int) $row['id'];
        $row['tenant_id'] = (int) $row['tenant_id'];
        $row['amount_kobo'] = (int) $row['amount_kobo'];

        return $row;
    }
}
