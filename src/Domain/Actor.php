<?php
declare(strict_types=1);

namespace CargoVelo\Domain;

/**
 * Who is performing an operation. Services scope every read and write by this, never by
 * re-checking WordPress capabilities: the route floor already did that.
 */
final class Actor
{
    public function __construct(
        public readonly int $userId,
        public readonly string $name,
        public readonly Role $role,
        public readonly ?int $customerId = null,
        public readonly ?int $courierId = null,
        public readonly ?string $hub = null,
    ) {
    }

    public static function guest(string $name = 'Website'): self
    {
        return new self(0, $name, Role::Guest);
    }

    public static function system(): self
    {
        return new self(0, 'Systeem', Role::Admin);
    }

    public function isStaff(): bool
    {
        return $this->role->isStaff();
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'id' => $this->userId,
            'name' => $this->name,
            'role' => $this->role->value,
            'customer_id' => $this->customerId,
            'courier_id' => $this->courierId,
            'hub' => $this->hub,
        ];
    }
}
