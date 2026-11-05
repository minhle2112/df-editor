<?php
/**
 * Plugin updates from GitHub releases (YahnisElsts/plugin-update-checker v5).
 *
 * Only active when wp-config.php defines DF_EDITOR_UPDATE_REPO, e.g.
 *   define( 'DF_EDITOR_UPDATE_REPO', 'https://github.com/minhle2112/df-editor/' );
 *   define( 'DF_EDITOR_GITHUB_TOKEN', '…' ); // optional, read-only token for a private repo
 * Without the constant the library is not even loaded: no network calls.
 *
 * @package DF_Editor
 */

defined( 'ABSPATH' ) || exit;

final class DF_Updater {

	/** @var object|null Update checker instance. */
	private static $checker = null;

	public static function init(): void {
		if ( ! defined( 'DF_EDITOR_UPDATE_REPO' ) || '' === (string) DF_EDITOR_UPDATE_REPO ) {
			return;
		}
		$repo = (string) DF_EDITOR_UPDATE_REPO;
		if ( ! preg_match( '#^https://github\.com/[A-Za-z0-9_.-]+/[A-Za-z0-9_.-]+/?$#', $repo ) ) {
			if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
				error_log( 'DF Editor: DF_EDITOR_UPDATE_REPO must be a https://github.com/<owner>/<repo>/ URL.' ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			}
			return;
		}

		$loader = DF_EDITOR_DIR . 'vendor/plugin-update-checker/plugin-update-checker.php';
		if ( ! is_readable( $loader ) ) {
			return;
		}
		require_once $loader;

		if ( ! class_exists( '\YahnisElsts\PluginUpdateChecker\v5\PucFactory' ) ) {
			return;
		}

		self::$checker = \YahnisElsts\PluginUpdateChecker\v5\PucFactory::buildUpdateChecker( trailingslashit( $repo ), DF_EDITOR_FILE, 'df-editor' );

		// Releases are built by .github/workflows/release.yml with the plugin zip as asset.
		$api = self::$checker->getVcsApi();
		if ( $api && method_exists( $api, 'enableReleaseAssets' ) ) {
			$api->enableReleaseAssets( '/df-editor.*\.zip$/i' );
		}

		if ( defined( 'DF_EDITOR_GITHUB_TOKEN' ) && '' !== (string) DF_EDITOR_GITHUB_TOKEN ) {
			self::$checker->setAuthentication( (string) DF_EDITOR_GITHUB_TOKEN );
		}
	}

	/**
	 * The update checker, or null when updates are not configured.
	 */
	public static function checker(): ?object {
		return self::$checker;
	}
}
