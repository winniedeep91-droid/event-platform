<?php
/**
 * Shared asset manager.
 *
 * @package EventOS
 */

declare( strict_types = 1 );

namespace EventOS\Admin;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Registers module scripts, styles, images and icons and enqueues them only on
 * the EventOS screens that ask for them.
 */
final class Asset_Manager {

	/**
	 * Registered assets keyed by handle.
	 *
	 * @var array<string, array<string, mixed>>
	 */
	private static array $assets = array();

	/**
	 * Register a single asset.
	 *
	 * Accepted keys: handle, type (script|style|image|icon), src (absolute URL
	 * or path relative to the plugin directory), path (absolute filesystem path
	 * used for versioning), deps, version, screens (menu slugs or '*'), footer,
	 * inline (string or callable), localize (array{object, data}), module.
	 *
	 * @param array<string, mixed> $asset  Asset definition.
	 * @param string               $module Owning module slug.
	 * @return void
	 */
	public static function register( array $asset, string $module = 'core' ): void {
		if ( empty( $asset['handle'] ) || empty( $asset['src'] ) ) {
			return;
		}

		$handle = sanitize_key( (string) $asset['handle'] );

		self::$assets[ $handle ] = wp_parse_args(
			$asset,
			array(
				'handle'   => $handle,
				'type'     => 'script',
				'path'     => '',
				'deps'     => array(),
				'version'  => EVENTOS_VERSION,
				'screens'  => array( '*' ),
				'footer'   => true,
				'inline'   => '',
				'localize' => array(),
				'module'   => $module,
			)
		);
	}

	/**
	 * Register several assets at once.
	 *
	 * @param array<int, array<string, mixed>> $assets Asset definitions.
	 * @param string                           $module Owning module slug.
	 * @return void
	 */
	public static function register_many( array $assets, string $module = 'core' ): void {
		foreach ( $assets as $asset ) {
			if ( is_array( $asset ) ) {
				self::register( $asset, $module );
			}
		}
	}

	/**
	 * All registered assets.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public static function all(): array {
		return self::$assets;
	}

	/**
	 * URL of a registered asset, useful for images and icons.
	 *
	 * @param string $handle Asset handle.
	 * @return string
	 */
	public static function url( string $handle ): string {
		$asset = self::$assets[ sanitize_key( $handle ) ] ?? null;

		return $asset ? self::resolve_url( (string) $asset['src'] ) : '';
	}

	/**
	 * Enqueue every asset registered for a screen.
	 *
	 * @param string $screen Menu slug of the current EventOS screen.
	 * @return void
	 */
	public static function enqueue_for_screen( string $screen ): void {
		foreach ( self::$assets as $asset ) {
			$screens = (array) $asset['screens'];

			if ( ! in_array( '*', $screens, true ) && ! in_array( $screen, $screens, true ) ) {
				continue;
			}

			self::enqueue( $asset );
		}
	}

	/**
	 * Enqueue a single asset definition.
	 *
	 * @param array<string, mixed> $asset Asset definition.
	 * @return void
	 */
	private static function enqueue( array $asset ): void {
		$type = (string) $asset['type'];

		if ( ! in_array( $type, array( 'script', 'style' ), true ) ) {
			return;
		}

		$path = (string) $asset['path'];

		if ( $path && ! file_exists( $path ) ) {
			return;
		}

		$handle  = (string) $asset['handle'];
		$src     = self::resolve_url( (string) $asset['src'] );
		$version = $path ? (string) filemtime( $path ) : (string) $asset['version'];

		if ( 'style' === $type ) {
			wp_enqueue_style( $handle, $src, (array) $asset['deps'], $version );

			$inline = self::inline( $asset );

			if ( $inline ) {
				wp_add_inline_style( $handle, $inline );
			}

			return;
		}

		wp_enqueue_script( $handle, $src, (array) $asset['deps'], $version, (bool) $asset['footer'] );

		if ( ! empty( $asset['localize']['object'] ) ) {
			$data = $asset['localize']['data'] ?? array();
			$data = is_callable( $data ) ? call_user_func( $data ) : $data;

			wp_add_inline_script(
				$handle,
				'window.' . (string) $asset['localize']['object'] . ' = ' . wp_json_encode( $data ) . ';',
				'before'
			);
		}

		$inline = self::inline( $asset );

		if ( $inline ) {
			wp_add_inline_script( $handle, $inline );
		}
	}

	/**
	 * Resolve an inline payload.
	 *
	 * @param array<string, mixed> $asset Asset definition.
	 * @return string
	 */
	private static function inline( array $asset ): string {
		$inline = $asset['inline'];

		return (string) ( is_callable( $inline ) ? call_user_func( $inline ) : $inline );
	}

	/**
	 * Turn a relative source into a plugin URL.
	 *
	 * @param string $src Source path or URL.
	 * @return string
	 */
	private static function resolve_url( string $src ): string {
		if ( preg_match( '#^(https?:)?//#', $src ) ) {
			return $src;
		}

		return EVENTOS_PLUGIN_URL . ltrim( $src, '/' );
	}
}
