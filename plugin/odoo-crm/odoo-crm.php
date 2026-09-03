<?php
/**
 * Plugin Name: Odoo CRM
 * Description: Connects Fluent Forms submissions to Odoo CRM through a controlled integration layer.
 * Version: 0.9.0-dev
 * Author: psybORGltd
 * Requires at least: 6.5
 * Requires PHP: 8.1
 * Requires Plugins: fluentform
 * Text Domain: odoo-crm
 */

defined('ABSPATH') || exit;

define('ODOO_CRM_VERSION', '0.9.0-dev');
define('ODOO_CRM_PLUGIN_FILE', __FILE__);
define('ODOO_CRM_PLUGIN_DIR', plugin_dir_path(__FILE__));

require_once ODOO_CRM_PLUGIN_DIR . 'includes/class-odoo-crm-client.php';
require_once ODOO_CRM_PLUGIN_DIR . 'includes/class-odoo-crm-settings.php';
require_once ODOO_CRM_PLUGIN_DIR . 'includes/class-odoo-crm-routing-catalog.php';
require_once ODOO_CRM_PLUGIN_DIR . 'includes/class-odoo-crm-field-registry.php';
require_once ODOO_CRM_PLUGIN_DIR . 'includes/class-odoo-crm-fluent-forms-adapter.php';
require_once ODOO_CRM_PLUGIN_DIR . 'includes/class-odoo-crm-form-settings.php';
require_once ODOO_CRM_PLUGIN_DIR . 'includes/class-odoo-crm-notes-renderer.php';
require_once ODOO_CRM_PLUGIN_DIR . 'includes/class-odoo-crm-field-mapper.php';
require_once ODOO_CRM_PLUGIN_DIR . 'includes/class-odoo-crm-logger.php';
require_once ODOO_CRM_PLUGIN_DIR . 'includes/class-odoo-crm-delivery-receipt.php';
require_once ODOO_CRM_PLUGIN_DIR . 'includes/class-odoo-crm-partner-service.php';
require_once ODOO_CRM_PLUGIN_DIR . 'includes/class-odoo-crm-lead-service.php';
require_once ODOO_CRM_PLUGIN_DIR . 'includes/class-odoo-crm-fluent-forms-listener.php';
require_once ODOO_CRM_PLUGIN_DIR . 'admin/class-odoo-crm-admin.php';

final class Odoo_CRM_Plugin
{
    public static function init(): void
    {
        Odoo_CRM_Settings::init();
        Odoo_CRM_Form_Settings::init();
        Odoo_CRM_Admin::init();
        Odoo_CRM_Fluent_Forms_Listener::init();
    }
}

Odoo_CRM_Plugin::init();
