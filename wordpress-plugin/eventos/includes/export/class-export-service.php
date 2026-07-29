<?php
/**
 * Format agnostic export service.
 *
 * @package EventOS
 */

declare( strict_types = 1 );

namespace EventOS\Export;

use EventOS\Activity_Log;
use WP_Error;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Turns a registered entity into a downloadable document.
 *
 * Modules never write export code: they register columns and a row provider,
 * and the service renders CSV, JSON or PDF from the same data.
 */
final class Export_Service {

	/**
	 * Supported formats.
	 *
	 * @return string[]
	 */
	public static function formats(): array {
		/**
		 * Filter the export formats EventOS can render.
		 *
		 * @param string[] $formats Format slugs.
		 */
		return (array) apply_filters( 'eventos_export_formats', array( 'csv', 'json', 'pdf' ) );
	}

	/**
	 * Render an export.
	 *
	 * @param string               $entity Entity slug.
	 * @param string               $format Format slug.
	 * @param array<string, mixed> $args   Arguments passed to the row provider.
	 * @return array{filename: string, mime: string, content: string, rows: int}|WP_Error
	 */
	public static function export( string $entity, string $format, array $args = array() ) {
		$definition = Export_Registry::get( $entity );
		$format     = sanitize_key( $format );

		if ( null === $definition ) {
			return new WP_Error(
				'eventos_export_unknown_entity',
				__( 'Unknown export entity.', 'eventos' ),
				array( 'status' => 404 )
			);
		}

		if ( ! current_user_can( (string) $definition['capability'] ) ) {
			return new WP_Error(
				'eventos_forbidden',
				__( 'You are not allowed to export this data.', 'eventos' ),
				array( 'status' => rest_authorization_required_code() )
			);
		}

		if ( ! in_array( $format, array_map( 'strval', (array) $definition['formats'] ), true ) ) {
			return new WP_Error(
				'eventos_export_unsupported_format',
				sprintf(
					/* translators: %s: format slug. */
					__( 'The %s format is not available for this entity.', 'eventos' ),
					$format
				),
				array( 'status' => 400 )
			);
		}

		$rows = call_user_func( $definition['provider'], $args );

		if ( is_wp_error( $rows ) ) {
			return $rows;
		}

		$rows    = self::normalise_rows( $rows );
		$columns = (array) $definition['columns'];

		if ( ! $columns && $rows ) {
			$keys    = array_keys( (array) reset( $rows ) );
			$columns = array_combine( $keys, $keys );
		}

		$content = self::render( $format, $columns, $rows, (string) $definition['label'] );

		if ( is_wp_error( $content ) ) {
			return $content;
		}

		Activity_Log::log(
			array(
				'action'      => 'export_generated',
				'module'      => (string) $definition['module'],
				'object_type' => 'export',
				'object_id'   => (string) $definition['entity'],
				'context'     => array(
					'format' => $format,
					'rows'   => count( $rows ),
				),
			)
		);

		return array(
			'filename' => sprintf( '%s-%s.%s', sanitize_file_name( (string) $definition['filename'] ), gmdate( 'Ymd-His' ), $format ),
			'mime'     => self::mime( $format ),
			'content'  => $content,
			'rows'     => count( $rows ),
		);
	}

	/**
	 * Render rows in the requested format.
	 *
	 * @param string                          $format  Format slug.
	 * @param array<string, string>           $columns Column key => label.
	 * @param array<int, array<string, mixed>> $rows    Rows.
	 * @param string                          $title   Document title.
	 * @return string|WP_Error
	 */
	private static function render( string $format, array $columns, array $rows, string $title ) {
		switch ( $format ) {
			case 'csv':
				return Csv_Writer::render( $columns, $rows );
			case 'json':
				return Json_Writer::render( $columns, $rows, $title );
			case 'pdf':
				return Pdf_Writer::render( $columns, $rows, $title );
		}

		/**
		 * Filter the rendered payload for custom export formats.
		 *
		 * @param string|null $content Rendered content.
		 * @param string      $format  Format slug.
		 * @param array       $columns Column definitions.
		 * @param array       $rows    Rows.
		 * @param string      $title   Document title.
		 */
		$content = apply_filters( 'eventos_export_render', null, $format, $columns, $rows, $title );

		if ( is_string( $content ) ) {
			return $content;
		}

		return new WP_Error(
			'eventos_export_unsupported_format',
			__( 'No renderer is available for this export format.', 'eventos' ),
			array( 'status' => 400 )
		);
	}

	/**
	 * MIME type for a format.
	 *
	 * @param string $format Format slug.
	 * @return string
	 */
	public static function mime( string $format ): string {
		$types = array(
			'csv'  => 'text/csv',
			'json' => 'application/json',
			'pdf'  => 'application/pdf',
		);

		/**
		 * Filter the MIME type used for an export format.
		 *
		 * @param array  $types  Format slug => MIME type.
		 * @param string $format Requested format.
		 */
		$types = (array) apply_filters( 'eventos_export_mime_types', $types, $format );

		return (string) ( $types[ $format ] ?? 'application/octet-stream' );
	}

	/**
	 * Coerce a provider result into a list of associative rows.
	 *
	 * @param mixed $rows Provider result.
	 * @return array<int, array<string, mixed>>
	 */
	private static function normalise_rows( $rows ): array {
		if ( $rows instanceof \Traversable ) {
			$rows = iterator_to_array( $rows, false );
		}

		$normalised = array();

		foreach ( (array) $rows as $row ) {
			if ( is_object( $row ) ) {
				$row = get_object_vars( $row );
			}

			if ( is_array( $row ) ) {
				$normalised[] = $row;
			}
		}

		return $normalised;
	}
}
