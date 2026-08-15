<?php
/**
 * Named reference to the CRM capability.
 *
 * @package EventOS
 */

declare( strict_types = 1 );

namespace EventOS\Crm;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * `eventos_manage_crm` is already registered as a core capability by
 * {@see \EventOS\Permissions::bootstrap()} — reserved for this module before
 * it existed. This class does not declare or register anything; it only
 * gives the CRM module the same named-constant convention every other
 * module's REST controllers use (e.g. `Event_Capabilities::VIEW_EVENTS`),
 * so `Person_Controller` never references a raw capability string.
 */
final class Crm_Capabilities {

	/**
	 * Manage CRM data — persons, tags, notes, consent, segments.
	 */
	public const MANAGE_CRM = 'eventos_manage_crm';
}
