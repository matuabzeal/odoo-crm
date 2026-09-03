<?php

defined('ABSPATH') || exit;

final class Odoo_CRM_Fluent_Forms_Listener
{
    public static function init(): void
    {
        add_action('fluentform/submission_inserted', [self::class, 'handle_submission'], 10, 3);
        add_action('fluentform/after_payment_status_change', [self::class, 'handle_payment_status'], 10, 2);
    }

    public static function handle_submission($entry_id, $form_data, $form): void
    {
        $form_id = self::resolve_form_id($form);
        $entry_id = is_numeric($entry_id) ? (int) $entry_id : 0;
        $form_data = is_array($form_data) ? $form_data : [];

        if ($form_id <= 0 || $entry_id <= 0) {
            return;
        }

        $settings = Odoo_CRM_Form_Settings::get($form_id);
        if (empty($settings['enabled']) || ($settings['delivery_trigger'] ?? 'submission') !== 'submission') {
            return;
        }

        Odoo_CRM_Lead_Service::submit($form_id, $entry_id, $form_data, 'submission');
    }

    public static function handle_payment_status($new_status, $submission): void
    {
        if (sanitize_key((string) $new_status) !== 'paid' || !is_object($submission)) {
            return;
        }

        $form_id = isset($submission->form_id) && is_numeric($submission->form_id) ? (int) $submission->form_id : 0;
        $entry_id = isset($submission->id) && is_numeric($submission->id) ? (int) $submission->id : 0;
        if ($form_id <= 0 || $entry_id <= 0) {
            return;
        }

        $settings = Odoo_CRM_Form_Settings::get($form_id);
        if (empty($settings['enabled']) || ($settings['delivery_trigger'] ?? 'submission') !== 'payment_paid') {
            return;
        }

        $payload = self::submission_payload($submission);
        Odoo_CRM_Lead_Service::submit($form_id, $entry_id, $payload, 'payment_paid');
    }

    private static function submission_payload(object $submission): array
    {
        $response = $submission->response ?? [];
        if (is_array($response)) {
            return $response;
        }
        if (is_object($response)) {
            return (array) $response;
        }
        if (is_string($response) && $response !== '') {
            $decoded = json_decode($response, true);
            if (is_array($decoded)) {
                return $decoded;
            }
            $maybe = maybe_unserialize($response);
            if (is_array($maybe)) {
                return $maybe;
            }
        }
        return [];
    }

    private static function resolve_form_id($form): int
    {
        if (is_object($form) && isset($form->id) && is_numeric($form->id)) {
            return (int) $form->id;
        }
        if (is_array($form) && isset($form['id']) && is_numeric($form['id'])) {
            return (int) $form['id'];
        }
        return 0;
    }
}
