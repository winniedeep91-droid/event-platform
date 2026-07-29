<?php
/**
 * CSV export writer.
 *
 * @package EventOS
 */

declare( strict_types = 1 );

namespace EventOS\Export;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Renders rows as RFC 4180 CSV with a UTF-8 byte order mark for spreadsheets.
 */
final class Csv_Writer {

	/**
	 * Render a CSV document.
	 *
	 * @param array<string, string>            $columns Column key => label.
	 * @param array<int, array<string, mixed>> $rows    Rows.
	 * @return string
	 */
	public static function render( array $columns, array $rows ): string {
		$handle = fopen( 'php://temp', 'r+b' );

		if ( false === $handle ) {
			return '';
		}

		fputcsv( $handle, array_values( array_map( 'strval', $columns ) ) );

		foreach ( $rows as $row ) {
			$line = array();

			foreach ( array_keys( $columns ) as $key ) {
				$line[] = self::stringify( $row[ $key ] ?? '' );
			}

			fputcsv( $handle, $line );
		}

		rewind( $handle );
		$content = (string) stream_get_contents( $handle );
		fclose( $handle );

		return "\xEF\xBB\xBF" . $content;
	}

	/**
	 * Flatten a value for a CSV cell.
	 *
	 * @param mixed $value Cell value.
	 * @return string
	 */
	private static function stringify( $value ): string {
		if ( is_bool( $value ) ) {
			return $value ? 'yes' : 'no';
		}

		if ( is_array( $value ) || is_object( $value ) ) {
			return (string) wp_json_encode( $value );
		}

		return (string) $value;
	}
}
