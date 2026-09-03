<?php

defined('ABSPATH') || exit;

final class Odoo_CRM_Routing_Catalog
{
    public const OPTION_NAME = 'odoo_crm_routing_catalog';

    public static function discover(): array
    {
        return (new Odoo_CRM_Client(Odoo_CRM_Settings::get()))->discover_routing_options();
    }

    public static function cached(): array
    {
        $stored = get_option(self::OPTION_NAME, []);
        if (!is_array($stored) || empty($stored['catalog']) || !is_array($stored['catalog'])) {
            return self::empty_cache('not_refreshed');
        }

        $stored_key = isset($stored['connection_key']) ? (string) $stored['connection_key'] : '';
        if ($stored_key === '' || !hash_equals($stored_key, self::connection_key())) {
            return self::empty_cache('connection_changed');
        }

        return [
            'available' => true,
            'status' => 'current_connection',
            'updated_at_utc' => sanitize_text_field((string) ($stored['updated_at_utc'] ?? '')),
            'stages' => self::normalize_records($stored['catalog']['stages'] ?? []),
            'mediums' => self::normalize_records($stored['catalog']['mediums'] ?? []),
            'tags' => self::normalize_records($stored['catalog']['tags'] ?? []),
        ];
    }

    public static function refresh(): array
    {
        $catalog = self::discover();
        if (empty($catalog['success'])) {
            return [
                'success' => false,
                'code' => isset($catalog['code']) ? sanitize_key((string) $catalog['code']) : 'routing_refresh_failed',
            ];
        }

        $normalized = [
            'stages' => self::normalize_records($catalog['stages'] ?? []),
            'mediums' => self::normalize_records($catalog['mediums'] ?? []),
            'tags' => self::normalize_records($catalog['tags'] ?? []),
        ];
        $updated_at_utc = gmdate('c');
        update_option(self::OPTION_NAME, [
            'connection_key' => self::connection_key(),
            'updated_at_utc' => $updated_at_utc,
            'catalog' => $normalized,
        ], false);

        $forms_reconciled = Odoo_CRM_Form_Settings::reconcile_routing_names($normalized);

        return [
            'success' => true,
            'updated_at_utc' => $updated_at_utc,
            'forms_reconciled' => $forms_reconciled,
            'stages' => $normalized['stages'],
            'mediums' => $normalized['mediums'],
            'tags' => $normalized['tags'],
        ];
    }

    public static function find_name(array $records, int $id): string
    {
        if ($id <= 0) {
            return '';
        }
        foreach ($records as $record) {
            if (is_array($record) && absint($record['id'] ?? 0) === $id) {
                return sanitize_text_field((string) ($record['name'] ?? ''));
            }
        }
        return '';
    }

    public static function has_id(array $records, int $id): bool
    {
        return $id > 0 && self::find_name($records, $id) !== '';
    }

    public static function enrich_form_input(array $input): array
    {
        $catalog = self::cached();
        if (empty($catalog['available'])) {
            return $input;
        }

        $routing = isset($input['routing']) && is_array($input['routing']) ? $input['routing'] : [];
        $stage = isset($routing['stage']) && is_array($routing['stage']) ? $routing['stage'] : [];
        $medium = isset($routing['medium']) && is_array($routing['medium']) ? $routing['medium'] : [];
        $stage_id = absint($stage['id'] ?? 0);
        $medium_id = absint($medium['id'] ?? 0);

        $input['routing']['stage'] = [
            'id' => $stage_id,
            'name' => self::find_name($catalog['stages'] ?? [], $stage_id),
        ];
        $input['routing']['medium'] = [
            'id' => $medium_id,
            'name' => self::find_name($catalog['mediums'] ?? [], $medium_id),
        ];

        $tag_ids = $routing['tag_ids'] ?? [];
        if (!is_array($tag_ids)) {
            $tag_ids = preg_split('/[\\s,]+/', (string) $tag_ids) ?: [];
        }
        $tag_names = [];
        foreach ($tag_ids as $tag_id) {
            $id = absint($tag_id);
            if ($id > 0) {
                $tag_names[$id] = self::find_name($catalog['tags'] ?? [], $id);
            }
        }
        $input['routing']['tag_names'] = $tag_names;
        return $input;
    }

    private static function empty_cache(string $status): array
    {
        return [
            'available' => false,
            'status' => $status,
            'updated_at_utc' => '',
            'stages' => [],
            'mediums' => [],
            'tags' => [],
        ];
    }

    private static function normalize_records(mixed $records): array
    {
        if (!is_array($records)) {
            return [];
        }
        $normalized = [];
        foreach ($records as $record) {
            if (!is_array($record)) {
                continue;
            }
            $id = absint($record['id'] ?? 0);
            $name = sanitize_text_field((string) ($record['name'] ?? ''));
            if ($id > 0 && $name !== '') {
                $normalized[] = ['id' => $id, 'name' => $name];
            }
        }
        return $normalized;
    }

    private static function connection_key(): string
    {
        $settings = Odoo_CRM_Settings::get();
        $parts = [
            untrailingslashit(strtolower(trim((string) ($settings['url'] ?? '')))),
            trim((string) ($settings['database'] ?? '')),
            strtolower(trim((string) ($settings['username'] ?? ''))),
        ];
        return hash('sha256', implode('|', $parts));
    }
}
