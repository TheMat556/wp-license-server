<?php
/**
 * License entity (readonly value object).
 *
 * @package WpLicenseServer\Models
 */

declare(strict_types=1);

namespace WpLicenseServer\Models;

final class License implements \JsonSerializable {

    public function __construct(
        public readonly int $id,
        /** @internal Never serialize, log, or expose in REST responses */
        public readonly string $license_key,
        public readonly string $key_prefix,
        public readonly string $customer_name,
        public readonly string $customer_email,
        public readonly string $role,
        public readonly string $tier,
        public readonly string $status,
        public readonly int $max_activations,
        public readonly string $payment_interval,
        public readonly bool $auto_renewal,
        public readonly ?string $notes,
        public readonly int $key_version,
        public readonly ?string $previous_key_encrypted,
        public readonly ?string $previous_key_prefix,
        public readonly ?string $rotation_at,
        public readonly string $created_at,
        public readonly string $valid_until,
        public readonly string $updated_at,
    ) {}

    public static function from_row( object $row ): static {
        return new static(
            id:                     (int) $row->id,
            license_key:            $row->license_key,
            key_prefix:             $row->key_prefix,
            customer_name:          $row->customer_name,
            customer_email:         $row->customer_email,
            role:                   $row->role,
            tier:                   $row->tier,
            status:                 $row->status,
            max_activations:        (int) $row->max_activations,
            payment_interval:       $row->payment_interval,
            auto_renewal:           (bool) $row->auto_renewal,
            notes:                  $row->notes,
            key_version:            (int) ( $row->key_version ?? 1 ),
            previous_key_encrypted: $row->previous_key_encrypted ?? null,
            previous_key_prefix:    $row->previous_key_prefix ?? null,
            rotation_at:            $row->rotation_at ?? null,
            created_at:             $row->created_at,
            valid_until:            $row->valid_until,
            updated_at:             $row->updated_at,
        );
    }

    public function __debugInfo(): array {
        $data = $this->jsonSerialize();
        $data['license_key'] = '***REDACTED***';
        return $data;
    }

    /** @return array<string, mixed> */
    public function jsonSerialize(): array {
        return [
            'id'                     => $this->id,
            'key_prefix'             => $this->key_prefix,
            'customer_name'          => $this->customer_name,
            'customer_email'         => $this->customer_email,
            'role'                   => $this->role,
            'tier'                   => $this->tier,
            'status'                 => $this->status,
            'max_activations'        => $this->max_activations,
            'payment_interval'       => $this->payment_interval,
            'auto_renewal'           => $this->auto_renewal,
            'notes'                  => $this->notes,
            'key_version'            => $this->key_version,
            'previous_key_encrypted' => $this->previous_key_encrypted,
            'previous_key_prefix'    => $this->previous_key_prefix,
            'rotation_at'            => $this->rotation_at,
            'created_at'             => $this->created_at,
            'valid_until'            => $this->valid_until,
            'updated_at'             => $this->updated_at,
        ];
    }
}
