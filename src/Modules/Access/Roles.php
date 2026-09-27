<?php
declare(strict_types=1);

namespace CargoVelo\Modules\Access;

/**
 * Three roles, four capabilities. Route floors consume the capabilities; services scope by Actor.
 *
 *  cargovelo_manage   settings, price lists, customers, couriers      (administrator)
 *  cargovelo_dispatch ops desk: every shipment, assign, override      (administrator, cv_dispatcher)
 *  cargovelo_courier  courier app: own stops, proof of delivery       (cv_courier)
 *  cargovelo_book     customer portal: own customer's shipments       (cv_customer, administrator)
 */
final class Roles
{
    public const VERSION = 1;
    public const OPTION = 'cargovelo_roles_version';

    public const CAP_MANAGE = 'cargovelo_manage';
    public const CAP_DISPATCH = 'cargovelo_dispatch';
    public const CAP_COURIER = 'cargovelo_courier';
    public const CAP_BOOK = 'cargovelo_book';

    public const ROLE_DISPATCHER = 'cv_dispatcher';
    public const ROLE_COURIER = 'cv_courier';
    public const ROLE_CUSTOMER = 'cv_customer';

    public const META_CUSTOMER_ID = '_cargovelo_customer_id';

    public static function ensure(): void
    {
        if ((int) get_option(self::OPTION, 0) >= self::VERSION) {
            return;
        }
        remove_role(self::ROLE_DISPATCHER);
        remove_role(self::ROLE_COURIER);
        remove_role(self::ROLE_CUSTOMER);

        add_role(self::ROLE_DISPATCHER, 'Cargo Velo dispatcher', ['read' => true, self::CAP_DISPATCH => true]);
        add_role(self::ROLE_COURIER, 'Cargo Velo koerier', ['read' => true, self::CAP_COURIER => true]);
        add_role(self::ROLE_CUSTOMER, 'Cargo Velo klant', ['read' => true, self::CAP_BOOK => true]);

        $admin = get_role('administrator');
        if ($admin) {
            $admin->add_cap(self::CAP_MANAGE);
            $admin->add_cap(self::CAP_DISPATCH);
            $admin->add_cap(self::CAP_BOOK);
        }
        update_option(self::OPTION, self::VERSION);
    }
}
