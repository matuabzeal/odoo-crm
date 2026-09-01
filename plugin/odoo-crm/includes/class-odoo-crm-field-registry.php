<?php

defined('ABSPATH') || exit;

final class Odoo_CRM_Field_Registry
{
    public static function approved_core_fields(): array
    {
        return [
            'contact_name' => ['label' => 'Contact Name', 'type' => 'text'],
            'email_from'   => ['label' => 'Email', 'type' => 'email'],
            'phone'        => ['label' => 'Phone', 'type' => 'phone'],
            'mobile'       => ['label' => 'Mobile', 'type' => 'phone'],
        ];
    }

    public static function reserved_fields(): array
    {
        return ['name', 'description', 'stage_id', 'medium_id', 'tag_ids'];
    }

    public static function is_approved_core(string $field_name): bool
    {
        return array_key_exists($field_name, self::approved_core_fields());
    }

    public static function is_reserved(string $field_name): bool
    {
        return in_array($field_name, self::reserved_fields(), true);
    }

    public static function is_system_element(string $element): bool
    {
        return in_array($element, ['recaptcha', 'hcaptcha', 'turnstile'], true);
    }

    public static function automatic_destination(array $field): string
    {
        $name = isset($field['name']) ? (string) $field['name'] : '';
        $element = isset($field['element']) ? (string) $field['element'] : '';

        if (self::is_system_element($element)) {
            return 'exclude';
        }

        if (self::is_approved_core($name)) {
            return 'core_notes';
        }

        return 'notes';
    }

    public static function allowed_destination(string $requested, array $field): string
    {
        $requested = sanitize_key($requested);
        if ($requested === '' || $requested === 'automatic') {
            return 'automatic';
        }

        if ($requested === 'core_notes' && !self::is_approved_core((string) ($field['name'] ?? ''))) {
            return 'automatic';
        }

        if (in_array($requested, ['notes', 'exclude', 'core_notes'], true)) {
            return $requested;
        }

        return 'automatic';
    }
}
