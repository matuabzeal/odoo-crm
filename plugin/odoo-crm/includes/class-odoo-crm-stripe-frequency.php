<?php

if (!defined('ABSPATH')) {
    exit;
}

final class Odoo_CRM_Stripe_Frequency
{
    private const FREQUENCIES = [
        'weekly' => ['interval' => 'week', 'interval_count' => 1],
        'fortnightly' => ['interval' => 'week', 'interval_count' => 2],
        'monthly' => ['interval' => 'month', 'interval_count' => 1],
        'quarterly' => ['interval' => 'month', 'interval_count' => 3],
        'annual' => ['interval' => 'year', 'interval_count' => 1],
    ];

    private static $active_subscription_context = null;

    public static function init(): void
    {
        add_filter('fluentform/submission_subscription_items', [self::class, 'filter_subscription_items'], 10, 3);
        add_filter('fluentform/stripe_plan_name', [self::class, 'filter_plan_name'], 10, 2);
        add_filter('fluentform/stripe_plan_name_generated', [self::class, 'filter_generated_plan_name'], 10, 3);
        add_filter('fluentform/stripe_request_body', [self::class, 'filter_stripe_request_body'], 10, 2);
    }

    public static function allowed_frequencies(): array
    {
        return array_keys(self::FREQUENCIES);
    }

    public static function frequency_config(string $frequency): ?array
    {
        $frequency = sanitize_key($frequency);
        return self::FREQUENCIES[$frequency] ?? null;
    }

    public static function filter_subscription_items($items, $submission_data, $form)
    {
        if (!is_array($items) || !$items) {
            return $items;
        }

        $form_id = self::form_id($form);
        $frequency = self::configured_frequency($form_id);
        $config = $frequency !== '' ? self::frequency_config($frequency) : null;

        if (!$config) {
            return $items;
        }

        foreach ($items as $index => $item) {
            if (!is_array($item)) {
                continue;
            }

            $original_plan = maybe_unserialize($item['original_plan'] ?? []);
            if (!is_array($original_plan)) {
                $original_plan = [];
            }

            $original_plan['billing_interval'] = $config['interval'];
            $original_plan['odoo_crm_frequency'] = $frequency;
            $original_plan['odoo_crm_interval_count'] = $config['interval_count'];

            $item['billing_interval'] = $config['interval'];
            $item['original_plan'] = maybe_serialize($original_plan);
            $items[$index] = $item;
        }

        return $items;
    }

    public static function filter_plan_name($plan_name, $subscription)
    {
        return self::extend_plan_name((string) $plan_name, $subscription);
    }

    public static function filter_generated_plan_name($plan_name, $subscription, $currency)
    {
        return self::extend_plan_name((string) $plan_name, $subscription);
    }

    public static function filter_stripe_request_body($request, $api)
    {
        if (!is_array($request) || !is_string($api)) {
            return $request;
        }

        if ($api === 'plans' && isset($request['id'])) {
            $parsed = self::parse_extended_plan_id((string) $request['id']);
            if ($parsed && $parsed['interval_count'] > 1) {
                $request['interval_count'] = $parsed['interval_count'];
            }
            return $request;
        }

        if (
            preg_match('#^subscriptions/[^/]+$#', $api)
            && isset($request['cancel_at'])
            && is_numeric($request['cancel_at'])
        ) {
            $context = self::$active_subscription_context;

            if (
                is_array($context)
                && ($context['interval_count'] ?? 1) > 1
                && ($context['bill_times'] ?? 0) > 0
                && in_array(($context['interval'] ?? ''), ['day', 'week', 'month', 'year'], true)
            ) {
                $periods = (int) $context['bill_times'] * (int) $context['interval_count'];
                $cancel_at = strtotime('+' . $periods . ' ' . $context['interval'], time());

                if ($cancel_at !== false) {
                    $trial_days = max(0, (int) ($context['trial_days'] ?? 0));
                    $request['cancel_at'] = $cancel_at + ($trial_days * 86400);
                }
            }

            self::$active_subscription_context = null;
        }

        return $request;
    }

    private static function extend_plan_name(string $plan_name, $subscription): string
    {
        $context = self::subscription_context($subscription);
        if (!$context) {
            return $plan_name;
        }

        self::$active_subscription_context = $context;
        $suffix = '__odoocrm_' . $context['frequency'] . '_x' . $context['interval_count'];

        if (substr($plan_name, -strlen($suffix)) === $suffix) {
            return $plan_name;
        }

        return $plan_name . $suffix;
    }

    private static function subscription_context($subscription): ?array
    {
        if (!is_object($subscription)) {
            return null;
        }

        $original_plan = maybe_unserialize($subscription->original_plan ?? []);
        if (!is_array($original_plan)) {
            return null;
        }

        $frequency = sanitize_key((string) ($original_plan['odoo_crm_frequency'] ?? ''));
        $interval_count = (int) ($original_plan['odoo_crm_interval_count'] ?? 0);
        $config = self::frequency_config($frequency);

        if (!$config || $interval_count !== (int) $config['interval_count']) {
            return null;
        }

        return [
            'frequency' => $frequency,
            'interval' => $config['interval'],
            'interval_count' => $config['interval_count'],
            'bill_times' => max(0, (int) ($subscription->bill_times ?? 0)),
            'trial_days' => max(0, (int) ($subscription->trial_days ?? 0)),
        ];
    }

    private static function parse_extended_plan_id(string $plan_id): ?array
    {
        if (!preg_match('/__odoocrm_(weekly|fortnightly|monthly|quarterly|annual)_x([123])$/', $plan_id, $matches)) {
            return null;
        }

        $frequency = $matches[1];
        $interval_count = (int) $matches[2];
        $config = self::frequency_config($frequency);

        if (!$config || $interval_count !== (int) $config['interval_count']) {
            return null;
        }

        return ['frequency' => $frequency, 'interval_count' => $interval_count];
    }

    private static function configured_frequency(int $form_id): string
    {
        if ($form_id <= 0 || !class_exists('Odoo_CRM_Form_Settings')) {
            return '';
        }

        $settings = Odoo_CRM_Form_Settings::get($form_id);
        $frequency = sanitize_key((string) ($settings['recurring_frequency'] ?? ''));

        return self::frequency_config($frequency) ? $frequency : '';
    }

    private static function form_id($form): int
    {
        if (is_object($form) && isset($form->id)) {
            return (int) $form->id;
        }

        if (is_array($form) && isset($form['id'])) {
            return (int) $form['id'];
        }

        return 0;
    }
}
