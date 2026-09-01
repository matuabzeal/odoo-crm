<?php

defined('ABSPATH') || exit;

final class Odoo_CRM_Form_Settings
{
    public const OPTION_NAME = 'odoo_crm_form_settings';

    public static function init(): void
    {
        // Per-form settings are saved through the bounded admin-post handler.
    }

    public static function defaults(): array
    {
        return [
            'enabled' => false,
            'record_title' => '',
            'routing' => [
                'stage' => ['id' => 0, 'name' => ''],
                'medium' => ['id' => 0, 'name' => ''],
                'tags' => [],
            ],
            'notes' => [
                'include_form_title' => true,
                'include_core_fields' => true,
                'include_entry_link' => true,
                'include_empty' => false,
                'unknown_fields' => 'include',
            ],
            'field_overrides' => [],
        ];
    }

    public static function get_all(): array
    {
        $stored = get_option(self::OPTION_NAME, []);
        return is_array($stored) ? $stored : [];
    }

    public static function get(int $form_id): array
    {
        $all = self::get_all();
        $stored = isset($all[$form_id]) && is_array($all[$form_id]) ? $all[$form_id] : [];
        return self::merge_defaults($stored);
    }

    public static function save(int $form_id, array $input, array $fields = []): array
    {
        if ($form_id <= 0) {
            return self::defaults();
        }

        $sanitized = self::sanitize($input, $fields);
        $all = self::get_all();
        $all[$form_id] = $sanitized;
        update_option(self::OPTION_NAME, $all, false);
        return $sanitized;
    }

    public static function delete(int $form_id): void
    {
        $all = self::get_all();
        if (array_key_exists($form_id, $all)) {
            unset($all[$form_id]);
            update_option(self::OPTION_NAME, $all, false);
        }
    }

    public static function sanitize(array $input, array $fields = []): array
    {
        $stage = isset($input['routing']['stage']) && is_array($input['routing']['stage']) ? $input['routing']['stage'] : [];
        $medium = isset($input['routing']['medium']) && is_array($input['routing']['medium']) ? $input['routing']['medium'] : [];
        $tags_input = $input['routing']['tag_ids'] ?? [];
        $tag_ids = self::sanitize_id_list($tags_input);
        $tag_names = isset($input['routing']['tag_names']) && is_array($input['routing']['tag_names']) ? $input['routing']['tag_names'] : [];
        $tags = [];
        foreach ($tag_ids as $tag_id) {
            $tags[] = ['id' => $tag_id, 'name' => isset($tag_names[$tag_id]) ? sanitize_text_field((string) $tag_names[$tag_id]) : ''];
        }

        $unknown_fields = isset($input['notes']['unknown_fields']) ? sanitize_key((string) $input['notes']['unknown_fields']) : 'include';
        if (!in_array($unknown_fields, ['include', 'exclude'], true)) {
            $unknown_fields = 'include';
        }

        $field_map = [];
        foreach ($fields as $field) {
            if (is_array($field) && !empty($field['name'])) {
                $field_map[(string) $field['name']] = $field;
            }
        }

        $overrides = [];
        $raw_overrides = isset($input['field_overrides']) && is_array($input['field_overrides']) ? $input['field_overrides'] : [];
        foreach ($raw_overrides as $field_name => $override) {
            $field_name = sanitize_key((string) $field_name);
            if ($field_name === '' || !isset($field_map[$field_name]) || !is_array($override)) {
                continue;
            }

            $destination = Odoo_CRM_Field_Registry::allowed_destination((string) ($override['destination'] ?? 'automatic'), $field_map[$field_name]);
            $notes_label = isset($override['notes_label']) ? sanitize_text_field((string) $override['notes_label']) : '';

            if ($destination !== 'automatic' || $notes_label !== '') {
                $overrides[$field_name] = [
                    'destination' => $destination,
                    'notes_label' => $notes_label,
                ];
            }
        }

        return [
            'enabled' => !empty($input['enabled']),
            'record_title' => isset($input['record_title']) ? sanitize_text_field((string) $input['record_title']) : '',
            'routing' => [
                'stage' => [
                    'id' => isset($stage['id']) ? absint($stage['id']) : 0,
                    'name' => isset($stage['name']) ? sanitize_text_field((string) $stage['name']) : '',
                ],
                'medium' => [
                    'id' => isset($medium['id']) ? absint($medium['id']) : 0,
                    'name' => isset($medium['name']) ? sanitize_text_field((string) $medium['name']) : '',
                ],
                'tags' => $tags,
            ],
            'notes' => [
                'include_form_title' => !empty($input['notes']['include_form_title']),
                'include_core_fields' => !empty($input['notes']['include_core_fields']),
                'include_entry_link' => !empty($input['notes']['include_entry_link']),
                'include_empty' => !empty($input['notes']['include_empty']),
                'unknown_fields' => $unknown_fields,
            ],
            'field_overrides' => $overrides,
        ];
    }

    public static function tag_ids(array $settings): array
    {
        $tags = isset($settings['routing']['tags']) && is_array($settings['routing']['tags']) ? $settings['routing']['tags'] : [];
        $ids = [];
        foreach ($tags as $tag) {
            if (is_array($tag) && !empty($tag['id'])) {
                $ids[] = absint($tag['id']);
            }
        }
        return array_values(array_unique(array_filter($ids)));
    }

    private static function merge_defaults(array $stored): array
    {
        $merged = array_replace_recursive(self::defaults(), $stored);
        if (!isset($merged['routing']['tags']) || !is_array($merged['routing']['tags'])) {
            $merged['routing']['tags'] = [];
        }
        if (!isset($merged['field_overrides']) || !is_array($merged['field_overrides'])) {
            $merged['field_overrides'] = [];
        }
        return $merged;
    }

    private static function sanitize_id_list(array|string $value): array
    {
        if (is_array($value)) {
            $parts = $value;
        } else {
            if ($value === '') {
                return [];
            }
            $parts = preg_split('/[\s,]+/', $value) ?: [];
        }
        $ids = [];
        foreach ($parts as $part) {
            $id = absint($part);
            if ($id > 0) {
                $ids[] = $id;
            }
        }
        return array_values(array_unique($ids));
    }
}
