<?php
/**
 * The one fully-implemented Import Profile: a generic CSV export shape
 * common to ticketing platforms in general, not any one named platform.
 *
 * @package EventOS
 */

declare( strict_types = 1 );

namespace EventOS\Import\Profiles;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Pure data. Every field maps onto an existing `events`/`ticket_types`/
 * `tickets` import target field ({@see \EventOS\Modules\Events_Module::register_import_targets()})
 * — nothing here invents new EventOS fields or schema.
 *
 * `source`/`event_source`/`ticket_type_source` are constants (`'const'`):
 * the identity *type* is fixed for a whole generic-CSV import, never a
 * per-row column. `source_id`/`event_source_id`/`ticket_type_source_id`
 * are the actual per-row external identifiers, read from a column.
 */
final class Generic_Csv_Profile {

	/**
	 * The identity type every generic-CSV-sourced record is stamped with.
	 */
	public const SOURCE_TYPE = 'generic_csv';

	/**
	 * The profile definition.
	 *
	 * @return array<string, mixed>
	 */
	public static function definition(): array {
		return array(
			'id'          => 'generic-csv',
			'name'        => __( 'Generic CSV Export', 'eventos' ),
			'provider'    => __( 'Generic / any platform', 'eventos' ),
			'format'      => 'csv',
			'version'     => '1.0.0',
			'status'      => 'ready',
			'description' => __( 'A common exported-ticketing-data column shape, not specific to any one platform. Use as-is, or as a starting template for a platform-specific profile.', 'eventos' ),
			// Declares the multi-file bundle order this profile supports —
			// describes the relationship only; execution is entirely
			// EventOS\Import\Ticketing_Import_Orchestrator::run_bundle().
			'bundle'      => array( 'events', 'ticket_types', 'tickets' ),
			'stages'      => array(
				'events'       => array(
					'fields' => array(
						'source'            => array( 'const' => self::SOURCE_TYPE ),
						'source_id'         => array(
							'column'  => 'External Event ID',
							'aliases' => array( 'Event ID', 'EventID', 'Event Id' ),
						),
						'title'             => array(
							'column'  => 'Event Name',
							'aliases' => array( 'Title', 'Name', 'Event Title' ),
						),
						'description'       => array( 'column' => 'Description' ),
						'short_description' => array(
							'column'  => 'Short Description',
							'aliases' => array( 'Summary' ),
						),
						'venue_name'        => array(
							'column'  => 'Venue',
							'aliases' => array( 'Location' ),
						),
						'starts_at'         => array(
							'column'  => 'Start Date',
							'aliases' => array( 'Start', 'Event Date', 'Date' ),
						),
						'ends_at'           => array(
							'column'  => 'End Date',
							'aliases' => array( 'End' ),
						),
					),
				),
				'ticket_types' => array(
					'fields' => array(
						'event_source'    => array( 'const' => self::SOURCE_TYPE ),
						'event_source_id' => array(
							'column'  => 'External Event ID',
							'aliases' => array( 'Event ID', 'EventID', 'Event Id' ),
						),
						'source'          => array( 'const' => self::SOURCE_TYPE ),
						'source_id'       => array(
							'column'  => 'External Ticket Type ID',
							'aliases' => array( 'Ticket Type ID' ),
						),
						'name'            => array(
							'column'  => 'Ticket Type Name',
							'aliases' => array( 'Ticket Type', 'Type' ),
						),
						'price'           => array(
							'column'    => 'Price',
							'aliases'   => array( 'Ticket Price', 'Cost' ),
							'transform' => 'money',
						),
						'capacity'        => array(
							'column'  => 'Capacity',
							'aliases' => array( 'Quantity', 'Stock' ),
						),
						'status'          => array( 'column' => 'Status', 'transform' => 'status' ),
					),
				),
				'tickets'      => array(
					'fields' => array(
						'event_source'          => array( 'const' => self::SOURCE_TYPE ),
						'event_source_id'       => array(
							'column'  => 'External Event ID',
							'aliases' => array( 'Event ID', 'EventID', 'Event Id' ),
						),
						'ticket_type_source'    => array( 'const' => self::SOURCE_TYPE ),
						'ticket_type_source_id' => array(
							'column'  => 'External Ticket Type ID',
							'aliases' => array( 'Ticket Type ID' ),
						),
						'source'                => array( 'const' => self::SOURCE_TYPE ),
						'source_id'             => array(
							'column'  => 'External Ticket ID',
							'aliases' => array( 'Ticket ID' ),
						),
						// Tried in order: a single "Attendee Name"-style
						// column first, else join First + Last name — see
						// Import_Profile_Mapper::resolve_mapping().
						'name'                  => array(
							array(
								'column'  => 'Attendee Name',
								'aliases' => array( 'Name', 'Full Name' ),
							),
							array(
								'columns'   => array( 'First Name', 'Last Name' ),
								'transform' => 'full_name',
							),
						),
						'email'                 => array(
							'column'    => 'Email',
							'aliases'   => array( 'Attendee Email', 'Email Address' ),
							'transform' => 'email',
						),
						'phone'                 => array(
							'column'    => 'Phone',
							'aliases'   => array( 'Attendee Phone', 'Mobile', 'Phone Number' ),
							'transform' => 'phone',
						),
						'status'                => array( 'column' => 'Ticket Status', 'transform' => 'status' ),
					),
				),
			),
		);
	}
}
