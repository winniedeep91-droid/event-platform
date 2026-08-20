<?php
/**
 * Validation and orchestration for event expenses.
 *
 * @package EventOS
 */

declare( strict_types = 1 );

namespace EventOS\Finance;

use EventOS\WooCommerce;
use WP_Error;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Thin layer between the REST controller and {@see Expense_Repository}:
 * everything here is validation and defaulting, no query logic of its own.
 */
final class Expense_Service {

	/**
	 * Repository.
	 *
	 * @var Expense_Repository
	 */
	private Expense_Repository $repository;

	/**
	 * Constructor.
	 *
	 * @param Expense_Repository $repository Repository.
	 */
	public function __construct( Expense_Repository $repository ) {
		$this->repository = $repository;
	}

	/**
	 * Suggested expense categories. Not an enum — a custom category is
	 * still a plain string the way "other custom categories" in the
	 * spec calls for; these are only what the UI offers by default.
	 *
	 * @return string[]
	 */
	public static function suggested_categories(): array {
		return array(
			'venue',
			'artist_fees',
			'production',
			'sound',
			'lighting',
			'security',
			'staffing',
			'marketing',
			'accommodation',
			'transport',
			'permits',
			'catering',
			'equipment',
			'miscellaneous',
			'other',
		);
	}

	/**
	 * List expenses for an event.
	 *
	 * @param int                   $event_id Event ID.
	 * @param array<string, mixed> $args     Query args.
	 * @return array{items: array<int, array<string, mixed>>, total: int, page: int, per_page: int}
	 */
	public function list_for_event( int $event_id, array $args = array() ): array {
		$args['event_id'] = $event_id;

		return $this->repository->query( $args );
	}

	/**
	 * Create an expense.
	 *
	 * @param int                   $event_id Event ID.
	 * @param array<string, mixed> $data     Submitted fields.
	 * @param int                   $user_id  Creating user.
	 * @return array<string, mixed>|WP_Error
	 */
	public function create( int $event_id, array $data, int $user_id ) {
		$validated = $this->validate( $data );

		if ( is_wp_error( $validated ) ) {
			return $validated;
		}

		$validated['event_id']   = $event_id;
		$validated['created_by'] = $user_id;

		$expense = $this->repository->create( $validated );

		if ( null === $expense ) {
			return new WP_Error( 'eventos_expense_create_failed', __( 'The expense could not be saved.', 'eventos' ), array( 'status' => 500 ) );
		}

		return $expense;
	}

	/**
	 * Update an expense, scoped to the given event.
	 *
	 * @param int                   $event_id   Event ID.
	 * @param int                   $expense_id Expense ID.
	 * @param array<string, mixed> $data       Submitted fields.
	 * @return array<string, mixed>|WP_Error
	 */
	public function update( int $event_id, int $expense_id, array $data ) {
		$existing = $this->repository->find( $expense_id );

		if ( null === $existing || $existing['event_id'] !== $event_id ) {
			return new WP_Error( 'eventos_expense_not_found', __( 'Expense not found for this event.', 'eventos' ), array( 'status' => 404 ) );
		}

		if ( Expense_Repository::STATUS_VOID === $existing['status'] ) {
			return new WP_Error( 'eventos_expense_voided', __( 'A voided expense cannot be edited.', 'eventos' ), array( 'status' => 409 ) );
		}

		$validated = $this->validate( $data, false );

		if ( is_wp_error( $validated ) ) {
			return $validated;
		}

		return $this->repository->update( $expense_id, $validated );
	}

	/**
	 * Void an expense, scoped to the given event.
	 *
	 * @param int $event_id   Event ID.
	 * @param int $expense_id Expense ID.
	 * @return true|WP_Error
	 */
	public function void( int $event_id, int $expense_id ) {
		$existing = $this->repository->find( $expense_id );

		if ( null === $existing || $existing['event_id'] !== $event_id ) {
			return new WP_Error( 'eventos_expense_not_found', __( 'Expense not found for this event.', 'eventos' ), array( 'status' => 404 ) );
		}

		if ( Expense_Repository::STATUS_VOID === $existing['status'] ) {
			// Already void — treat a repeat request as a success rather
			// than an error, so a doubled click/retry is harmless.
			return true;
		}

		return $this->repository->void( $expense_id ) ? true : new WP_Error( 'eventos_expense_void_failed', __( 'The expense could not be voided.', 'eventos' ), array( 'status' => 500 ) );
	}

	/**
	 * Validate and sanitize submitted expense fields.
	 *
	 * @param array<string, mixed> $data     Submitted fields.
	 * @param bool                  $require_amount Whether amount/description are required (false on partial update).
	 * @return array<string, mixed>|WP_Error
	 */
	private function validate( array $data, bool $require_amount = true ) {
		$out = array();

		if ( array_key_exists( 'description', $data ) ) {
			$description = sanitize_text_field( (string) $data['description'] );

			if ( $require_amount && '' === $description ) {
				return new WP_Error( 'eventos_expense_invalid', __( 'A description is required.', 'eventos' ), array( 'status' => 400 ) );
			}

			$out['description'] = $description;
		}

		if ( array_key_exists( 'amount', $data ) ) {
			$amount = (float) $data['amount'];

			if ( $amount <= 0 ) {
				return new WP_Error( 'eventos_expense_invalid', __( 'Amount must be greater than zero.', 'eventos' ), array( 'status' => 400 ) );
			}

			$out['amount'] = $amount;
		} elseif ( $require_amount ) {
			return new WP_Error( 'eventos_expense_invalid', __( 'Amount must be greater than zero.', 'eventos' ), array( 'status' => 400 ) );
		}

		if ( array_key_exists( 'category', $data ) ) {
			$category      = sanitize_key( (string) $data['category'] );
			$out['category'] = '' !== $category ? $category : 'other';
		} elseif ( $require_amount ) {
			$out['category'] = 'other';
		}

		if ( array_key_exists( 'currency', $data ) && '' !== (string) $data['currency'] ) {
			$out['currency'] = sanitize_text_field( (string) $data['currency'] );
		} elseif ( $require_amount ) {
			$out['currency'] = WooCommerce::currency();
		}

		if ( array_key_exists( 'expense_date', $data ) ) {
			$date              = sanitize_text_field( (string) $data['expense_date'] );
			$out['expense_date'] = preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date ) ? $date : null;
		}

		foreach ( array( 'reference', 'payee' ) as $field ) {
			if ( array_key_exists( $field, $data ) ) {
				$out[ $field ] = sanitize_text_field( (string) $data[ $field ] );
			}
		}

		if ( array_key_exists( 'notes', $data ) ) {
			$out['notes'] = sanitize_textarea_field( (string) $data['notes'] );
		}

		return $out;
	}
}
