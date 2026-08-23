<?php
/**
 * Discoverable identities for named ticketing platforms with no verified
 * export sample yet — explicitly not working mappings.
 *
 * @package EventOS
 */

declare( strict_types = 1 );

namespace EventOS\Import\Profiles;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Per Phase 3's own instruction: do not fabricate Quicket/Webtickets/
 * Howler/FIXR/Resident Advisor column names. These entries exist only so
 * the platform has a stable, discoverable `id`/`name` in
 * `Import_Registry::profiles()` to build a *real* profile against later —
 * every `stages` array is empty on purpose. `Import_Profile_Mapper::resolve_mapping()`
 * on an empty stage returns a `WP_Error`, so a stub can never silently
 * "succeed" at importing anything.
 */
final class Platform_Profile_Stubs {

	/**
	 * Every stub definition.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public static function definitions(): array {
		$platforms = array(
			'quicket'          => __( 'Quicket', 'eventos' ),
			'webtickets'       => __( 'Webtickets', 'eventos' ),
			'howler'           => __( 'Howler', 'eventos' ),
			'fixr'             => __( 'FIXR', 'eventos' ),
			'resident-advisor' => __( 'Resident Advisor', 'eventos' ),
		);

		$stubs = array();

		foreach ( $platforms as $id => $name ) {
			$stubs[] = array(
				'id'          => $id,
				'name'        => $name,
				'provider'    => $name,
				'format'      => 'csv',
				'version'     => '0.0.0',
				'status'      => 'stub',
				/* translators: %s: platform name. */
				'description' => sprintf( __( 'No verified %s export sample/column structure is available yet — this identity exists so a real profile can be registered against it later, without any fabricated column mapping in the meantime.', 'eventos' ), $name ),
				'bundle'      => array(),
				'stages'      => array(),
			);
		}

		return $stubs;
	}
}
