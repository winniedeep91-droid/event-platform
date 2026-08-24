<?php
/**
 * Automatic Event + Ticket Type provisioning from WooCommerce variable products.
 *
 * @package EventOS
 */

declare( strict_types = 1 );

namespace EventOS\Woocommerce;

use EventOS\Events\Event_Identity_Repository;
use EventOS\Events\Event_Identity_Resolver;
use EventOS\Events\Event_Service;
use EventOS\Events\Ticket_Type_Repository;
use EventOS\WooCommerce;
use WC_Product;
use WC_Product_Variable;
use WC_Product_Variation;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The EventOS/WooCommerce event model: one WooCommerce *variable* product is
 * one Event, and its variations are that Event's ticket types. Deterministic
 * identity only — `wc_product_id` (the parent product's own ID) via
 * {@see Event_Identity_Resolver}, never name/date matching.
 *
 * Manual mapping stays authoritative: a product already carrying
 * `Wc_Meta::EVENT_META` (set by the existing admin mapping UI) is adopted
 * as-is, never overridden, and opportunistically gets its identity
 * backfilled so later syncs resolve the fast, idempotent way too.
 *
 * Ticket types created here use {@see Ticket_Type_Repository::attach_from_wc_variation()}
 * / {@see Ticket_Type_Repository::update_from_wc_variation()} — never the
 * normal create()/update() path, which pushes a *new* WooCommerce product.
 * The WooCommerce variation already exists; EventOS attaches to it.
 */
final class Wc_Event_Provisioning {

	/**
	 * Provision/update Events and Ticket Types from every WooCommerce
	 * variable product.
	 *
	 * @return array{events_created: int, events_matched: int, ticket_types_created: int, ticket_types_updated: int}
	 */
	public static function sync(): array {
		$counts = array(
			'events_created'       => 0,
			'events_matched'       => 0,
			'ticket_types_created' => 0,
			'ticket_types_updated' => 0,
		);

		if ( ! WooCommerce::is_active() ) {
			return $counts;
		}

		$event_service    = new Event_Service();
		$event_identities = new Event_Identity_Repository();
		$event_resolver   = new Event_Identity_Resolver( $event_service, $event_identities );
		$ticket_types     = new Ticket_Type_Repository();
		$now              = current_time( 'mysql', true );

		$product_ids = wc_get_products(
			array(
				'type'   => 'variable',
				'limit'  => -1,
				'status' => array( 'publish', 'draft', 'pending', 'private' ),
				'return' => 'ids',
			)
		);

		foreach ( $product_ids as $product_id ) {
			$product = wc_get_product( $product_id );

			if ( ! $product instanceof WC_Product_Variable ) {
				continue;
			}

			$event_id = self::resolve_event( (int) $product_id, $product, $event_resolver, $event_identities, $event_service, $counts );

			if ( $event_id <= 0 ) {
				continue;
			}

			update_post_meta( (int) $product_id, Wc_Meta::EVENT_META, $event_id );
			update_post_meta( (int) $product_id, Wc_Meta::SYNCED_META, $now );

			foreach ( $product->get_children() as $variation_id ) {
				self::resolve_ticket_type( (int) $variation_id, $event_id, $ticket_types, $counts, $now );
			}
		}

		return $counts;
	}

	/**
	 * Resolve (manual mapping first) or auto-create the Event for one
	 * variable product.
	 *
	 * @param int                      $product_id       WooCommerce parent product ID.
	 * @param WC_Product_Variable      $product          The product.
	 * @param Event_Identity_Resolver  $resolver         Event identity resolver.
	 * @param Event_Identity_Repository $identities      Event identity repository.
	 * @param Event_Service            $events           Event service, used only to validate a manual mapping still points to a real row.
	 * @param array<string, int>      $counts           Counters, updated in place.
	 * @return int Event ID, 0 on failure.
	 */
	private static function resolve_event( int $product_id, WC_Product_Variable $product, Event_Identity_Resolver $resolver, Event_Identity_Repository $identities, Event_Service $events, array &$counts ): int {
		$manual_event_id = (int) get_post_meta( $product_id, Wc_Meta::EVENT_META, true );

		if ( $manual_event_id > 0 ) {
			if ( null !== $events->events()->find( $manual_event_id ) ) {
				$identities->attach_identity( $manual_event_id, 'wc_product_id', (string) $product_id );
				++$counts['events_matched'];

				return $manual_event_id;
			}

			// This product's manual-mapping meta points at an Event that no
			// longer exists — most commonly because the plugin was deleted
			// and reinstalled: uninstall.php drops every EventOS table but
			// never touches WordPress's own post meta, so the mapping
			// survives pointing at a row that isn't there. Trusting it as-is
			// would silently attach an identity/ticket type to a
			// non-existent event on every sync, forever, with no error and
			// no way to self-heal. Clearing it here makes this exactly
			// equivalent to "no manual mapping was ever set", so the normal
			// identity-based resolve-or-create path below runs instead and
			// provisions a real Event.
			delete_post_meta( $product_id, Wc_Meta::EVENT_META );
		}

		$result = $resolver->resolve_or_create(
			'wc_product_id',
			(string) $product_id,
			array(
				'title'             => $product->get_name(),
				'description'       => (string) $product->get_description(),
				'short_description' => (string) $product->get_short_description(),
			)
		);

		if ( is_wp_error( $result ) ) {
			return 0;
		}

		++$counts[ $result['created'] ? 'events_created' : 'events_matched' ];

		return (int) $result['event']['id'];
	}

	/**
	 * Resolve (existing wc_product_id) or attach a new Ticket Type for one
	 * variation.
	 *
	 * @param int                    $variation_id WooCommerce variation ID.
	 * @param int                    $event_id     Event ID the ticket type belongs to.
	 * @param Ticket_Type_Repository $ticket_types Ticket type repository.
	 * @param array<string, int>     $counts       Counters, updated in place.
	 * @param string                 $now          Current MySQL UTC timestamp.
	 * @return void
	 */
	private static function resolve_ticket_type( int $variation_id, int $event_id, Ticket_Type_Repository $ticket_types, array &$counts, string $now ): void {
		$variation = wc_get_product( $variation_id );

		if ( ! $variation instanceof WC_Product_Variation ) {
			return;
		}

		$data = self::variation_ticket_type_data( $variation );

		$existing = $ticket_types->find_by_wc_product_id( $variation_id );

		if ( null !== $existing ) {
			$ticket_types->update_from_wc_variation( (int) $existing['id'], $data );
			++$counts['ticket_types_updated'];
			$ticket_type_id = (int) $existing['id'];
		} else {
			$created = $ticket_types->attach_from_wc_variation( $event_id, $variation_id, $data );

			if ( is_wp_error( $created ) ) {
				return;
			}

			++$counts['ticket_types_created'];
			$ticket_type_id = (int) $created['id'];
		}

		update_post_meta( $variation_id, Wc_Meta::TICKET_TYPE_META, $ticket_type_id );
		update_post_meta( $variation_id, Wc_Meta::SYNCED_META, $now );
	}

	/**
	 * Map a WooCommerce variation onto the ticket-type field shape
	 * {@see Ticket_Type_Repository::sanitize()} expects.
	 *
	 * @param WC_Product_Variation $variation The variation.
	 * @return array<string, mixed>
	 */
	private static function variation_ticket_type_data( WC_Product_Variation $variation ): array {
		return array(
			'name'        => self::variation_display_name( $variation ),
			'description' => (string) $variation->get_description(),
			'price'       => (float) $variation->get_regular_price(),
			'capacity'    => $variation->managing_stock() ? max( 0, (int) $variation->get_stock_quantity() ) : null,
			'status'      => 'publish' === $variation->get_status() ? 'active' : 'paused',
		);
	}

	/**
	 * The ticket-type name for a variation: the value of its first
	 * variation attribute (e.g. "GA"), resolved to its proper term label
	 * for a taxonomy attribute rather than the raw slug. Deliberately just
	 * the value, not WooCommerce's own "Attribute: Value" summary format —
	 * "GA" reads as a ticket type, "Ticket type: GA" does not.
	 *
	 * @param WC_Product_Variation $variation The variation.
	 * @return string
	 */
	private static function variation_display_name( WC_Product_Variation $variation ): string {
		foreach ( $variation->get_attributes() as $taxonomy => $value ) {
			$value = (string) $value;

			if ( '' === $value ) {
				continue;
			}

			if ( taxonomy_exists( $taxonomy ) ) {
				$term = get_term_by( 'slug', $value, $taxonomy );

				if ( $term instanceof \WP_Term ) {
					return $term->name;
				}
			}

			return $value;
		}

		return trim( (string) $variation->get_name() );
	}
}
