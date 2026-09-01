<?php

defined('ABSPATH') || exit;

final class Odoo_CRM_Lead_Service
{
    public static function submit(int $form_id, int $entry_id, array $submission): array
    {
        $settings = Odoo_CRM_Form_Settings::get($form_id);
        if (empty($settings['enabled'])) {
            return [
                'success' => false,
                'code' => 'FORM_DISABLED',
                'message' => 'Odoo CRM integration is disabled for this form.',
            ];
        }

        $form = Odoo_CRM_Fluent_Forms_Adapter::find_form($form_id);
        if (!$form) {
            $result = [
                'success' => false,
                'code' => 'FORM_NOT_FOUND',
                'message' => 'Fluent Form could not be loaded.',
            ];
            Odoo_CRM_Logger::record($form_id, $entry_id, false, $result['code']);
            return $result;
        }

        $lead_fields = self::build_lead_fields($form, $submission, $settings, $entry_id);
        $client = new Odoo_CRM_Client(Odoo_CRM_Settings::get());
        $result = $client->create_lead($lead_fields);

        Odoo_CRM_Logger::record(
            $form_id,
            $entry_id,
            !empty($result['success']),
            (string) ($result['code'] ?? 'unknown_error'),
            isset($result['lead_id']) ? (int) $result['lead_id'] : null
        );

        return $result;
    }

    public static function build_lead_fields(array $form, array $submission, array $settings, int $entry_id): array
    {
        $title = trim((string) ($settings['record_title'] ?? ''));
        if ($title === '') {
            $title = trim((string) ($form['title'] ?? 'Form submission')) . ': Web Inquiry';
        }

        $lead_fields = [
            'name' => sanitize_text_field($title),
            'description' => Odoo_CRM_Notes_Renderer::render($form, $submission, $settings, $entry_id),
        ];

        $stage_id = (int) ($settings['routing']['stage']['id'] ?? 0);
        $medium_id = (int) ($settings['routing']['medium']['id'] ?? 0);
        $tag_ids = [];
        foreach (($settings['routing']['tags'] ?? []) as $tag) {
            if (is_array($tag) && !empty($tag['id'])) {
                $tag_ids[] = (int) $tag['id'];
            }
        }
        $tag_ids = array_values(array_unique(array_filter($tag_ids, static fn($id) => $id > 0)));

        if ($stage_id > 0) {
            $lead_fields['stage_id'] = $stage_id;
        }
        if ($medium_id > 0) {
            $lead_fields['medium_id'] = $medium_id;
        }
        if ($tag_ids !== []) {
            $lead_fields['tag_ids'] = [[6, 0, $tag_ids]];
        }

        $core_fields = Odoo_CRM_Field_Mapper::map_core_fields($form['fields'] ?? [], $submission, $settings);
        foreach ($core_fields as $field_name => $value) {
            if (!Odoo_CRM_Field_Registry::is_reserved($field_name)) {
                $lead_fields[$field_name] = $value;
            }
        }

        return $lead_fields;
    }
}
