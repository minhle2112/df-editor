<?php
/**
 * Plugin Name:       DF Editor
 * Description:       Page layouts from theme sections, CSS framework and front-end content editing (DF Inhalt) by Digitale Formate.
 * Version:           1.1.0
 * Requires at least: 6.5
 * Requires PHP:      8.0
 * Author:            Digitale Formate
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       df-editor
 * Domain Path:       /languages
 * Update URI:        https://github.com/minhle2112/df-editor/
 *
 * @package DF_Editor
 */

defined( 'ABSPATH' ) || exit;

define( 'DF_EDITOR_VERSION', '1.1.0' );
define( 'DF_EDITOR_FILE', __FILE__ );
define( 'DF_EDITOR_DIR', plugin_dir_path( __FILE__ ) );
define( 'DF_EDITOR_URL', plugin_dir_url( __FILE__ ) );
define( 'DF_LAYOUT_FORMAT', 1 );

// Module "DF Inhalt" (front-end editing). The core works without it.
if ( file_exists( __DIR__ . '/content/bootstrap.php' ) ) {
	require_once __DIR__ . '/content/bootstrap.php';
	if ( function_exists( 'df_inhalt_boot' ) ) {
		df_inhalt_boot( __FILE__ );
	}
}

require_once DF_EDITOR_DIR . 'includes/class-registry.php';
require_once DF_EDITOR_DIR . 'includes/class-content.php';
require_once DF_EDITOR_DIR . 'includes/class-section.php';
require_once DF_EDITOR_DIR . 'includes/class-layout-store.php';
require_once DF_EDITOR_DIR . 'includes/class-validator.php';
require_once DF_EDITOR_DIR . 'includes/class-renderer.php';
require_once DF_EDITOR_DIR . 'includes/class-assets.php';
require_once DF_EDITOR_DIR . 'includes/class-setup.php';
require_once DF_EDITOR_DIR . 'includes/class-fallback.php';
require_once DF_EDITOR_DIR . 'includes/class-design.php';
require_once DF_EDITOR_DIR . 'includes/class-customizer.php';
require_once DF_EDITOR_DIR . 'includes/class-settings.php';
require_once DF_EDITOR_DIR . 'includes/class-upgrade.php';
require_once DF_EDITOR_DIR . 'includes/class-updater.php';
require_once DF_EDITOR_DIR . 'includes/class-viewport.php';
require_once DF_EDITOR_DIR . 'includes/helpers.php';

// Section template helpers and icons: after the theme's functions.php, so a theme's own version wins.
add_action(
	'after_setup_theme',
	static function (): void {
		require_once DF_EDITOR_DIR . 'includes/icons.php';
		require_once DF_EDITOR_DIR . 'includes/section-helpers.php';
	},
	0
);

// Feature modules (custom post types, form integration): every file, sorted, if present.
foreach ( array( 'cpt', 'forms' ) as $df_editor_module_dir ) {
	$df_editor_module_files = glob( DF_EDITOR_DIR . 'includes/' . $df_editor_module_dir . '/*.php' );
	if ( $df_editor_module_files ) {
		sort( $df_editor_module_files, SORT_STRING );
		foreach ( $df_editor_module_files as $df_editor_module_file ) {
			require_once $df_editor_module_file;
		}
	}
}
unset( $df_editor_module_dir, $df_editor_module_files, $df_editor_module_file );

if ( is_admin() ) {
	require_once DF_EDITOR_DIR . 'includes/class-admin.php';
	DF_Admin::init();
	DF_Settings::init();
}

if ( defined( 'WP_CLI' ) && WP_CLI ) {
	require_once DF_EDITOR_DIR . 'includes/class-cli.php';
	WP_CLI::add_command( 'df', 'DF_CLI' );
}

register_activation_hook( __FILE__, array( 'DF_Setup', 'activate' ) );
register_activation_hook( __FILE__, array( 'DF_Upgrade', 'activate' ) );

DF_Layout_Store::init();
DF_Upgrade::init();
DF_Renderer::init();
DF_Assets::init();
DF_Setup::init();
DF_Design::init();
DF_Customizer::init();
DF_Updater::init();
DF_Viewport::init();

add_action(
	'init',
	static function () {
		load_plugin_textdomain( 'df-editor', false, dirname( plugin_basename( DF_EDITOR_FILE ) ) . '/languages' );
	}
);
