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

private const LIFECYCLE_OPTION_NAME = 'odoo_crm_lifecycle_receipts';
    private const LIFECYCLE_LIMIT = 500;

    public static function get(int $form_id, int $entry_id): ?array
    {
        $all = get_option(self::OPTION_NAME, []);
        $key = self::key($form_id, $entry_id);
        return is_array($all) && isset($all[$key]) && is_array($all[$key]) ? $all[$key] : null;
    }

    public static function lifecycle_exists(int $form_id, int $entry_id, string $state, string $event_identity): bool
    {
        $all = get_option(self::LIFECYCLE_OPTION_NAME, []);
        return is_array($all) && isset($all[self::lifecycle_key($form_id, $entry_id, $state, $event_identity)]);
    }

    public static function lifecycle_event_exists(int $form_id, int $entry_id, string $event_identity): bool
    {
        $all = get_option(self::LIFECYCLE_OPTION_NAME, []);
        if (!is_array($all)) {
            return false;
        }
        foreach ($all as $receipt) {
            if (!is_array($receipt)) {
                continue;
            }
            if ((int) ($receipt['form_id'] ?? 0) === $form_id &&
                (int) ($receipt['entry_id'] ?? 0) === $entry_id &&
                (string) ($receipt['event_identity'] ?? '') === $event_identity) {
                return true;
            }
        }
        return false;
    }

    public static function lifecycle_state_exists(int $form_id, int $entry_id, string $state): bool
    {
        $all = get_option(self::LIFECYCLE_OPTION_NAME, []);
        if (!is_array($all)) {
            return false;
        }
        foreach ($all as $receipt) {
            if (!is_array($receipt)) {
                continue;
            }
            if ((int) ($receipt['form_id'] ?? 0) === $form_id &&
                (int) ($receipt['entry_id'] ?? 0) === $entry_id &&
                (string) ($receipt['state'] ?? '') === $state) {
                return true;
            }
        }
        return false;
    }

    public static function record_lifecycle(int $form_id, int $entry_id, int $lead_id, string $state, string $event_identity): void
    {
        $all = get_option(self::LIFECYCLE_OPTION_NAME, []);
        if (!is_array($all)) {
            $all = [];
        }
        $key = self::lifecycle_key($form_id, $entry_id, $state, $event_identity);
        $all[$key] = [
            'form_id' => $form_id,
            'entry_id' => $entry_id,
            'lead_id' => $lead_id,
            'state' => $state,
            'event_identity' => $event_identity,
            'recorded_at_gmt' => gmdate('c'),
        ];
        if (count($all) > self::LIFECYCLE_LIMIT) {
            $all = array_slice($all, -self::LIFECYCLE_LIMIT, null, true);
        }
        update_option(self::LIFECYCLE_OPTION_NAME, $all, false);
    }

    private static function lifecycle_key(int $form_id, int $entry_id, string $state, string $event_identity): string
    {
        return hash('sha256', $form_id . '|' . $entry_id . '|' . $state . '|' . $event_identity);
    }
}
