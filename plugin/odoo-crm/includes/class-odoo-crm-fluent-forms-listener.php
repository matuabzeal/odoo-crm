<?php

defined('ABSPATH') || exit;

final class Odoo_CRM_Fluent_Forms_Listener
{
    public static function init(): void
    {
        add_action('fluentform/submission_inserted', [self::class, 'handle_submission'], 10, 3);
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
        if (empty($settings['enabled'])) {
            return;
        }

        Odoo_CRM_Lead_Service::submit($form_id, $entry_id, $form_data);
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
