<?php
/**
 * Contract implemented by every EventOS import provider.
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
 * Provider contract.
 *
 * The import engine never knows which provider it is talking to: it only ever
 * calls the methods below. A provider is responsible for understanding a single
 * source system (a CSV file, a WooCommerce store, a ticketing API) and for
 * turning it into rows the registered import targets can consume.
 */
interface Import_Provider_Interface {

	/**
	 * Unique provider slug.
	 *
	 * @return string
	 */
	public function slug(): string;

	/**
	 * Human readable provider name.
	 *
	 * @return string
	 */
	public function label(): string;

	/**
	 * Short description shown in the import UI.
	 *
	 * @return string
	 */
	public function description(): string;

	/**
	 * Target entity slugs this provider can produce rows for.
	 *
	 * @return string[]
	 */
	public function entities(): array;

	/**
	 * Whether the provider can handle the given source definition.
	 *
	 * @param array<string, mixed> $source Source definition.
	 * @return bool
	 */
	public function detect( array $source ): bool;

	/**
	 * Validate a source definition before any data is read.
	 *
	 * @param array<string, mixed> $source Source definition.
	 * @return true|WP_Error
	 */
	public function validate( array $source );

	/**
	 * Read a small sample of the source.
	 *
	 * @param array<string, mixed> $source Source definition.
	 * @param int                  $limit  Maximum rows to return.
	 * @return array{columns: string[], rows: array<int, array<string, mixed>>, total: int}|WP_Error
	 */
	public function preview( array $source, int $limit = 10 );

	/**
	 * Suggested mapping between source columns and target fields.
	 *
	 * @param array<string, mixed> $source Source definition.
	 * @param string               $entity Target entity slug.
	 * @return array<string, string>|WP_Error Target field => source column.
	 */
	public function map_fields( array $source, string $entity );

	/**
	 * Import the source into the target entity.
	 *
	 * @param array<string, mixed>          $source  Source definition.
	 * @param array<string, string|mixed[]> $mapping Target field => source column, or the extended
	 *                                                shape {@see Abstract_Import_Provider::apply_mapping()} accepts
	 *                                                (const / column+transform / columns+transform) when this run's
	 *                                                mapping came from an Import Profile rather than auto-detection.
	 * @param array<string, mixed>          $context Run context (run_id, entity, dry_run, offset, limit).
	 * @return array{imported: int, skipped: int, failed: int, errors: string[], created: array<int, array<string, mixed>>, done: bool}|WP_Error
	 */
	public function import( array $source, array $mapping, array $context );

	/**
	 * Undo everything a previous run created.
	 *
	 * @param array<string, mixed> $run Stored run record.
	 * @return true|WP_Error
	 */
	public function rollback( array $run );
}
