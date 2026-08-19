<?php
/**
 * Orchestration layer for discount campaigns and promo links.
 *
 * @package EventOS
 */

declare( strict_types = 1 );

namespace EventOS\Events;

use EventOS\Activity_Log;
use EventOS\Marketing\Audience_Repository;
use EventOS\Marketing\Audience_Resolver;
use WP_Error;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The Marketing_Controller talks only to this class, which scopes every
 * mutation to its owning event and logs it.
 *
 * Audiences ({@see Audience_Repository}/{@see Audience_Resolver}) are
 * deliberately not event-scoped the way campaigns/links are — an audience
 * can be global (targets the whole Audience CRM) or reference one event, so
 * this service's audience methods take an optional event_id rather than a
 * required one. See the Marketing architecture report's "Event vs Global
 * Marketing" recommendation.
 */
final class Marketing_Service {

	private Campaign_Repository $campaigns;
	private Promo_Link_Repository $links;
	private Audience_Repository $audiences;
	private Audience_Resolver $audience_resolver;

	/**
	 * Constructor.
	 *
	 * @param Campaign_Repository   $campaigns         Campaign repository.
	 * @param Promo_Link_Repository $links             Promo link repository.
	 * @param Audience_Repository   $audiences         Audience repository.
	 * @param Audience_Resolver     $audience_resolver Audience resolver.
	 */
	public function __construct(
		Campaign_Repository $campaigns,
		Promo_Link_Repository $links,
		Audience_Repository $audiences,
		Audience_Resolver $audience_resolver
	) {
		$this->campaigns         = $campaigns;
		$this->links             = $links;
		$this->audiences         = $audiences;
		$this->audience_resolver = $audience_resolver;
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
	 * Audiences usable from an event's Marketing tab — every event-scoped
	 * audience for this event, plus every global (event_id null) audience,
	 * each annotated with its live resolved count. Resolution happens on
	 * every call rather than being cached/stored — see the class docblock
	 * on {@see \EventOS\Marketing\Audience_Resolver} for why: an audience is
	 * a rule against live CRM/Events data, not a stored list, so "how many
	 * people currently match" can only ever be a live answer.
	 *
	 * @param int $event_id Event ID.
	 * @return array<int, array<string, mixed>>
	 */
	public function audiences( int $event_id ): array {
		return array_map(
			function ( array $audience ): array {
				$audience['count'] = $this->audience_resolver->count( $audience );

				return $audience;
			},
			$this->audiences->all( array( 'event_id' => $event_id ) )
		);
	}

	/**
	 * Every audience (optionally global-only), without resolving counts —
	 * for the audience-management list, where a full-page resolve of every
	 * row on every load would be wasteful; the UI fetches a count/preview
	 * only for the audience currently being viewed.
	 *
	 * @param array<string, mixed> $args Optional filters, see {@see Audience_Repository::all()}.
	 * @return array<int, array<string, mixed>>
	 */
	public function list_audiences( array $args = array() ): array {
		return $this->audiences->all( $args );
	}

	/**
	 * Read a single audience definition.
	 *
	 * @param int $id Audience ID.
	 * @return array<string, mixed>|WP_Error
	 */
	public function find_audience( int $id ) {
		$audience = $this->audiences->find( $id );

		return null === $audience ? $this->not_found() : $audience;
	}

	/**
	 * Create an audience definition.
	 *
	 * @param array<string, mixed> $input Field values.
	 * @return array<string, mixed>|WP_Error
	 */
	public function create_audience( array $input ) {
		$result = $this->audiences->create( $input );

		if ( ! is_wp_error( $result ) ) {
			$this->log( 'audience_created', (int) ( $result['event_id'] ?? 0 ), 'audience', (string) $result['id'], null, $result );
		}

		return $result;
	}

	/**
	 * Update an audience definition.
	 *
	 * @param int                  $id    Audience ID.
	 * @param array<string, mixed> $input Field values.
	 * @return array<string, mixed>|WP_Error
	 */
	public function update_audience( int $id, array $input ) {
		$before = $this->audiences->find( $id );

		if ( null === $before ) {
			return $this->not_found();
		}

		$result = $this->audiences->update( $id, $input );

		if ( ! is_wp_error( $result ) ) {
			$this->log( 'audience_updated', (int) ( $result['event_id'] ?? 0 ), 'audience', (string) $id, $before, $result );
		}

		return $result;
	}

	/**
	 * Archive an audience.
	 *
	 * @param int $id Audience ID.
	 * @return true|WP_Error
	 */
	public function archive_audience( int $id ) {
		$before = $this->audiences->find( $id );

		if ( null === $before ) {
			return $this->not_found();
		}

		$result = $this->audiences->archive( $id );

		if ( true === $result ) {
			$this->log( 'audience_archived', (int) ( $before['event_id'] ?? 0 ), 'audience', (string) $id, $before, null );
		}

		return $result;
	}

	/**
	 * Live resolved count for an audience.
	 *
	 * @param int $id Audience ID.
	 * @return array<string, mixed>|WP_Error
	 */
	public function audience_count( int $id ) {
		$audience = $this->audiences->find( $id );

		if ( null === $audience ) {
			return $this->not_found();
		}

		return array( 'count' => $this->audience_resolver->count( $audience ) );
	}

	/**
	 * A small live sample of the people an audience currently resolves to.
	 *
	 * @param int $id    Audience ID.
	 * @param int $limit Maximum sample size.
	 * @return array<string, mixed>|WP_Error
	 */
	public function audience_preview( int $id, int $limit = 5 ) {
		$audience = $this->audiences->find( $id );

		if ( null === $audience ) {
			return $this->not_found();
		}

		return array(
			'count'   => $this->audience_resolver->count( $audience ),
			'preview' => $this->audience_resolver->preview( $audience, $limit ),
		);
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
