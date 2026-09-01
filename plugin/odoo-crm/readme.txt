=== Odoo CRM ===
Contributors: zeal-digital
Requires at least: 6.5
Tested up to: 7.1
Requires PHP: 8.1
Requires Plugins: fluentform
Stable tag: 0.8.4-dev
License: Proprietary

Connects selected Fluent Forms submissions to Odoo CRM using JSON-RPC.

== Description ==

Odoo CRM provides a controlled WordPress integration layer between Fluent Forms and Odoo CRM.

Current capabilities include:

* Opt-in delivery per Fluent Form.
* Authenticated Odoo JSON-RPC connection testing.
* Read-only discovery of CRM stages, UTM mediums, and CRM tags.
* Whitelisted direct CRM field mapping with safe Notes fallback.
* Controlled HTML Notes rendering with a source Fluent Forms entry link.
* Privacy-bounded operational diagnostics.
* Deterministic error classification and no automatic crm.lead.create retry.

Odoo workflow automation and Activities remain configured in Odoo, not WordPress.

== Installation ==

1. Install and activate Fluent Forms.
2. Install and activate Odoo CRM.
3. Open Odoo CRM > Connection and enter the Odoo URL, database, username, and API key.
4. Test the connection.
5. Open Odoo CRM > Forms and explicitly enable/configure each form that should create CRM records.

== Privacy ==

Fluent Forms remains the authoritative source record. Diagnostics retain only operational metadata: timestamp, Form ID, Entry ID, success/failure status, diagnostic code, and Odoo CRM record ID. Submission contents and connection credentials are not written to the diagnostics log.

== Uninstall ==

Uninstalling the plugin removes its WordPress connection settings, per-form routing settings, and diagnostics log. Fluent Forms entries and Odoo CRM records are not deleted.

== Changelog ==

= 0.8.4-dev =
* Shortened the Fluent Forms dependency label on the WordPress Plugins screen to "Fluent Forms" while retaining the native dependency link and enforcement.

= 0.8.3-dev =
* Moved the Docs link from the plugin action controls to the plugin information row beside the author metadata.

= 0.8.2-dev =
* Added a Docs link on the WordPress Plugins screen pointing to the public Odoo knowledgebase.

= 0.8.1-dev =
* Added plugin authorship metadata so WordPress displays psybORGltd as the plugin author.

= 0.8.0-dev =
* Added WordPress plugin dependency metadata for Fluent Forms.
* Added distributable plugin readme and uninstall cleanup.
* Hardened opaque API-key handling so printable secret punctuation is preserved.
* Added release-candidate packaging and verification workflow.
