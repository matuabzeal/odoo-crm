<?php

defined('ABSPATH') || exit;

final class Odoo_CRM_Fluent_Forms_Adapter
{
    public static function get_forms(): array
    {
        $items = self::load_persisted_forms();
        $forms = [];

        foreach ($items as $item) {
            $normalized = self::normalize_form($item);
            if ($normalized !== null) {
                $forms[] = $normalized;
            }
        }

        return $forms;
    }

    public static function find_form(int $form_id): ?array
    {
        if ($form_id <= 0) {
            return null;
        }

        if (class_exists('\\FluentForm\\App\\Models\\Form')) {
            try {
                $form = \FluentForm\App\Models\Form::query()->find($form_id);
                if ($form) {
                    return self::normalize_form($form);
                }
            } catch (Throwable $e) {
                // Fall through to a read-only database lookup.
            }
        }

        global $wpdb;
        $table = $wpdb->prefix . 'fluentform_forms';
        $form = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT id, title, status, form_fields FROM `{$table}` WHERE id = %d LIMIT 1",
                $form_id
            )
        );

        return self::normalize_form($form);
    }

    private static function load_persisted_forms(): array
    {
        if (class_exists('\\FluentForm\\App\\Models\\Form')) {
            try {
                $items = \FluentForm\App\Models\Form::query()
                    ->orderBy('id', 'desc')
                    ->get(['id', 'title', 'status', 'form_fields'])
                    ->all();
                if (is_array($items) && $items !== []) {
                    return $items;
                }
            } catch (Throwable $e) {
                // Fall through to a read-only database lookup.
            }
        }

        global $wpdb;
        $table = $wpdb->prefix . 'fluentform_forms';
        $exists = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table));
        if ($exists !== $table) {
            return [];
        }

        $items = $wpdb->get_results(
            "SELECT id, title, status, form_fields FROM `{$table}` ORDER BY id DESC"
        );

        return is_array($items) ? $items : [];
    }

    public static function fields_for_form(int $form_id): array
    {
        $form = self::find_form($form_id);
        return $form ? $form['fields'] : [];
    }

    private static function normalize_form($form): ?array
    {
        if (!$form) {
            return null;
        }

        $id = self::read_value($form, 'id');
        $id = is_numeric($id) ? (int) $id : 0;
        if ($id <= 0) {
            return null;
        }

        $title = (string) (self::read_value($form, 'title') ?? ('Form ' . $id));
        $status = (string) (self::read_value($form, 'status') ?? '');
        $raw_fields = self::read_value($form, 'form_fields');

        return [
            'id' => $id,
            'title' => $title,
            'status' => $status,
            'fields' => self::normalize_fields($raw_fields),
        ];
    }

    private static function normalize_fields($raw_fields): array
    {
        if (is_string($raw_fields)) {
            $decoded = json_decode($raw_fields, true);
            if (is_array($decoded)) {
                $raw_fields = $decoded;
            }
        } elseif (is_object($raw_fields)) {
            $raw_fields = json_decode(wp_json_encode($raw_fields), true);
        }

        if (!is_array($raw_fields)) {
            return [];
        }

        $items = isset($raw_fields['fields']) && is_array($raw_fields['fields']) ? $raw_fields['fields'] : $raw_fields;
        $fields = [];

        foreach ($items as $item) {
            if (is_object($item)) {
                $item = json_decode(wp_json_encode($item), true);
            }
            if (!is_array($item)) {
                continue;
            }

            $attributes = isset($item['attributes']) && is_array($item['attributes']) ? $item['attributes'] : [];
            $settings = isset($item['settings']) && is_array($item['settings']) ? $item['settings'] : [];
            $name = isset($attributes['name']) ? (string) $attributes['name'] : '';
            $element = isset($item['element']) ? (string) $item['element'] : '';

            if ($name === '' || $element === 'button') {
                continue;
            }

            $admin_label = isset($settings['admin_field_label']) ? trim((string) $settings['admin_field_label']) : '';
            $label = isset($settings['label']) ? trim((string) $settings['label']) : '';
            $display_label = $admin_label !== '' ? $admin_label : ($label !== '' ? $label : $name);

            $fields[] = [
                'name' => $name,
                'element' => $element,
                'attribute_type' => isset($attributes['type']) ? (string) $attributes['type'] : '',
                'label' => $display_label,
                'source_label' => $label,
                'admin_label' => $admin_label,
                'label_placement' => isset($settings['label_placement']) ? (string) $settings['label_placement'] : '',
                'options' => isset($settings['advanced_options']) && is_array($settings['advanced_options']) ? $settings['advanced_options'] : [],
            ];
        }

        return $fields;
    }

    private static function read_value($value, string $key)
    {
        if (is_object($value)) {
            return $value->{$key} ?? null;
        }
        if (is_array($value)) {
            return $value[$key] ?? null;
        }
        return null;
    }
}
