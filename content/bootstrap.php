<?php
/**
 * DF Inhalt module bootstrap.
 *
 * Licence and attribution: see CREDITS.md.
 * Loaded by the main plugin file:
 *
 *     require_once __DIR__ . '/content/bootstrap.php';
 *     df_inhalt_boot( __FILE__ );
 *
 * @package DF_Editor
 */

defined( 'ABSPATH' ) || exit;

if ( ! defined( 'DF_INHALT_VERSION' ) ) {
	define( 'DF_INHALT_VERSION', '2.0.4-df.1' );
	// Paths and URLs point at the content/ directory, not the plugin root.
	define( 'DF_INHALT_PLUGIN_PATH', plugin_dir_path( __FILE__ ) );
	define( 'DF_INHALT_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
}

require_once DF_INHALT_PLUGIN_PATH . 'includes/class-df-inhalt-fields.php';
require_once DF_INHALT_PLUGIN_PATH . 'includes/class-df-inhalt-activity.php';
require_once DF_INHALT_PLUGIN_PATH . 'includes/class-df-inhalt-admin.php';
require_once DF_INHALT_PLUGIN_PATH . 'includes/class-df-inhalt-plugin.php';
require_once DF_INHALT_PLUGIN_PATH . 'includes/template-functions.php';
require_once DF_INHALT_PLUGIN_PATH . 'includes/class-df-inhalt-client.php';
require_once DF_INHALT_PLUGIN_PATH . 'includes/class-df-inhalt-comments.php';
require_once DF_INHALT_PLUGIN_PATH . 'includes/class-df-inhalt-abilities.php';
require_once DF_INHALT_PLUGIN_PATH . 'includes/class-df-inhalt.php';

if ( ! function_exists( 'df_inhalt_boot' ) ) {
	/**
	 * Boot the DF Inhalt module once.
	 *
	 * @param string $main_plugin_file Absolute path of the main plugin file (df-editor.php).
	 * @return void
	 */
	function df_inhalt_boot( string $main_plugin_file ): void {
		static $booted = false;

		if ( $booted ) {
			return;
		}

		$booted = true;

		register_activation_hook( $main_plugin_file, 'df_inhalt_activate' );
		register_deactivation_hook( $main_plugin_file, 'df_inhalt_deactivate' );

		add_action(
			'init',
			static function () use ( $main_plugin_file ) {
				if ( ! is_textdomain_loaded( 'df-editor' ) ) {
					load_plugin_textdomain( 'df-editor', false, dirname( plugin_basename( $main_plugin_file ) ) . '/languages' );
				}
			},
			1
		);

		DF_Inhalt_Activity::init();
		DF_Inhalt_Admin::init();
		DF_Inhalt_Plugin::init();
		DF_Inhalt_Client::init();
		DF_Inhalt_Comments::init();
		DF_Inhalt_Abilities::init();

		DF_Inhalt::mark_booted();
	}
}

if ( ! function_exists( 'df_inhalt_activate' ) ) {
	/**
	 * Activation: create the activity and comment tables, restore client capabilities.
	 *
	 * @internal
	 * @return void
	 */
	function df_inhalt_activate(): void {
		DF_Inhalt_Activity::install();
		DF_Inhalt_Comments::install();
		DF_Inhalt_Client::sync_role();
	}
}

if ( ! function_exists( 'df_inhalt_deactivate' ) ) {
	/**
	 * Deactivation: take back the capabilities the module gave the df_client role.
	 *
	 * Upstream left Clients with editing rights after deactivation. The role
	 * itself and user assignments stay, with only `read`, so reactivating restores
	 * access through DF_Inhalt_Client::sync_role(). Data (values, tables) is kept.
	 *
	 * @internal
	 * @return void
	 */
	function df_inhalt_deactivate(): void {
		DF_Inhalt_Client::revoke_capabilities();
	}
}
