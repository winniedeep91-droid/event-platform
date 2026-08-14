<?php
/**
 * CRM / permanent Person module.
 *
 * @package EventOS
 */

declare( strict_types = 1 );

namespace EventOS\Modules;

use EventOS\Abstract_Module;
use EventOS\Crm\Person_Schema;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Wires the permanent Person / Relationship (CRM) module into EventOS.
 *
 * "Tickets belong to events. People belong to the brand." — this module owns
 * the global Person entity and everything built on top of it (identity,
 * tags/notes, consent, segments, rewards, relationship timeline). The event
 * operational layer (guests, tickets, checkins) stays owned by the Events
 * module and is untouched by this one.
 *
 * Phase 1 establishes only the schema foundation described in the Final
 * Implementation Specification, Section 17: no REST endpoints, menu items or
 * capabilities are declared yet, and no existing table is modified.
 */
final class Crm_Module extends Abstract_Module {

	/**
	 * Module slug.
	 *
	 * @return string
	 */
	public function slug(): string {
		return 'crm';
	}

	/**
	 * Module name.
	 *
	 * @return string
	 */
	public function name(): string {
		return __( 'CRM', 'eventos' );
	}

	/**
	 * Module description.
	 *
	 * @return string
	 */
	public function description(): string {
		return __( 'Permanent Person identity and relationship history, global across every event.', 'eventos' );
	}

	/**
	 * Module dependencies.
	 *
	 * @return string[]
	 */
	public function dependencies(): array {
		return array( 'core', 'events' );
	}

	/**
	 * Install the module's tables on first activation.
	 *
	 * @return void
	 */
	public function activate(): void {
		Person_Schema::install();
	}

	/**
	 * Keep the schema current on upgrades.
	 *
	 * @param string $from_version Previously installed version.
	 * @return void
	 */
	public function upgrade( string $from_version ): void {
		unset( $from_version );

		Person_Schema::install();
	}

	/**
	 * Register runtime hooks.
	 *
	 * @return void
	 */
	public function init(): void {
		Person_Schema::maybe_install();
	}
}
