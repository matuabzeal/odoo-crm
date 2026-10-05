<?php

defined('ABSPATH') || exit;

final class Odoo_CRM_Fluent_Forms_Listener
{
    public static function init(): void
    {
        add_action('fluentform/submission_inserted', [self::class, 'handle_submission'], 10, 3);
        add_action('fluentform/after_payment_status_change', [self::class, 'handle_payment_status'], 10, 2);
        add_action('fluentform/payment_stripe_failed', [self::class, 'handle_payment_stripe_failed'], 20, 5);
        add_action('fluentform/ipn_endpoint_stripe', [self::class, 'handle_stripe_invoice_payment_failed'], 5, 0);
        add_action('fluentform/payment_refunded', [self::class, 'handle_payment_refunded'], 20, 3);
        add_action('fluentform/payment_partially-refunded', [self::class, 'handle_payment_partially_refunded'], 20, 3);
        add_action('fluentform/subscription_received_payment', [self::class, 'handle_subscription_received_payment'], 20, 2);
        add_action('fluentform/subscription_payment_canceled', [self::class, 'handle_subscription_payment_canceled'], 20, 3);

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

public static function handle_payment_stripe_failed($submission, $transaction, $form_id, $charge, $type): void
    {
        $form_id = is_numeric($form_id) ? (int) $form_id : 0;
        $entry_id = self::object_int($submission, ['id', 'submission_id', 'entry_id']);
        if ($form_id <= 0 || $entry_id <= 0 || !self::is_recurring_context($transaction, $type)) {
            return;
        }

        $transaction_id = self::object_string($transaction, ['id', 'transaction_id', 'uuid']);
        $charge_id = self::object_string($charge, ['id']);
        $event_identity = $transaction_id !== '' ? 'transaction:' . $transaction_id : ($charge_id !== '' ? 'charge:' . $charge_id : '');
        if ($event_identity === '') {
            return;
        }

        if (Odoo_CRM_Delivery_Receipt::lifecycle_event_exists($form_id, $entry_id, $event_identity)) {
            return;
        }

        $state = Odoo_CRM_Delivery_Receipt::lifecycle_state_exists($form_id, $entry_id, 'RECURRING_PAYMENT_FAILED') ||
            Odoo_CRM_Delivery_Receipt::lifecycle_state_exists($form_id, $entry_id, 'RECURRING_PAYMENT_RETRY_FAILED')
            ? 'RECURRING_PAYMENT_RETRY_FAILED'
            : 'RECURRING_PAYMENT_FAILED';

        Odoo_CRM_Lead_Service::apply_payment_lifecycle($form_id, $entry_id, $state, $event_identity, [
            'transaction_id' => $transaction_id,
            'charge_id' => $charge_id,
            'type' => is_scalar($type) ? (string) $type : '',
        ]);
    }
    public static function handle_stripe_invoice_payment_failed(): void
    {
        $listener_class = '\\FluentForm\\App\\Modules\\Payments\\PaymentMethods\\Stripe\\API\\StripeListener';
        $subscription_class = '\\FluentForm\\App\\Models\\Subscription';
        $submission_class = '\\FluentForm\\App\\Models\\Submission';
        if (!class_exists($listener_class) || !class_exists($subscription_class) || !class_exists($submission_class)) {
            return;
        }

        $webhook_secret = get_option('fluentform_stripe_webhook_secret', '');
        $signature = isset($_SERVER['HTTP_STRIPE_SIGNATURE']) ? (string) $_SERVER['HTTP_STRIPE_SIGNATURE'] : '';
        if (!is_string($webhook_secret) || $webhook_secret === '' || $signature === '') {
            return;
        }

        $body = @file_get_contents('php://input');
        if (!is_string($body) || $body === '') {
            return;
        }

        $stripe_listener = new $listener_class();
        if (!method_exists($stripe_listener, 'verifySignature') || !$stripe_listener->verifySignature($body, $signature, $webhook_secret)) {
            return;
        }

        $event = json_decode($body);
        if (!is_object($event) || self::object_string($event, ['id']) === '' || self::object_string($event, ['type']) !== 'invoice.payment_failed') {
            return;
        }
        if (!isset($event->data) || !is_object($event->data) || !isset($event->data->object) || !is_object($event->data->object)) {
            return;
        }

        $invoice = $event->data->object;
        $event_id = self::object_string($event, ['id']);
        $invoice_id = self::object_string($invoice, ['id']);
        $subscription_id = self::object_string($invoice, ['subscription']);
        $customer_id = self::object_string($invoice, ['customer']);
        if ($event_id === '' || $subscription_id === '') {
            return;
        }

        $subscription_query = $subscription_class::byVendorSubscriptionId($subscription_id);
        if (!$subscription_query) {
            return;
        }
        if ($customer_id !== '') {
            $subscription_query = $subscription_query->where('vendor_customer_id', $customer_id);
        }
        $subscription = $subscription_query->first();
        if (!is_object($subscription)) {
            return;
        }

        $submission_id = self::object_int($subscription, ['submission_id']);
        if ($submission_id <= 0) {
            return;
        }
        $submission = $submission_class::find($submission_id);
        if (!is_object($submission)) {
            return;
        }

        $form_id = self::object_int($submission, ['form_id']);
        $entry_id = self::object_int($submission, ['id', 'submission_id', 'entry_id']);
        $subscription_form_id = self::object_int($subscription, ['form_id']);
        $submission_payment_method = strtolower(self::object_string($submission, ['payment_method']));
        if ($form_id <= 0 || $entry_id <= 0 || $entry_id !== $submission_id) {
            return;
        }
        if ($subscription_form_id > 0 && $subscription_form_id !== $form_id) {
            return;
        }
        if ($submission_payment_method !== '' && $submission_payment_method !== 'stripe') {
            return;
        }

        $event_identity = 'stripe-event:' . $event_id;
        if (Odoo_CRM_Delivery_Receipt::lifecycle_event_exists($form_id, $entry_id, $event_identity)) {
            return;
        }

        $state = Odoo_CRM_Delivery_Receipt::lifecycle_state_exists($form_id, $entry_id, 'RECURRING_PAYMENT_FAILED') ||
            Odoo_CRM_Delivery_Receipt::lifecycle_state_exists($form_id, $entry_id, 'RECURRING_PAYMENT_RETRY_FAILED')
            ? 'RECURRING_PAYMENT_RETRY_FAILED'
            : 'RECURRING_PAYMENT_FAILED';

        Odoo_CRM_Lead_Service::apply_payment_lifecycle($form_id, $entry_id, $state, $event_identity, [
            'event_id' => $event_id,
            'invoice_id' => $invoice_id,
            'subscription_id' => $subscription_id,
            'attempt_count' => self::object_int($invoice, ['attempt_count']),
            'next_payment_attempt' => self::object_int($invoice, ['next_payment_attempt']),
        ]);
    }
    public static function handle_payment_refunded($refund, $transaction, $submission): void
    {
        self::apply_refund_lifecycle($refund, $transaction, $submission, 'PAYMENT_REFUNDED_FULL', 'refunded');
    }

    public static function handle_payment_partially_refunded($refund, $transaction, $submission): void
    {
        self::apply_refund_lifecycle($refund, $transaction, $submission, 'PAYMENT_REFUNDED_PARTIAL', 'partially-refunded');
    }

    private static function apply_refund_lifecycle($refund, $transaction, $submission, string $state, string $status): void
    {
        $form_id = self::object_int($submission, ['form_id']);
        $entry_id = self::object_int($submission, ['id', 'submission_id', 'entry_id']);
        if ($form_id <= 0 || $entry_id <= 0) {
            return;
        }

        $refund_id = self::object_string($refund, ['charge_id', 'id', 'refund_id', 'vendor_refund_id']);
        $transaction_id = self::object_string($transaction, ['id', 'transaction_id', 'uuid']);
        $event_identity = $refund_id !== '' ? 'refund:' . $refund_id : ($transaction_id !== '' ? 'refund-transaction:' . $transaction_id . ':' . $status : '');
        if ($event_identity === '') {
            return;
        }

        Odoo_CRM_Lead_Service::apply_payment_lifecycle($form_id, $entry_id, $state, $event_identity, [
            'refund_id' => $refund_id,
            'transaction_id' => $transaction_id,
            'status' => $status,
            'amount' => self::object_string($refund, ['amount', 'refund_amount', 'payment_total']),
            'currency' => self::object_string($refund, ['currency']),
        ]);
    }

    public static function handle_subscription_payment_canceled($subscription, $submission, $vendor_data): void
    {
        $form_id = self::object_int($submission, ['form_id']);
        $entry_id = self::object_int($submission, ['id', 'submission_id', 'entry_id']);
        if ($form_id <= 0 || $entry_id <= 0) {
            return;
        }

        $subscription_id = self::object_string($subscription, ['vendor_subscription_id', 'subscription_id', 'id']);
        $vendor_subscription_id = self::object_string($vendor_data, ['id', 'subscription']);
        if ($subscription_id === '') {
            $subscription_id = $vendor_subscription_id;
        }
        $event_identity = $subscription_id !== '' ? 'subscription-cancelled:' . $subscription_id : '';
        if ($event_identity === '') {
            return;
        }

        Odoo_CRM_Lead_Service::apply_payment_lifecycle($form_id, $entry_id, 'SUBSCRIPTION_CANCELLED', $event_identity, [
            'subscription_id' => $subscription_id,
            'status' => self::object_string($subscription, ['status']),
        ]);
    }
    public static function handle_subscription_received_payment($subscription, $submission): void
    {
        $form_id = self::object_int($submission, ['form_id']);
        $entry_id = self::object_int($submission, ['id', 'submission_id', 'entry_id']);
        if ($form_id <= 0 || $entry_id <= 0) {
            return;
        }
        if (!Odoo_CRM_Delivery_Receipt::lifecycle_state_exists($form_id, $entry_id, 'RECURRING_PAYMENT_FAILED') &&
            !Odoo_CRM_Delivery_Receipt::lifecycle_state_exists($form_id, $entry_id, 'RECURRING_PAYMENT_RETRY_FAILED')) {
            return;
        }

        $subscription_id = self::object_string($subscription, ['vendor_subscription_id', 'subscription_id', 'id']);
        $bill_count = self::object_int($subscription, ['bill_count']);
        if ($subscription_id === '' || $bill_count <= 0) {
            return;
        }

        $event_identity = 'subscription-payment:' . $subscription_id . ':bill:' . $bill_count;
        Odoo_CRM_Lead_Service::apply_payment_lifecycle($form_id, $entry_id, 'RECURRING_PAYMENT_RECOVERED', $event_identity, [
            'subscription_id' => $subscription_id,
            'bill_count' => $bill_count,
            'status' => self::object_string($subscription, ['status']),
        ]);
    }

    public static function derive_scheduled_cancellation_state($subscription_payload): array
    {
        $cancel_at_period_end = self::object_bool($subscription_payload, ['cancel_at_period_end']);
        $cancel_at = self::object_string($subscription_payload, ['cancel_at']);
        if (!$cancel_at_period_end && $cancel_at === '') {
            return [];
        }
        return [
            'state' => 'CANCELLATION_SCHEDULED',
            'cancel_at_period_end' => $cancel_at_period_end,
            'cancel_at' => $cancel_at,
        ];
    }

    private static function is_recurring_context($transaction, $type): bool
    {
        $type_value = strtolower(is_scalar($type) ? (string) $type : '');
        if (strpos($type_value, 'subscription') !== false || strpos($type_value, 'recurring') !== false) {
            return true;
        }
        return self::object_string($transaction, ['subscription_id', 'vendor_subscription_id', 'parent_payment_id']) !== '';
    }

    private static function object_int($object, array $names): int
    {
        foreach ($names as $name) {
            $value = self::object_value($object, $name);
            if (is_numeric($value)) {
                return (int) $value;
            }
        }
        return 0;
    }

    private static function object_string($object, array $names): string
    {
        foreach ($names as $name) {
            $value = self::object_value($object, $name);
            if (is_scalar($value) && (string) $value !== '') {
                return sanitize_text_field((string) $value);
            }
        }
        return '';
    }

    private static function object_bool($object, array $names): bool
    {
        foreach ($names as $name) {
            $value = self::object_value($object, $name);
            if (is_bool($value)) {
                return $value;
            }
            if (is_numeric($value)) {
                return (int) $value === 1;
            }
            if (is_string($value)) {
                return in_array(strtolower($value), ['1', 'true', 'yes', 'on'], true);
            }
        }
        return false;
    }

    private static function object_value($object, string $name)
    {
        if (is_array($object) && array_key_exists($name, $object)) {
            return $object[$name];
        }
        if (is_object($object) && isset($object->{$name})) {
            return $object->{$name};
        }
        return null;
    }
}
