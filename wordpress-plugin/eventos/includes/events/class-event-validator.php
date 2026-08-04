<?php
/**
 * Input validation and normalisation for the Events module.
 *
 * @package EventOS
 */

declare( strict_types = 1 );

namespace EventOS\Events;

use WP_Error;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Turns untrusted request payloads into safe column values.
 */
final class Event_Validator {

	/**
	 * Validate and normalise an event payload.
	 *
	 * @param array<string, mixed> $input   Raw payload.
	 * @param array<string, mixed>|null $existing Existing event when updating.
	 * @return array<string, mixed>|WP_Error
	 */
	public static function event( array $input, ?array $existing = null ) {
		$is_update = null !== $existing;
		$data      = array();
		$errors    = new WP_Error();

		if ( ! $is_update || array_key_exists( 'title', $input ) ) {
			$title = sanitize_text_field( (string) ( $input['title'] ?? '' ) );

			if ( '' === $title ) {
				$errors->add( 'title', __( 'An event needs a title.', 'eventos' ) );
			}

			$data['title'] = $title;
		}

		foreach ( array( 'subtitle', 'age_restriction' ) as $key ) {
			if ( array_key_exists( $key, $input ) ) {
				$data[ $key ] = sanitize_text_field( (string) $input[ $key ] );
			}
		}

		if ( array_key_exists( 'description', $input ) ) {
			$data['description'] = wp_kses_post( (string) $input['description'] );
		}

		foreach ( array( 'short_description', 'accessibility' ) as $key ) {
			if ( array_key_exists( $key, $input ) ) {
				$data[ $key ] = sanitize_textarea_field( (string) $input[ $key ] );
			}
		}

		if ( array_key_exists( 'status', $input ) ) {
			$status = sanitize_key( (string) $input['status'] );

			if ( ! Event_Status::is_valid( $status ) ) {
				$errors->add( 'status', __( 'Unknown event status.', 'eventos' ) );
			} else {
				$data['status'] = $status;
			}
		}

		if ( array_key_exists( 'visibility', $input ) ) {
			$visibility = sanitize_key( (string) $input['visibility'] );

			if ( ! array_key_exists( $visibility, Event_Status::visibilities() ) ) {
				$errors->add( 'visibility', __( 'Unknown event visibility.', 'eventos' ) );
			} else {
				$data['visibility'] = $visibility;
			}
		}

		if ( array_key_exists( 'ticket_visibility', $input ) ) {
			$ticket = sanitize_key( (string) $input['ticket_visibility'] );

			if ( ! array_key_exists( $ticket, Event_Status::ticket_visibilities() ) ) {
				$errors->add( 'ticket_visibility', __( 'Unknown ticket visibility.', 'eventos' ) );
			} else {
				$data['ticket_visibility'] = $ticket;
			}
		}

		$visibility = (string) ( $data['visibility'] ?? ( $existing['visibility'] ?? 'public' ) );

		if ( array_key_exists( 'password', $input ) ) {
			$password = (string) $input['password'];

			if ( 'password' === $visibility && '' === $password && empty( $existing['password_protected'] ) ) {
				$errors->add( 'password', __( 'Password protected events need a password.', 'eventos' ) );
			}

			$data['password_hash'] = '' === $password ? '' : wp_hash_password( $password );
		} elseif ( 'password' !== $visibility && array_key_exists( 'visibility', $input ) ) {
			$data['password_hash'] = '';
		}

		if ( array_key_exists( 'timezone', $input ) ) {
			$timezone = (string) $input['timezone'];

			if ( '' === $timezone || ! in_array( $timezone, timezone_identifiers_list(), true ) ) {
				$errors->add( 'timezone', __( 'Choose a valid timezone.', 'eventos' ) );
			} else {
				$data['timezone'] = $timezone;
			}
		}

		foreach ( array( 'venue_id', 'featured_image_id' ) as $key ) {
			if ( array_key_exists( $key, $input ) ) {
				$data[ $key ] = max( 0, (int) $input[ $key ] );
			}
		}

		if ( array_key_exists( 'capacity', $input ) ) {
			$data['capacity'] = max( 0, (int) $input['capacity'] );
		}

		foreach ( array( 'starts_at', 'ends_at', 'doors_open_at' ) as $key ) {
			if ( ! array_key_exists( $key, $input ) ) {
				continue;
			}

			$value = self::datetime( $input[ $key ] );

			if ( false === $value ) {
				$errors->add( $key, __( 'Enter a valid date and time.', 'eventos' ) );
				continue;
			}

			$data[ $key ] = $value;
		}

		$starts = $data['starts_at'] ?? ( $existing['starts_at'] ?? null );
		$ends   = $data['ends_at'] ?? ( $existing['ends_at'] ?? null );
		$doors  = $data['doors_open_at'] ?? ( $existing['doors_open_at'] ?? null );

		if ( $starts && $ends && strtotime( (string) $ends ) < strtotime( (string) $starts ) ) {
			$errors->add( 'ends_at', __( 'The closing time must come after the opening time.', 'eventos' ) );
		}

		if ( $starts && $doors && strtotime( (string) $doors ) > strtotime( (string) $starts ) ) {
			$errors->add( 'doors_open_at', __( 'Doors cannot open after the event starts.', 'eventos' ) );
		}

		foreach ( array( 'organisers', 'collaborators' ) as $key ) {
			if ( array_key_exists( $key, $input ) ) {
				$ids = array_values(
					array_unique(
						array_filter(
							array_map( 'absint', (array) $input[ $key ] )
						)
					)
				);

				$data[ $key ] = (string) wp_json_encode( $ids );
			}
		}

		if ( array_key_exists( 'recurrence', $input ) ) {
			$recurrence = self::recurrence( (array) $input['recurrence'] );

			if ( is_wp_error( $recurrence ) ) {
				$errors->add( 'recurrence', $recurrence->get_error_message() );
			} else {
				$data['recurrence'] = (string) wp_json_encode( $recurrence );
			}
		}

		if ( $errors->has_errors() ) {
			return $errors;
		}

		return $data;
	}

	/**
	 * Validate a recurrence rule.
	 *
	 * @param array<string, mixed> $input Raw rule.
	 * @return array<string, mixed>|WP_Error
	 */
	public static function recurrence( array $input ) {
		$frequency = sanitize_key( (string) ( $input['frequency'] ?? 'none' ) );

		if ( ! in_array( $frequency, array( 'none', 'daily', 'weekly', 'monthly' ), true ) ) {
			return new WP_Error( 'eventos_invalid_recurrence', __( 'Unsupported recurrence frequency.', 'eventos' ) );
		}

		if ( 'none' === $frequency ) {
			return array( 'frequency' => 'none' );
		}

		$interval = max( 1, min( 52, (int) ( $input['interval'] ?? 1 ) ) );
		$count    = max( 1, min( 104, (int) ( $input['count'] ?? 1 ) ) );
		$weekdays = array_values(
			array_filter(
				array_map( 'absint', (array) ( $input['weekdays'] ?? array() ) ),
				static function ( int $day ): bool {
					return $day >= 0 && $day <= 6;
				}
			)
		);

		$until = self::datetime( $input['until'] ?? null );

		return array(
			'frequency' => $frequency,
			'interval'  => $interval,
			'count'     => $count,
			'weekdays'  => $weekdays,
			'until'     => false === $until ? null : $until,
		);
	}

	/**
	 * Validate a venue payload.
	 *
	 * @param array<string, mixed> $input     Raw payload.
	 * @param bool                 $is_update Whether this is a partial update.
	 * @return array<string, mixed>|WP_Error
	 */
	public static function venue( array $input, bool $is_update = false ) {
		$data   = array();
		$errors = new WP_Error();

		if ( ! $is_update || array_key_exists( 'name', $input ) ) {
			$name = sanitize_text_field( (string) ( $input['name'] ?? '' ) );

			if ( '' === $name ) {
				$errors->add( 'name', __( 'A venue needs a name.', 'eventos' ) );
			}

			$data['name'] = $name;
		}

		foreach ( array( 'address_line1', 'address_line2', 'city', 'province', 'postal_code' ) as $key ) {
			if ( array_key_exists( $key, $input ) ) {
				$data[ $key ] = sanitize_text_field( (string) $input[ $key ] );
			}
		}

		if ( array_key_exists( 'country', $input ) ) {
			$country = strtoupper( substr( preg_replace( '/[^A-Za-z]/', '', (string) $input['country'] ) ?? '', 0, 2 ) );
			$data['country'] = $country;
		}

		foreach ( array( 'latitude' => 90.0, 'longitude' => 180.0 ) as $key => $bound ) {
			if ( ! array_key_exists( $key, $input ) ) {
				continue;
			}

			$raw = $input[ $key ];

			if ( null === $raw || '' === $raw ) {
				$data[ $key ] = null;
				continue;
			}

			$value = (float) $raw;

			if ( abs( $value ) > $bound ) {
				$errors->add( $key, __( 'Coordinates are out of range.', 'eventos' ) );
				continue;
			}

			$data[ $key ] = number_format( $value, 7, '.', '' );
		}

		if ( array_key_exists( 'maps_url', $input ) ) {
			$data['maps_url'] = esc_url_raw( (string) $input['maps_url'] );
		}

		foreach ( array( 'parking_info', 'notes' ) as $key ) {
			if ( array_key_exists( $key, $input ) ) {
				$data[ $key ] = sanitize_textarea_field( (string) $input[ $key ] );
			}
		}

		if ( array_key_exists( 'capacity', $input ) ) {
			$data['capacity'] = max( 0, (int) $input['capacity'] );
		}

		if ( array_key_exists( 'seating_configuration', $input ) ) {
			$sections = array();

			foreach ( (array) $input['seating_configuration'] as $section ) {
				$section = (array) $section;
				$label   = sanitize_text_field( (string) ( $section['label'] ?? '' ) );

				if ( '' === $label ) {
					continue;
				}

				$sections[] = array(
					'label'    => $label,
					'capacity' => max( 0, (int) ( $section['capacity'] ?? 0 ) ),
					'notes'    => sanitize_text_field( (string) ( $section['notes'] ?? '' ) ),
				);
			}

			$data['seating_configuration'] = (string) wp_json_encode( $sections );
		}

		if ( $errors->has_errors() ) {
			return $errors;
		}

		return $data;
	}

	/**
	 * Validate an artist payload.
	 *
	 * @param array<string, mixed> $input     Raw payload.
	 * @param bool                 $is_update Whether this is a partial update.
	 * @return array<string, mixed>|WP_Error
	 */
	public static function artist( array $input, bool $is_update = false ) {
		$data   = array();
		$errors = new WP_Error();

		if ( ! $is_update || array_key_exists( 'name', $input ) ) {
			$name = sanitize_text_field( (string) ( $input['name'] ?? '' ) );

			if ( '' === $name ) {
				$errors->add( 'name', __( 'An artist needs a name.', 'eventos' ) );
			}

			$data['name'] = $name;
		}

		if ( array_key_exists( 'biography', $input ) ) {
			$data['biography'] = wp_kses_post( (string) $input['biography'] );
		}

		if ( array_key_exists( 'genres', $input ) ) {
			$genres = array_values(
				array_unique(
					array_filter(
						array_map(
							static function ( $genre ): string {
								return sanitize_text_field( (string) $genre );
							},
							(array) $input['genres']
						)
					)
				)
			);

			$data['genres'] = (string) wp_json_encode( $genres );
		}

		if ( array_key_exists( 'social_links', $input ) ) {
			$links = array();

			foreach ( (array) $input['social_links'] as $network => $url ) {
				$network = sanitize_key( (string) $network );
				$url     = esc_url_raw( (string) $url );

				if ( '' !== $network && '' !== $url ) {
					$links[ $network ] = $url;
				}
			}

			$data['social_links'] = (string) wp_json_encode( $links );
		}

		if ( array_key_exists( 'website', $input ) ) {
			$data['website'] = esc_url_raw( (string) $input['website'] );
		}

		if ( array_key_exists( 'country', $input ) ) {
			$data['country'] = strtoupper( substr( preg_replace( '/[^A-Za-z]/', '', (string) $input['country'] ) ?? '', 0, 2 ) );
		}

		if ( array_key_exists( 'image_id', $input ) ) {
			$data['image_id'] = max( 0, (int) $input['image_id'] );
		}

		if ( $errors->has_errors() ) {
			return $errors;
		}

		return $data;
	}

	/**
	 * Normalise a datetime for storage.
	 *
	 * @param mixed $value Raw value.
	 * @return string|null|false Null when empty, false when invalid.
	 */
	public static function datetime( $value ) {
		if ( null === $value || '' === $value ) {
			return null;
		}

		if ( ! is_string( $value ) ) {
			return false;
		}

		$timestamp = strtotime( $value );

		return $timestamp ? gmdate( 'Y-m-d H:i:s', $timestamp ) : false;
	}
}
