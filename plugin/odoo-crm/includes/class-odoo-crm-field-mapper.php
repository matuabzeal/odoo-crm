<?php

defined('ABSPATH') || exit;

final class Odoo_CRM_Field_Mapper
{
    public static function map_core_fields(array $fields, array $submission, array $settings): array
    {
        $mapped = [];
        $overrides = isset($settings['field_overrides']) && is_array($settings['field_overrides']) ? $settings['field_overrides'] : [];

        foreach ($fields as $field) {
            if (!is_array($field)) {
                continue;
            }

            $name = (string) ($field['name'] ?? '');
            if ($name === '' || !Odoo_CRM_Field_Registry::is_approved_core($name)) {
                continue;
            }

            $override = isset($overrides[$name]) && is_array($overrides[$name]) ? $overrides[$name] : [];
            $requested = isset($override['destination']) ? (string) $override['destination'] : 'automatic';
            $requested = Odoo_CRM_Field_Registry::allowed_destination($requested, $field);
            $destination = $requested === 'automatic' ? Odoo_CRM_Field_Registry::automatic_destination($field) : $requested;
            if ($destination !== 'core_notes') {
                continue;
            }

            if (!array_key_exists($name, $submission)) {
                continue;
            }

            $value = self::scalar_value($submission[$name]);
            if ($value === '') {
                continue;
            }

            if ($name === 'email_from') {
                $value = sanitize_email($value);
                if ($value === '') {
                    continue;
                }
            } else {
                $value = sanitize_text_field($value);
            }

            $mapped[$name] = $value;
        }

        return $mapped;
    }

    private static function scalar_value($value): string
    {
        if (is_scalar($value) || $value === null) {
            return trim((string) $value);
        }

        if (is_array($value)) {
            $flat = [];
            array_walk_recursive($value, static function ($item) use (&$flat): void {
                if (is_scalar($item) || $item === null) {
                    $text = trim((string) $item);
                    if ($text !== '') {
                        $flat[] = $text;
                    }
                }
            });
            return implode(', ', $flat);
        }

        return '';
    }
}
