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
            'delivery_trigger' => 'submission',
            'recurring_frequency' => '',
            'partner' => [
                'enabled' => false,
                'email_field' => '',
                'name_field' => '',
            ],
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
        if (absint($stage['id'] ?? 0) <= 0 && absint($stage['preserved_id'] ?? 0) > 0) {
            $stage['id'] = absint($stage['preserved_id']);
            $stage['name'] = sanitize_text_field((string) ($stage['preserved_name'] ?? ($stage['name'] ?? '')));
        }
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

        $delivery_trigger = isset($input['delivery_trigger']) ? sanitize_key((string) $input['delivery_trigger']) : 'submission';
        if (!in_array($delivery_trigger, ['submission', 'payment_paid'], true)) {
            $delivery_trigger = 'submission';
        }
        $recurring_frequency = isset($input['recurring_frequency']) ? sanitize_key((string) $input['recurring_frequency']) : '';
        if (!in_array($recurring_frequency, ['weekly', 'fortnightly', 'monthly', 'quarterly', 'annual'], true)) {
            $recurring_frequency = '';
        }

        $partner_email_field = isset($input['partner']['email_field']) ? sanitize_key((string) $input['partner']['email_field']) : '';
        $partner_name_field = isset($input['partner']['name_field']) ? sanitize_key((string) $input['partner']['name_field']) : '';
        if ($partner_email_field !== '' && !isset($field_map[$partner_email_field])) {
            $partner_email_field = '';
        }
        if ($partner_name_field !== '' && !isset($field_map[$partner_name_field])) {
            $partner_name_field = '';
        }

        return [
            'enabled' => !empty($input['enabled']),
            'record_title' => isset($input['record_title']) ? sanitize_text_field((string) $input['record_title']) : '',
            'delivery_trigger' => $delivery_trigger,
            'recurring_frequency' => $recurring_frequency,
            'partner' => [
                'enabled' => !empty($input['partner']['enabled']),
                'email_field' => $partner_email_field,
                'name_field' => $partner_name_field,
            ],
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

    public static function reconcile_routing_names(array $catalog): int
    {
        $all = self::get_all();
        $changed_forms = 0;

        foreach ($all as $form_id => $stored) {
            if (!is_array($stored)) {
                continue;
            }
            $changed = false;
            $stage_id = absint($stored['routing']['stage']['id'] ?? 0);
            $medium_id = absint($stored['routing']['medium']['id'] ?? 0);

            if ($stage_id > 0) {
                $name = Odoo_CRM_Routing_Catalog::find_name($catalog['stages'] ?? [], $stage_id);
                if ($name !== '' && (string) ($stored['routing']['stage']['name'] ?? '') !== $name) {
                    $stored['routing']['stage']['name'] = $name;
                    $changed = true;
                }
            }
            if ($medium_id > 0) {
                $name = Odoo_CRM_Routing_Catalog::find_name($catalog['mediums'] ?? [], $medium_id);
                if ($name !== '' && (string) ($stored['routing']['medium']['name'] ?? '') !== $name) {
                    $stored['routing']['medium']['name'] = $name;
                    $changed = true;
                }
            }

            $tags = isset($stored['routing']['tags']) && is_array($stored['routing']['tags']) ? $stored['routing']['tags'] : [];
            foreach ($tags as $index => $tag) {
                if (!is_array($tag)) {
                    continue;
                }
                $tag_id = absint($tag['id'] ?? 0);
                $name = Odoo_CRM_Routing_Catalog::find_name($catalog['tags'] ?? [], $tag_id);
                if ($name !== '' && (string) ($tag['name'] ?? '') !== $name) {
                    $stored['routing']['tags'][$index]['name'] = $name;
                    $changed = true;
                }
            }

            if ($changed) {
                $all[$form_id] = $stored;
                $changed_forms++;
            }
        }

        if ($changed_forms > 0) {
            update_option(self::OPTION_NAME, $all, false);
        }
        return $changed_forms;
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

    public static function routing_health(array $settings, ?array $catalog = null): array
    {
        $catalog = is_array($catalog) ? $catalog : Odoo_CRM_Routing_Catalog::cached();
        $catalog_available = !empty($catalog['available']);
        $stage = isset($settings['routing']['stage']) && is_array($settings['routing']['stage']) ? $settings['routing']['stage'] : [];
        $medium = isset($settings['routing']['medium']) && is_array($settings['routing']['medium']) ? $settings['routing']['medium'] : [];
        $stage_id = absint($stage['id'] ?? 0);
        $medium_id = absint($medium['id'] ?? 0);
        $missing_stage = false;
        $missing_medium = false;
        $missing_tags = [];
        $blocking = [];
        $warnings = [];

        if (empty($settings['enabled'])) {
            return [
                'state' => 'disabled',
                'label' => 'Disabled',
                'blocking' => [],
                'warnings' => [],
                'missing_stage' => false,
                'missing_medium' => false,
                'missing_tags' => [],
                'signature' => 'disabled',
            ];
        }

        if ($stage_id <= 0) {
            $blocking[] = 'A valid Odoo Stage is required before this form can deliver records.';
        } elseif ($catalog_available && !Odoo_CRM_Routing_Catalog::has_id($catalog['stages'] ?? [], $stage_id)) {
            $missing_stage = true;
            $stage_name = sanitize_text_field((string) ($stage['name'] ?? ''));
            $blocking[] = 'Configured Stage "' . ($stage_name !== '' ? $stage_name : 'ID ' . $stage_id) . '" is no longer available in Odoo. Select a valid Stage before this form can deliver records.';
        }

        if ($medium_id > 0 && $catalog_available && !Odoo_CRM_Routing_Catalog::has_id($catalog['mediums'] ?? [], $medium_id)) {
            $missing_medium = true;
            $medium_name = sanitize_text_field((string) ($medium['name'] ?? ''));
            $warnings[] = 'Configured Medium "' . ($medium_name !== '' ? $medium_name : 'ID ' . $medium_id) . '" is no longer available in Odoo and will be omitted from delivery.';
        }

        foreach (($settings['routing']['tags'] ?? []) as $tag) {
            if (!is_array($tag)) {
                continue;
            }
            $tag_id = absint($tag['id'] ?? 0);
            if ($tag_id > 0 && $catalog_available && !Odoo_CRM_Routing_Catalog::has_id($catalog['tags'] ?? [], $tag_id)) {
                $missing_tags[] = [
                    'id' => $tag_id,
                    'name' => sanitize_text_field((string) ($tag['name'] ?? '')),
                ];
            }
        }

        if ($missing_tags !== []) {
            $count = count($missing_tags);
            $warnings[] = $count . ' configured ' . ($count === 1 ? 'Tag is' : 'Tags are') . ' no longer available in Odoo. Delivery can continue using the remaining valid Tags.';
        }

        $state = $blocking !== [] ? 'blocked' : ($warnings !== [] ? 'enabled_warning' : 'enabled');
        $label = $state === 'blocked' ? 'Blocked' : ($state === 'enabled_warning' ? 'Enabled with warnings' : 'Enabled');
        $missing_tag_ids = array_map(static fn(array $tag): int => (int) $tag['id'], $missing_tags);
        sort($missing_tag_ids);

        return [
            'state' => $state,
            'label' => $label,
            'blocking' => $blocking,
            'warnings' => $warnings,
            'missing_stage' => $missing_stage,
            'missing_medium' => $missing_medium,
            'missing_tags' => $missing_tags,
            'signature' => implode('|', [
                $state,
                $missing_stage ? 'stage:' . $stage_id : 'stage:ok',
                $missing_medium ? 'medium:' . $medium_id : 'medium:ok',
                'tags:' . implode(',', $missing_tag_ids),
            ]),
        ];
    }

    public static function effective_medium_id(array $settings, ?array $catalog = null): int
    {
        $catalog = is_array($catalog) ? $catalog : Odoo_CRM_Routing_Catalog::cached();
        $id = absint($settings['routing']['medium']['id'] ?? 0);
        if ($id <= 0) {
            return 0;
        }
        if (!empty($catalog['available']) && !Odoo_CRM_Routing_Catalog::has_id($catalog['mediums'] ?? [], $id)) {
            return 0;
        }
        return $id;
    }

    public static function effective_tag_ids(array $settings, ?array $catalog = null): array
    {
        $catalog = is_array($catalog) ? $catalog : Odoo_CRM_Routing_Catalog::cached();
        $ids = self::tag_ids($settings);
        if (empty($catalog['available'])) {
            return $ids;
        }
        return array_values(array_filter($ids, static fn(int $id): bool => Odoo_CRM_Routing_Catalog::has_id($catalog['tags'] ?? [], $id)));
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
        if (!isset($merged['partner']) || !is_array($merged['partner'])) {
            $merged['partner'] = self::defaults()['partner'];
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
