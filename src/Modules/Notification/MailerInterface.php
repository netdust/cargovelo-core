<?php
declare(strict_types=1);

namespace CargoVelo\Modules\Notification;

interface MailerInterface
{
    public function send(string $to, string $subject, string $html): bool;
}
