<?php

defined('ABSPATH') || exit;

final class Odoo_CRM_Admin
{
    private const MENU_SLUG = 'odoo-crm';
    private const FORMS_SLUG = 'odoo-crm-forms';

    public static function init(): void
    {
        add_action('admin_menu', [self::class, 'register_menu']);
        add_action('admin_post_odoo_crm_test_connection', [self::class, 'handle_test_connection']);
        add_action('admin_post_odoo_crm_save_form', [self::class, 'handle_save_form']);
        add_action('admin_post_odoo_crm_refresh_routing', [self::class, 'handle_refresh_routing']);
        add_action('admin_post_odoo_crm_clear_diagnostics', [self::class, 'handle_clear_diagnostics']);
        add_filter('plugin_row_meta', [self::class, 'plugin_row_meta'], 10, 2);
        add_action('admin_footer-plugins.php', [self::class, 'render_dependency_label_script']);
    }

    public static function plugin_row_meta(array $links, string $plugin_file): array
    {
        if ($plugin_file !== plugin_basename(ODOO_CRM_PLUGIN_FILE)) {
            return $links;
        }

        $docs_url = 'https://zeal2.odoo.com/knowledge/article/35';
        $links[] = '<a href="' . esc_url($docs_url) . '" target="_blank" rel="noopener noreferrer">Docs</a>';
        return $links;
    }

    public static function render_dependency_label_script(): void
    {
        $plugin_basename = plugin_basename(ODOO_CRM_PLUGIN_FILE);
        ?>
        <script>
        document.addEventListener('DOMContentLoaded', function () {
            const pluginRow = document.querySelector('tr[data-plugin="<?php echo esc_js($plugin_basename); ?>"]');
            if (!pluginRow) {
                return;
            }

            const dependencyLinks = pluginRow.querySelectorAll('.requires a');
            dependencyLinks.forEach(function (link) {
                if (link.textContent.trim().startsWith('Fluent Forms')) {
                    link.textContent = 'Fluent Forms';
                }
            });
        });
        </script>
        <?php
    }

    public static function register_menu(): void
    {
        add_menu_page('Odoo CRM', 'Odoo CRM', 'manage_options', self::MENU_SLUG, [self::class, 'render_connection_page'], 'dashicons-networking', 58);
        add_submenu_page(self::MENU_SLUG, 'Connection', 'Connection', 'manage_options', self::MENU_SLUG, [self::class, 'render_connection_page']);
        add_submenu_page(self::MENU_SLUG, 'Forms', 'Forms', 'manage_options', self::FORMS_SLUG, [self::class, 'render_forms_page']);
        add_submenu_page(self::MENU_SLUG, 'Diagnostics', 'Diagnostics', 'manage_options', 'odoo-crm-diagnostics', [self::class, 'render_diagnostics_page']);
    }

    public static function render_connection_page(): void
    {
        if (!current_user_can('manage_options')) { return; }
        $settings = Odoo_CRM_Settings::get();
        $notice = isset($_GET['odoo_crm_notice']) ? sanitize_key((string) $_GET['odoo_crm_notice']) : '';
        $user_id = isset($_GET['odoo_crm_uid']) ? absint($_GET['odoo_crm_uid']) : 0;
        ?>
        <div class="wrap">
            <h1>Odoo CRM</h1><h2>Connection</h2>
            <?php if ($notice === 'connected') : ?>
                <div class="notice notice-success is-dismissible"><p>Connection successful<?php echo $user_id ? '. Authenticated Odoo user ID: ' . esc_html((string) $user_id) : ''; ?>.</p></div>
            <?php elseif ($notice !== '') : ?>
                <div class="notice notice-error is-dismissible"><p>Connection test failed: <?php echo esc_html(str_replace('_', ' ', $notice)); ?>.</p></div>
            <?php endif; ?>
            <form method="post" action="options.php">
                <?php settings_fields('odoo_crm_connection_group'); ?>
                <table class="form-table" role="presentation">
                    <tr><th scope="row"><label for="odoo-crm-url">Odoo URL</label></th><td><input id="odoo-crm-url" class="regular-text" type="url" name="<?php echo esc_attr(Odoo_CRM_Settings::OPTION_NAME); ?>[url]" value="<?php echo esc_attr($settings['url']); ?>" placeholder="https://example.odoo.com" required></td></tr>
                    <tr><th scope="row"><label for="odoo-crm-database">Database</label></th><td><input id="odoo-crm-database" class="regular-text" type="text" name="<?php echo esc_attr(Odoo_CRM_Settings::OPTION_NAME); ?>[database]" value="<?php echo esc_attr($settings['database']); ?>" required></td></tr>
                    <tr><th scope="row"><label for="odoo-crm-username">Username</label></th><td><input id="odoo-crm-username" class="regular-text" type="text" name="<?php echo esc_attr(Odoo_CRM_Settings::OPTION_NAME); ?>[username]" value="<?php echo esc_attr($settings['username']); ?>" autocomplete="username" required></td></tr>
                    <tr><th scope="row"><label for="odoo-crm-api-key">API Key</label></th><td><input id="odoo-crm-api-key" class="regular-text" type="password" name="<?php echo esc_attr(Odoo_CRM_Settings::OPTION_NAME); ?>[api_key]" value="" autocomplete="new-password"><p class="description"><?php echo $settings['api_key'] !== '' ? 'A key is stored. Leave blank to keep it unchanged.' : 'No API key is currently stored.'; ?></p></td></tr>
                </table>
                <?php submit_button('Save Connection'); ?>
            </form>
            <hr>
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>"><input type="hidden" name="action" value="odoo_crm_test_connection"><?php wp_nonce_field('odoo_crm_test_connection'); ?><?php submit_button('Test Connection', 'secondary', 'submit', false); ?></form>
        </div>
        <?php
    }

    public static function render_forms_page(): void
    {
        if (!current_user_can('manage_options')) { return; }
        $form_id = isset($_GET['form_id']) ? absint($_GET['form_id']) : 0;
        $notice = isset($_GET['odoo_crm_notice']) ? sanitize_key((string) $_GET['odoo_crm_notice']) : '';
        if ($form_id > 0) { self::render_form_editor($form_id, $notice); return; }

        $forms = Odoo_CRM_Fluent_Forms_Adapter::get_forms();
        $catalog = Odoo_CRM_Routing_Catalog::cached();
        ?>
        <div class="wrap">
            <h1>Odoo CRM</h1><h2>Forms</h2>
            <p>Enable and route individual Fluent Forms to Odoo CRM. Odoo automation and Activity configuration remain managed in Odoo.</p>
            <?php self::render_routing_catalog_status($catalog, $notice); ?>
            <?php self::render_refresh_routing_form(); ?>
            <?php if (!$forms) : ?>
                <div class="notice notice-info inline"><p>No Fluent Forms are currently available in this WordPress environment.</p></div>
            <?php else : ?>
                <table class="widefat striped"><thead><tr><th>Form</th><th>Status</th><th>Odoo</th><th>Stage</th><th>Medium</th><th></th></tr></thead><tbody>
                <?php foreach ($forms as $form) : $settings = Odoo_CRM_Form_Settings::get($form['id']); $routing_health = Odoo_CRM_Form_Settings::routing_health($settings, $catalog); ?>
                    <tr>
                        <td><strong><?php echo esc_html($form['title']); ?></strong><br><code>ID <?php echo esc_html((string) $form['id']); ?></code></td>
                        <td><?php echo esc_html($form['status']); ?></td>
                        <td><strong><?php echo esc_html((string) ($routing_health['label'] ?? 'Disabled')); ?></strong><?php if (($routing_health['state'] ?? '') === 'enabled_warning') : ?><br><span class="description"><?php echo esc_html(count($routing_health['warnings'] ?? [])); ?> routing warning(s)</span><?php elseif (($routing_health['state'] ?? '') === 'blocked') : ?><br><span class="description">Delivery refused until corrected</span><?php endif; ?></td>
                        <td><?php echo self::routing_summary($settings['routing']['stage'] ?? [], $catalog['stages'] ?? []); ?></td>
                        <td><?php echo self::routing_summary($settings['routing']['medium'] ?? [], $catalog['mediums'] ?? []); ?></td>
                        <td><a class="button" href="<?php echo esc_url(add_query_arg(['page' => self::FORMS_SLUG, 'form_id' => $form['id']], admin_url('admin.php'))); ?>">Configure</a></td>
                    </tr>
                <?php endforeach; ?>
                </tbody></table>
            <?php endif; ?>
        </div>
        <?php
    }

    private static function render_form_editor(int $form_id, string $notice): void
    {
        $form = Odoo_CRM_Fluent_Forms_Adapter::find_form($form_id);
        if (!$form) {
            echo '<div class="wrap"><h1>Odoo CRM</h1><h2>Forms</h2><div class="notice notice-error"><p>Fluent Form not found.</p></div></div>';
            return;
        }

        $settings = Odoo_CRM_Form_Settings::get($form_id);
        $tag_ids = Odoo_CRM_Form_Settings::tag_ids($settings);
        $catalog = Odoo_CRM_Routing_Catalog::cached();
        $catalog_ok = !empty($catalog['available']);
        $routing_health = Odoo_CRM_Form_Settings::routing_health($settings, $catalog);
        $record_title = $settings['record_title'] !== '' ? $settings['record_title'] : $form['title'] . ': Web Inquiry';
        ?>
        <div class="wrap">
            <h1>Odoo CRM</h1><h2>Forms &rsaquo; <?php echo esc_html($form['title']); ?></h2>
            <?php if ($notice === 'form_saved') : ?><div class="notice notice-success is-dismissible"><p>Form configuration saved.</p></div><?php endif; ?>
            <?php self::render_routing_catalog_status($catalog, $notice); ?>
            <?php if (($routing_health['state'] ?? '') === 'blocked') : ?><div class="notice notice-error inline"><p><strong>Odoo delivery is blocked.</strong> <?php echo esc_html((string) (($routing_health['blocking'][0] ?? '') ?: 'Correct the required routing configuration before delivery can resume.')); ?></p></div><?php elseif (($routing_health['state'] ?? '') === 'enabled_warning') : ?><div class="notice notice-warning inline"><p><strong>Odoo delivery is enabled with warnings.</strong> <?php echo esc_html(implode(' ', $routing_health['warnings'] ?? [])); ?></p></div><?php endif; ?>
            <p><a href="<?php echo esc_url(add_query_arg(['page' => self::FORMS_SLUG], admin_url('admin.php'))); ?>">&larr; Back to Forms</a></p>
            <?php self::render_refresh_routing_form($form_id); ?>
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                <input type="hidden" name="action" value="odoo_crm_save_form"><input type="hidden" name="form_id" value="<?php echo esc_attr((string) $form_id); ?>"><?php wp_nonce_field('odoo_crm_save_form_' . $form_id); ?>

                <h2>Integration</h2>
                <table class="form-table" role="presentation">
                    <tr><th scope="row">Odoo delivery</th><td><label><input type="checkbox" name="odoo_crm_form[enabled]" value="1" <?php checked(!empty($settings['enabled'])); ?>> Enable delivery from this form to Odoo CRM.</label></td></tr>
                    <tr><th scope="row"><label for="odoo-crm-delivery-trigger">Delivery Trigger</label></th><td><select id="odoo-crm-delivery-trigger" name="odoo_crm_form[delivery_trigger]"><option value="submission" <?php selected(($settings['delivery_trigger'] ?? 'submission'), 'submission'); ?>>Successful form submission</option><option value="payment_paid" <?php selected(($settings['delivery_trigger'] ?? 'submission'), 'payment_paid'); ?>>Successful payment (Fluent Forms status = paid)</option></select><p class="description">Use successful payment for donation/payment forms so Odoo delivery waits for Fluent Forms to confirm payment.</p></td></tr>
                    <tr><th scope="row"><label for="odoo-crm-record-title">Record Title</label></th><td><input id="odoo-crm-record-title" class="regular-text" type="text" name="odoo_crm_form[record_title]" value="<?php echo esc_attr($record_title); ?>"></td></tr>
                    <tr><th scope="row">Partner Resolution</th><td><label><input type="checkbox" name="odoo_crm_form[partner][enabled]" value="1" <?php checked(!empty($settings['partner']['enabled'])); ?>> Resolve or create an Odoo contact (res.partner) before creating the CRM record.</label><p class="description">Existing contacts are matched conservatively by exact normalized email. Existing contact data is never overwritten.</p></td></tr>
                    <tr><th scope="row"><label for="odoo-crm-partner-email-field">Partner Email Source</label></th><td><select id="odoo-crm-partner-email-field" name="odoo_crm_form[partner][email_field]"><option value="">Automatic (mapped CRM email)</option><?php foreach ($form['fields'] as $partner_field) : $partner_field_name = (string) ($partner_field['name'] ?? ''); if ($partner_field_name === '') { continue; } ?><option value="<?php echo esc_attr($partner_field_name); ?>" <?php selected((string) ($settings['partner']['email_field'] ?? ''), $partner_field_name); ?>><?php echo esc_html((string) ($partner_field['label'] ?? $partner_field_name)); ?> — <?php echo esc_html($partner_field_name); ?></option><?php endforeach; ?></select><p class="description">Choose the donor email field when its Fluent Forms technical name is not <code>email_from</code>.</p></td></tr>
                    <tr><th scope="row"><label for="odoo-crm-partner-name-field">Partner Name Source</label></th><td><select id="odoo-crm-partner-name-field" name="odoo_crm_form[partner][name_field]"><option value="">Automatic (mapped CRM contact name)</option><?php foreach ($form['fields'] as $partner_field) : $partner_field_name = (string) ($partner_field['name'] ?? ''); if ($partner_field_name === '') { continue; } ?><option value="<?php echo esc_attr($partner_field_name); ?>" <?php selected((string) ($settings['partner']['name_field'] ?? ''), $partner_field_name); ?>><?php echo esc_html((string) ($partner_field['label'] ?? $partner_field_name)); ?> — <?php echo esc_html($partner_field_name); ?></option><?php endforeach; ?></select><p class="description">If no name is available, the donor email is used as the Odoo contact name.</p></td></tr>
                </table>

                <h2>Odoo Routing</h2>
                <p>These resources are created and governed in Odoo. The plugin uses the last successfully refreshed routing catalogue and stores both the selected IDs and their display names.</p>
                <p style="margin: 8px 0 16px;"><button type="submit" class="button button-secondary" form="odoo-crm-refresh-routing-form">Refresh Odoo Data</button></p>
                <?php if (!$catalog_ok) : ?>
                    <div class="notice notice-warning inline"><p>Routing choices are unavailable until Odoo data is refreshed for the current connection. Existing selections are preserved.</p></div>
                <?php endif; ?>
                <table class="form-table" role="presentation">
                    <tr><th scope="row"><label for="odoo-crm-stage-id">Stage</label></th><td>
                        <select id="odoo-crm-stage-id" name="odoo_crm_form[routing][stage][id]" <?php disabled(!$catalog_ok); ?>>
                            <option value="0">— Select stage —</option>
                            <?php foreach (($catalog['stages'] ?? []) as $option) : ?><option value="<?php echo esc_attr((string) $option['id']); ?>" <?php selected(!$routing_health['missing_stage'] && (int) $settings['routing']['stage']['id'] === (int) $option['id']); ?>><?php echo esc_html($option['name']); ?></option><?php endforeach; ?>
                        </select>
                        <?php if (!empty($routing_health['missing_stage'])) : ?><input type="hidden" name="odoo_crm_form[routing][stage][preserved_id]" value="<?php echo esc_attr((string) $settings['routing']['stage']['id']); ?>"><input type="hidden" name="odoo_crm_form[routing][stage][preserved_name]" value="<?php echo esc_attr((string) ($settings['routing']['stage']['name'] ?? '')); ?>"><p class="description" style="color:#b32d2e;"><strong>Blocking:</strong> <?php echo esc_html((string) ($routing_health['blocking'][0] ?? 'Configured Stage is unavailable.')); ?> Prior value is preserved internally until a valid replacement is selected.</p><?php endif; ?>
                        <?php if (!$catalog_ok && !empty($settings['routing']['stage']['id'])) : ?><input type="hidden" name="odoo_crm_form[routing][stage][id]" value="<?php echo esc_attr((string) $settings['routing']['stage']['id']); ?>"><?php endif; ?>
                    </td></tr>
                    <tr><th scope="row"><label for="odoo-crm-medium-id">Medium</label></th><td>
                        <select id="odoo-crm-medium-id" name="odoo_crm_form[routing][medium][id]" <?php disabled(!$catalog_ok); ?>>
                            <option value="0">— Select medium —</option>
                            <?php foreach (($catalog['mediums'] ?? []) as $option) : ?><option value="<?php echo esc_attr((string) $option['id']); ?>" <?php selected((int) $settings['routing']['medium']['id'], (int) $option['id']); ?>><?php echo esc_html($option['name']); ?></option><?php endforeach; ?>
                            <?php if ($catalog_ok && !empty($settings['routing']['medium']['id']) && !Odoo_CRM_Routing_Catalog::has_id($catalog['mediums'] ?? [], (int) $settings['routing']['medium']['id'])) : ?><option value="<?php echo esc_attr((string) $settings['routing']['medium']['id']); ?>" selected><?php echo esc_html(self::missing_routing_label($settings['routing']['medium'])); ?></option><?php endif; ?>
                        </select>
                        <?php if (!empty($routing_health['missing_medium'])) : ?><p class="description"><strong>Warning:</strong> <?php echo esc_html((string) (($routing_health['warnings'][0] ?? '') ?: 'Configured Medium is unavailable and will be omitted from delivery.')); ?></p><?php endif; ?>
                        <?php if (!$catalog_ok && !empty($settings['routing']['medium']['id'])) : ?><input type="hidden" name="odoo_crm_form[routing][medium][id]" value="<?php echo esc_attr((string) $settings['routing']['medium']['id']); ?>"><?php endif; ?>
                    </td></tr>
                    <tr><th scope="row"><label for="odoo-crm-tag-ids">Tags</label></th><td>
                        <select id="odoo-crm-tag-ids" name="odoo_crm_form[routing][tag_ids][]" multiple size="6" <?php disabled(!$catalog_ok); ?>>
                            <?php foreach (($catalog['tags'] ?? []) as $option) : ?><option value="<?php echo esc_attr((string) $option['id']); ?>" <?php selected(in_array((int) $option['id'], $tag_ids, true)); ?>><?php echo esc_html($option['name']); ?></option><?php endforeach; ?>
                            <?php if ($catalog_ok) : foreach (($settings['routing']['tags'] ?? []) as $stored_tag) : $stored_tag_id = absint($stored_tag['id'] ?? 0); if ($stored_tag_id > 0 && !Odoo_CRM_Routing_Catalog::has_id($catalog['tags'] ?? [], $stored_tag_id)) : ?><option value="<?php echo esc_attr((string) $stored_tag_id); ?>" selected><?php echo esc_html(self::missing_routing_label($stored_tag)); ?></option><?php endif; endforeach; endif; ?>
                        </select>
                        <?php if (!$catalog_ok) : foreach ($tag_ids as $tag_id) : ?><input type="hidden" name="odoo_crm_form[routing][tag_ids][]" value="<?php echo esc_attr((string) $tag_id); ?>"><?php endforeach; endif; ?>
                        <?php if (!empty($routing_health['missing_tags'])) : ?><p class="description"><strong>Warning:</strong> <?php echo esc_html(count($routing_health['missing_tags'])); ?> configured <?php echo count($routing_health['missing_tags']) === 1 ? 'Tag is' : 'Tags are'; ?> no longer available in Odoo. Missing values are preserved for diagnosis but omitted from delivery; remaining valid Tags continue to be sent.</p><?php foreach ($routing_health['missing_tags'] as $missing_tag) : ?><span class="description" style="display:block;">Previously configured: <?php echo esc_html((string) (($missing_tag['name'] ?? '') !== '' ? $missing_tag['name'] : 'ID ' . ($missing_tag['id'] ?? 0))); ?></span><?php endforeach; endif; ?>
                        <p class="description">Use Ctrl/Command to select multiple tags. Odoo many-to-many command syntax remains internal to the plugin.</p>
                    </td></tr>
                </table>

                <h2>Notes Formatting</h2>
                <table class="form-table" role="presentation">
                    <tr><th scope="row">Form title</th><td><label><input type="checkbox" name="odoo_crm_form[notes][include_form_title]" value="1" <?php checked(!empty($settings['notes']['include_form_title'])); ?>> Include the Fluent Forms title in Notes.</label></td></tr>
                    <tr><th scope="row">Core CRM fields</th><td><label><input type="checkbox" name="odoo_crm_form[notes][include_core_fields]" value="1" <?php checked(!empty($settings['notes']['include_core_fields'])); ?>> Also include directly mapped core CRM fields in Notes.</label></td></tr>
                    <tr><th scope="row">Source entry link</th><td><label><input type="checkbox" name="odoo_crm_form[notes][include_entry_link]" value="1" <?php checked(!empty($settings['notes']['include_entry_link'])); ?>> Append a link to the original Fluent Forms entry.</label></td></tr>
                    <tr><th scope="row">Empty values</th><td><label><input type="checkbox" name="odoo_crm_form[notes][include_empty]" value="1" <?php checked(!empty($settings['notes']['include_empty'])); ?>> Include fields that have no submitted value.</label></td></tr>
                    <tr><th scope="row"><label for="odoo-crm-unknown-fields">Unknown field types</label></th><td><select id="odoo-crm-unknown-fields" name="odoo_crm_form[notes][unknown_fields]"><option value="include" <?php selected($settings['notes']['unknown_fields'], 'include'); ?>>Include using safe fallback</option><option value="exclude" <?php selected($settings['notes']['unknown_fields'], 'exclude'); ?>>Exclude</option></select></td></tr>
                </table>

                <h2>Field Routing</h2>
                <p>Approved core field names map directly to Odoo and can also remain in Notes. System fields are excluded automatically. All other fields default to Notes.</p>
                <style>
                    .odoo-crm-field-routing-table { table-layout: fixed; width: 100%; }
                    .odoo-crm-field-routing-table th,
                    .odoo-crm-field-routing-table td { vertical-align: top; }
                    .odoo-crm-field-routing-table code { white-space: normal; overflow-wrap: anywhere; word-break: break-word; }
                    .odoo-crm-field-routing-table select,
                    .odoo-crm-field-routing-table input[type="text"] { max-width: 100%; }
                </style>
                <table class="widefat striped odoo-crm-field-routing-table">
                    <colgroup>
                        <col style="width:18%">
                        <col style="width:22%">
                        <col style="width:12%">
                        <col style="width:14%">
                        <col style="width:12%">
                        <col style="width:22%">
                    </colgroup>
                    <thead><tr><th>Field</th><th>Technical name</th><th>Type</th><th>Automatic destination</th><th>Override</th><th>Notes label override</th></tr></thead>
                    <tbody>
                    <?php foreach ($form['fields'] as $field) :
                        $name = (string) $field['name'];
                        $auto = Odoo_CRM_Field_Registry::automatic_destination($field);
                        $override = isset($settings['field_overrides'][$name]) && is_array($settings['field_overrides'][$name]) ? $settings['field_overrides'][$name] : [];
                        $selected_destination = isset($override['destination']) ? (string) $override['destination'] : 'automatic';
                        $notes_label = isset($override['notes_label']) ? (string) $override['notes_label'] : '';
                        $reserved = Odoo_CRM_Field_Registry::is_reserved($name);
                        ?>
                        <tr>
                            <td><strong><?php echo esc_html($field['label']); ?></strong><?php if ($reserved) : ?><br><span class="description">Reserved Odoo field name; direct form mapping is blocked.</span><?php endif; ?></td>
                            <td><code><?php echo esc_html($name); ?></code></td>
                            <td><?php echo esc_html((string) $field['element']); ?></td>
                            <td><?php echo esc_html(self::destination_label($auto)); ?></td>
                            <td>
                                <select name="odoo_crm_form[field_overrides][<?php echo esc_attr($name); ?>][destination]">
                                    <option value="automatic" <?php selected($selected_destination, 'automatic'); ?>>Automatic</option>
                                    <?php if (Odoo_CRM_Field_Registry::is_approved_core($name)) : ?><option value="core_notes" <?php selected($selected_destination, 'core_notes'); ?>>Odoo Core + Notes</option><?php endif; ?>
                                    <option value="notes" <?php selected($selected_destination, 'notes'); ?>>Notes only</option>
                                    <option value="exclude" <?php selected($selected_destination, 'exclude'); ?>>Exclude</option>
                                </select>
                            </td>
                            <td><input class="regular-text" type="text" name="odoo_crm_form[field_overrides][<?php echo esc_attr($name); ?>][notes_label]" value="<?php echo esc_attr($notes_label); ?>" placeholder="<?php echo esc_attr($field['label']); ?>"></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
                <?php submit_button('Save Form Configuration'); ?>
            </form>
        </div>
        <?php
    }

    private static function render_refresh_routing_form(int $form_id = 0): void
    {
        $form_id_attr = $form_id > 0 ? ' id="odoo-crm-refresh-routing-form"' : '';
        $style = $form_id > 0 ? 'display:none;' : 'margin: 8px 0 16px;';
        ?>
        <form<?php echo $form_id_attr; ?> method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="<?php echo esc_attr($style); ?>">
            <input type="hidden" name="action" value="odoo_crm_refresh_routing">
            <?php if ($form_id > 0) : ?><input type="hidden" name="form_id" value="<?php echo esc_attr((string) $form_id); ?>"><?php endif; ?>
            <?php wp_nonce_field('odoo_crm_refresh_routing'); ?>
            <?php if ($form_id <= 0) : ?><?php submit_button('Refresh Odoo Data', 'secondary', 'submit', false); ?><?php endif; ?>
        </form>
        <?php
    }

    private static function render_routing_catalog_status(array $catalog, string $notice): void
    {
        if ($notice === 'routing_refreshed') {
            echo '<div class="notice notice-success is-dismissible"><p>Odoo routing data refreshed successfully. Saved display names were reconciled by authoritative Odoo IDs.</p></div>';
        } elseif ($notice === 'routing_refresh_failed') {
            echo '<div class="notice notice-error is-dismissible"><p>Odoo routing refresh failed. The last-known-good catalogue, if any, has been preserved.</p></div>';
        }

        if (!empty($catalog['available'])) {
            $timestamp = isset($catalog['updated_at_utc']) ? strtotime((string) $catalog['updated_at_utc']) : false;
            $display = $timestamp ? wp_date('j M Y, g:i a T', $timestamp) : 'Unknown';
            echo '<p class="description"><strong>Routing data last refreshed:</strong> ' . esc_html($display) . '</p>';
        } elseif (($catalog['status'] ?? '') === 'connection_changed') {
            echo '<div class="notice notice-warning inline"><p>Saved connection details differ from the routing catalogue source. Refresh Odoo Data before changing routing.</p></div>';
        } else {
            echo '<p class="description"><strong>Routing data:</strong> Not refreshed yet for this connection.</p>';
        }
    }

    private static function routing_summary(array $stored, array $records): string
    {
        $id = absint($stored['id'] ?? 0);
        if ($id <= 0) {
            return '&mdash;';
        }
        $name = sanitize_text_field((string) ($stored['name'] ?? ''));
        $label = $name !== '' ? $name : 'ID ' . $id;
        if ($records !== [] && !Odoo_CRM_Routing_Catalog::has_id($records, $id)) {
            $label .= ' (not in current Odoo data)';
        }
        return esc_html($label);
    }

    private static function missing_routing_label(array $stored): string
    {
        $id = absint($stored['id'] ?? 0);
        $name = sanitize_text_field((string) ($stored['name'] ?? ''));
        $label = $name !== '' ? $name : 'ID ' . $id;
        return $label . ' — unavailable in current Odoo data';
    }

    private static function has_missing_tags(array $stored_tags, array $records): bool
    {
        foreach ($stored_tags as $tag) {
            if (is_array($tag)) {
                $id = absint($tag['id'] ?? 0);
                if ($id > 0 && !Odoo_CRM_Routing_Catalog::has_id($records, $id)) {
                    return true;
                }
            }
        }
        return false;
    }

    private static function destination_label(string $destination): string
    {
        return match ($destination) {
            'core_notes' => 'Odoo Core + Notes',
            'exclude' => 'Excluded',
            default => 'Notes',
        };
    }

    public static function render_diagnostics_page(): void
    {
        if (!current_user_can('manage_options')) { return; }

        $items = array_reverse(Odoo_CRM_Logger::get_all());
        $notice = isset($_GET['odoo_crm_notice']) ? sanitize_key((string) $_GET['odoo_crm_notice']) : '';
        ?>
        <div class="wrap">
            <h1>Odoo CRM</h1><h2>Diagnostics</h2>
            <p>Operational delivery metadata only. Submission contents, Notes, credentials, API keys, email addresses, phone numbers, and other form values are not recorded here.</p>
            <?php if ($notice === 'diagnostics_cleared') : ?><div class="notice notice-success is-dismissible"><p>Diagnostics log cleared.</p></div><?php endif; ?>

            <p><strong>Entries:</strong> <?php echo esc_html((string) count($items)); ?> / 100 maximum retained.</p>
            <table class="widefat striped">
                <thead><tr><th>Timestamp (UTC)</th><th>Form ID</th><th>Entry ID</th><th>Status</th><th>Code</th><th>Odoo CRM Record ID</th></tr></thead>
                <tbody>
                <?php if ($items === []) : ?>
                    <tr><td colspan="6">No diagnostics recorded.</td></tr>
                <?php else : foreach ($items as $item) : ?>
                    <tr>
                        <td><?php echo esc_html((string) ($item['timestamp_utc'] ?? '')); ?></td>
                        <td><?php echo esc_html((string) absint($item['form_id'] ?? 0)); ?></td>
                        <td><?php echo esc_html((string) absint($item['entry_id'] ?? 0)); ?></td>
                        <td><?php echo esc_html((string) ($item['status'] ?? '')); ?></td>
                        <td><code><?php echo esc_html((string) ($item['code'] ?? '')); ?></code></td>
                        <td><?php $record_id = absint($item['odoo_record_id'] ?? 0); echo $record_id > 0 ? esc_html((string) $record_id) : '&mdash;'; ?></td>
                    </tr>
                <?php endforeach; endif; ?>
                </tbody>
            </table>

            <hr>
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                <input type="hidden" name="action" value="odoo_crm_clear_diagnostics">
                <?php wp_nonce_field('odoo_crm_clear_diagnostics'); ?>
                <?php submit_button('Clear Diagnostics Log', 'secondary', 'submit', false); ?>
            </form>
        </div>
        <?php
    }

    public static function handle_clear_diagnostics(): void
    {
        if (!current_user_can('manage_options')) { wp_die('You do not have permission to clear diagnostics.'); }
        check_admin_referer('odoo_crm_clear_diagnostics');
        Odoo_CRM_Logger::clear();
        wp_safe_redirect(add_query_arg(['page' => 'odoo-crm-diagnostics', 'odoo_crm_notice' => 'diagnostics_cleared'], admin_url('admin.php')));
        exit;
    }

    public static function handle_test_connection(): void
    {
        if (!current_user_can('manage_options')) { wp_die('You do not have permission to test this connection.'); }
        check_admin_referer('odoo_crm_test_connection');
        $result = (new Odoo_CRM_Client(Odoo_CRM_Settings::get()))->test_connection();
        $args = ['page' => self::MENU_SLUG];
        if (!empty($result['success'])) {
            $args['odoo_crm_notice'] = 'connected';
            $args['odoo_crm_uid'] = isset($result['user_id']) ? (int) $result['user_id'] : 0;
        } else {
            $args['odoo_crm_notice'] = isset($result['code']) ? sanitize_key((string) $result['code']) : 'unknown_error';
        }
        wp_safe_redirect(add_query_arg($args, admin_url('admin.php'))); exit;
    }

    public static function handle_refresh_routing(): void
    {
        if (!current_user_can('manage_options')) { wp_die('You do not have permission to refresh Odoo routing data.'); }
        check_admin_referer('odoo_crm_refresh_routing');
        $form_id = isset($_POST['form_id']) ? absint($_POST['form_id']) : 0;
        $before_catalog = Odoo_CRM_Routing_Catalog::cached();
        $before_health = [];
        foreach (Odoo_CRM_Form_Settings::get_all() as $configured_form_id => $stored) {
            if (is_array($stored)) {
                $before_health[(int) $configured_form_id] = Odoo_CRM_Form_Settings::routing_health(Odoo_CRM_Form_Settings::get((int) $configured_form_id), $before_catalog);
            }
        }
        $result = Odoo_CRM_Routing_Catalog::refresh();
        if (!empty($result['success'])) {
            $after_catalog = Odoo_CRM_Routing_Catalog::cached();
            foreach (Odoo_CRM_Form_Settings::get_all() as $configured_form_id => $stored) {
                $configured_form_id = (int) $configured_form_id;
                if (!is_array($stored) || $configured_form_id <= 0) {
                    continue;
                }
                $after = Odoo_CRM_Form_Settings::routing_health(Odoo_CRM_Form_Settings::get($configured_form_id), $after_catalog);
                $before_signature = (string) ($before_health[$configured_form_id]['signature'] ?? '');
                $after_signature = (string) ($after['signature'] ?? '');
                if ($before_signature === $after_signature) {
                    continue;
                }
                if (($after['state'] ?? '') === 'blocked') {
                    Odoo_CRM_Logger::record($configured_form_id, 0, false, 'ROUTING_CONFIGURATION_INVALIDATED');
                } elseif (($after['state'] ?? '') === 'enabled_warning') {
                    Odoo_CRM_Logger::record($configured_form_id, 0, true, 'ROUTING_CONFIGURATION_DRIFT');
                } elseif (($after['state'] ?? '') === 'enabled' && in_array(($before_health[$configured_form_id]['state'] ?? ''), ['blocked', 'enabled_warning'], true)) {
                    Odoo_CRM_Logger::record($configured_form_id, 0, true, 'ROUTING_CONFIGURATION_RESTORED');
                }
            }
        }
        $args = [
            'page' => self::FORMS_SLUG,
            'odoo_crm_notice' => !empty($result['success']) ? 'routing_refreshed' : 'routing_refresh_failed',
        ];
        if ($form_id > 0) {
            $args['form_id'] = $form_id;
        }
        wp_safe_redirect(add_query_arg($args, admin_url('admin.php')));
        exit;
    }

    public static function handle_save_form(): void
    {
        if (!current_user_can('manage_options')) { wp_die('You do not have permission to configure this form.'); }
        $form_id = isset($_POST['form_id']) ? absint($_POST['form_id']) : 0;
        $form = Odoo_CRM_Fluent_Forms_Adapter::find_form($form_id);
        if ($form_id <= 0 || !$form) { wp_die('The requested Fluent Form could not be found.'); }
        check_admin_referer('odoo_crm_save_form_' . $form_id);
        $input = isset($_POST['odoo_crm_form']) && is_array($_POST['odoo_crm_form']) ? wp_unslash($_POST['odoo_crm_form']) : [];
        $input = Odoo_CRM_Routing_Catalog::enrich_form_input($input);
        Odoo_CRM_Form_Settings::save($form_id, $input, $form['fields']);
        wp_safe_redirect(add_query_arg(['page' => self::FORMS_SLUG, 'form_id' => $form_id, 'odoo_crm_notice' => 'form_saved'], admin_url('admin.php'))); exit;
    }
}
