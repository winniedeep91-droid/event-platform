<?php
/**
 * Capability vocabulary owned by the Finance module.
 *
 * @package EventOS
 */

declare( strict_types = 1 );

namespace EventOS\Finance;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Finance and P&L already have dedicated capabilities registered by core
 * ({@see \EventOS\Permissions::bootstrap()}) and a "finance" role built
 * around them, because a promoter's financial data is deliberately walled
 * off from general administration — the `administrator` role itself is
 * granted every other capability except `eventos_manage_finance`. This
 * class only gives that pre-existing vocabulary a module-scoped name,
 * matching the convention every other module's `Xxx_Capabilities` class
 * follows (see {@see \EventOS\Events\Event_Capabilities}); it registers
 * nothing new with the permission engine.
 */
final class Finance_Capabilities {

	/**
	 * View event financial summaries, P&L and expenses.
	 */
	public const VIEW_FINANCE = 'eventos_view_finance';

	/**
	 * Create, edit and void expenses; manage financial records.
	 */
	public const MANAGE_FINANCE = 'eventos_manage_finance';
}
