<?php
/**
 * Service registration for cargovelo-core. Boot order is array order.
 * Services register hooks in their constructor; handlers declare REST routes in theirs.
 */
declare(strict_types=1);

return [
    'bindings' => [
        \CargoVelo\Modules\Notification\MailerInterface::class => \CargoVelo\Modules\Notification\WpMailer::class,
    ],
    'services' => [
        \CargoVelo\Modules\Settings\SettingsService::class,
        \CargoVelo\Modules\Shipment\ShipmentService::class,
        \CargoVelo\Modules\Notification\NotificationService::class,
        \CargoVelo\Admin\AdminPage::class,
        \CargoVelo\Frontend\Shortcodes::class,
    ],
    'handlers' => [
        \CargoVelo\Handlers\PublicHandler::class,
        \CargoVelo\Handlers\AppHandler::class,
        \CargoVelo\Handlers\OpsHandler::class,
        \CargoVelo\Handlers\PortalHandler::class,
        \CargoVelo\Handlers\CourierHandler::class,
    ],
];
