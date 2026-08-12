<?php
/**
 * Orchestration layer for discount campaigns and promo links.
 *
 * @package EventOS
 */

declare( strict_types = 1 );

namespace EventOS\Events;

use EventOS\Activity_Log;
use WP_Error;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The Marketing_Controller talks only to this class, which scopes every
 * mutation to its owning event and logs it.
 */
final class Marketing_Service {

	private Campaign_Repository $campaigns;
	private Promo_Link_Repository $links;

	/**
	 * Constructor.
	 *
	 * @param Campaign_Repository   $campaigns Campaign repository.
	 * @param Promo_Link_Repository $links     Promo link repository.
	 */
	public function __construct( Campaign_Repository $campaigns, Promo_Link_Repository $links ) {
		$this->campaigns = $campaigns;
		$this->links     = $links;
	}

	/**
	 * Campaigns for an event.
	 *
	 * @param int $event_id Event ID.
	 * @return array<int, array<string, mixed>>
	 */
	public function campaigns_for_event( int $event_id ): array {
		return $this->campaigns->for_event( $event_id );
	}

	/**
	 * Create a discount campaign.
	 *
	 * @param int                  $event_id Event ID.
	 * @param array<string, mixed> $input    Field values.
	 * @return array<string, mixed>|WP_Error
	 */
	public function create_campaign( int $event_id, array $input ) {
		$result = $this->campaigns->create( $event_id, $input );

		if ( ! is_wp_error( $result ) ) {
			$this->log( 'campaign_created', $event_id, 'campaign', (string) $result['id'], null, $result );
		}

		return $result;
	}

	/**
	 * Update a discount campaign.
	 *
	 * @param int                  $event_id Event ID.
	 * @param int                  $id       Campaign ID.
	 * @param array<string, mixed> $input    Field values.
	 * @return array<string, mixed>|WP_Error
	 */
	public function update_campaign( int $event_id, int $id, array $input ) {
		$before = $this->campaigns->find( $id );

		if ( null === $before || (int) $before['event_id'] !== $event_id ) {
			return $this->not_found();
		}

		$result = $this->campaigns->update( $id, $input );

		if ( ! is_wp_error( $result ) ) {
			$this->log( 'campaign_updated', $event_id, 'campaign', (string) $id, $before, $result );
		}

		return $result;
	}

	/**
	 * Archive a discount campaign.
	 *
	 * @param int $event_id Event ID.
	 * @param int $id       Campaign ID.
	 * @return true|WP_Error
	 */
	public function delete_campaign( int $event_id, int $id ) {
		$before = $this->campaigns->find( $id );

		if ( null === $before || (int) $before['event_id'] !== $event_id ) {
			return $this->not_found();
		}

		$result = $this->campaigns->archive( $id );

		if ( true === $result ) {
			$this->log( 'campaign_archived', $event_id, 'campaign', (string) $id, $before, null );
		}

		return $result;
	}

	/**
	 * Promo links for an event.
	 *
	 * @param int $event_id Event ID.
	 * @return array<int, array<string, mixed>>
	 */
	public function links_for_event( int $event_id ): array {
		return $this->links->for_event( $event_id );
	}

	/**
	 * Create a promo link.
	 *
	 * @param int                  $event_id Event ID.
	 * @param array<string, mixed> $input    Field values.
	 * @return array<string, mixed>|WP_Error
	 */
	public function create_link( int $event_id, array $input ) {
		$result = $this->links->create( $event_id, $input );

		if ( ! is_wp_error( $result ) ) {
			$this->log( 'promo_link_created', $event_id, 'promo_link', (string) $result['id'], null, $result );
		}

		return $result;
	}

	/**
	 * Delete a promo link.
	 *
	 * @param int $event_id Event ID.
	 * @param int $id       Link ID.
	 * @return true|WP_Error
	 */
	public function delete_link( int $event_id, int $id ) {
		$link = $this->links->find( $id );

		if ( null === $link || (int) $link['event_id'] !== $event_id ) {
			return $this->not_found();
		}

		$this->links->delete( $id );
		$this->log( 'promo_link_deleted', $event_id, 'promo_link', (string) $id, $link, null );

		return true;
	}

	/**
	 * Audience segments for an event.
	 *
	 * No segmentation model exists yet — the Marketing tab already hides
	 * this card when the list is empty, so an honest empty result is
	 * correct here rather than inventing segment data or a separate
	 * marketing-automation feature.
	 *
	 * @param int $event_id Event ID.
	 * @return array<int, array<string, mixed>>
	 */
	public function audiences( int $event_id ): array {
		unset( $event_id );

		return array();
	}

	/**
	 * Standard 404.
	 *
	 * @return WP_Error
	 */
	private function not_found(): WP_Error {
		return new WP_Error( 'eventos_not_found', __( 'That record no longer exists.', 'eventos' ), array( 'status' => 404 ) );
	}

	/**
	 * Record an activity log entry.
	 *
	 * @param string                    $action      Action slug.
	 * @param int                       $event_id    Event ID.
	 * @param string                    $entity_type Entity type.
	 * @param string                    $entity_id   Entity ID.
	 * @param array<string, mixed>|null $before      Before value.
	 * @param array<string, mixed>|null $after       After value.
	 * @return void
	 */
	private function log( string $action, int $event_id, string $entity_type, string $entity_id, ?array $before, ?array $after ): void {
		Activity_Log::log(
			array(
				'action'      => $action,
				'module'      => 'events',
				'entity_type' => $entity_type,
				'entity_id'   => $entity_id,
				'before'      => $before,
				'after'       => $after,
				'context'     => array( 'event_id' => $event_id ),
			)
		);
	}
}
