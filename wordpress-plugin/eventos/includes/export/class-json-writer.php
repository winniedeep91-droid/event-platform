<?php
/**
 * JSON export writer.
 *
 * @package EventOS
 */

declare( strict_types = 1 );

namespace EventOS\Export;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Renders rows as a documented JSON envelope.
 */
final class Json_Writer {

	/**
	 * Render a JSON document.
	 *
	 * @param array<string, string>            $columns Column key => label.
	 * @param array<int, array<string, mixed>> $rows    Rows.
	 * @param string                           $title   Document title.
	 * @return string
	 */
	public static function render( array $columns, array $rows, string $title ): string {
		$items = array();

		foreach ( $rows as $row ) {
			$item = array();

			foreach ( array_keys( $columns ) as $key ) {
				$item[ $key ] = $row[ $key ] ?? null;
			}

			$items[] = $item;
		}

		return (string) wp_json_encode(
			array(
				'title'        => $title,
				'generated_at' => gmdate( 'c' ),
				'generator'    => 'EventOS ' . EVENTOS_VERSION,
				'columns'      => $columns,
				'count'        => count( $items ),
				'items'        => $items,
			),
			JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
		);
	}
}
