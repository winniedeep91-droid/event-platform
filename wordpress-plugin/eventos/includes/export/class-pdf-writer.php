<?php
/**
 * PDF export writer.
 *
 * @package EventOS
 */

declare( strict_types = 1 );

namespace EventOS\Export;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Renders a tabular report as a self contained PDF 1.4 document.
 *
 * The writer builds the file from primitives (no external dependency) using the
 * Helvetica base-14 fonts, so exports work on every WordPress host. Modules that
 * need richly designed documents can short-circuit the
 * `eventos_export_render` filter instead.
 */
final class Pdf_Writer {

	/**
	 * Page width in points (A4 landscape).
	 */
	private const PAGE_WIDTH = 841.89;

	/**
	 * Page height in points (A4 landscape).
	 */
	private const PAGE_HEIGHT = 595.28;

	/**
	 * Outer margin in points.
	 */
	private const MARGIN = 36.0;

	/**
	 * Body line height in points.
	 */
	private const LINE_HEIGHT = 14.0;

	/**
	 * Render a PDF document.
	 *
	 * @param array<string, string>            $columns Column key => label.
	 * @param array<int, array<string, mixed>> $rows    Rows.
	 * @param string                           $title   Document title.
	 * @return string
	 */
	public static function render( array $columns, array $rows, string $title ): string {
		$keys       = array_keys( $columns );
		$width      = self::PAGE_WIDTH - ( 2 * self::MARGIN );
		$col_width  = $keys ? $width / count( $keys ) : $width;
		$chars      = max( 6, (int) floor( $col_width / 5.4 ) );
		$per_page   = (int) floor( ( self::PAGE_HEIGHT - ( 2 * self::MARGIN ) - 60 ) / self::LINE_HEIGHT );
		$per_page   = max( 1, $per_page );
		$pages      = array_chunk( $rows, $per_page );
		$pages      = $pages ? $pages : array( array() );
		$streams    = array();
		$page_count = count( $pages );

		foreach ( $pages as $index => $page_rows ) {
			$streams[] = self::page_stream(
				$title,
				$keys,
				array_map( 'strval', array_values( $columns ) ),
				$page_rows,
				$col_width,
				$chars,
				$index + 1,
				$page_count
			);
		}

		return self::assemble( $streams );
	}

	/**
	 * Build the content stream for one page.
	 *
	 * @param string                           $title      Document title.
	 * @param string[]                         $keys       Column keys.
	 * @param string[]                         $labels     Column labels.
	 * @param array<int, array<string, mixed>> $rows       Rows on this page.
	 * @param float                            $col_width  Column width in points.
	 * @param int                              $chars      Characters per cell.
	 * @param int                              $page       Page number.
	 * @param int                              $page_count Total pages.
	 * @return string
	 */
	private static function page_stream( string $title, array $keys, array $labels, array $rows, float $col_width, int $chars, int $page, int $page_count ): string {
		$top    = self::PAGE_HEIGHT - self::MARGIN;
		$stream = "BT\n/F2 16 Tf\n" . self::text_at( self::MARGIN, $top - 16, $title ) . "ET\n";

		$stream .= "BT\n/F1 9 Tf\n" . self::text_at(
			self::MARGIN,
			$top - 32,
			sprintf(
				/* translators: 1: generation date, 2: current page, 3: total pages. */
				__( 'Generated %1$s — page %2$d of %3$d', 'eventos' ),
				gmdate( 'Y-m-d H:i' ) . ' UTC',
				$page,
				$page_count
			)
		) . "ET\n";

		$header_y = $top - 58;

		$stream .= "BT\n/F2 9 Tf\n";

		foreach ( $labels as $index => $label ) {
			$stream .= self::text_at( self::MARGIN + ( $index * $col_width ), $header_y, self::truncate( $label, $chars ) );
		}

		$stream .= "ET\n";
		$stream .= sprintf(
			"0.6 w\n%.2F %.2F m\n%.2F %.2F l\nS\n",
			self::MARGIN,
			$header_y - 4,
			self::PAGE_WIDTH - self::MARGIN,
			$header_y - 4
		);

		$stream .= "BT\n/F1 9 Tf\n";
		$y       = $header_y - self::LINE_HEIGHT - 4;

		foreach ( $rows as $row ) {
			foreach ( $keys as $index => $key ) {
				$stream .= self::text_at(
					self::MARGIN + ( $index * $col_width ),
					$y,
					self::truncate( self::stringify( $row[ $key ] ?? '' ), $chars )
				);
			}

			$y -= self::LINE_HEIGHT;
		}

		return $stream . "ET\n";
	}

	/**
	 * A positioned text showing operator.
	 *
	 * @param float  $x    X position.
	 * @param float  $y    Y position.
	 * @param string $text Text content.
	 * @return string
	 */
	private static function text_at( float $x, float $y, string $text ): string {
		return sprintf( "1 0 0 1 %.2F %.2F Tm\n(%s) Tj\n", $x, $y, self::escape( $text ) );
	}

	/**
	 * Escape a string for a PDF literal.
	 *
	 * @param string $text Raw text.
	 * @return string
	 */
	private static function escape( string $text ): string {
		$text = wp_strip_all_tags( html_entity_decode( $text, ENT_QUOTES, 'UTF-8' ) );
		$text = self::to_latin1( $text );

		return str_replace( array( '\\', '(', ')', "\r", "\n" ), array( '\\\\', '\\(', '\\)', ' ', ' ' ), $text );
	}

	/**
	 * Convert UTF-8 to the WinAnsi range the base-14 fonts support.
	 *
	 * @param string $text Raw text.
	 * @return string
	 */
	private static function to_latin1( string $text ): string {
		$converted = @iconv( 'UTF-8', 'Windows-1252//TRANSLIT', $text );

		if ( false !== $converted && null !== $converted ) {
			return $converted;
		}

		return preg_replace( '/[^\x20-\x7E]/', '?', $text ) ?? '';
	}

	/**
	 * Truncate a cell value.
	 *
	 * @param string $text  Text.
	 * @param int    $chars Maximum characters.
	 * @return string
	 */
	private static function truncate( string $text, int $chars ): string {
		$text = trim( preg_replace( '/\s+/u', ' ', $text ) ?? '' );

		if ( mb_strlen( $text ) <= $chars ) {
			return $text;
		}

		return mb_substr( $text, 0, max( 1, $chars - 1 ) ) . '…';
	}

	/**
	 * Flatten a value for a table cell.
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

	/**
	 * Assemble page streams into a PDF file with a valid cross reference table.
	 *
	 * @param string[] $streams Page content streams.
	 * @return string
	 */
	private static function assemble( array $streams ): string {
		$objects   = array();
		$count     = count( $streams );
		$first     = 4;
		$page_ids  = array();

		for ( $i = 0; $i < $count; $i++ ) {
			$page_ids[] = $first + ( $i * 2 );
		}

		$objects[1] = "<< /Type /Catalog /Pages 2 0 R >>";
		$objects[2] = sprintf(
			'<< /Type /Pages /Count %d /Kids [%s] >>',
			$count,
			implode( ' ', array_map( static fn( int $id ): string => $id . ' 0 R', $page_ids ) )
		);
		$objects[3] = '<< /Font << /F1 ' . ( $first + ( $count * 2 ) ) . ' 0 R /F2 ' . ( $first + ( $count * 2 ) + 1 ) . " 0 R >> >>";

		foreach ( $streams as $index => $stream ) {
			$page_id            = $page_ids[ $index ];
			$content_id         = $page_id + 1;
			$objects[ $page_id ] = sprintf(
				'<< /Type /Page /Parent 2 0 R /MediaBox [0 0 %.2F %.2F] /Resources 3 0 R /Contents %d 0 R >>',
				self::PAGE_WIDTH,
				self::PAGE_HEIGHT,
				$content_id
			);

			$objects[ $content_id ] = sprintf( "<< /Length %d >>\nstream\n%s\nendstream", strlen( $stream ), $stream );
		}

		$objects[ $first + ( $count * 2 ) ]       = '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica /Encoding /WinAnsiEncoding >>';
		$objects[ $first + ( $count * 2 ) + 1 ]   = '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica-Bold /Encoding /WinAnsiEncoding >>';

		ksort( $objects );

		$pdf     = "%PDF-1.4\n%\xE2\xE3\xCF\xD3\n";
		$offsets = array();

		foreach ( $objects as $id => $body ) {
			$offsets[ $id ] = strlen( $pdf );
			$pdf           .= $id . " 0 obj\n" . $body . "\nendobj\n";
		}

		$xref_offset = strlen( $pdf );
		$total       = count( $objects ) + 1;
		$pdf        .= "xref\n0 " . $total . "\n0000000000 65535 f \n";

		foreach ( array_keys( $objects ) as $id ) {
			$pdf .= sprintf( "%010d 00000 n \n", $offsets[ $id ] );
		}

		$pdf .= sprintf( "trailer\n<< /Size %d /Root 1 0 R >>\nstartxref\n%d\n%%%%EOF", $total, $xref_offset );

		return $pdf;
	}
}
