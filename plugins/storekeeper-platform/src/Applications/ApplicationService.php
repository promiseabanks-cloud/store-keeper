<?php

declare(strict_types=1);

namespace StoreKeeper\Platform\Applications;

use StoreKeeper\Commerce\Core\Clock;
use StoreKeeper\Commerce\Core\Database\Connection;
use StoreKeeper\Commerce\Core\Exception\NotFound;
use StoreKeeper\Commerce\Core\Exception\ValidationFailed;
use StoreKeeper\Commerce\Core\Phone;
use StoreKeeper\Commerce\Core\Uuid;
use StoreKeeper\Platform\Audit\PlatformAudit;

/**
 * Merchant applications (§17.1): the form on the platform site, the Owner's
 * inbox, and the hand-off to provisioning.
 */
final class ApplicationService
{
    public const STATUSES = ['new', 'contacted', 'approved', 'rejected', 'converted'];

    public function __construct(
        private readonly Connection $db,
        private readonly PlatformAudit $audit,
        private readonly Clock $clock,
    ) {
    }

    /**
     * @param array<string,mixed> $input business_name, contact_name, phone, email, category, sells_to, approx_products, current_tools, instagram, message
     * @return array<string,mixed>
     */
    public function submit(array $input): array
    {
        $business = trim((string) ($input['business_name'] ?? ''));
        $contact = trim((string) ($input['contact_name'] ?? ''));

        if ($business === '') {
            throw new ValidationFailed('Tell us the business name.', ['business_name' => 'Required.']);
        }

        if ($contact === '') {
            throw new ValidationFailed('Tell us who to contact.', ['contact_name' => 'Required.']);
        }

        try {
            $phone = Phone::normalise((string) ($input['phone'] ?? ''));
        } catch (\Throwable) {
            throw new ValidationFailed('Use a Nigerian phone number.', ['phone' => 'Invalid number.']);
        }

        $sellsTo = (string) ($input['sells_to'] ?? 'both');
        if (!in_array($sellsTo, ['wholesale', 'retail', 'both'], true)) {
            throw new ValidationFailed('Choose who you sell to.', ['sells_to' => 'wholesale, retail or both.']);
        }

        $email = trim((string) ($input['email'] ?? ''));
        if ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            throw new ValidationFailed('That email address is not valid.', ['email' => 'Invalid.']);
        }

        $now = $this->clock->nowString();

        $id = $this->db->insert($this->db->networkPrefix() . 'applications', [
            'uuid' => Uuid::v4(),
            'business_name' => $business,
            'contact_name' => $contact,
            'phone_e164' => $phone,
            'email' => $email === '' ? null : $email,
            'category' => trim((string) ($input['category'] ?? '')) ?: null,
            'sells_to' => $sellsTo,
            'approx_products' => isset($input['approx_products']) ? max(0, (int) $input['approx_products']) : null,
            'current_tools' => trim((string) ($input['current_tools'] ?? '')) ?: null,
            'instagram' => trim((string) ($input['instagram'] ?? '')) ?: null,
            'message' => trim((string) ($input['message'] ?? '')) ?: null,
            'status' => 'new',
            'owner_notes' => null,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $this->audit->log(0, 'application.submitted', null, $id, null, [
            'business_name' => $business,
            'phone' => 'redacted',
        ]);

        return $this->find($id) ?? [];
    }

    /** @return array<string,mixed>|null */
    public function find(int $applicationId): ?array
    {
        $row = $this->db->first(
            sprintf('SELECT * FROM %sapplications WHERE id = ?', $this->db->networkPrefix()),
            [$applicationId]
        );

        return $row === null ? null : $this->hydrate($row);
    }

    /** @return list<array<string,mixed>> */
    public function all(?string $status = null, int $limit = 100): array
    {
        $sql = sprintf('SELECT * FROM %sapplications', $this->db->networkPrefix());
        $params = [];

        if ($status !== null && $status !== '') {
            $sql .= ' WHERE status = ?';
            $params[] = $status;
        }

        $sql .= ' ORDER BY created_at DESC, id DESC LIMIT ?';
        $params[] = $limit;

        return array_map([$this, 'hydrate'], $this->db->select($sql, $params));
    }

    public function updateStatus(int $applicationId, string $status, ?string $notes, int $actorId): array
    {
        $application = $this->find($applicationId);
        if ($application === null) {
            throw new NotFound('application', $applicationId);
        }

        if (!in_array($status, self::STATUSES, true)) {
            throw new ValidationFailed('Unknown application status.', ['status' => implode(', ', self::STATUSES)]);
        }

        $this->db->update($this->db->networkPrefix() . 'applications', [
            'status' => $status,
            'owner_notes' => $notes,
            'updated_at' => $this->clock->nowString(),
        ], ['id' => $applicationId]);

        $this->audit->log($actorId, 'application.' . $status, null, $applicationId, [
            'status' => $application['status'],
        ], ['status' => $status]);

        return $this->find($applicationId) ?? [];
    }

    /**
     * @param array<string,mixed> $row
     * @return array<string,mixed>
     */
    private function hydrate(array $row): array
    {
        $row['id'] = (int) $row['id'];

        return $row;
    }
}
