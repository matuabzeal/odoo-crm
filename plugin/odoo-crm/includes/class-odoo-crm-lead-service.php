<?php

defined('ABSPATH') || exit;

final class Odoo_CRM_Lead_Service
{
    public static function submit(int $form_id, int $entry_id, array $submission, string $trigger = 'submission'): array
    {
        $settings = Odoo_CRM_Form_Settings::get($form_id);
        if (empty($settings['enabled'])) {
            return [
                'success' => false,
                'code' => 'FORM_DISABLED',
                'message' => 'Odoo CRM integration is disabled for this form.',
            ];
        }

        $catalog = Odoo_CRM_Routing_Catalog::cached();
        $routing_health = Odoo_CRM_Form_Settings::routing_health($settings, $catalog);
        if (($routing_health['state'] ?? '') === 'blocked') {
            $result = [
                'success' => false,
                'code' => 'ROUTING_CONFIGURATION_INVALIDATED',
                'message' => (string) (($routing_health['blocking'][0] ?? '') ?: 'Odoo routing configuration is blocked.'),
            ];
            Odoo_CRM_Logger::record($form_id, $entry_id, false, $result['code']);
            return $result;
        }

        if (Odoo_CRM_Delivery_Receipt::exists($form_id, $entry_id)) {
            return [
                'success' => true,
                'code' => 'DELIVERY_ALREADY_COMPLETED',
                'message' => 'This Fluent Forms entry has already been delivered to Odoo.',
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

        $partner = Odoo_CRM_Partner_Service::resolve($submission, $settings, $lead_fields);
        if (empty($partner['success'])) {
            Odoo_CRM_Logger::record($form_id, $entry_id, false, (string) ($partner['code'] ?? 'PARTNER_RESOLUTION_FAILED'));
            return $partner;
        }
        $partner_id = isset($partner['partner_id']) ? (int) $partner['partner_id'] : 0;
        if ($partner_id > 0) {
            $lead_fields['partner_id'] = $partner_id;
        }

        $client = new Odoo_CRM_Client(Odoo_CRM_Settings::get());
        $result = $client->create_lead($lead_fields);

        Odoo_CRM_Logger::record(
            $form_id,
            $entry_id,
            !empty($result['success']),
            (string) ($result['code'] ?? 'unknown_error'),
            isset($result['lead_id']) ? (int) $result['lead_id'] : null
        );

        if (!empty($result['success']) && !empty($result['lead_id'])) {
            Odoo_CRM_Delivery_Receipt::record(
                $form_id,
                $entry_id,
                (int) $result['lead_id'],
                $partner_id > 0 ? $partner_id : null,
                $trigger
            );
            if ($partner_id > 0) {
                $result['partner_id'] = $partner_id;
                $result['partner_code'] = (string) ($partner['code'] ?? 'PARTNER_RESOLVED');
            }
        }

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
        $catalog = Odoo_CRM_Routing_Catalog::cached();
        $medium_id = Odoo_CRM_Form_Settings::effective_medium_id($settings, $catalog);
        $tag_ids = Odoo_CRM_Form_Settings::effective_tag_ids($settings, $catalog);

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
