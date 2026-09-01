<?php

defined('ABSPATH') || exit;

final class Odoo_CRM_Routing_Catalog
{
    public static function discover(): array
    {
        return (new Odoo_CRM_Client(Odoo_CRM_Settings::get()))->discover_routing_options();
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

    public static function enrich_form_input(array $input): array
    {
        $catalog = self::discover();
        if (empty($catalog['success'])) {
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
}
