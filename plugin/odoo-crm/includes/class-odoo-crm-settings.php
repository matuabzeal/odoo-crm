<?php

defined('ABSPATH') || exit;

final class Odoo_CRM_Settings
{
    public const OPTION_NAME = 'odoo_crm_connection';

    public static function init(): void
    {
        add_action('admin_init', [self::class, 'register']);
    }

    public static function register(): void
    {
        register_setting(
            'odoo_crm_connection_group',
            self::OPTION_NAME,
            [
                'type' => 'array',
                'sanitize_callback' => [self::class, 'sanitize'],
                'default' => self::defaults(),
            ]
        );
    }

    public static function defaults(): array
    {
        return [
            'url' => '',
            'database' => '',
            'username' => '',
            'api_key' => '',
        ];
    }

    public static function get(): array
    {
        $stored = get_option(self::OPTION_NAME, []);
        if (!is_array($stored)) {
            $stored = [];
        }

        return array_merge(self::defaults(), $stored);
    }

    public static function sanitize($input): array
    {
        $existing = self::get();
        $input = is_array($input) ? $input : [];

        $url = isset($input['url']) ? esc_url_raw(trim((string) $input['url'])) : '';
        $database = isset($input['database']) ? sanitize_text_field((string) $input['database']) : '';
        $username = isset($input['username']) ? sanitize_text_field((string) $input['username']) : '';
        $api_key_input = isset($input['api_key']) ? self::sanitize_secret((string) $input['api_key']) : '';

        return [
            'url' => untrailingslashit($url),
            'database' => $database,
            'username' => $username,
            // Blank means preserve the currently stored secret.
            'api_key' => $api_key_input !== '' ? $api_key_input : (string) $existing['api_key'],
        ];
    }

    private static function sanitize_secret(string $secret): string
    {
        // Odoo API keys/passwords are opaque secrets. Preserve printable punctuation
        // rather than applying text-field sanitization that could alter the secret.
        $secret = trim($secret);
        return (string) preg_replace('/[\x00-\x1F\x7F]/', '', $secret);
    }
}
