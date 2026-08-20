<?php
/**
 * Shows a ticket holder their QR check-in code on their own order.
 *
 * @package EventOS
 */

declare( strict_types = 1 );

namespace EventOS\Events;

use WC_Order;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Every EventOS ticket already carries a scannable {@see Ticket_Identifier::qr_token()},
 * but until now nothing ever rendered it anywhere a ticket holder could see
 * it — the door's camera scanner had nothing real to scan. This hooks the
 * two WooCommerce templates a customer actually lands on for their own order
 * — the order-received ("Thank you") page and My Account → Orders → View —
 * and lists each active ticket with its number and a QR placeholder for
 * {@see ../../../src/customer/ticket-qr.ts} to render into.
 *
 * Deliberately not a new "My Tickets" portal or an emailed ticket: both of
 * those need a server-rendered QR *image* (a PDF attachment or an emailed
 * `<img>` can't run JavaScript), which would need a QR-image rendering
 * dependency this plugin has no Composer/vendor infrastructure for yet. This
 * covers the same information on the two pages WooCommerce already shows the
 * customer their order on, using only what already ships with the admin
 * build's dependencies.
 */
final class Ticket_Display {

	/**
	 * Ticket repository.
	 *
	 * @var Ticket_Repository
	 */
	private Ticket_Repository $tickets;

	/**
	 * Ticket type repository.
	 *
	 * @var Ticket_Type_Repository
	 */
	private Ticket_Type_Repository $ticket_types;

	/**
	 * Constructor.
	 *
	 * @param Ticket_Repository      $tickets      Ticket repository.
	 * @param Ticket_Type_Repository $ticket_types Ticket type repository.
	 */
	public function __construct( Ticket_Repository $tickets, Ticket_Type_Repository $ticket_types ) {
		$this->tickets      = $tickets;
		$this->ticket_types = $ticket_types;
	}

	/**
	 * Register WooCommerce hooks.
	 *
	 * @return void
	 */
	public function bootstrap(): void {
		add_action( 'woocommerce_order_details_after_order_table', array( $this, 'render_tickets' ) );
		add_action( 'wp_enqueue_scripts', array( $this, 'maybe_enqueue_assets' ) );
	}

	/**
	 * Enqueue the QR renderer only on the two customer pages that can show it.
	 *
	 * @return void
	 */
	public function maybe_enqueue_assets(): void {
		if ( ! $this->is_own_order_page() ) {
			return;
		}

		$path = EVENTOS_PLUGIN_DIR . 'assets/customer/ticket-qr.js';

		if ( ! file_exists( $path ) ) {
			return;
		}

		wp_enqueue_script(
			'eventos-ticket-qr',
			EVENTOS_PLUGIN_URL . 'assets/customer/ticket-qr.js',
			array(),
			(string) filemtime( $path ),
			true
		);

		// Registered on our own handle rather than attached to a WooCommerce
		// style handle via wp_add_inline_style() — that only outputs
		// anything if the target handle is already registered by the time
		// this runs, which depends on hook-priority ordering against
		// WooCommerce's own `wp_enqueue_scripts` callback.
		wp_register_style( 'eventos-ticket-display', false, array(), EVENTOS_VERSION );
		wp_enqueue_style( 'eventos-ticket-display' );
		wp_add_inline_style( 'eventos-ticket-display', $this->inline_styles() );
	}

	/**
	 * Render the ticket list for one order.
	 *
	 * Fires on both the order-received page and My Account → View Order —
	 * WooCommerce's own template already restricts both to the order's
	 * owner (session order-key match, or the logged-in customer who placed
	 * it), so no additional access check is needed here.
	 *
	 * @param WC_Order $order Order being viewed.
	 * @return void
	 */
	public function render_tickets( WC_Order $order ): void {
		$tickets = array_filter(
			$this->tickets->for_order( $order->get_id() ),
			static function ( array $ticket ): bool {
				return 'active' === $ticket['status'];
			}
		);

		if ( empty( $tickets ) ) {
			return;
		}

		$types_by_id = array();

		echo '<section class="eventos-tickets">';
		echo '<h2>' . esc_html__( 'Your tickets', 'eventos' ) . '</h2>';
		echo '<div class="eventos-tickets__grid">';

		foreach ( $tickets as $ticket ) {
			$type_id = (int) $ticket['ticket_type_id'];

			if ( ! isset( $types_by_id[ $type_id ] ) ) {
				$types_by_id[ $type_id ] = $this->ticket_types->find( $type_id );
			}

			$type_name = null !== $types_by_id[ $type_id ] ? (string) $types_by_id[ $type_id ]['name'] : __( 'Ticket', 'eventos' );

			echo '<div class="eventos-ticket-card">';
			echo '<p class="eventos-ticket-card__type">' . esc_html( $type_name ) . '</p>';
			echo '<p class="eventos-ticket-card__number">' . esc_html( (string) $ticket['ticket_number'] ) . '</p>';

			if ( $ticket['checked_in'] ) {
				echo '<p class="eventos-ticket-card__status">' . esc_html__( 'Already checked in', 'eventos' ) . '</p>';
			} else {
				printf(
					'<div class="eventos-ticket-qr" data-eventos-qr="%s"></div>',
					esc_attr( (string) $ticket['qr_token'] )
				);
				echo '<p class="eventos-ticket-card__hint">' . esc_html__( 'Show this code at the door.', 'eventos' ) . '</p>';
			}

			echo '</div>';
		}

		echo '</div>';
		echo '</section>';
	}

	/**
	 * Whether the current request is the customer viewing their own order.
	 *
	 * @return bool
	 */
	private function is_own_order_page(): bool {
		if ( function_exists( 'is_order_received_page' ) && is_order_received_page() ) {
			return true;
		}

		return function_exists( 'is_wc_endpoint_url' ) && is_wc_endpoint_url( 'view-order' );
	}

	/**
	 * Minimal, self-contained styling — deliberately not pulled from the
	 * admin build's design tokens, since this renders on the storefront
	 * theme, not inside EventOS's own admin shell.
	 *
	 * @return string
	 */
	private function inline_styles(): string {
		return '
			.eventos-tickets { margin-top: 2em; }
			.eventos-tickets__grid { display: flex; flex-wrap: wrap; gap: 1.5em; margin-top: 1em; }
			.eventos-ticket-card { border: 1px solid #ddd; border-radius: 8px; padding: 1.25em; text-align: center; min-width: 200px; }
			.eventos-ticket-card__type { font-weight: 600; margin: 0 0 0.25em; }
			.eventos-ticket-card__number { font-family: monospace; color: #555; margin: 0 0 1em; }
			.eventos-ticket-card__status { color: #2e7d32; font-weight: 600; }
			.eventos-ticket-card__hint { font-size: 0.85em; color: #777; margin: 0.75em 0 0; }
			.eventos-ticket-qr { display: inline-flex; align-items: center; justify-content: center; min-height: 160px; min-width: 160px; }
		';
	}
}
