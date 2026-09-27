<?php
declare(strict_types=1);

namespace CargoVelo\Modules\Notification;

/** wp_mail with an HTML content type. Fluent SMTP (or any SMTP plugin) takes it from there. */
final class WpMailer implements MailerInterface
{
    public function send(string $to, string $subject, string $html): bool
    {
        if ($to === '' || !filter_var($to, FILTER_VALIDATE_EMAIL)) {
            return false;
        }
        return (bool) wp_mail($to, $subject, $html, ['Content-Type: text/html; charset=UTF-8']);
    }
}
