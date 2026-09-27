<?php
declare(strict_types=1);

namespace CargoVelo\Modules\Access;

use CargoVelo\Domain\Actor;
use CargoVelo\Domain\Role;
use CargoVelo\Modules\Courier\CourierRepository;

/** Builds the Actor for the current WordPress user. Capabilities decide the role; meta decides the scope. */
final class AccessContext
{
    public function __construct(private readonly CourierRepository $couriers)
    {
    }

    public function current(): Actor
    {
        $userId = get_current_user_id();
        if ($userId <= 0) {
            return Actor::guest();
        }
        $user = get_userdata($userId);
        $name = $user ? (string) $user->display_name : 'Gebruiker';

        if (user_can($userId, Roles::CAP_MANAGE)) {
            return new Actor($userId, $name, Role::Admin);
        }
        if (user_can($userId, Roles::CAP_DISPATCH)) {
            return new Actor($userId, $name, Role::Dispatcher);
        }
        if (user_can($userId, Roles::CAP_COURIER)) {
            $courier = $this->couriers->findByUser($userId);
            return new Actor($userId, $name, Role::Courier, null, $courier?->id, $courier?->hub);
        }
        if (user_can($userId, Roles::CAP_BOOK)) {
            $customerId = (int) get_user_meta($userId, Roles::META_CUSTOMER_ID, true);
            return new Actor($userId, $name, Role::Customer, $customerId > 0 ? $customerId : null);
        }
        return new Actor($userId, $name, Role::Guest);
    }
}
