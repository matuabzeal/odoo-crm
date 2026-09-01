<?php

defined('ABSPATH') || exit;

final class Odoo_CRM_Logger
{
    public const OPTION_NAME = 'odoo_crm_diagnostics_log';
    private const MAX_ITEMS = 100;

    public static function record(int $form_id, int $entry_id, bool $success, string $code, ?int $odoo_record_id = null): void
    {
        $items = get_option(self::OPTION_NAME, []);
        if (!is_array($items)) {
            $items = [];
        }

        $normalized_code = strtoupper((string) preg_replace('/[^A-Za-z0-9_]/', '_', $code));
        if ($normalized_code === '') {
            $normalized_code = 'UNKNOWN_ERROR';
        }

        $items[] = [
            'timestamp_utc' => gmdate('c'),
            'form_id' => $form_id,
            'entry_id' => $entry_id,
            'status' => $success ? 'success' : 'failure',
            'code' => $normalized_code,
            'odoo_record_id' => $odoo_record_id && $odoo_record_id > 0 ? $odoo_record_id : null,
        ];

        if (count($items) > self::MAX_ITEMS) {
            $items = array_slice($items, -self::MAX_ITEMS);
        }

        update_option(self::OPTION_NAME, $items, false);
    }

    public static function get_all(): array
    {
        $items = get_option(self::OPTION_NAME, []);
        return is_array($items) ? $items : [];
    }

    public static function clear(): void
    {
        delete_option(self::OPTION_NAME);
    }
}
