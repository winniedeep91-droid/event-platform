<?php
/**
 * Shared behaviour for import providers.
 *
 * @package EventOS
 */

declare( strict_types = 1 );

namespace EventOS\Import;

use WP_Error;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Base provider implementing everything that is identical for every source.
 *
 * Concrete providers override the reading parts (detect, preview, rows) while
 * mapping, validation plumbing and rollback stay in one place.
 */
abstract class Abstract_Import_Provider implements Import_Provider_Interface {

	/**
	 * Short description shown in the import UI.
	 *
	 * @return string
	 */
	public function description(): string {
		return '';
	}

	/**
	 * Target entity slugs this provider can produce rows for.
	 *
	 * @return string[]
	 */
	public function entities(): array {
		return array_keys( Import_Registry::targets() );
	}

	/**
	 * Whether the provider is ready to read data.
	 *
	 * Scaffolded providers return a WP_Error describing what is missing; the
	 * engine surfaces it instead of starting an unusable run.
	 *
	 * @return true|WP_Error
	 */
	public function readiness() {
		return true;
	}

	/**
	 * Whether the provider can handle the given source definition.
	 *
	 * @param array<string, mixed> $source Source definition.
	 * @return bool
	 */
	public function detect( array $source ): bool {
		return isset( $source['provider'] ) && $this->slug() === (string) $source['provider'];
	}

	/**
	 * Validate a source definition.
	 *
	 * @param array<string, mixed> $source Source definition.
	 * @return true|WP_Error
	 */
	public function validate( array $source ) {
		$ready = $this->readiness();

		if ( is_wp_error( $ready ) ) {
			return $ready;
		}

		if ( ! $this->detect( $source ) ) {
			return new WP_Error(
				'eventos_import_source_mismatch',
				/* translators: %s: provider label. */
				sprintf( __( 'This source cannot be read by the %s importer.', 'eventos' ), $this->label() ),
				array( 'status' => 400 )
			);
		}

		return true;
	}

	/**
	 * Read a sample of the source.
	 *
	 * @param array<string, mixed> $source Source definition.
	 * @param int                  $limit  Maximum rows.
	 * @return array{columns: string[], rows: array<int, array<string, mixed>>, total: int}|WP_Error
	 */
	public function preview( array $source, int $limit = 10 ) {
		$valid = $this->validate( $source );

		if ( is_wp_error( $valid ) ) {
			return $valid;
		}

		$rows = $this->read_rows( $source, 0, max( 1, $limit ) );

		if ( is_wp_error( $rows ) ) {
			return $rows;
		}

		$columns = $rows ? array_keys( (array) reset( $rows ) ) : $this->columns( $source );

		return array(
			'columns' => array_values( $columns ),
			'rows'    => array_values( $rows ),
			'total'   => $this->count_rows( $source ),
		);
	}

	/**
	 * Suggested mapping between target fields and source columns.
	 *
	 * @param array<string, mixed> $source Source definition.
	 * @param string               $entity Target entity slug.
	 * @return array<string, string>|WP_Error
	 */
	public function map_fields( array $source, string $entity ) {
		$target = Import_Registry::target( $entity );

		if ( null === $target ) {
			return new WP_Error(
				'eventos_import_unknown_entity',
				__( 'Unknown import target.', 'eventos' ),
				array( 'status' => 404 )
			);
		}

		$preview = $this->preview( $source, 1 );

		if ( is_wp_error( $preview ) ) {
			return $preview;
		}

		$mapping = array();

		foreach ( (array) $target['fields'] as $field => $definition ) {
			$candidates = array_merge( array( $field, (string) $definition['label'] ), (array) $definition['aliases'] );

			foreach ( $preview['columns'] as $column ) {
				$normalised = self::normalise( (string) $column );

				foreach ( $candidates as $candidate ) {
					if ( $normalised === self::normalise( (string) $candidate ) ) {
						$mapping[ $field ] = (string) $column;
						break 2;
					}
				}
			}
		}

		return $mapping;
	}

	/**
	 * Import a batch of rows into the target entity.
	 *
	 * @param array<string, mixed>  $source  Source definition.
	 * @param array<string, string> $mapping Target field => source column.
	 * @param array<string, mixed>  $context Run context.
	 * @return array{imported: int, skipped: int, failed: int, errors: string[], created: array<int, array<string, mixed>>, done: bool}|WP_Error
	 */
	public function import( array $source, array $mapping, array $context ) {
		$valid = $this->validate( $source );

		if ( is_wp_error( $valid ) ) {
			return $valid;
		}

		$entity = (string) ( $context['entity'] ?? '' );
		$target = Import_Registry::target( $entity );

		if ( null === $target ) {
			return new WP_Error(
				'eventos_import_unknown_entity',
				__( 'Unknown import target.', 'eventos' ),
				array( 'status' => 404 )
			);
		}

		$offset  = max( 0, (int) ( $context['offset'] ?? 0 ) );
		$limit   = max( 1, (int) ( $context['limit'] ?? 100 ) );
		$dry_run = ! empty( $context['dry_run'] );
		$rows    = $this->read_rows( $source, $offset, $limit );

		if ( is_wp_error( $rows ) ) {
			return $rows;
		}

		$result = array(
			'imported' => 0,
			'skipped'  => 0,
			'failed'   => 0,
			'errors'   => array(),
			'created'  => array(),
			'done'     => count( $rows ) < $limit,
			// 'new'/'existing'/'duplicate' are only ever populated when the
			// target registers an optional 'classifier' callable — most
			// targets don't need one (an import always creates a fresh row
			// for them, so every row is trivially "new"). Where dedup is
			// meaningful (e.g. CRM People, matched by e-mail), the target can
			// classify a row without writing anything, which the writer
			// itself cannot do here since dry-run never calls it.
			'new'       => 0,
			'existing'  => 0,
			'duplicate' => 0,
		);

		$classifier = is_callable( $target['classifier'] ?? null ) ? $target['classifier'] : null;

		foreach ( $rows as $index => $row ) {
			$record = $this->apply_mapping( (array) $row, $mapping );
			$check  = Import_Registry::validate_record( $entity, $record );

			if ( is_wp_error( $check ) ) {
				++$result['failed'];
				$result['errors'][] = sprintf(
					/* translators: 1: row number, 2: error message. */
					__( 'Row %1$d: %2$s', 'eventos' ),
					$offset + (int) $index + 1,
					$check->get_error_message()
				);
				continue;
			}

			if ( $dry_run ) {
				++$result['skipped'];

				if ( null !== $classifier ) {
					$classification = (string) call_user_func( $classifier, $record );

					if ( isset( $result[ $classification ] ) && in_array( $classification, array( 'new', 'existing', 'duplicate' ), true ) ) {
						++$result[ $classification ];
					}
				}

				continue;
			}

			$written = call_user_func( $target['writer'], $record, $context );

			if ( is_wp_error( $written ) ) {
				++$result['failed'];
				$result['errors'][] = sprintf(
					/* translators: 1: row number, 2: error message. */
					__( 'Row %1$d: %2$s', 'eventos' ),
					$offset + (int) $index + 1,
					$written->get_error_message()
				);
				continue;
			}

			++$result['imported'];
			$result['created'][] = array(
				'entity' => $entity,
				'id'     => is_scalar( $written ) ? $written : '',
			);
		}

		return $result;
	}

	/**
	 * Delete everything a run created, newest first.
	 *
	 * @param array<string, mixed> $run Stored run record.
	 * @return true|WP_Error
	 */
	public function rollback( array $run ) {
		$created = array_reverse( (array) ( $run['created'] ?? array() ) );
		$failed  = 0;

		foreach ( $created as $item ) {
			$target = Import_Registry::target( (string) ( $item['entity'] ?? '' ) );

			if ( null === $target || ! is_callable( $target['deleter'] ) ) {
				++$failed;
				continue;
			}

			if ( ! call_user_func( $target['deleter'], $item['id'] ?? '' ) ) {
				++$failed;
			}
		}

		if ( $failed ) {
			return new WP_Error(
				'eventos_import_rollback_incomplete',
				sprintf(
					/* translators: %d: number of records. */
					__( '%d record(s) could not be removed during rollback.', 'eventos' ),
					$failed
				),
				array( 'status' => 500 )
			);
		}

		return true;
	}

	/**
	 * Columns the source exposes when no row could be read.
	 *
	 * @param array<string, mixed> $source Source definition.
	 * @return string[]
	 */
	protected function columns( array $source ): array {
		unset( $source );

		return array();
	}

	/**
	 * Total number of rows available, -1 when unknown.
	 *
	 * @param array<string, mixed> $source Source definition.
	 * @return int
	 */
	protected function count_rows( array $source ): int {
		unset( $source );

		return -1;
	}

	/**
	 * Read raw rows from the source.
	 *
	 * @param array<string, mixed> $source Source definition.
	 * @param int                  $offset Row offset.
	 * @param int                  $limit  Maximum rows.
	 * @return array<int, array<string, mixed>>|WP_Error
	 */
	abstract protected function read_rows( array $source, int $offset, int $limit );

	/**
	 * Translate a source row into a target record.
	 *
	 * A mapping value is normally a plain source column name (unchanged
	 * behaviour). {@see \EventOS\Import\Import_Profile_Mapper} can also
	 * produce a small extended shape this method understands:
	 * `['const' => …]` (a literal, not read from any column — e.g. a fixed
	 * identity type), `['column' => …, 'transform' => …]`, and
	 * `['columns' => […], 'transform' => …]` (join several columns, e.g.
	 * first + last name). Every existing target keeps working unchanged
	 * since they only ever pass plain strings.
	 *
	 * The actual row transformation lives on `Import_Profile_Mapper` itself
	 * — the same code a "preview mapped data" REST endpoint needs, so it
	 * exists in exactly one place rather than being duplicated here and in
	 * a controller.
	 *
	 * @param array<string, mixed>          $row     Source row.
	 * @param array<string, string|mixed[]> $mapping Target field => source column, or the extended shape above.
	 * @return array<string, mixed>
	 */
	protected function apply_mapping( array $row, array $mapping ): array {
		return Import_Profile_Mapper::apply_to_row( $row, $mapping );
	}

	/**
	 * Error used by providers whose connector is not enabled yet.
	 *
	 * @param string $requirement What the site owner must supply.
	 * @return WP_Error
	 */
	protected function unavailable( string $requirement ): WP_Error {
		return new WP_Error(
			'eventos_import_provider_unavailable',
			sprintf(
				/* translators: 1: provider label, 2: requirement. */
				__( 'The %1$s importer is registered but not connected. %2$s', 'eventos' ),
				$this->label(),
				$requirement
			),
			array(
				'status'   => 409,
				'provider' => $this->slug(),
			)
		);
	}

	/**
	 * Normalise a column or field name for fuzzy matching.
	 *
	 * @param string $value Raw value.
	 * @return string
	 */
	private static function normalise( string $value ): string {
		return preg_replace( '/[^a-z0-9]/', '', strtolower( $value ) ) ?? '';
	}
}
