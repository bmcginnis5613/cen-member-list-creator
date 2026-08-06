<?php
/**
 * Dependency-free PDF writer for the membership roster.
 *
 * The layout mirrors the supplied Company Merge.docx: Letter portrait,
 * first-page title/instruction, muted blue-gray text, and a two-column roster
 * separated by a thin olive rule.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class CEN_MLV_PDF {
	const PAGE_WIDTH = 612.0;
	const PAGE_HEIGHT = 792.0;
	const LEFT = 72.0;
	const RIGHT = 540.0;
	const BOTTOM = 60.0;
	const DIVIDER_X = 178.0;
	const LINE_HEIGHT = 12.0;
	const TEXT_SCALE = 85;

	/** @var array<int,array<int,string>> */
	private $pages = array();

	/** @var int */
	private $page_index = -1;

	/** @var float */
	private $cursor_y = 0.0;

	/**
	 * Render roster rows to a complete PDF byte string.
	 *
	 * @param array<int,array<string,mixed>> $rows Roster rows.
	 * @return string
	 */
	public function render( $rows ) {
		$this->pages = array();
		$this->page_index = -1;
		$this->new_page( true );

		foreach ( (array) $rows as $row ) {
			$company = isset( $row['company'] ) ? $this->normalize_text( $row['company'] ) : '';
			$member_name = isset( $row['member_name'] ) ? $this->normalize_text( $row['member_name'] ) : '';
			$description = isset( $row['description'] ) ? $this->normalize_text( $row['description'] ) : '';
			if ( '' === $company ) {
				$company = '' !== $member_name ? $member_name . ' (company not provided)' : 'Company not provided';
			}
			$this->draw_roster_row( $company, $description );
		}

		return $this->build_document();
	}

	/**
	 * Start a page and draw the template header on page one.
	 *
	 * @param bool $first_page Whether this is the first page.
	 */
	private function new_page( $first_page = false ) {
		$this->pages[] = array();
		$this->page_index++;

		if ( $first_page ) {
			$this->draw_centered_text( 'MEMBERSHIP ROSTER', 744.0, 'F2', 16.0 );
			$instruction = 'Please scan the company names & descriptions found below. If you feel you have a competitive conflict with a company, please let us know and we will ensure you are placed in different breakout groups at meetings.';
			$instruction_lines = $this->wrap_text( $instruction, 486.0, 10.0, false );
			$line_y = 716.0;
			foreach ( $instruction_lines as $line ) {
				$this->draw_centered_text( $line, $line_y, 'F3', 10.0 );
				$line_y -= 12.0;
			}
			$this->cursor_y = $line_y - 16.0;
		} else {
			$this->cursor_y = 720.0;
		}
	}

	/**
	 * Draw one row, splitting unusually long descriptions across pages safely.
	 *
	 * @param string $company Company name.
	 * @param string $description Company description.
	 */
	private function draw_roster_row( $company, $description ) {
		$company_lines = $this->wrap_text( $company, 94.0, 10.0, true );
		$description_lines = $this->wrap_text( $description, 350.0, 10.0, false );
		if ( empty( $description_lines ) ) {
			$description_lines = array( '' );
		}

		$company_offset = 0;
		$description_offset = 0;
		$first_segment = true;
		while ( $company_offset < count( $company_lines ) || $description_offset < count( $description_lines ) ) {
			$remaining_height = $this->cursor_y - self::BOTTOM;
			$max_lines = (int) floor( ( $remaining_height - 14.0 ) / self::LINE_HEIGHT );
			if ( $max_lines < 1 ) {
				$this->new_page();
				continue;
			}

			$company_remaining = array_slice( $company_lines, $company_offset );
			$description_remaining = array_slice( $description_lines, $description_offset );
			$needed_lines = max( count( $company_remaining ), count( $description_remaining ), 1 );
			if ( $needed_lines <= $max_lines ) {
				$segment_lines = $needed_lines;
			} elseif ( $this->cursor_y < 680.0 ) {
				$this->new_page();
				continue;
			} else {
				$segment_lines = $max_lines;
			}

			$company_chunk = array_slice( $company_remaining, 0, $segment_lines );
			$description_chunk = array_slice( $description_remaining, 0, $segment_lines );
			if ( ! $first_segment && empty( $company_chunk ) ) {
				$company_chunk = $this->wrap_text( $company . ' (continued)', 94.0, 10.0, true );
			}
			$this->draw_row_segment( $company_chunk, $description_chunk, $segment_lines );
			$company_offset += min( $segment_lines, count( $company_remaining ) );
			$description_offset += min( $segment_lines, count( $description_remaining ) );
			$first_segment = false;

			if ( $company_offset < count( $company_lines ) || $description_offset < count( $description_lines ) ) {
				$this->new_page();
			}
		}
	}

	/**
	 * Draw a row segment on the current page.
	 *
	 * @param string[] $company_lines Company lines.
	 * @param string[] $description_lines Description lines.
	 * @param int $line_count Segment line count.
	 */
	private function draw_row_segment( $company_lines, $description_lines, $line_count ) {
		$row_height = max( 30.0, ( $line_count * self::LINE_HEIGHT ) + 14.0 );
		$top = $this->cursor_y;
		$bottom = $top - $row_height;
		$this->add_command( 'q 0.624 0.639 0.455 RG 0.25 w ' . $this->number( self::DIVIDER_X ) . ' ' . $this->number( $top ) . ' m ' . $this->number( self::DIVIDER_X ) . ' ' . $this->number( $bottom ) . ' l S Q' );

		$baseline = $top - 17.0;
		foreach ( $company_lines as $index => $line ) {
			$this->draw_text( $line, self::LEFT + 5.0, $baseline - ( $index * self::LINE_HEIGHT ), 'F2', 10.0 );
		}
		foreach ( $description_lines as $index => $line ) {
			$this->draw_text( $line, self::DIVIDER_X + 8.0, $baseline - ( $index * self::LINE_HEIGHT ), 'F1', 10.0 );
		}

		$this->cursor_y = $bottom;
	}

	/**
	 * Draw left-aligned text.
	 *
	 * @param string $text Text.
	 * @param float $x X coordinate.
	 * @param float $y Y coordinate.
	 * @param string $font Font resource key.
	 * @param float $size Font size.
	 */
	private function draw_text( $text, $x, $y, $font, $size ) {
		$encoded = $this->encode_text( $text );
		$command = sprintf(
			'BT /%1$s %2$s Tf %3$d Tz 0.271 0.329 0.373 rg 1 0 0 1 %4$s %5$s Tm (%6$s) Tj ET',
			$font,
			$this->number( $size ),
			self::TEXT_SCALE,
			$this->number( $x ),
			$this->number( $y ),
			$this->escape_pdf_text( $encoded )
		);
		$this->add_command( $command );
	}

	/**
	 * Draw centered text.
	 *
	 * @param string $text Text.
	 * @param float $y Y coordinate.
	 * @param string $font Font resource key.
	 * @param float $size Font size.
	 */
	private function draw_centered_text( $text, $y, $font, $size ) {
		$bold = 'F2' === $font;
		$width = $this->text_width( $this->encode_text( $text ), $size, $bold );
		$x = ( self::PAGE_WIDTH - $width ) / 2.0;
		$this->draw_text( $text, $x, $y, $font, $size );
	}

	/**
	 * Add a raw content-stream command to the current page.
	 *
	 * @param string $command PDF command.
	 */
	private function add_command( $command ) {
		$this->pages[ $this->page_index ][] = $command;
	}

	/**
	 * Wrap a string using conservative Helvetica metrics and 85% text scaling.
	 *
	 * @param string $text Text.
	 * @param float $max_width Maximum rendered width.
	 * @param float $font_size Font size.
	 * @param bool $bold Whether the text uses the bold font.
	 * @return string[]
	 */
	private function wrap_text( $text, $max_width, $font_size, $bold ) {
		$encoded = trim( $this->encode_text( $text ) );
		if ( '' === $encoded ) {
			return array();
		}

		$words = preg_split( '/\s+/', $encoded );
		$lines = array();
		$current = '';
		foreach ( $words as $word ) {
			if ( $this->text_width( $word, $font_size, $bold ) > $max_width ) {
				if ( '' !== $current ) {
					$lines[] = $current;
					$current = '';
				}
				$pieces = $this->split_long_word( $word, $max_width, $font_size, $bold );
				while ( count( $pieces ) > 1 ) {
					$lines[] = array_shift( $pieces );
				}
				$current = isset( $pieces[0] ) ? $pieces[0] : '';
				continue;
			}

			$candidate = '' === $current ? $word : $current . ' ' . $word;
			if ( '' !== $current && $this->text_width( $candidate, $font_size, $bold ) > $max_width ) {
				$lines[] = $current;
				$current = $word;
			} else {
				$current = $candidate;
			}
		}
		if ( '' !== $current ) {
			$lines[] = $current;
		}

		return $lines;
	}

	/**
	 * Break one long token at byte boundaries supported by WinAnsiEncoding.
	 *
	 * @param string $word Encoded word.
	 * @param float $max_width Maximum rendered width.
	 * @param float $font_size Font size.
	 * @param bool $bold Whether the text uses the bold font.
	 * @return string[]
	 */
	private function split_long_word( $word, $max_width, $font_size, $bold ) {
		$pieces = array();
		$current = '';
		$length = strlen( $word );
		for ( $index = 0; $index < $length; $index++ ) {
			$candidate = $current . $word[ $index ];
			if ( '' !== $current && $this->text_width( $candidate, $font_size, $bold ) > $max_width ) {
				$pieces[] = $current;
				$current = $word[ $index ];
			} else {
				$current = $candidate;
			}
		}
		if ( '' !== $current ) {
			$pieces[] = $current;
		}

		return $pieces;
	}

	/**
	 * Approximate Helvetica widths closely enough to wrap before the divider.
	 *
	 * @param string $encoded WinAnsi string.
	 * @param float $font_size Font size.
	 * @param bool $bold Whether the text uses the bold font.
	 * @return float
	 */
	private function text_width( $encoded, $font_size, $bold ) {
		$units = 0.0;
		$length = strlen( $encoded );
		for ( $index = 0; $index < $length; $index++ ) {
			$character = $encoded[ $index ];
			if ( false !== strpos( " ilI.,'`:;!|", $character ) ) {
				$units += 0.28;
			} elseif ( false !== strpos( 'MW@%&#', $character ) ) {
				$units += 0.86;
			} elseif ( ctype_upper( $character ) ) {
				$units += 0.63;
			} elseif ( ctype_digit( $character ) ) {
				$units += 0.56;
			} else {
				$units += 0.51;
			}
		}
		if ( $bold ) {
			$units *= 1.03;
		}

		return $units * $font_size * ( self::TEXT_SCALE / 100.0 );
	}

	/**
	 * Normalize HTML/profile text to a single readable line.
	 *
	 * @param mixed $text Input value.
	 * @return string
	 */
	private function normalize_text( $text ) {
		$text = html_entity_decode( strip_tags( (string) $text ), ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		$text = preg_replace( '/\s+/u', ' ', $text );
		return trim( (string) $text );
	}

	/**
	 * Convert UTF-8 input to the built-in PDF font encoding.
	 *
	 * @param string $text UTF-8 text.
	 * @return string
	 */
	private function encode_text( $text ) {
		$text = (string) $text;
		if ( 1 !== preg_match( '//u', $text ) ) {
			return $text;
		}

		if ( function_exists( 'iconv' ) ) {
			$encoded = iconv( 'UTF-8', 'Windows-1252//TRANSLIT//IGNORE', $text );
			if ( false !== $encoded ) {
				return $encoded;
			}
		}

		return preg_replace( '/[^\x20-\x7E]/', '?', $text );
	}

	/**
	 * Escape one PDF literal string.
	 *
	 * @param string $text Encoded text.
	 * @return string
	 */
	private function escape_pdf_text( $text ) {
		return strtr(
			$text,
			array(
				'\\' => '\\\\',
				'('  => '\\(',
				')'  => '\\)',
				"\r" => '',
				"\n" => ' ',
			)
		);
	}

	/**
	 * Format a compact PDF number.
	 *
	 * @param float|int $value Numeric value.
	 * @return string
	 */
	private function number( $value ) {
		return rtrim( rtrim( number_format( (float) $value, 2, '.', '' ), '0' ), '.' );
	}

	/**
	 * Assemble catalog, pages, fonts, streams, xref, and trailer.
	 *
	 * @return string
	 */
	private function build_document() {
		$objects = array();
		$page_count = count( $this->pages );
		$page_ids = array();
		for ( $index = 0; $index < $page_count; $index++ ) {
			$page_ids[] = 7 + ( $index * 2 );
		}

		$objects[1] = '<< /Type /Catalog /Pages 2 0 R >>';
		$objects[2] = '<< /Type /Pages /Kids [' . implode( ' ', array_map( static function ( $id ) { return $id . ' 0 R'; }, $page_ids ) ) . '] /Count ' . $page_count . ' >>';
		$objects[3] = '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica /Encoding /WinAnsiEncoding >>';
		$objects[4] = '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica-Bold /Encoding /WinAnsiEncoding >>';
		$objects[5] = '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica-Oblique /Encoding /WinAnsiEncoding >>';
		$objects[6] = '<< /Title (Membership Roster) /Creator (CEN Member List PDF) /CreationDate (D:' . gmdate( 'YmdHis' ) . 'Z) >>';

		foreach ( $this->pages as $index => $commands ) {
			$page_id = 7 + ( $index * 2 );
			$content_id = $page_id + 1;
			$stream = implode( "\n", $commands ) . "\n";
			$objects[ $page_id ] = '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 612 792] /Resources << /Font << /F1 3 0 R /F2 4 0 R /F3 5 0 R >> >> /Contents ' . $content_id . ' 0 R >>';
			$objects[ $content_id ] = '<< /Length ' . strlen( $stream ) . ">>\nstream\n" . $stream . 'endstream';
		}

		ksort( $objects );
		$pdf = "%PDF-1.4\n%\xE2\xE3\xCF\xD3\n";
		$offsets = array( 0 => 0 );
		foreach ( $objects as $id => $object ) {
			$offsets[ $id ] = strlen( $pdf );
			$pdf .= $id . " 0 obj\n" . $object . "\nendobj\n";
		}

		$xref_offset = strlen( $pdf );
		$size = max( array_keys( $objects ) ) + 1;
		$pdf .= "xref\n0 " . $size . "\n";
		$pdf .= "0000000000 65535 f \n";
		for ( $id = 1; $id < $size; $id++ ) {
			$pdf .= sprintf( "%010d 00000 n \n", $offsets[ $id ] );
		}
		$pdf .= "trailer\n<< /Size " . $size . " /Root 1 0 R /Info 6 0 R >>\nstartxref\n" . $xref_offset . "\n%%EOF";

		return $pdf;
	}
}
