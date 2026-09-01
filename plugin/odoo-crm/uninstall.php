<?php

if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

delete_option('odoo_crm_connection');
delete_option('odoo_crm_form_settings');
delete_option('odoo_crm_diagnostics_log');
