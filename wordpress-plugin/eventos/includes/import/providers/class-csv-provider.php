<?php
/**
 * CSV import provider.
 *
 * @package EventOS
 */

declare( strict_types = 1 );

namespace EventOS\Import\Providers;

use EventOS\Import\Abstract_Import_Provider;
use WP_Error;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Reads delimited files from the media library or an uploaded path.
 *
 * Source keys: provider ('csv'), attachment_id or path, delimiter, enclosure.
 */
final class Csv_Provider extends Abstract_Import_Provider {

	/**
	 * Provider slug.
	 *
	 * @return string
	 */
	public function slug(): string {
		return 'csv';
	}

	/**
	 * Provider label.
	 *
	 * @return string
	 */
	public function label(): string {
		return __( 'CSV file', 'eventos' );
	}

	/**
	 * Provider description.
	 *
	 * @return string
	 */
	public function description(): string {
		return __( 'Import any delimited file from the media library using the shared field mapper.', 'eventos' );
	}

	/**
	 * Detect a CSV source.
	 *
	 * @param array<string, mixed> $source Source definition.
	 * @return bool
	 */
	public function detect( array $source ): bool {
		if ( parent::detect( $source ) ) {
			return true;
		}

		$path = $this->path( $source );

		return is_string( $path ) && 'csv' === strtolower( (string) pathinfo( $path, PATHINFO_EXTENSION ) );
	}

	/**
	 * Validate the file is readable.
	 *
	 * @param array<string, mixed> $source Source definition.
	 * @return true|WP_Error
	 */
	public function validate( array $source ) {
		$path = $this->path( $source );

		if ( is_wp_error( $path ) ) {
			return $path;
		}

		$handle = fopen( $path, 'rb' );

		if ( false === $handle ) {
			return new WP_Error(
				'eventos_import_unreadable_file',
				__( 'The CSV file could not be opened.', 'eventos' ),
				array( 'status' => 400 )
			);
		}

		$header = fgetcsv( $handle, 0, $this->delimiter( $source ), $this->enclosure( $source ) );
		fclose( $handle );

		if ( ! is_array( $header ) || ! array_filter( $header ) ) {
			return new WP_Error(
				'eventos_import_empty_file',
				__( 'The CSV file has no header row.', 'eventos' ),
				array( 'status' => 400 )
			);
		}

		return true;
	}

	/**
	 * Header columns.
	 *
	 * @param array<string, mixed> $source Source definition.
	 * @return string[]
	 */
	protected function columns( array $source ): array {
		$path = $this->path( $source );

		if ( is_wp_error( $path ) ) {
			return array();
		}

		$handle = fopen( $path, 'rb' );

		if ( false === $handle ) {
			return array();
		}

		$header = fgetcsv( $handle, 0, $this->delimiter( $source ), $this->enclosure( $source ) );
		fclose( $handle );

		return is_array( $header ) ? array_map( 'strval', $header ) : array();
	}

	/**
	 * Count data rows.
	 *
	 * @param array<string, mixed> $source Source definition.
	 * @return int
	 */
	protected function count_rows( array $source ): int {
		$path = $this->path( $source );

		if ( is_wp_error( $path ) ) {
			return -1;
		}

		$handle = fopen( $path, 'rb' );

		if ( false === $handle ) {
			return -1;
		}

		$count = -1;

		while ( false !== fgetcsv( $handle, 0, $this->delimiter( $source ), $this->enclosure( $source ) ) ) {
			++$count;
		}

		fclose( $handle );

		return max( 0, $count );
	}

	/**
	 * Read a slice of data rows keyed by header column.
	 *
	 * @param array<string, mixed> $source Source definition.
	 * @param int                  $offset Row offset.
	 * @param int                  $limit  Maximum rows.
	 * @return array<int, array<string, mixed>>|WP_Error
	 */
	protected function read_rows( array $source, int $offset, int $limit ) {
		$path = $this->path( $source );

		if ( is_wp_error( $path ) ) {
			return $path;
		}

		$handle = fopen( $path, 'rb' );

		if ( false === $handle ) {
			return new WP_Error( 'eventos_import_unreadable_file', __( 'The CSV file could not be opened.', 'eventos' ) );
		}

		$delimiter = $this->delimiter( $source );
		$enclosure = $this->enclosure( $source );
		$header    = fgetcsv( $handle, 0, $delimiter, $enclosure );

		if ( ! is_array( $header ) ) {
			fclose( $handle );

			return new WP_Error( 'eventos_import_empty_file', __( 'The CSV file has no header row.', 'eventos' ) );
		}

		$header = array_map( 'strval', $header );
		$rows   = array();
		$index  = 0;

		while ( count( $rows ) < $limit ) {
			$line = fgetcsv( $handle, 0, $delimiter, $enclosure );

			if ( false === $line ) {
				break;
			}

			if ( $index++ < $offset ) {
				continue;
			}

			$line = array_pad( array_slice( (array) $line, 0, count( $header ) ), count( $header ), '' );
			$rows[] = array_combine( $header, array_map( 'strval', $line ) );
		}

		fclose( $handle );

		return $rows;
	}

	/**
	 * Resolve the absolute file path for a source.
	 *
	 * @param array<string, mixed> $source Source definition.
	 * @return string|WP_Error
	 */
	private function path( array $source ) {
		if ( ! empty( $source['attachment_id'] ) ) {
			$path = get_attached_file( (int) $source['attachment_id'] );

			if ( is_string( $path ) && is_readable( $path ) ) {
				return $path;
			}

			return new WP_Error(
				'eventos_import_missing_attachment',
				__( 'The selected media file could not be found.', 'eventos' ),
				array( 'status' => 404 )
			);
		}

		$uploads = wp_get_upload_dir();
		$path    = isset( $source['path'] ) ? wp_normalize_path( (string) $source['path'] ) : '';
		$base    = wp_normalize_path( (string) $uploads['basedir'] );

		if ( '' === $path || 0 !== strpos( $path, $base ) || ! is_readable( $path ) ) {
			return new WP_Error(
				'eventos_import_invalid_path',
				__( 'CSV files must live inside the WordPress uploads directory.', 'eventos' ),
				array( 'status' => 400 )
			);
		}

		return $path;
	}

	/**
	 * Field delimiter.
	 *
	 * @param array<string, mixed> $source Source definition.
	 * @return string
	 */
	private function delimiter( array $source ): string {
		$delimiter = (string) ( $source['delimiter'] ?? ',' );

		return '' === $delimiter ? ',' : substr( $delimiter, 0, 1 );
	}

	/**
	 * Field enclosure.
	 *
	 * @param array<string, mixed> $source Source definition.
	 * @return string
	 */
	private function enclosure( array $source ): string {
		$enclosure = (string) ( $source['enclosure'] ?? '"' );

		return '' === $enclosure ? '"' : substr( $enclosure, 0, 1 );
	}
}
