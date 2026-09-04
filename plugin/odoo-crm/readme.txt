=== Odoo CRM ===
Contributors: zeal-digital
Requires at least: 6.5
Tested up to: 7.1
Requires PHP: 8.1
Requires Plugins: fluentform
Stable tag: 1.0.0
License: Proprietary

Connects selected Fluent Forms submissions to Odoo CRM using JSON-RPC.

== Description ==

Odoo CRM provides a controlled WordPress integration layer between Fluent Forms and Odoo CRM.

Current capabilities include:

* Opt-in delivery per Fluent Form.
* Authenticated Odoo JSON-RPC connection testing.
* Explicit refresh and last-known-good caching of CRM stages, UTM mediums, and CRM tags.
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

= 0.8.7-dev =
* Fixed the per-form Save Form Configuration action by removing invalid nested forms introduced by the routing refresh control.
* Kept Refresh Odoo Data visually within Odoo Routing while associating it with a separate valid form.

= 0.8.6-dev =
* Adds explicit Refresh Odoo Data controls and a last-known-good routing catalogue cache.
* Shows the last successful routing refresh time and friendly Stage/Medium names on the Forms overview.
* Reconciles cached routing display names by authoritative Odoo IDs without changing configured IDs.
* Preserves and warns about configured routing IDs that disappear from the refreshed Odoo catalogue.
* Invalidates cached routing choices when saved Odoo connection identity changes.

= 0.8.5-dev =
* Improves Field Routing table proportions and long technical-name wrapping in the per-form configuration screen.

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

== 0.9.0-dev ==
* Adds optional Odoo res.partner resolution/creation by donor email before CRM record creation.
* Adds payment-aware delivery using Fluent Forms paid-status lifecycle events.
* Adds bounded delivery receipts to prevent duplicate CRM creation from repeated payment events.
* Existing partner records are reused without automatic field overwrites.
