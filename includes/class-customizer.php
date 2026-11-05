<?php
/**
 * Customizer section "DF Design" (colours, fonts, radius, button style).
 *
 * Values live in option df_design (see DF_Design). Transport is "refresh": the preview reloads
 * and WordPress regenerates the theme.json CSS variables, which is reliable for every token
 * (on-primary/on-accent are computed server-side). The logo stays core custom_logo.
 *
 * @package DF_Editor
 */

defined( 'ABSPATH' ) || exit;

final class DF_Customizer {

	public const SECTION = 'df_design';

	public static function init(): void {
		add_action( 'customize_register', array( __CLASS__, 'register' ) );
		add_action( 'customize_controls_enqueue_scripts', array( __CLASS__, 'controls_script' ) );
		// Preview values (filtered option) must not hit a theme.json cache built before the preview filters.
		add_action( 'customize_preview_init', array( 'DF_Design', 'flush' ), 99 );

		// Two editing layers must not overlap: no DF Inhalt inside the Customizer preview.
		add_filter( 'pre_option_df_inhalt_disabled', array( __CLASS__, 'disable_inhalt_in_preview' ) );
		add_filter( 'df_inhalt_can_edit', array( __CLASS__, 'can_edit_in_preview' ), 99 );
	}

	/**
	 * @param mixed $pre Short-circuit value (false = not short-circuited).
	 */
	public static function disable_inhalt_in_preview( $pre ) {
		return self::in_preview() ? '1' : $pre;
	}

	public static function can_edit_in_preview( $can_edit ): bool {
		return self::in_preview() ? false : (bool) $can_edit;
	}

	private static function in_preview(): bool {
		return function_exists( 'is_customize_preview' ) && is_customize_preview();
	}

	public static function register( WP_Customize_Manager $wp_customize ): void {
		$wp_customize->add_section(
			self::SECTION,
			array(
				'title'       => __( 'DF Design', 'df-editor' ),
				'description' => __( 'Brand colours, fonts and shapes for all DF sections. Empty fields use the theme values. The logo is set under Site Identity.', 'df-editor' ),
				'priority'    => 25,
				'capability'  => 'edit_theme_options',
			)
		);

		$palette  = DF_Design::theme_palette();
		$defaults = DF_Design::defaults();
		$labels   = array(
			'primary'   => __( 'Primary colour', 'df-editor' ),
			'secondary' => __( 'Secondary colour', 'df-editor' ),
			'accent'    => __( 'Accent colour', 'df-editor' ),
		);
		$help     = array(
			'primary'   => __( 'Buttons, links, scheme "primaer". Text on it is chosen automatically (black or white).', 'df-editor' ),
			'secondary' => __( 'Hover states and secondary elements.', 'df-editor' ),
			'accent'    => __( 'Highlights, badges, focus ring, scheme "akzent". Text on it is chosen automatically.', 'df-editor' ),
		);

		$priority = 10;
		foreach ( DF_Design::COLORS as $key => $slug ) {
			$id = DF_Design::OPTION . '[' . $key . ']';
			$wp_customize->add_setting(
				$id,
				array(
					'type'              => 'option',
					'capability'        => 'edit_theme_options',
					'default'           => '',
					'transport'         => 'refresh',
					'sanitize_callback' => array( 'DF_Design', 'sanitize_color' ),
					'validate_callback' => static function ( $validity, $value ) use ( $key ) {
						$hex = DF_Design::sanitize_color( $value );
						if ( '' !== $hex ) {
							$error = DF_Design::contrast_error( $key, $hex );
							if ( null !== $error ) {
								$validity->add( 'df_contrast', $error );
							}
						}
						return $validity;
					},
				)
			);
			$wp_customize->add_control(
				new WP_Customize_Color_Control(
					$wp_customize,
					$id,
					array(
						'label'       => $labels[ $key ],
						/* translators: %s: theme colour hex value */
						'description' => $help[ $key ] . ' ' . sprintf( __( 'Theme value: %s', 'df-editor' ), $palette[ $slug ] ?? '—' ),
						'section'     => self::SECTION,
						'priority'    => $priority,
					)
				)
			);
			$priority += 10;
		}

		$selects = array(
			'heading_font' => array( __( 'Heading font', 'df-editor' ), DF_Design::font_choices(), array( 'DF_Design', 'sanitize_font' ), __( 'System stacks need no download. Theme fonts are self-hosted by the theme.', 'df-editor' ) ),
			'body_font'    => array( __( 'Body font', 'df-editor' ), DF_Design::font_choices(), array( 'DF_Design', 'sanitize_font' ), '' ),
			'radius'       => array( __( 'Corner radius', 'df-editor' ), DF_Design::radius_choices(), array( 'DF_Design', 'sanitize_radius' ), __( 'Buttons, cards and framed images.', 'df-editor' ) ),
		);
		foreach ( $selects as $key => [ $label, $choices, $sanitize, $description ] ) {
			$id = DF_Design::OPTION . '[' . $key . ']';
			$wp_customize->add_setting(
				$id,
				array(
					'type'              => 'option',
					'capability'        => 'edit_theme_options',
					'default'           => $defaults[ $key ],
					'transport'         => 'refresh',
					'sanitize_callback' => $sanitize,
				)
			);
			$wp_customize->add_control(
				$id,
				array(
					'type'        => 'select',
					'label'       => $label,
					'description' => $description,
					'choices'     => $choices,
					'section'     => self::SECTION,
					'priority'    => $priority,
				)
			);
			$priority += 10;
		}

		$id = DF_Design::OPTION . '[button_style]';
		$wp_customize->add_setting(
			$id,
			array(
				'type'              => 'option',
				'capability'        => 'edit_theme_options',
				'default'           => 'filled',
				'transport'         => 'refresh',
				'sanitize_callback' => array( 'DF_Design', 'sanitize_button_style' ),
			)
		);
		$wp_customize->add_control(
			$id,
			array(
				'type'     => 'radio',
				'label'    => __( 'Button style', 'df-editor' ),
				'choices'  => array(
					'filled'  => __( 'Filled', 'df-editor' ),
					'outline' => __( 'Outline', 'df-editor' ),
				),
				'section'  => self::SECTION,
				'priority' => $priority,
			)
		);
	}

	/**
	 * Live contrast warnings (4.5:1) in the controls pane. Errors below 3:1 come from the server.
	 */
	public static function controls_script(): void {
		$pairs = array();
		foreach ( DF_Design::pairs() as $key => $list ) {
			foreach ( $list as [ $fg, $bg, $label ] ) {
				$pairs[ $key ][] = array( $fg, $bg, $label );
			}
		}
		$data = array(
			'option'  => DF_Design::OPTION,
			'colors'  => DF_Design::COLORS,
			'palette' => DF_Design::theme_palette(),
			'pairs'   => $pairs,
			'aa'      => DF_Design::CONTRAST_AA,
			'min'     => DF_Design::CONTRAST_MIN,
			/* translators: 1: colour pair description, 2: contrast ratio */
			'warning' => __( 'Low contrast: %1$s is %2$s:1. WCAG AA needs 4.5:1 for text. Saving is allowed, but please check.', 'df-editor' ),
		);
		$file = DF_EDITOR_DIR . 'assets/js/customizer-controls.js';
		wp_enqueue_script( 'df-customizer-controls', DF_EDITOR_URL . 'assets/js/customizer-controls.js', array( 'customize-controls' ), DF_EDITOR_VERSION . '.' . (string) @filemtime( $file ), true ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
		wp_add_inline_script( 'df-customizer-controls', 'window.dfDesign = ' . wp_json_encode( $data ) . ';', 'before' );
	}
}
