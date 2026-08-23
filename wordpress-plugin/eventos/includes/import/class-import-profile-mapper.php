<?php
/**
 * Resolves an Import Profile's declarative field spec against a real
 * source's columns, and applies the small set of value transforms profiles
 * can declare.
 *
 * @package EventOS
 */

declare( strict_types = 1 );

namespace EventOS\Import;

use EventOS\Crm\Identity_Normalizer;
use WP_Error;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Pure computation, no persistence and no I/O — mirrors the separation
 * already in the codebase between "resolve what to do" (this class,
 * `Event_Identity_Resolver`, …) and "do it" (`Import_Engine`,
 * `Event_Service`, the target writers).
 *
 * A profile's per-stage field spec entry is one of:
 * - `'Event ID'`                                        — a source column name (shorthand for `['column' => …]`).
 * - `['column' => 'Event ID']`                           — same, explicit.
 * - `['column' => 'Event ID', 'aliases' => ['EventID']]` — exact + a fixed list of alternate column names.
 * - `['const' => 'quicket_event_id']`                    — a literal value, not read from any column
 *   (used for the identity *type* — e.g. `source` — which is one fixed
 *   string for an entire profile/stage, never a per-row column).
 * - `['column' => 'Price', 'transform' => 'money']`      — a column plus a normalizer.
 * - `['columns' => ['First Name', 'Last Name'], 'transform' => 'full_name']` — join two columns.
 *
 * The resolved output is the same extended mapping shape
 * {@see Abstract_Import_Provider::apply_mapping()} already knows how to
 * consume — this class never talks to `Import_Engine` directly.
 */
final class Import_Profile_Mapper {

	/**
	 * Every transform name {@see self::normalize()} recognises — used to
	 * validate a mapping (including an administrator-edited one) before an
	 * import starts, rather than silently letting an unknown transform pass
	 * through unnoticed.
	 *
	 * @var string[]
	 */
	private const KNOWN_TRANSFORMS = array( 'money', 'full_name', 'status', 'email', 'phone' );

	/**
	 * Resolve a profile stage's field spec against a source's actual columns.
	 *
	 * @param array<string, mixed> $profile           Registered profile definition.
	 * @param string               $entity            Stage/entity slug, e.g. 'events'.
	 * @param string[]             $available_columns Column headers the source actually has.
	 * @return array<string, mixed>|WP_Error Extended mapping — target field => string|array.
	 */
	public static function resolve_mapping( array $profile, string $entity, array $available_columns ) {
		$stage = (array) ( $profile['stages'][ $entity ] ?? array() );

		if ( empty( $stage['fields'] ) ) {
			return new WP_Error(
				'eventos_import_profile_no_stage',
				sprintf(
					/* translators: 1: profile id, 2: entity/stage slug. */
					__( 'Profile "%1$s" has no field mapping for the "%2$s" stage.', 'eventos' ),
					(string) ( $profile['id'] ?? '' ),
					$entity
				)
			);
		}

		$mapping = array();

		foreach ( (array) $stage['fields'] as $field => $spec ) {
			$field = (string) $field;

			// A field may declare a *list* of alternative specs — e.g. try
			// a single "Attendee Name" column, and only if the source
			// doesn't have one, fall back to joining "First Name" +
			// "Last Name" — tried in order, first one whose column(s)
			// actually exist in this source wins. Any other shape is one
			// spec, tried on its own.
			$alternatives = ( is_array( $spec ) && isset( $spec[0] ) && is_array( $spec[0] ) ) ? $spec : array( $spec );

			foreach ( $alternatives as $alternative ) {
				$resolved = self::resolve_field_spec( $alternative, $available_columns );

				if ( null !== $resolved ) {
					$mapping[ $field ] = $resolved;
					break;
				}
			}

			// Simply left unmapped when nothing matches —
			// Import_Registry::validate_record() catches a missing
			// *required* field; an optional one just stays absent.
		}

		return $mapping;
	}

	/**
	 * Apply a resolved mapping to one source row — the single place this
	 * transformation happens. Used both by the real import writer path
	 * ({@see Abstract_Import_Provider::apply_mapping()}, a thin delegate to
	 * this method) and by a "preview mapped data" caller, so the preview a
	 * user sees is guaranteed to be exactly what import will persist.
	 *
	 * @param array<string, mixed>          $row     Source row.
	 * @param array<string, string|mixed[]> $mapping Target field => source column, or the extended shape
	 *                                                {@see self::resolve_mapping()} produces.
	 * @return array<string, mixed>
	 */
	public static function apply_to_row( array $row, array $mapping ): array {
		$record = array();

		foreach ( $mapping as $field => $spec ) {
			$field = (string) $field;

			if ( is_string( $spec ) ) {
				$record[ $field ] = $row[ $spec ] ?? null;
				continue;
			}

			$spec = (array) $spec;

			if ( array_key_exists( 'const', $spec ) ) {
				$record[ $field ] = $spec['const'];
				continue;
			}

			if ( ! empty( $spec['columns'] ) && is_array( $spec['columns'] ) ) {
				$values = array();

				foreach ( $spec['columns'] as $column ) {
					$values[] = $row[ (string) $column ] ?? '';
				}

				$record[ $field ] = '' !== (string) ( $spec['transform'] ?? '' )
					? self::normalize( (string) $spec['transform'], $values )
					: implode( ' ', array_filter( array_map( 'strval', $values ) ) );

				continue;
			}

			$value = isset( $spec['column'] ) ? ( $row[ (string) $spec['column'] ] ?? null ) : null;

			$record[ $field ] = null !== $value && '' !== (string) ( $spec['transform'] ?? '' )
				? self::normalize( (string) $spec['transform'], $value )
				: $value;
		}

		return $record;
	}

	/**
	 * Validate a resolved (or administrator-edited) mapping before an
	 * import is allowed to start — a pre-flight, mapping-level check,
	 * complementary to {@see \EventOS\Import\Import_Registry::validate_record()}'s
	 * existing per-row validation at import time.
	 *
	 * @param array<string, mixed>            $mapping       Target field => source column, or the extended shape.
	 * @param array<string, array<string, mixed>> $target_fields Target's registered field definitions
	 *                                                             ({@see \EventOS\Import\Import_Registry::target()}'s `fields`).
	 * @param string[]                        $available_columns Real source column headers.
	 * @return array<int, array{field: string, message: string}> Empty when the mapping is valid.
	 */
	public static function validate_mapping( array $mapping, array $target_fields, array $available_columns ): array {
		$errors = array();

		foreach ( $target_fields as $field => $definition ) {
			$field = (string) $field;
			$spec  = $mapping[ $field ] ?? null;

			if ( ! empty( $definition['required'] ) && null === $spec ) {
				$errors[] = array(
					'field'   => $field,
					'message' => __( 'This required field is not mapped to any source column.', 'eventos' ),
				);
			}
		}

		foreach ( $mapping as $field => $spec ) {
			$field = (string) $field;

			if ( is_string( $spec ) ) {
				if ( ! in_array( $spec, $available_columns, true ) ) {
					$errors[] = array(
						'field'   => $field,
						/* translators: %s: source column name. */
						'message' => sprintf( __( 'Mapped source column "%s" does not exist in this file.', 'eventos' ), $spec ),
					);
				}

				continue;
			}

			$spec = (array) $spec;

			if ( array_key_exists( 'const', $spec ) ) {
				continue; // A constant needs no column and is always valid.
			}

			$columns = ! empty( $spec['columns'] ) && is_array( $spec['columns'] ) ? $spec['columns'] : ( isset( $spec['column'] ) ? array( $spec['column'] ) : array() );

			if ( empty( $columns ) ) {
				$errors[] = array(
					'field'   => $field,
					'message' => __( 'This mapping has neither a source column nor a constant value.', 'eventos' ),
				);
			}

			foreach ( $columns as $column ) {
				if ( ! in_array( (string) $column, $available_columns, true ) ) {
					$errors[] = array(
						'field'   => $field,
						/* translators: %s: source column name. */
						'message' => sprintf( __( 'Mapped source column "%s" does not exist in this file.', 'eventos' ), (string) $column ),
					);
				}
			}

			$transform = (string) ( $spec['transform'] ?? '' );

			if ( '' !== $transform && ! in_array( $transform, self::KNOWN_TRANSFORMS, true ) ) {
				$errors[] = array(
					'field'   => $field,
					/* translators: %s: transform name. */
					'message' => sprintf( __( 'Unknown transform "%s".', 'eventos' ), $transform ),
				);
			}
		}

		return $errors;
	}

	/**
	 * Resolve one field spec (string shorthand, `const`, `column`, or
	 * `columns` join) against the source's real columns.
	 *
	 * @param mixed    $spec              One field spec.
	 * @param string[] $available_columns Real source column headers.
	 * @return string|array<string, mixed>|null The mapping value, or null if this spec doesn't match the source.
	 */
	private static function resolve_field_spec( $spec, array $available_columns ) {
		if ( is_string( $spec ) ) {
			$spec = array( 'column' => $spec );
		}

		$spec = (array) $spec;

		if ( array_key_exists( 'const', $spec ) ) {
			return array( 'const' => $spec['const'] );
		}

		if ( ! empty( $spec['columns'] ) && is_array( $spec['columns'] ) ) {
			$resolved = array();

			foreach ( $spec['columns'] as $candidate ) {
				$found = self::match_column( (string) $candidate, array(), $available_columns );

				if ( null !== $found ) {
					$resolved[] = $found;
				}
			}

			if ( empty( $resolved ) ) {
				return null;
			}

			return array(
				'columns'   => $resolved,
				'transform' => (string) ( $spec['transform'] ?? '' ),
			);
		}

		if ( ! isset( $spec['column'] ) ) {
			return null;
		}

		$column = self::match_column( (string) $spec['column'], (array) ( $spec['aliases'] ?? array() ), $available_columns );

		if ( null === $column ) {
			return null;
		}

		return isset( $spec['transform'] ) && '' !== (string) $spec['transform']
			? array(
				'column'    => $column,
				'transform' => (string) $spec['transform'],
			)
			: $column;
	}

	/**
	 * Find one declared column (by name or alias) among the real headers —
	 * exact, deterministic matching only. No fuzzy/AI guessing.
	 *
	 * @param string   $primary  Primary expected column name.
	 * @param string[] $aliases  Alternate accepted column names.
	 * @param string[] $columns  Real source column headers.
	 * @return string|null
	 */
	private static function match_column( string $primary, array $aliases, array $columns ): ?string {
		$candidates = array_merge( array( $primary ), $aliases );

		foreach ( $columns as $column ) {
			$normalised = self::normalise_name( (string) $column );

			foreach ( $candidates as $candidate ) {
				if ( $normalised === self::normalise_name( (string) $candidate ) ) {
					return (string) $column;
				}
			}
		}

		return null;
	}

	/**
	 * Normalize a column/field name for exact-after-normalizing comparison
	 * (case/space/punctuation-insensitive, still deterministic).
	 *
	 * @param string $value Raw value.
	 * @return string
	 */
	private static function normalise_name( string $value ): string {
		return preg_replace( '/[^a-z0-9]/', '', strtolower( $value ) ) ?? '';
	}

	/**
	 * Apply one of the small set of value transforms a profile can declare.
	 *
	 * @param string $transform Transform name.
	 * @param mixed  $value     Raw value (a single value, or an array of
	 *                          values for a multi-column join).
	 * @return mixed
	 */
	public static function normalize( string $transform, $value ) {
		switch ( $transform ) {
			case 'money':
				return self::normalize_money( (string) $value );

			case 'full_name':
				return self::normalize_full_name( (array) $value );

			case 'status':
				return self::normalize_status( (string) $value );

			case 'email':
				return Identity_Normalizer::normalize_email( (string) $value );

			case 'phone':
				return Identity_Normalizer::normalize_phone( (string) $value );

			default:
				return $value;
		}
	}

	/**
	 * Strip currency symbols/thousands separators and return a plain float
	 * string — "R 1,200.00", "$50", "50.00" all normalize to a plain number.
	 *
	 * @param string $value Raw price text.
	 * @return string
	 */
	private static function normalize_money( string $value ): string {
		$value = trim( $value );

		if ( '' === $value ) {
			return '';
		}

		$negative = 0 === strpos( $value, '-' ) || ( 1 === preg_match( '/^\(.*\)$/', $value ) );
		$digits   = preg_replace( '/[^0-9.]/', '', $value ) ?? '';

		if ( '' === $digits ) {
			return '';
		}

		return ( $negative ? '-' : '' ) . $digits;
	}

	/**
	 * Join non-empty parts (e.g. first + last name) with a single space.
	 *
	 * @param array<int, mixed> $parts Raw values, in order.
	 * @return string
	 */
	private static function normalize_full_name( array $parts ): string {
		$parts = array_map( static fn( $part ): string => trim( (string) $part ), $parts );
		$parts = array_filter( $parts, static fn( string $part ): bool => '' !== $part );

		return implode( ' ', $parts );
	}

	/**
	 * Fold common source status synonyms onto EventOS's own ticket status
	 * vocabulary. Anything unrecognised passes through unchanged — the
	 * downstream writer/repository already falls back safely to a known
	 * default rather than erroring.
	 *
	 * @param string $value Raw status text.
	 * @return string
	 */
	private static function normalize_status( string $value ): string {
		$key = strtolower( trim( $value ) );

		$map = array(
			'confirmed' => 'active',
			'valid'     => 'active',
			'complete'  => 'active',
			'active'    => 'active',
			'cancelled' => 'cancelled',
			'canceled'  => 'cancelled',
			'refunded'  => 'cancelled',
			'void'      => 'cancelled',
		);

		return $map[ $key ] ?? $value;
	}
}
