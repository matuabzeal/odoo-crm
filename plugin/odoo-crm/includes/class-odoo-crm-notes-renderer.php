<?php

defined('ABSPATH') || exit;

final class Odoo_CRM_Notes_Renderer
{
    public static function render(array $form, array $submission, array $settings, int $submission_id): string
    {
        $parts = [];
        $notes = isset($settings['notes']) && is_array($settings['notes']) ? $settings['notes'] : [];
        $include_empty = !empty($notes['include_empty']);
        $include_core = !empty($notes['include_core_fields']);

        if (!empty($notes['include_form_title'])) {
            $parts[] = '<h3>Form Submitted: ' . esc_html((string) ($form['title'] ?? 'Form submission')) . '</h3>';
        }

        foreach (($form['fields'] ?? []) as $field) {
            if (!is_array($field)) {
                continue;
            }

            $name = (string) ($field['name'] ?? '');
            if ($name === '') {
                continue;
            }

            $destination = self::resolve_destination($field, $settings);
            if ($destination === 'exclude') {
                continue;
            }
            if ($destination === 'core_notes' && !$include_core) {
                continue;
            }

            $has_value = array_key_exists($name, $submission);
            $value = $has_value ? $submission[$name] : null;
            if (!$include_empty && self::is_empty_value($value)) {
                continue;
            }

            $label = self::resolve_label($field, $settings);
            $parts[] = self::render_field($field, $label, $value);
        }

        if (!empty($notes['include_entry_link']) && !empty($form['id']) && $submission_id > 0) {
            $entry_url = self::build_entry_url((int) $form['id'], $submission_id);
            $parts[] = '<hr><p><strong>Original Fluent Forms Entry:</strong><br><a href="' . esc_url($entry_url) . '">View original submission</a></p>';
        }

        return implode("\n", array_values(array_filter($parts, static fn($part) => $part !== '')));
    }

    public static function build_entry_url(int $form_id, int $submission_id): string
    {
        $entry_query_args = [
            'page' => 'fluent_forms',
            'route' => 'entries',
            'form_id' => $form_id,
        ];

        $entries_admin_url = add_query_arg($entry_query_args, admin_url('admin.php'));
        return $entries_admin_url . '#/entries/' . $submission_id;
    }

    private static function resolve_destination(array $field, array $settings): string
    {
        $name = (string) ($field['name'] ?? '');
        $overrides = isset($settings['field_overrides']) && is_array($settings['field_overrides']) ? $settings['field_overrides'] : [];
        $override = isset($overrides[$name]) && is_array($overrides[$name]) ? $overrides[$name] : [];
        $requested = isset($override['destination']) ? (string) $override['destination'] : 'automatic';
        $requested = Odoo_CRM_Field_Registry::allowed_destination($requested, $field);
        return $requested === 'automatic' ? Odoo_CRM_Field_Registry::automatic_destination($field) : $requested;
    }

    private static function resolve_label(array $field, array $settings): string
    {
        $name = (string) ($field['name'] ?? '');
        $overrides = isset($settings['field_overrides']) && is_array($settings['field_overrides']) ? $settings['field_overrides'] : [];
        $override = isset($overrides[$name]) && is_array($overrides[$name]) ? $overrides[$name] : [];
        $custom = isset($override['notes_label']) ? trim((string) $override['notes_label']) : '';
        if ($custom !== '') {
            return $custom;
        }
        return (string) ($field['label'] ?? $name);
    }

    private static function render_field(array $field, string $label, $value): string
    {
        $element = (string) ($field['element'] ?? '');
        $attribute_type = (string) ($field['attribute_type'] ?? '');

        if ($element === 'input_checkbox' || (is_array($value) && count($value) > 1)) {
            $values = self::flatten_values($value);
            $items = '';
            foreach ($values as $item) {
                $items .= '<li>' . esc_html($item) . '</li>';
            }
            return '<p><strong>' . esc_html($label) . ':</strong></p><ul>' . $items . '</ul>';
        }

        if ($element === 'textarea') {
            return '<p><strong>' . esc_html($label) . ':</strong><br>' . nl2br(esc_html(self::scalar_value($value))) . '</p>';
        }

        if ($element === 'input_email' || $attribute_type === 'email') {
            $email = sanitize_email(self::scalar_value($value));
            if ($email === '') {
                return '<p><strong>' . esc_html($label) . ':</strong></p>';
            }
            return '<p><strong>' . esc_html($label) . ':</strong> <a href="' . esc_attr('mailto:' . $email) . '">' . esc_html($email) . '</a></p>';
        }

        if ($attribute_type === 'url' || in_array($element, ['input_url', 'url'], true)) {
            $url = esc_url(self::scalar_value($value));
            if ($url === '') {
                return '<p><strong>' . esc_html($label) . ':</strong></p>';
            }
            return '<p><strong>' . esc_html($label) . ':</strong> <a href="' . $url . '">' . esc_html($url) . '</a></p>';
        }

        if (is_array($value)) {
            $values = self::flatten_values($value);
            if (count($values) > 1) {
                $items = '';
                foreach ($values as $item) {
                    $items .= '<li>' . esc_html($item) . '</li>';
                }
                return '<p><strong>' . esc_html($label) . ':</strong></p><ul>' . $items . '</ul>';
            }
            $value = $values[0] ?? '';
        }

        return '<p><strong>' . esc_html($label) . ':</strong> ' . esc_html(self::scalar_value($value)) . '</p>';
    }

    private static function flatten_values($value): array
    {
        if (!is_array($value)) {
            return [self::scalar_value($value)];
        }

        $result = [];
        array_walk_recursive($value, static function ($item) use (&$result): void {
            if (is_scalar($item) || $item === null) {
                $text = trim((string) $item);
                if ($text !== '') {
                    $result[] = $text;
                }
            }
        });
        return $result;
    }

    private static function scalar_value($value): string
    {
        if (is_scalar($value) || $value === null) {
            return (string) $value;
        }
        return wp_json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '';
    }

    private static function is_empty_value($value): bool
    {
        if ($value === null) {
            return true;
        }
        if (is_string($value)) {
            return trim($value) === '';
        }
        if (is_array($value)) {
            return count(self::flatten_values($value)) === 0;
        }
        return false;
    }
}
