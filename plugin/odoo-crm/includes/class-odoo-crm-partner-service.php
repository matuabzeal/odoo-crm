<?php

defined('ABSPATH') || exit;

final class Odoo_CRM_Partner_Service
{
    public static function resolve(array $submission, array $settings, array $lead_fields): array
    {
        $partner_settings = isset($settings['partner']) && is_array($settings['partner']) ? $settings['partner'] : [];
        if (empty($partner_settings['enabled'])) {
            return ['success' => true, 'code' => 'PARTNER_RESOLUTION_DISABLED', 'partner_id' => 0];
        }

        $email = self::resolve_email($submission, $partner_settings, $lead_fields);
        if ($email === '') {
            return [
                'success' => false,
                'code' => 'PARTNER_EMAIL_REQUIRED',
                'message' => 'Partner resolution requires a valid donor email address.',
            ];
        }

        $client = new Odoo_CRM_Client(Odoo_CRM_Settings::get());
        $search = $client->find_partners_by_email($email);
        if (empty($search['success'])) {
            return $search;
        }

        $records = isset($search['records']) && is_array($search['records']) ? $search['records'] : [];
        if (count($records) > 1) {
            return [
                'success' => false,
                'code' => 'PARTNER_MATCH_AMBIGUOUS',
                'message' => 'More than one Odoo partner has the submitted email address.',
            ];
        }
        if (count($records) === 1) {
            return [
                'success' => true,
                'code' => 'PARTNER_REUSED',
                'partner_id' => (int) $records[0]['id'],
                'created' => false,
            ];
        }

        $name = self::resolve_name($submission, $partner_settings, $lead_fields, $email);
        $fields = [
            'name' => $name,
            'email' => $email,
            'company_type' => 'person',
            'is_company' => false,
        ];
        if (!empty($lead_fields['phone'])) {
            $fields['phone'] = sanitize_text_field((string) $lead_fields['phone']);
        }
        if (!empty($lead_fields['mobile'])) {
            $fields['mobile'] = sanitize_text_field((string) $lead_fields['mobile']);
        }

        $created = $client->create_partner($fields);
        if (empty($created['success'])) {
            return $created;
        }

        return [
            'success' => true,
            'code' => 'PARTNER_CREATED',
            'partner_id' => (int) $created['partner_id'],
            'created' => true,
        ];
    }

    private static function resolve_email(array $submission, array $partner_settings, array $lead_fields): string
    {
        $field = sanitize_key((string) ($partner_settings['email_field'] ?? ''));
        $raw = $field !== '' && array_key_exists($field, $submission) ? self::scalar($submission[$field]) : (string) ($lead_fields['email_from'] ?? '');
        return strtolower(trim(sanitize_email($raw)));
    }

    private static function resolve_name(array $submission, array $partner_settings, array $lead_fields, string $email): string
    {
        $field = sanitize_key((string) ($partner_settings['name_field'] ?? ''));
        $raw = $field !== '' && array_key_exists($field, $submission) ? self::scalar($submission[$field]) : (string) ($lead_fields['contact_name'] ?? '');
        $name = trim(sanitize_text_field($raw));
        return $name !== '' ? $name : $email;
    }

    private static function scalar($value): string
    {
        if (is_scalar($value) || $value === null) {
            return trim((string) $value);
        }
        if (is_array($value)) {
            $parts = [];
            array_walk_recursive($value, static function ($item) use (&$parts): void {
                if (is_scalar($item) || $item === null) {
                    $text = trim((string) $item);
                    if ($text !== '') {
                        $parts[] = $text;
                    }
                }
            });
            return implode(' ', $parts);
        }
        return '';
    }
}
