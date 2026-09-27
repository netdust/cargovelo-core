<?php
declare(strict_types=1);

namespace CargoVelo\Domain;

use WP_Error;

/**
 * Address snapshot stored as JSON on the shipment. Editing an address-book entry later must
 * never rewrite a booked shipment, hence a value object copied at booking time.
 */
final class Address
{
    public function __construct(
        public readonly string $name,
        public readonly string $street,
        public readonly string $number,
        public readonly string $postcode,
        public readonly string $city,
        public readonly string $company = '',
        public readonly string $box = '',
        public readonly string $phone = '',
        public readonly string $email = '',
        public readonly string $instructions = '',
    ) {
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data, string $field = 'address'): self|WP_Error
    {
        $str = static fn(string $k): string => trim((string) ($data[$k] ?? ''));

        $missing = [];
        foreach (['name', 'street', 'number', 'postcode', 'city'] as $required) {
            if ($str($required) === '') {
                $missing[] = $required;
            }
        }
        if ($missing !== []) {
            return new WP_Error(
                'invalid_address',
                sprintf('%s: verplichte velden ontbreken (%s).', $field, implode(', ', $missing)),
                ['field' => $field, 'missing' => $missing]
            );
        }
        $postcode = preg_replace('/\s+/', '', $str('postcode')) ?? '';
        if (!preg_match('/^\d{4}$/', $postcode)) {
            return new WP_Error('invalid_postcode', sprintf('%s: ongeldige Belgische postcode.', $field), ['field' => $field]);
        }
        $email = $str('email');
        if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return new WP_Error('invalid_email', sprintf('%s: ongeldig e-mailadres.', $field), ['field' => $field]);
        }

        return new self(
            name: mb_substr($str('name'), 0, 120),
            street: mb_substr($str('street'), 0, 160),
            number: mb_substr($str('number'), 0, 20),
            postcode: $postcode,
            city: mb_substr($str('city'), 0, 80),
            company: mb_substr($str('company'), 0, 120),
            box: mb_substr($str('box'), 0, 20),
            phone: mb_substr($str('phone'), 0, 40),
            email: mb_substr($email, 0, 160),
            instructions: mb_substr($str('instructions'), 0, 500),
        );
    }

    /** @return array<string, string> */
    public function toArray(): array
    {
        return [
            'name' => $this->name,
            'company' => $this->company,
            'street' => $this->street,
            'number' => $this->number,
            'box' => $this->box,
            'postcode' => $this->postcode,
            'city' => $this->city,
            'phone' => $this->phone,
            'email' => $this->email,
            'instructions' => $this->instructions,
        ];
    }

    public function line(): string
    {
        $box = $this->box !== '' ? ' bus ' . $this->box : '';
        return sprintf('%s %s%s, %s %s', $this->street, $this->number, $box, $this->postcode, $this->city);
    }

    public function withInstructions(string $instructions): self
    {
        return new self(
            $this->name, $this->street, $this->number, $this->postcode, $this->city,
            $this->company, $this->box, $this->phone, $this->email, mb_substr(trim($instructions), 0, 500)
        );
    }
}
