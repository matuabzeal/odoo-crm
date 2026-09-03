<?php

defined('ABSPATH') || exit;

final class Odoo_CRM_Delivery_Receipt
{
    private const OPTION_NAME = 'odoo_crm_delivery_receipts';
    private const LIMIT = 500;

    public static function exists(int $form_id, int $entry_id): bool
    {
        $all = get_option(self::OPTION_NAME, []);
        return is_array($all) && isset($all[self::key($form_id, $entry_id)]);
    }

    public static function record(int $form_id, int $entry_id, int $lead_id, ?int $partner_id, string $trigger): void
    {
        $all = get_option(self::OPTION_NAME, []);
        $all = is_array($all) ? $all : [];
        $all[self::key($form_id, $entry_id)] = [
            'form_id' => $form_id,
            'entry_id' => $entry_id,
            'lead_id' => $lead_id,
            'partner_id' => $partner_id ?: 0,
            'trigger' => sanitize_key($trigger),
            'timestamp_utc' => gmdate('c'),
        ];
        if (count($all) > self::LIMIT) {
            $all = array_slice($all, -self::LIMIT, null, true);
        }
        update_option(self::OPTION_NAME, $all, false);
    }

    private static function key(int $form_id, int $entry_id): string
    {
        return $form_id . ':' . $entry_id;
    }
}
