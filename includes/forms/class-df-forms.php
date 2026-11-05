<?php
/**
 * DF_Forms: adapter for the free form plugins Contact Form 7 and Fluent Forms.
 *
 * - detect plugins, list forms (id => title), render a form via its shortcode
 * - CF7 honeypot (option df_editor_settings['honeypot'], default on)
 * - form styles live in sections/formular/style.css; CF7's own JS/CSS load only on pages that
 *   print a CF7 form (cf7_gate_assets() + late enqueue in render())
 *
 * Self-contained: loaded by df-editor.php (glob of includes/forms/*.php) on plugins_loaded.
 *
 * @package DF_Editor
 */

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'DF_Forms' ) ) :

	final class DF_Forms {

		public const CF7    = 'cf7';
		public const FLUENT = 'fluentforms';

		/** Name of the CF7 honeypot input. */
		public const HONEYPOT_FIELD = 'df_hp_website';

		private static bool $booted = false;

		public static function init(): void {
			if ( self::$booted ) {
				return;
			}
			self::$booted = true;

			add_filter( 'wpcf7_form_elements', array( __CLASS__, 'cf7_honeypot_field' ) );
			add_filter( 'wpcf7_spam', array( __CLASS__, 'cf7_honeypot_check' ), 10, 2 );
			// CF7 assets only where a form is printed (render() enqueues them late for DF sections).
			add_filter( 'wpcf7_load_js', array( __CLASS__, 'cf7_gate_assets' ), 20 );
			add_filter( 'wpcf7_load_css', array( __CLASS__, 'cf7_gate_assets' ), 20 );

			if ( defined( 'WP_CLI' ) && WP_CLI && class_exists( 'WP_CLI' ) ) {
				\WP_CLI::add_command(
					'df-forms',
					array( __CLASS__, 'cli_list' ),
					array( 'shortdesc' => 'Lists the forms of Contact Form 7 / Fluent Forms (id => title).' )
				);
			}
		}

		/* ---------- Detection ---------- */

		public static function has_cf7(): bool {
			return defined( 'WPCF7_VERSION' ) || class_exists( 'WPCF7_ContactForm' );
		}

		public static function has_fluentforms(): bool {
			return defined( 'FLUENTFORM' ) || defined( 'FLUENTFORM_VERSION' ) || function_exists( 'wpFluentForm' );
		}

		/**
		 * Active form plugins.
		 *
		 * @return array<string,bool> cf7 => bool, fluentforms => bool
		 */
		public static function available(): array {
			return array(
				self::CF7    => self::has_cf7(),
				self::FLUENT => self::has_fluentforms(),
			);
		}

		/**
		 * Resolves "auto" (or an unknown/inactive value) to an active plugin key, or ''.
		 * auto: CF7 first, then Fluent Forms.
		 */
		public static function resolve_plugin( string $plugin = 'auto' ): string {
			$available = self::available();
			if ( isset( $available[ $plugin ] ) ) {
				return $available[ $plugin ] ? $plugin : '';
			}
			foreach ( $available as $key => $active ) {
				if ( $active ) {
					return $key;
				}
			}
			return '';
		}

		/* ---------- Forms ---------- */

		/**
		 * Forms of one plugin (or all active plugins when $plugin is '' / 'auto').
		 *
		 * @return array<int,string>|array<string,array<int,string>> id => title, or plugin => (id => title) for all.
		 */
		public static function forms( string $plugin = '' ): array {
			if ( '' === $plugin || 'auto' === $plugin ) {
				$out = array();
				foreach ( self::available() as $key => $active ) {
					if ( $active ) {
						$out[ $key ] = self::forms( $key );
					}
				}
				return $out;
			}

			$forms = array();
			if ( self::CF7 === $plugin && self::has_cf7() ) {
				$posts = get_posts(
					array(
						'post_type'      => 'wpcf7_contact_form',
						'post_status'    => 'publish',
						'posts_per_page' => 200,
						'orderby'        => 'ID',
						'order'          => 'ASC',
						'no_found_rows'  => true,
					)
				);
				foreach ( $posts as $post ) {
					$forms[ (int) $post->ID ] = get_the_title( $post );
				}
			} elseif ( self::FLUENT === $plugin && self::has_fluentforms() ) {
				global $wpdb;
				$table = $wpdb->prefix . 'fluentform_forms';
				// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) === $table ) {
					$rows = $wpdb->get_results( "SELECT id, title FROM {$table} WHERE status = 'published' ORDER BY id ASC LIMIT 200" );
					foreach ( (array) $rows as $row ) {
						$forms[ (int) $row->id ] = (string) $row->title;
					}
				}
				// phpcs:enable
			}
			return $forms;
		}

		/**
		 * Resolves a form reference to its numeric ID (0 when it does not exist).
		 * CF7 also accepts the 7-character hash ID of CF7 5.8+. Empty $id = first form.
		 */
		public static function resolve_id( string $plugin, int|string $id ): int {
			$plugin = self::resolve_plugin( $plugin );
			if ( '' === $plugin ) {
				return 0;
			}
			$id    = trim( (string) $id );
			$forms = self::forms( $plugin );
			if ( '' === $id ) {
				return $forms ? (int) array_key_first( $forms ) : 0;
			}
			if ( ctype_digit( $id ) ) {
				return isset( $forms[ (int) $id ] ) ? (int) $id : 0;
			}
			if ( self::CF7 === $plugin && function_exists( 'wpcf7_get_contact_form_by_hash' ) ) {
				$form = wpcf7_get_contact_form_by_hash( $id );
				return $form ? (int) $form->id() : 0;
			}
			return 0;
		}

		/**
		 * Admin screen to edit a form (or the plugin's form list when $id is 0).
		 */
		public static function edit_url( string $plugin, int $id = 0 ): string {
			$plugin = self::resolve_plugin( $plugin );
			if ( self::CF7 === $plugin ) {
				return $id > 0 ? admin_url( 'admin.php?page=wpcf7&post=' . $id . '&action=edit' ) : admin_url( 'admin.php?page=wpcf7' );
			}
			if ( self::FLUENT === $plugin ) {
				return $id > 0 ? admin_url( 'admin.php?page=fluent_forms&route=editor&form_id=' . $id ) : admin_url( 'admin.php?page=fluent_forms' );
			}
			return admin_url( 'plugins.php' );
		}

		/**
		 * Human-readable plugin name.
		 */
		public static function plugin_name( string $plugin ): string {
			return self::FLUENT === $plugin ? 'Fluent Forms' : ( self::CF7 === $plugin ? 'Contact Form 7' : '' );
		}

		/**
		 * Renders a form through its plugin shortcode. Returns '' when the plugin or form is missing.
		 *
		 * @param string     $plugin cf7|fluentforms|auto.
		 * @param int|string $id     Form ID (CF7: numeric or hash; '' = first form).
		 */
		public static function render( string $plugin, int|string $id ): string {
			$plugin  = self::resolve_plugin( $plugin );
			$form_id = self::resolve_id( $plugin, $id );
			if ( '' === $plugin || $form_id <= 0 ) {
				return '';
			}
			if ( self::CF7 === $plugin ) {
				self::cf7_enqueue();
			}
			$shortcode = self::CF7 === $plugin
				? sprintf( '[contact-form-7 id="%d"]', $form_id )
				: sprintf( '[fluentform id="%d"]', $form_id );
			return (string) do_shortcode( $shortcode );
		}

		/* ---------- CF7 assets ---------- */

		/**
		 * wpcf7_load_js / wpcf7_load_css: on singular pages keep CF7 assets only when the post
		 * content contains a CF7 shortcode or block. Archives etc. keep the default.
		 *
		 * @param mixed $load Current value.
		 */
		public static function cf7_gate_assets( $load ) {
			if ( ! $load || is_admin() || ! is_singular() ) {
				return $load;
			}
			$post = get_queried_object();
			if ( ! $post instanceof \WP_Post ) {
				return $load;
			}
			return has_shortcode( (string) $post->post_content, 'contact-form-7' ) || has_block( 'contact-form-7/contact-form-selector', $post );
		}

		/**
		 * Enqueues CF7 JS/CSS late (in the footer) when a DF section prints a CF7 form.
		 */
		private static function cf7_enqueue(): void {
			if ( ! function_exists( 'wpcf7_enqueue_scripts' ) || did_action( 'wp_enqueue_scripts' ) === 0 ) {
				return;
			}
			remove_filter( 'wpcf7_load_js', array( __CLASS__, 'cf7_gate_assets' ), 20 );
			remove_filter( 'wpcf7_load_css', array( __CLASS__, 'cf7_gate_assets' ), 20 );
			if ( ! wp_script_is( 'contact-form-7' ) && wpcf7_load_js() ) {
				wpcf7_enqueue_scripts();
			}
			if ( function_exists( 'wpcf7_enqueue_styles' ) && ! wp_style_is( 'contact-form-7' ) && wpcf7_load_css() ) {
				wpcf7_enqueue_styles();
			}
			add_filter( 'wpcf7_load_js', array( __CLASS__, 'cf7_gate_assets' ), 20 );
			add_filter( 'wpcf7_load_css', array( __CLASS__, 'cf7_gate_assets' ), 20 );
		}

		/* ---------- CF7 honeypot ---------- */

		/**
		 * Honeypot on/off: df_editor_settings['honeypot'], default on.
		 */
		public static function honeypot_enabled(): bool {
			$settings = get_option( 'df_editor_settings', array() );
			if ( ! is_array( $settings ) || ! array_key_exists( 'honeypot', $settings ) ) {
				return true;
			}
			$value = $settings['honeypot'];
			if ( is_string( $value ) ) {
				return ! in_array( strtolower( trim( $value ) ), array( '', '0', 'no', 'off', 'false' ), true );
			}
			return (bool) $value;
		}

		/**
		 * Adds a hidden text field. Humans never see it (off-screen, aria-hidden, no tab stop);
		 * bots that fill every input mark the submission as spam.
		 */
		public static function cf7_honeypot_field( $elements ) {
			if ( ! is_string( $elements ) || ! self::honeypot_enabled() || str_contains( $elements, self::HONEYPOT_FIELD ) ) {
				return $elements;
			}
			$field = sprintf(
				'<span class="df-hp" aria-hidden="true" style="position:absolute;left:-9999px;top:auto;width:1px;height:1px;overflow:hidden;"><label>%1$s <input type="text" name="%2$s" value="" tabindex="-1" autocomplete="off"></label></span>',
				esc_html__( 'Please leave this field empty', 'df-editor' ),
				esc_attr( self::HONEYPOT_FIELD )
			);
			return $field . $elements;
		}

		/**
		 * wpcf7_spam: spam when the honeypot is filled.
		 *
		 * @param bool  $spam       Current spam state.
		 * @param mixed $submission WPCF7_Submission (CF7 5.3+ passes it).
		 */
		public static function cf7_honeypot_check( $spam, $submission = null ) {
			if ( $spam || ! self::honeypot_enabled() ) {
				return $spam;
			}
			// phpcs:ignore WordPress.Security.NonceVerification.Missing -- CF7 handles the request; we only read the trap field.
			$value = isset( $_POST[ self::HONEYPOT_FIELD ] ) ? trim( (string) wp_unslash( $_POST[ self::HONEYPOT_FIELD ] ) ) : '';
			if ( '' === $value ) {
				return $spam;
			}
			if ( is_object( $submission ) && method_exists( $submission, 'add_spam_log' ) ) {
				$submission->add_spam_log(
					array(
						'agent'  => 'df-editor',
						'reason' => __( 'Honeypot field was filled in.', 'df-editor' ),
					)
				);
			}
			return true;
		}

		/* ---------- WP-CLI ---------- */

		/**
		 * Lists the available forms.
		 *
		 * ## EXAMPLES
		 *
		 *     wp df-forms
		 */
		public static function cli_list(): void {
			$any = false;
			foreach ( self::available() as $plugin => $active ) {
				if ( ! $active ) {
					continue;
				}
				$any = true;
				\WP_CLI::log( self::plugin_name( $plugin ) . ' (' . $plugin . '):' );
				$forms = self::forms( $plugin );
				if ( ! $forms ) {
					\WP_CLI::log( '  (no forms)' );
				}
				foreach ( $forms as $id => $title ) {
					\WP_CLI::log( sprintf( '  %d => %s', $id, $title ) );
				}
			}
			if ( ! $any ) {
				\WP_CLI::warning( 'Neither Contact Form 7 nor Fluent Forms is active.' );
			}
		}
	}

	DF_Forms::init();

endif;
