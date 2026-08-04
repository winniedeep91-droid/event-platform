<?php
/**
 * Business logic for the Events module.
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
 * The only place event data is written. Owns validation, slugs, status
 * transitions, relation syncing and the audit trail.
 */
final class Event_Service {

	/**
	 * Module slug used in the activity log.
	 */
	public const MODULE = 'events';

	/**
	 * Event repository.
	 *
	 * @var Event_Repository
	 */
	private Event_Repository $events;

	/**
	 * Venue repository.
	 *
	 * @var Venue_Repository
	 */
	private Venue_Repository $venues;

	/**
	 * Artist repository.
	 *
	 * @var Artist_Repository
	 */
	private Artist_Repository $artists;

	/**
	 * Category repository.
	 *
	 * @var Term_Repository
	 */
	private Term_Repository $categories;

	/**
	 * Tag repository.
	 *
	 * @var Term_Repository
	 */
	private Term_Repository $tags;

	/**
	 * Constructor.
	 */
	public function __construct() {
		$this->events     = new Event_Repository();
		$this->venues     = new Venue_Repository();
		$this->artists    = new Artist_Repository();
		$this->categories = new Term_Repository( 'category' );
		$this->tags       = new Term_Repository( 'tag' );
	}

	/**
	 * Event repository accessor.
	 *
	 * @return Event_Repository
	 */
	public function events(): Event_Repository {
		return $this->events;
	}

	/**
	 * Venue repository accessor.
	 *
	 * @return Venue_Repository
	 */
	public function venues(): Venue_Repository {
		return $this->venues;
	}

	/**
	 * Artist repository accessor.
	 *
	 * @return Artist_Repository
	 */
	public function artists(): Artist_Repository {
		return $this->artists;
	}

	/**
	 * Taxonomy repository accessor.
	 *
	 * @param string $taxonomy Taxonomy slug.
	 * @return Term_Repository
	 */
	public function terms( string $taxonomy ): Term_Repository {
		return 'tag' === $taxonomy ? $this->tags : $this->categories;
	}

	/* --------------------------------------------------------------------- */
	/* Events                                                                 */
	/* --------------------------------------------------------------------- */

	/**
	 * Create an event.
	 *
	 * @param array<string, mixed> $input Raw payload.
	 * @return array<string, mixed>|WP_Error
	 */
	public function create_event( array $input ) {
		$data = Event_Validator::event( $input );

		if ( is_wp_error( $data ) ) {
			return $data;
		}

		$now  = current_time( 'mysql', true );
		$user = get_current_user_id();

		$data = array_merge(
			array(
				'subtitle'          => '',
				'description'       => '',
				'short_description' => '',
				'status'            => Event_Status::DRAFT,
				'visibility'        => 'public',
				'password_hash'     => '',
				'ticket_visibility' => 'public',
				'venue_id'          => 0,
				'timezone'          => $this->default_timezone(),
				'capacity'          => 0,
				'age_restriction'   => '',
				'accessibility'     => '',
				'featured_image_id' => 0,
				'organisers'        => (string) wp_json_encode( array( $user ) ),
				'collaborators'     => '[]',
				'recurrence'        => (string) wp_json_encode( array( 'frequency' => 'none' ) ),
			),
			$data,
			array(
				'created_by' => $user,
				'updated_by' => $user,
				'created_at' => $now,
				'updated_at' => $now,
			)
		);

		$data['slug'] = $this->events->unique_slug( (string) ( $input['slug'] ?? $data['title'] ) );

		if ( Event_Status::PUBLISHED === $data['status'] ) {
			$data['published_at'] = $now;
		}

		$id = $this->events->insert( $data );

		if ( $id <= 0 ) {
			return new WP_Error( 'eventos_event_not_created', __( 'The event could not be saved.', 'eventos' ), array( 'status' => 500 ) );
		}

		$this->sync_relations( $id, $input );

		$event = $this->events->find( $id );

		$this->log( 'event_created', $id, null, $event );

		/**
		 * Fires after an event has been created.
		 *
		 * @param int                  $id    Event ID.
		 * @param array<string, mixed> $event Hydrated event.
		 */
		do_action( 'eventos_event_created', $id, $event );

		return $event;
	}

	/**
	 * Update an event.
	 *
	 * @param int                  $id    Event ID.
	 * @param array<string, mixed> $input Raw payload.
	 * @return array<string, mixed>|WP_Error
	 */
	public function update_event( int $id, array $input ) {
		$existing = $this->events->find( $id );

		if ( null === $existing ) {
			return $this->not_found();
		}

		$data = Event_Validator::event( $input, $existing );

		if ( is_wp_error( $data ) ) {
			return $data;
		}

		if ( isset( $data['status'] ) && $data['status'] !== $existing['status'] ) {
			$allowed = Event_Status::transitions( (string) $existing['status'] );

			if ( ! in_array( $data['status'], $allowed, true ) ) {
				return new WP_Error(
					'eventos_invalid_transition',
					sprintf(
						/* translators: 1: current status, 2: requested status. */
						__( 'An event cannot move from %1$s to %2$s.', 'eventos' ),
						$existing['status'],
						$data['status']
					),
					array( 'status' => 422 )
				);
			}

			if ( Event_Status::PUBLISHED === $data['status'] && empty( $existing['published_at'] ) ) {
				$data['published_at'] = current_time( 'mysql', true );
			}
		}

		if ( array_key_exists( 'slug', $input ) ) {
			$data['slug'] = $this->events->unique_slug( (string) $input['slug'], $id );
		} elseif ( isset( $data['title'] ) && '' === (string) $existing['slug'] ) {
			$data['slug'] = $this->events->unique_slug( (string) $data['title'], $id );
		}

		$data['updated_by'] = get_current_user_id();
		$data['updated_at'] = current_time( 'mysql', true );

		$this->events->update( $id, $data );
		$this->sync_relations( $id, $input );

		$event = $this->events->find( $id );

		$this->log( 'event_updated', $id, $existing, $event );

		/**
		 * Fires after an event has been updated.
		 *
		 * @param int                  $id       Event ID.
		 * @param array<string, mixed> $event    Hydrated event.
		 * @param array<string, mixed> $existing Previous state.
		 */
		do_action( 'eventos_event_updated', $id, $event, $existing );

		return $event;
	}

	/**
	 * Move an event to another lifecycle status.
	 *
	 * @param int    $id     Event ID.
	 * @param string $status Target status.
	 * @return array<string, mixed>|WP_Error
	 */
	public function transition_event( int $id, string $status ) {
		return $this->update_event( $id, array( 'status' => $status ) );
	}

	/**
	 * Duplicate an event, including its relations.
	 *
	 * @param int $id Event ID.
	 * @return array<string, mixed>|WP_Error
	 */
	public function duplicate_event( int $id ) {
		$existing = $this->events->find( $id );

		if ( null === $existing ) {
			return $this->not_found();
		}

		$payload = array(
			'title'             => sprintf(
				/* translators: %s: original event title. */
				__( '%s (copy)', 'eventos' ),
				$existing['title']
			),
			'subtitle'          => $existing['subtitle'],
			'description'       => $existing['description'],
			'short_description' => $existing['short_description'],
			'status'            => Event_Status::DRAFT,
			'visibility'        => $existing['visibility'],
			'ticket_visibility' => $existing['ticket_visibility'],
			'venue_id'          => $existing['venue_id'],
			'timezone'          => $existing['timezone'],
			'starts_at'         => $existing['starts_at'],
			'ends_at'           => $existing['ends_at'],
			'doors_open_at'     => $existing['doors_open_at'],
			'capacity'          => $existing['capacity'],
			'age_restriction'   => $existing['age_restriction'],
			'accessibility'     => $existing['accessibility'],
			'featured_image_id' => $existing['featured_image_id'],
			'organisers'        => $existing['organisers'],
			'collaborators'     => $existing['collaborators'],
			'artists'           => $existing['artists'] ?? array(),
			'media'             => $existing['media'] ?? array(),
			'schedules'         => $existing['schedules'] ?? array(),
			'category_ids'      => $existing['category_ids'] ?? array(),
			'tag_ids'           => $existing['tag_ids'] ?? array(),
		);

		$created = $this->create_event( $payload );

		if ( ! is_wp_error( $created ) ) {
			$this->log( 'event_duplicated', (int) $created['id'], $existing, $created );
		}

		return $created;
	}

	/**
	 * Materialise a recurrence rule into draft occurrences.
	 *
	 * @param int                  $id   Event ID acting as the template.
	 * @param array<string, mixed> $rule Recurrence rule.
	 * @return array<int, array<string, mixed>>|WP_Error
	 */
	public function generate_occurrences( int $id, array $rule ) {
		$template = $this->events->find( $id );

		if ( null === $template ) {
			return $this->not_found();
		}

		$rule = Event_Validator::recurrence( $rule );

		if ( is_wp_error( $rule ) ) {
			return $rule;
		}

		if ( 'none' === $rule['frequency'] ) {
			return new WP_Error( 'eventos_no_recurrence', __( 'Choose a recurrence frequency first.', 'eventos' ), array( 'status' => 422 ) );
		}

		if ( empty( $template['starts_at'] ) ) {
			return new WP_Error( 'eventos_missing_start', __( 'The template event needs an opening time.', 'eventos' ), array( 'status' => 422 ) );
		}

		$created  = array();
		$start    = (int) strtotime( (string) $template['starts_at'] );
		$duration = $template['ends_at'] ? (int) strtotime( (string) $template['ends_at'] ) - $start : 0;
		$doors    = $template['doors_open_at'] ? $start - (int) strtotime( (string) $template['doors_open_at'] ) : 0;
		$until    = $rule['until'] ? (int) strtotime( (string) $rule['until'] ) : 0;
		$step     = $this->recurrence_step( (string) $rule['frequency'], (int) $rule['interval'] );
		$cursor   = $start;

		for ( $index = 0; $index < (int) $rule['count']; $index++ ) {
			$cursor = (int) strtotime( $step, $cursor );

			if ( $until && $cursor > $until ) {
				break;
			}

			if ( $rule['weekdays'] && ! in_array( (int) gmdate( 'w', $cursor ), $rule['weekdays'], true ) ) {
				continue;
			}

			$occurrence = $this->create_event(
				array(
					'title'             => $template['title'],
					'subtitle'          => $template['subtitle'],
					'description'       => $template['description'],
					'short_description' => $template['short_description'],
					'status'            => Event_Status::DRAFT,
					'visibility'        => $template['visibility'],
					'ticket_visibility' => $template['ticket_visibility'],
					'venue_id'          => $template['venue_id'],
					'timezone'          => $template['timezone'],
					'starts_at'         => gmdate( 'Y-m-d H:i:s', $cursor ),
					'ends_at'           => $duration ? gmdate( 'Y-m-d H:i:s', $cursor + $duration ) : null,
					'doors_open_at'     => $doors ? gmdate( 'Y-m-d H:i:s', $cursor - $doors ) : null,
					'capacity'          => $template['capacity'],
					'age_restriction'   => $template['age_restriction'],
					'accessibility'     => $template['accessibility'],
					'featured_image_id' => $template['featured_image_id'],
					'artists'           => $template['artists'] ?? array(),
					'category_ids'      => $template['category_ids'] ?? array(),
					'tag_ids'           => $template['tag_ids'] ?? array(),
					'recurrence'        => array( 'frequency' => 'none' ),
				)
			);

			if ( ! is_wp_error( $occurrence ) ) {
				$created[] = $occurrence;
			}
		}

		$this->events->update(
			$id,
			array(
				'recurrence' => (string) wp_json_encode( $rule ),
				'updated_at' => current_time( 'mysql', true ),
				'updated_by' => get_current_user_id(),
			)
		);

		$this->log( 'event_recurrence_generated', $id, $template, array( 'occurrences' => count( $created ) ) );

		return $created;
	}

	/**
	 * Delete an event.
	 *
	 * @param int $id Event ID.
	 * @return true|WP_Error
	 */
	public function delete_event( int $id ) {
		$existing = $this->events->find( $id );

		if ( null === $existing ) {
			return $this->not_found();
		}

		$this->events->delete( $id );
		$this->log( 'event_deleted', $id, $existing, null, Activity_Log::SEVERITY_WARNING );

		/**
		 * Fires after an event has been deleted.
		 *
		 * @param int                  $id       Event ID.
		 * @param array<string, mixed> $existing Deleted event.
		 */
		do_action( 'eventos_event_deleted', $id, $existing );

		return true;
	}

	/**
	 * Sync artists, media, schedules and taxonomy terms from a payload.
	 *
	 * @param int                  $id    Event ID.
	 * @param array<string, mixed> $input Raw payload.
	 * @return void
	 */
	private function sync_relations( int $id, array $input ): void {
		if ( array_key_exists( 'artists', $input ) ) {
			$this->events->set_artists( $id, (array) $input['artists'] );
		}

		if ( array_key_exists( 'media', $input ) ) {
			$this->events->set_media( $id, (array) $input['media'] );
		}

		if ( array_key_exists( 'schedules', $input ) ) {
			$this->events->set_schedules( $id, (array) $input['schedules'] );
		}

		if ( array_key_exists( 'category_ids', $input ) ) {
			$this->events->set_terms( $id, 'category', array_map( 'absint', (array) $input['category_ids'] ) );
		}

		if ( array_key_exists( 'tag_ids', $input ) ) {
			$this->events->set_terms( $id, 'tag', array_map( 'absint', (array) $input['tag_ids'] ) );
		}

		if ( array_key_exists( 'tag_names', $input ) ) {
			$ids = $this->tags->ensure( array_map( 'strval', (array) $input['tag_names'] ) );
			$this->events->set_terms( $id, 'tag', $ids );
		}
	}

	/* --------------------------------------------------------------------- */
	/* Venues                                                                 */
	/* --------------------------------------------------------------------- */

	/**
	 * Create a venue.
	 *
	 * @param array<string, mixed> $input Raw payload.
	 * @return array<string, mixed>|WP_Error
	 */
	public function create_venue( array $input ) {
		$data = Event_Validator::venue( $input );

		if ( is_wp_error( $data ) ) {
			return $data;
		}

		$now  = current_time( 'mysql', true );
		$data = array_merge( $data, array( 'created_at' => $now, 'updated_at' => $now ) );

		$data['slug'] = $this->venues->unique_slug( (string) ( $input['slug'] ?? $data['name'] ) );

		$id = $this->venues->insert( $data );

		if ( $id <= 0 ) {
			return new WP_Error( 'eventos_venue_not_created', __( 'The venue could not be saved.', 'eventos' ), array( 'status' => 500 ) );
		}

		$venue = $this->venues->find( $id );
		$this->log( 'venue_created', $id, null, $venue, Activity_Log::SEVERITY_INFO, 'venue' );

		return $venue;
	}

	/**
	 * Update a venue.
	 *
	 * @param int                  $id    Venue ID.
	 * @param array<string, mixed> $input Raw payload.
	 * @return array<string, mixed>|WP_Error
	 */
	public function update_venue( int $id, array $input ) {
		$existing = $this->venues->find( $id );

		if ( null === $existing ) {
			return $this->not_found();
		}

		$data = Event_Validator::venue( $input, true );

		if ( is_wp_error( $data ) ) {
			return $data;
		}

		if ( array_key_exists( 'slug', $input ) ) {
			$data['slug'] = $this->venues->unique_slug( (string) $input['slug'], $id );
		}

		$data['updated_at'] = current_time( 'mysql', true );

		$this->venues->update( $id, $data );

		$venue = $this->venues->find( $id );
		$this->log( 'venue_updated', $id, $existing, $venue, Activity_Log::SEVERITY_INFO, 'venue' );

		return $venue;
	}

	/**
	 * Delete a venue.
	 *
	 * @param int $id Venue ID.
	 * @return true|WP_Error
	 */
	public function delete_venue( int $id ) {
		$existing = $this->venues->find( $id );

		if ( null === $existing ) {
			return $this->not_found();
		}

		$this->venues->delete( $id );
		$this->log( 'venue_deleted', $id, $existing, null, Activity_Log::SEVERITY_WARNING, 'venue' );

		return true;
	}

	/* --------------------------------------------------------------------- */
	/* Artists                                                                */
	/* --------------------------------------------------------------------- */

	/**
	 * Create an artist.
	 *
	 * @param array<string, mixed> $input Raw payload.
	 * @return array<string, mixed>|WP_Error
	 */
	public function create_artist( array $input ) {
		$data = Event_Validator::artist( $input );

		if ( is_wp_error( $data ) ) {
			return $data;
		}

		$now  = current_time( 'mysql', true );
		$data = array_merge( $data, array( 'created_at' => $now, 'updated_at' => $now ) );

		$data['slug'] = $this->artists->unique_slug( (string) ( $input['slug'] ?? $data['name'] ) );

		$id = $this->artists->insert( $data );

		if ( $id <= 0 ) {
			return new WP_Error( 'eventos_artist_not_created', __( 'The artist could not be saved.', 'eventos' ), array( 'status' => 500 ) );
		}

		$artist = $this->artists->find( $id );
		$this->log( 'artist_created', $id, null, $artist, Activity_Log::SEVERITY_INFO, 'artist' );

		return $artist;
	}

	/**
	 * Update an artist.
	 *
	 * @param int                  $id    Artist ID.
	 * @param array<string, mixed> $input Raw payload.
	 * @return array<string, mixed>|WP_Error
	 */
	public function update_artist( int $id, array $input ) {
		$existing = $this->artists->find( $id );

		if ( null === $existing ) {
			return $this->not_found();
		}

		$data = Event_Validator::artist( $input, true );

		if ( is_wp_error( $data ) ) {
			return $data;
		}

		if ( array_key_exists( 'slug', $input ) ) {
			$data['slug'] = $this->artists->unique_slug( (string) $input['slug'], $id );
		}

		$data['updated_at'] = current_time( 'mysql', true );

		$this->artists->update( $id, $data );

		$artist = $this->artists->find( $id );
		$this->log( 'artist_updated', $id, $existing, $artist, Activity_Log::SEVERITY_INFO, 'artist' );

		return $artist;
	}

	/**
	 * Delete an artist.
	 *
	 * @param int $id Artist ID.
	 * @return true|WP_Error
	 */
	public function delete_artist( int $id ) {
		$existing = $this->artists->find( $id );

		if ( null === $existing ) {
			return $this->not_found();
		}

		$this->artists->delete( $id );
		$this->log( 'artist_deleted', $id, $existing, null, Activity_Log::SEVERITY_WARNING, 'artist' );

		return true;
	}

	/* --------------------------------------------------------------------- */
	/* Taxonomy                                                               */
	/* --------------------------------------------------------------------- */

	/**
	 * Create a category or tag.
	 *
	 * @param string               $taxonomy Taxonomy slug.
	 * @param array<string, mixed> $input    Raw payload.
	 * @return array<string, mixed>|WP_Error
	 */
	public function create_term( string $taxonomy, array $input ) {
		$name = sanitize_text_field( (string) ( $input['name'] ?? '' ) );

		if ( '' === $name ) {
			return new WP_Error( 'eventos_invalid_term', __( 'A name is required.', 'eventos' ), array( 'status' => 422 ) );
		}

		$repository = $this->terms( $taxonomy );

		$id = $repository->insert(
			$name,
			sanitize_textarea_field( (string) ( $input['description'] ?? '' ) ),
			max( 0, (int) ( $input['parent_id'] ?? 0 ) )
		);

		if ( $id <= 0 ) {
			return new WP_Error( 'eventos_term_not_created', __( 'The term could not be saved.', 'eventos' ), array( 'status' => 500 ) );
		}

		$term = $repository->find( $id );
		$this->log( $taxonomy . '_created', $id, null, $term, Activity_Log::SEVERITY_INFO, $taxonomy );

		return $term;
	}

	/**
	 * Update a category or tag.
	 *
	 * @param string               $taxonomy Taxonomy slug.
	 * @param int                  $id       Term ID.
	 * @param array<string, mixed> $input    Raw payload.
	 * @return array<string, mixed>|WP_Error
	 */
	public function update_term( string $taxonomy, int $id, array $input ) {
		$repository = $this->terms( $taxonomy );
		$existing   = $repository->find( $id );

		if ( null === $existing ) {
			return $this->not_found();
		}

		$data = array();

		if ( array_key_exists( 'name', $input ) ) {
			$data['name'] = sanitize_text_field( (string) $input['name'] );
		}

		if ( array_key_exists( 'description', $input ) ) {
			$data['description'] = sanitize_textarea_field( (string) $input['description'] );
		}

		if ( array_key_exists( 'parent_id', $input ) ) {
			$data['parent_id'] = max( 0, (int) $input['parent_id'] );
		}

		$repository->update( $id, $data );

		$term = $repository->find( $id );
		$this->log( $taxonomy . '_updated', $id, $existing, $term, Activity_Log::SEVERITY_INFO, $taxonomy );

		return $term;
	}

	/**
	 * Delete a category or tag.
	 *
	 * @param string $taxonomy Taxonomy slug.
	 * @param int    $id       Term ID.
	 * @return true|WP_Error
	 */
	public function delete_term( string $taxonomy, int $id ) {
		$repository = $this->terms( $taxonomy );
		$existing   = $repository->find( $id );

		if ( null === $existing ) {
			return $this->not_found();
		}

		$repository->delete( $id );
		$this->events->detach_term( $id, $repository->taxonomy() );
		$this->log( $taxonomy . '_deleted', $id, $existing, null, Activity_Log::SEVERITY_WARNING, $taxonomy );

		return true;
	}

	/* --------------------------------------------------------------------- */
	/* Reporting                                                              */
	/* --------------------------------------------------------------------- */

	/**
	 * Dashboard metrics for the Events module.
	 *
	 * @return array<string, mixed>
	 */
	public function dashboard(): array {
		$now   = current_time( 'mysql', true );
		$soon  = gmdate( 'Y-m-d H:i:s', strtotime( '+30 days', (int) strtotime( $now ) ) );
		$counts = $this->events->counts_by_status();

		$upcoming = $this->events->query(
			array(
				'from'     => $now,
				'orderby'  => 'starts_at',
				'order'    => 'asc',
				'per_page' => 5,
			)
		);

		$drafts = $this->events->query(
			array(
				'status'   => Event_Status::DRAFT,
				'orderby'  => 'updated_at',
				'order'    => 'desc',
				'per_page' => 5,
			)
		);

		return array(
			'counts'            => $counts,
			'total'             => array_sum( $counts ),
			'next_30_days'      => $this->events->count_between( $now, $soon ),
			'upcoming_capacity' => $this->events->upcoming_capacity(),
			'venues'            => $this->venues->total(),
			'artists'           => $this->artists->total(),
			'upcoming'          => $upcoming['items'],
			'drafts'            => $drafts['items'],
			'activity'          => Activity_Log::query(
				array(
					'module'   => self::MODULE,
					'per_page' => 10,
				)
			)['items'] ?? array(),
		);
	}

	/**
	 * Options used to populate the admin forms.
	 *
	 * @return array<string, mixed>
	 */
	public function form_options(): array {
		$venues  = $this->venues->query( array( 'per_page' => 200 ) );
		$artists = $this->artists->query( array( 'per_page' => 200 ) );

		return array(
			'statuses'            => Event_Status::labels(),
			'transitions'         => array_combine(
				Event_Status::all(),
				array_map( array( Event_Status::class, 'transitions' ), Event_Status::all() )
			),
			'visibilities'        => Event_Status::visibilities(),
			'ticket_visibilities' => Event_Status::ticket_visibilities(),
			'timezones'           => timezone_identifiers_list(),
			'default_timezone'    => $this->default_timezone(),
			'venues'              => $venues['items'],
			'artists'             => $artists['items'],
			'categories'          => $this->categories->all(),
			'tags'                => $this->tags->all(),
			'users'               => $this->assignable_users(),
		);
	}

	/**
	 * Users that can be attached as organisers or collaborators.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function assignable_users(): array {
		$users = get_users(
			array(
				'number'  => 100,
				'orderby' => 'display_name',
				'fields'  => array( 'ID', 'display_name', 'user_email' ),
			)
		);

		return array_map(
			static function ( $user ): array {
				return array(
					'id'    => (int) $user->ID,
					'name'  => (string) $user->display_name,
					'email' => (string) $user->user_email,
				);
			},
			$users
		);
	}

	/**
	 * Site timezone, falling back to UTC.
	 *
	 * @return string
	 */
	private function default_timezone(): string {
		$timezone = wp_timezone_string();

		return in_array( $timezone, timezone_identifiers_list(), true ) ? $timezone : 'UTC';
	}

	/**
	 * strtotime modifier for a recurrence frequency.
	 *
	 * @param string $frequency Frequency slug.
	 * @param int    $interval  Interval size.
	 * @return string
	 */
	private function recurrence_step( string $frequency, int $interval ): string {
		$units = array(
			'daily'   => 'days',
			'weekly'  => 'weeks',
			'monthly' => 'months',
		);

		return '+' . $interval . ' ' . ( $units[ $frequency ] ?? 'days' );
	}

	/**
	 * Standard not-found error.
	 *
	 * @return WP_Error
	 */
	private function not_found(): WP_Error {
		return new WP_Error( 'eventos_not_found', __( 'That record no longer exists.', 'eventos' ), array( 'status' => 404 ) );
	}

	/**
	 * Write an entry to the activity log.
	 *
	 * @param string                    $action   Action slug.
	 * @param int                       $id       Entity ID.
	 * @param array<string, mixed>|null $before   Previous state.
	 * @param array<string, mixed>|null $after    New state.
	 * @param string                    $severity Severity.
	 * @param string                    $entity   Entity type.
	 * @return void
	 */
	private function log( string $action, int $id, ?array $before, ?array $after, string $severity = Activity_Log::SEVERITY_INFO, string $entity = 'event' ): void {
		Activity_Log::log(
			array(
				'action'      => $action,
				'module'      => self::MODULE,
				'entity_type' => $entity,
				'entity_id'   => $id,
				'before'      => $before,
				'after'       => $after,
				'severity'    => $severity,
			)
		);
	}
}
