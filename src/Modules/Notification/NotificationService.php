<?php
declare(strict_types=1);

namespace CargoVelo\Modules\Notification;

use CargoVelo\Domain\ShipmentStatus;
use CargoVelo\Modules\Settings\SettingsService;
use CargoVelo\Modules\Tracking\TrackingService;

/**
 * Turns domain events into mails. Customer (booker) gets confirmation, delivered, failed.
 * Recipient gets "onderweg" and delivered. Dispatch gets every new website request.
 * Templates are simple HTML built here; override via the cargovelo/mail filter.
 */
final class NotificationService
{
    public function __construct(
        private readonly MailerInterface $mailer,
        private readonly TrackingService $tracking,
        private readonly SettingsService $settings,
    ) {
        $this->init();
    }

    private function init(): void
    {
        add_action('cargovelo/shipment/created', [$this, 'onCreated']);
        add_action('cargovelo/shipment/status_changed', [$this, 'onStatusChanged']);
    }

    /** @param array<string, mixed> $event */
    public function onCreated(array $event): void
    {
        $s = $event['shipment'];
        $track = $this->tracking->url($s['tracking_token']);
        $status = ShipmentStatus::from($s['status']);

        $subject = sprintf('%s: je zending %s is %s', 'Cargo Velo', $s['reference'], $status === ShipmentStatus::Confirmed ? 'bevestigd' : 'aangevraagd');
        $body = $this->layout($subject, [
            '<p>Bedankt! We hebben je aanvraag ontvangen.</p>',
            $this->summary($s),
            $status === ShipmentStatus::Requested ? '<p>Dispatch bekijkt je aanvraag en bevestigt zo snel mogelijk.</p>' : '<p>Je zending is ingepland.</p>',
            sprintf('<p><a href="%s">Volg je zending</a></p>', esc_url($track)),
        ]);
        $this->deliver('created', $s['contact_email'], $subject, $body, $s);

        if ($s['channel'] === 'web') {
            $dispatchSubject = sprintf('Nieuwe aanvraag %s (%s) via website', $s['reference'], $s['customer_name']);
            $this->deliver('dispatch_new', $this->settings->notifyEmail(), $dispatchSubject, $this->layout($dispatchSubject, [$this->summary($s, true)]), $s);
        }
    }

    /** @param array<string, mixed> $event */
    public function onStatusChanged(array $event): void
    {
        $s = $event['shipment'];
        $to = ShipmentStatus::from($event['to']);
        $track = $this->tracking->url($s['tracking_token']);
        $recipientEmail = (string) ($s['delivery']['email'] ?? '');

        switch ($to) {
            case ShipmentStatus::Confirmed:
                if ($event['from'] === 'requested') {
                    $subject = sprintf('Zending %s bevestigd', $s['reference']);
                    $this->deliver('confirmed', $s['contact_email'], $subject, $this->layout($subject, [$this->summary($s), sprintf('<p><a href="%s">Volg je zending</a></p>', esc_url($track))]), $s);
                }
                break;
            case ShipmentStatus::InTransit:
            case ShipmentStatus::PickedUp:
                if ($recipientEmail !== '' && $to === ShipmentStatus::PickedUp) {
                    $subject = sprintf('Je pakket van %s is onderweg', $s['customer_name']);
                    $this->deliver('recipient_in_transit', $recipientEmail, $subject, $this->layout($subject, [
                        '<p>Onze fietskoerier is onderweg met je pakket.</p>',
                        sprintf('<p><a href="%s">Volg de levering en geef instructies door</a></p>', esc_url($track)),
                    ]), $s);
                }
                break;
            case ShipmentStatus::Delivered:
                $subject = sprintf('Zending %s geleverd', $s['reference']);
                $body = $this->layout($subject, [$this->summary($s), sprintf('<p>Bewijs van levering: <a href="%s">bekijk</a></p>', esc_url($track))]);
                $this->deliver('delivered', $s['contact_email'], $subject, $body, $s);
                if ($recipientEmail !== '') {
                    $this->deliver('recipient_delivered', $recipientEmail, 'Je pakket is geleverd', $this->layout('Je pakket is geleverd', ['<p>Je pakket werd zonet afgeleverd door onze fietskoerier.</p>']), $s);
                }
                break;
            case ShipmentStatus::Failed:
                $subject = sprintf('Zending %s: levering mislukt', $s['reference']);
                $reason = (string) ($event['payload']['reason'] ?? $s['exception'] ?? '');
                $body = $this->layout($subject, [
                    sprintf('<p>De levering is niet gelukt (%s). Dispatch neemt contact op om een nieuwe poging in te plannen.</p>', esc_html($reason)),
                    $this->summary($s),
                ]);
                $this->deliver('failed', $s['contact_email'], $subject, $body, $s);
                $this->deliver('dispatch_failed', $this->settings->notifyEmail(), $subject, $this->layout($subject, [$this->summary($s, true)]), $s);
                break;
            case ShipmentStatus::Cancelled:
                $subject = sprintf('Zending %s geannuleerd', $s['reference']);
                $this->deliver('cancelled', $s['contact_email'], $subject, $this->layout($subject, [$this->summary($s)]), $s);
                break;
            default:
                break;
        }
    }

    /** @param array<string, mixed> $shipment */
    private function deliver(string $kind, string $to, string $subject, string $html, array $shipment): void
    {
        $mail = apply_filters('cargovelo/mail', ['to' => $to, 'subject' => $subject, 'html' => $html, 'kind' => $kind], $shipment);
        if (!is_array($mail) || empty($mail['to'])) {
            return;
        }
        if (!$this->mailer->send((string) $mail['to'], (string) $mail['subject'], (string) $mail['html'])) {
            cargovelo_log('mail')->warning('send failed', ['kind' => $kind, 'to' => $mail['to'], 'reference' => $shipment['reference'] ?? '']);
        }
    }

    /** @param array<string, mixed> $s */
    private function summary(array $s, bool $internal = false): string
    {
        $p = $s['pickup'];
        $d = $s['delivery'];
        $line = static fn(array $a): string => esc_html(sprintf('%s %s%s, %s %s', $a['street'] ?? '', $a['number'] ?? '', !empty($a['box']) ? ' bus ' . $a['box'] : '', $a['postcode'] ?? '', $a['city'] ?? ''));
        $rows = [
            ['Referentie', esc_html($s['reference'])],
            ['Dienst', esc_html(ucfirst((string) $s['service']))],
            ['Ophaling', esc_html((string) ($p['name'] ?? '')) . '<br>' . $line($p)],
            ['Levering', esc_html((string) ($d['name'] ?? '')) . '<br>' . $line($d)],
        ];
        if (!empty($s['pickup_window']['start'])) {
            $rows[] = ['Ophaalmoment', esc_html(str_replace('T', ' ', substr((string) $s['pickup_window']['start'], 0, 16)))];
        }
        if ($internal) {
            $rows[] = ['Klant', esc_html($s['customer_name']) . ' · ' . esc_html($s['contact_email'])];
            $rows[] = ['Prijs', $s['price_cents'] !== null ? '€ ' . number_format($s['price_cents'] / 100, 2, ',', '.') : '—'];
            $rows[] = ['Opmerkingen', nl2br(esc_html((string) $s['remarks']))];
        }
        $html = '<table cellpadding="6" style="border-collapse:collapse">';
        foreach ($rows as [$k, $v]) {
            $html .= sprintf('<tr><td style="color:#666;vertical-align:top">%s</td><td>%s</td></tr>', $k, $v);
        }
        return $html . '</table>';
    }

    /** @param list<string> $blocks */
    private function layout(string $title, array $blocks): string
    {
        return sprintf(
            '<div style="font-family:system-ui,sans-serif;max-width:560px;margin:0 auto;color:#111"><h2 style="font-weight:600">%s</h2>%s<p style="color:#666;font-size:12px;margin-top:32px">Cargo Velo · fietskoerier in Gent, Antwerpen, Brussel, Mechelen en Leuven</p></div>',
            esc_html($title),
            implode('', $blocks)
        );
    }
}
