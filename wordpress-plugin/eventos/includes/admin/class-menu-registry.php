<?php
/**
 * Admin menu registration framework.
 *
 * @package EventOS
 */

declare( strict_types = 1 );

namespace EventOS\Admin;

use EventOS\Capabilities;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Modules register admin pages here instead of calling add_menu_page().
 *
 * The registry owns capability checks, ordering, visibility and rendering of
 * the shared React mount point.
 */
final class Menu_Registry {

	/**
	 * Top level menu slug.
	 */
	public const ROOT_SLUG = 'eventos';

	/**
	 * Registered items keyed by menu slug.
	 *
	 * @var array<string, array<string, mixed>>
	 */
	private static array $items = array();

	/**
	 * Whether the admin_menu hook is attached.
	 *
	 * @var bool
	 */
	private static bool $hooked = false;

	/**
	 * Attach the registry to WordPress.
	 *
	 * @return void
	 */
	public static function init(): void {
		if ( self::$hooked ) {
			return;
		}

		self::$hooked = true;

		add_action( 'admin_menu', array( __CLASS__, 'register_menu' ) );
	}

	/**
	 * Register a menu item.
	 *
	 * Accepted keys: slug, title, menu_title, view, capability, parent, icon,
	 * position, order, visible (callable), module.
	 *
	 * @param array<string, mixed> $item   Menu item definition.
	 * @param string               $module Owning module slug.
	 * @return void
	 */
	public static function register( array $item, string $module = 'core' ): void {
		if ( empty( $item['slug'] ) || empty( $item['title'] ) ) {
			return;
		}

		$slug = sanitize_key( (string) $item['slug'] );

		self::$items[ $slug ] = wp_parse_args(
			$item,
			array(
				'slug'       => $slug,
				'menu_title' => (string) $item['title'],
				'view'       => $slug,
				'capability' => Capabilities::VIEW_DASHBOARD,
				'parent'     => self::ROOT_SLUG,
				'icon'       => 'dashicons-tickets-alt',
				'position'   => 26,
				'order'      => 10,
				'visible'    => null,
				'module'     => $module,
			)
		);

		self::$items[ $slug ]['slug'] = $slug;
	}

	/**
	 * Register several menu items at once.
	 *
	 * @param array<int, array<string, mixed>> $items  Menu item definitions.
	 * @param string                           $module Owning module slug.
	 * @return void
	 */
	public static function register_many( array $items, string $module = 'core' ): void {
		foreach ( $items as $item ) {
			if ( is_array( $item ) ) {
				self::register( $item, $module );
			}
		}
	}

	/**
	 * All registered pages keyed by slug, ordered.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public static function pages(): array {
		$items = self::$items;

		uasort(
			$items,
			static function ( array $a, array $b ): int {
				return (int) $a['order'] <=> (int) $b['order'];
			}
		);

		/**
		 * Filter the registered EventOS admin pages.
		 *
		 * @param array $items Menu items keyed by slug.
		 */
		return (array) apply_filters( 'eventos_admin_pages', $items );
	}

	/**
	 * Pages the given user may open.
	 *
	 * @param int $user_id User ID, 0 for the current user.
	 * @return array<string, array<string, mixed>>
	 */
	public static function visible_pages( int $user_id = 0 ): array {
		$visible = array();

		foreach ( self::pages() as $slug => $item ) {
			if ( is_callable( $item['visible'] ) && ! call_user_func( $item['visible'], $item ) ) {
				continue;
			}

			$allowed = $user_id
				? user_can( $user_id, (string) $item['capability'] )
				: current_user_can( (string) $item['capability'] );

			if ( $allowed ) {
				$visible[ $slug ] = $item;
			}
		}

		return $visible;
	}

	/**
	 * Register the WordPress menu from the registry.
	 *
	 * @return void
	 */
	public static function register_menu(): void {
		$pages = self::pages();

		if ( ! isset( $pages[ self::ROOT_SLUG ] ) ) {
			return;
		}

		$root = $pages[ self::ROOT_SLUG ];

		add_menu_page(
			'EventOS',
			'EventOS',
			(string) $root['capability'],
			self::ROOT_SLUG,
			array( __CLASS__, 'render' ),
			(string) $root['icon'],
			(int) $root['position']
		);

		foreach ( $pages as $slug => $page ) {
			if ( is_callable( $page['visible'] ) && ! call_user_func( $page['visible'], $page ) ) {
				continue;
			}

			add_submenu_page(
				(string) $page['parent'],
				sprintf( 'EventOS — %s', (string) $page['title'] ),
				(string) $page['menu_title'],
				(string) $page['capability'],
				$slug,
				array( __CLASS__, 'render' )
			);
		}
	}

	/**
	 * Render the React mount point for the current page.
	 *
	 * @return void
	 */
	public static function render(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$slug  = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : self::ROOT_SLUG;
		$pages = self::pages();
		$page  = $pages[ $slug ] ?? $pages[ self::ROOT_SLUG ];

		if ( ! current_user_can( (string) $page['capability'] ) ) {
			wp_die( esc_html__( 'You are not allowed to access this EventOS screen.', 'eventos' ), 403 );
		}

		printf(
			'<div class="wrap eventos-admin"><div id="eventos-admin-root" data-view="%1$s"><p class="eventos-admin__loading">%2$s</p></div></div>',
			esc_attr( (string) $page['view'] ),
			esc_html__( 'Loading EventOS…', 'eventos' )
		);
	}

	/**
	 * Links to every EventOS screen a user may access.
	 *
	 * @return array<int, array<string, string>>
	 */
	public static function links(): array {
		$links = array();

		foreach ( self::visible_pages() as $slug => $page ) {
			$links[] = array(
				'slug'  => $slug,
				'view'  => (string) $page['view'],
				'title' => (string) $page['title'],
				'url'   => esc_url_raw( admin_url( 'admin.php?page=' . $slug ) ),
			);
		}

		return $links;
	}
}
