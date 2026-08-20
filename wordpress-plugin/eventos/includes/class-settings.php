<?php
/**
 * Schema driven configuration store built on the WordPress options API.
 *
 * @package EventOS
 */

declare( strict_types = 1 );

namespace EventOS;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Settings registry.
 *
 * One schema drives defaults, sanitisation, the REST API and the React admin UI,
 * so future modules can extend configuration through a single filter.
 */
final class Settings {

	/**
	 * Option name prefix, one option per settings group.
	 */
	public const OPTION_PREFIX = 'eventos_settings_';

	/**
	 * Option flag: has an administrator ever explicitly saved the branding
	 * settings group through the real save path (update_group())? This is
	 * the only signal that distinguishes a genuine customization from a
	 * value that was merely auto-seeded by install_defaults() at some past
	 * activation and never touched since — only the latter is safe to
	 * migrate forward when the code's default palette changes. Never set by
	 * maybe_reseed_branding_colors() itself, only by an explicit save.
	 */
	public const BRANDING_CUSTOMIZED_OPTION = 'eventos_branding_customized';

	/**
	 * The branding fields a future default-palette migration is allowed to
	 * touch. Logo/attachment fields and everything else on the group are
	 * never touched by maybe_reseed_branding_colors().
	 *
	 * @var string[]
	 */
	private const BRANDING_COLOUR_FIELDS = array( 'primary_color', 'secondary_color', 'accent_color' );

	/**
	 * Cached schema.
	 *
	 * @var array<string, array<string, mixed>>|null
	 */
	private static ?array $schema = null;

	/**
	 * Full settings schema grouped by section.
	 *
	 * @return array<string, array{label: string, description: string, fields: array<string, array<string, mixed>>}>
	 */
	public static function schema(): array {
		if ( null !== self::$schema ) {
			return self::$schema;
		}

		$schema = array(
			'general'  => array(
				'label'       => __( 'General', 'eventos' ),
				'description' => __( 'Core business identity used across every EventOS module.', 'eventos' ),
				'fields'      => array(
					'business_name'        => self::field( __( 'Business Name', 'eventos' ), 'text', '' ),
					'legal_business_name'  => self::field( __( 'Legal Business Name', 'eventos' ), 'text', '' ),
					'registration_number'  => self::field( __( 'Business Registration Number', 'eventos' ), 'text', '' ),
					'website'              => self::field( __( 'Website', 'eventos' ), 'url', '' ),
					'support_email'        => self::field( __( 'Support Email', 'eventos' ), 'email', '' ),
					'support_phone'        => self::field( __( 'Support Phone', 'eventos' ), 'text', '' ),
					'timezone'             => self::field( __( 'Default Timezone', 'eventos' ), 'timezone', '' ),
					'currency'             => self::field( __( 'Default Currency', 'eventos' ), 'text', 'USD' ),
					'language'             => self::field( __( 'Language', 'eventos' ), 'text', 'en_US' ),
					'date_format'          => self::field( __( 'Date Format', 'eventos' ), 'text', 'F j, Y' ),
					'time_format'          => self::field( __( 'Time Format', 'eventos' ), 'text', 'H:i' ),
				),
			),
			'branding' => array(
				'label'       => __( 'Branding', 'eventos' ),
				'description' => __( 'Logos and colours reused by the dashboard, e-mails and PDF documents.', 'eventos' ),
				'fields'      => array(
					'business_logo_id'     => self::field( __( 'Business Logo', 'eventos' ), 'attachment', 0 ),
					'dashboard_logo_id'    => self::field( __( 'Dashboard Logo', 'eventos' ), 'attachment', 0 ),
					'login_logo_id'        => self::field( __( 'Login Logo', 'eventos' ), 'attachment', 0 ),
					'email_header_logo_id' => self::field( __( 'Email Header Logo', 'eventos' ), 'attachment', 0 ),
					'pdf_logo_id'          => self::field( __( 'PDF Logo', 'eventos' ), 'attachment', 0 ),
					'favicon_id'           => self::field( __( 'Favicon', 'eventos' ), 'attachment', 0 ),
					// Defaults match the Cherry Red / Sky Blue tokens the admin
					// UI ships with (src/wp-admin/ui/ui.css: --eos-primary/
					// --eos-secondary/--eos-accent) so a fresh install's branding
					// preview matches what the shell actually looks like.
					'primary_color'        => self::field( __( 'Primary Colour', 'eventos' ), 'color', '#be0015' ),
					'secondary_color'      => self::field( __( 'Secondary Colour', 'eventos' ), 'color', '#92e1ff' ),
					'accent_color'         => self::field( __( 'Accent Colour', 'eventos' ), 'color', '#92e1ff' ),
				),
			),
			'regional' => array(
				'label'       => __( 'Regional', 'eventos' ),
				'description' => __( 'Locale, tax and formatting defaults for this installation.', 'eventos' ),
				'fields'      => array(
					'country'            => self::field( __( 'Country', 'eventos' ), 'text', '' ),
					'state'              => self::field( __( 'Province / State', 'eventos' ), 'text', '' ),
					'city'               => self::field( __( 'City', 'eventos' ), 'text', '' ),
					'currency_symbol'    => self::field( __( 'Currency Symbol', 'eventos' ), 'text', '$' ),
					'tax_percentage'     => self::field( __( 'Tax Percentage', 'eventos' ), 'number', 0.0 ),
					'number_format'      => self::field(
						__( 'Number Format', 'eventos' ),
						'choice',
						'1,234.56',
						array( '1,234.56', '1.234,56', '1 234,56', '1234.56' )
					),
					'measurement_system' => self::field(
						__( 'Measurement System', 'eventos' ),
						'choice',
						'metric',
						array( 'metric', 'imperial' )
					),
				),
			),
			'security' => array(
				'label'       => __( 'Security', 'eventos' ),
				'description' => __( 'Access rules applied to EventOS users.', 'eventos' ),
				'fields'      => array(
					'session_timeout_minutes' => self::field( __( 'Session Timeout (minutes)', 'eventos' ), 'integer', 120 ),
					'password_min_length'     => self::field( __( 'Minimum Password Length', 'eventos' ), 'integer', 12 ),
					'password_require_mixed'  => self::field( __( 'Require Upper And Lower Case', 'eventos' ), 'boolean', true ),
					'password_require_number' => self::field( __( 'Require A Number', 'eventos' ), 'boolean', true ),
					'password_require_symbol' => self::field( __( 'Require A Symbol', 'eventos' ), 'boolean', false ),
					'login_notifications'     => self::field( __( 'Login Notifications', 'eventos' ), 'boolean', true ),
					'allowed_email_domains'   => self::field( __( 'Allowed Email Domains', 'eventos' ), 'list', array() ),
				),
			),
		);

		/**
		 * Filter the EventOS settings schema so modules can add their own groups.
		 *
		 * @param array $schema Settings schema.
		 */
		self::$schema = (array) apply_filters( 'eventos_settings_schema', $schema );

		return self::$schema;
	}

	/**
	 * Build a single field definition.
	 *
	 * @param string $label   Field label.
	 * @param string $type    Field type.
	 * @param mixed  $default Default value.
	 * @param array  $choices Allowed values for choice fields.
	 * @return array<string, mixed>
	 */
	private static function field( string $label, string $type, $default, array $choices = array() ): array {
		return array(
			'label'   => $label,
			'type'    => $type,
			'default' => $default,
			'choices' => $choices,
		);
	}

	/**
	 * Register additional settings groups contributed by a module.
	 *
	 * @param array<string, array<string, mixed>> $groups Group definitions.
	 * @return void
	 */
	public static function register_groups( array $groups ): void {
		if ( ! $groups ) {
			return;
		}

		self::schema();

		foreach ( $groups as $slug => $group ) {
			$slug = sanitize_key( (string) $slug );

			if ( '' === $slug || empty( $group['fields'] ) || ! is_array( $group['fields'] ) ) {
				continue;
			}

			self::$schema[ $slug ] = array(
				'label'       => (string) ( $group['label'] ?? $slug ),
				'description' => (string) ( $group['description'] ?? '' ),
				'fields'      => $group['fields'],
			);

			if ( false === get_option( self::option_name( $slug ), false ) ) {
				add_option( self::option_name( $slug ), self::defaults( $slug ) );
			}
		}
	}

	/**
	 * Build a single field definition for module authors.
	 *
	 * @param string $label   Field label.
	 * @param string $type    Field type.
	 * @param mixed  $default Default value.
	 * @param array  $choices Allowed values for choice fields.
	 * @return array<string, mixed>
	 */
	public static function define_field( string $label, string $type, $default, array $choices = array() ): array {
		return self::field( $label, $type, $default, $choices );
	}

	/**
	 * Option name for a group.
	 *
	 * @param string $group Group slug.
	 * @return string
	 */
	public static function option_name( string $group ): string {
		return self::OPTION_PREFIX . $group;
	}

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public static function init(): void {
		add_action( 'eventos_settings_updated', array( __CLASS__, 'mark_branding_customized' ) );
		add_action( 'eventos_upgraded', array( __CLASS__, 'maybe_reseed_branding_colors' ) );
	}

	/**
	 * Marks branding as administrator-customized the moment it is saved
	 * through the real settings save path. This is the only place
	 * BRANDING_CUSTOMIZED_OPTION is ever set — maybe_reseed_branding_colors()
	 * writes the option directly and never calls update_group(), so the
	 * migration itself can never trigger this and mark itself as a
	 * customization.
	 *
	 * @param string $group Group slug that was saved.
	 * @return void
	 */
	public static function mark_branding_customized( string $group ): void {
		if ( 'branding' !== $group ) {
			return;
		}

		update_option( self::BRANDING_CUSTOMIZED_OPTION, true );
	}

	/**
	 * One-time-per-upgrade migration: if this installation's branding has
	 * never been explicitly saved by an administrator, refresh only its
	 * three colour fields to the current code defaults so a palette change
	 * reaches installs nobody has customized. Logo/media fields and every
	 * other branding field are left exactly as stored, and this never sets
	 * BRANDING_CUSTOMIZED_OPTION — an install that has never been
	 * customized must stay eligible for the next palette change too.
	 *
	 * @return void
	 */
	public static function maybe_reseed_branding_colors(): void {
		if ( get_option( self::BRANDING_CUSTOMIZED_OPTION ) ) {
			return;
		}

		$option_name = self::option_name( 'branding' );
		$stored      = get_option( $option_name, array() );
		$stored      = is_array( $stored ) ? $stored : array();
		$defaults    = self::defaults( 'branding' );

		foreach ( self::BRANDING_COLOUR_FIELDS as $field ) {
			if ( isset( $defaults[ $field ] ) ) {
				$stored[ $field ] = $defaults[ $field ];
			}
		}

		update_option( $option_name, $stored );
	}

	/**
	 * Default values for a group.
	 *
	 * @param string $group Group slug.
	 * @return array<string, mixed>
	 */
	public static function defaults( string $group ): array {
		$schema = self::schema();

		if ( ! isset( $schema[ $group ] ) ) {
			return array();
		}

		$defaults = array();

		foreach ( $schema[ $group ]['fields'] as $key => $field ) {
			$defaults[ $key ] = $field['default'];
		}

		return $defaults;
	}

	/**
	 * Seed default options on install without overwriting existing values.
	 *
	 * @return void
	 */
	public static function install_defaults(): void {
		foreach ( array_keys( self::schema() ) as $group ) {
			$defaults = self::defaults( $group );

			if ( 'general' === $group ) {
				$defaults['business_name'] = get_bloginfo( 'name' );
				$defaults['website']       = home_url();
				$defaults['support_email'] = get_bloginfo( 'admin_email' );
				$defaults['timezone']      = wp_timezone_string();
				$defaults['date_format']   = (string) get_option( 'date_format', 'F j, Y' );
				$defaults['time_format']   = (string) get_option( 'time_format', 'H:i' );
				$defaults['language']      = (string) get_option( 'WPLANG', 'en_US' );
			}

			add_option( self::option_name( $group ), $defaults );
		}
	}

	/**
	 * Read a settings group merged with its defaults.
	 *
	 * @param string $group Group slug.
	 * @return array<string, mixed>
	 */
	public static function get_group( string $group ): array {
		$defaults = self::defaults( $group );
		$stored   = get_option( self::option_name( $group ), array() );
		$stored   = is_array( $stored ) ? $stored : array();

		return array_merge( $defaults, array_intersect_key( $stored, $defaults ) );
	}

	/**
	 * Read every settings group.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public static function get_all(): array {
		$all = array();

		foreach ( array_keys( self::schema() ) as $group ) {
			$all[ $group ] = self::get_group( $group );
		}

		return $all;
	}

	/**
	 * Read a single value.
	 *
	 * @param string $group Group slug.
	 * @param string $key   Field key.
	 * @return mixed
	 */
	public static function get( string $group, string $key ) {
		$values = self::get_group( $group );

		return $values[ $key ] ?? null;
	}

	/**
	 * Persist a settings group after sanitising every field.
	 *
	 * @param string               $group Group slug.
	 * @param array<string, mixed> $input Raw input values.
	 * @return array<string, mixed> The stored values.
	 */
	public static function update_group( string $group, array $input ): array {
		$schema = self::schema();

		if ( ! isset( $schema[ $group ] ) ) {
			return array();
		}

		$current = self::get_group( $group );
		$values  = $current;

		foreach ( $schema[ $group ]['fields'] as $key => $field ) {
			if ( ! array_key_exists( $key, $input ) ) {
				continue;
			}

			$values[ $key ] = self::sanitize_field( $field, $input[ $key ] );
		}

		update_option( self::option_name( $group ), $values );

		/**
		 * Fires after a settings group has been saved.
		 *
		 * @param string $group  Group slug.
		 * @param array  $values Stored values.
		 */
		do_action( 'eventos_settings_updated', $group, $values );

		return $values;
	}

	/**
	 * Sanitise a value according to its field definition.
	 *
	 * @param array<string, mixed> $field Field definition.
	 * @param mixed                $value Raw value.
	 * @return mixed
	 */
	public static function sanitize_field( array $field, $value ) {
		switch ( $field['type'] ) {
			case 'email':
				return sanitize_email( (string) $value );

			case 'url':
				return esc_url_raw( (string) $value );

			case 'color':
				$color = sanitize_hex_color( (string) $value );

				return $color ? $color : (string) $field['default'];

			case 'attachment':
				$id = absint( $value );

				return ( $id && 'attachment' === get_post_type( $id ) ) ? $id : 0;

			case 'integer':
				return absint( $value );

			case 'number':
				return round( (float) $value, 4 );

			case 'boolean':
				return (bool) filter_var( $value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE );

			case 'timezone':
				$zone = sanitize_text_field( (string) $value );

				return in_array( $zone, timezone_identifiers_list(), true ) ? $zone : wp_timezone_string();

			case 'choice':
				$choice = sanitize_text_field( (string) $value );

				return in_array( $choice, (array) $field['choices'], true ) ? $choice : (string) $field['default'];

			case 'list':
				$items = is_array( $value ) ? $value : explode( ',', (string) $value );
				$items = array_filter( array_map( 'sanitize_text_field', array_map( 'trim', $items ) ) );

				return array_values( array_unique( $items ) );

			default:
				return sanitize_text_field( (string) $value );
		}
	}
}
