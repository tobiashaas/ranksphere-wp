<?php
/**
 * RankSphere's look in the WordPress admin.
 *
 * @package RankSphere
 */

declare(strict_types=1);

namespace RankSphere\Admin;

use RankSphere\Insights\Value;

/**
 * The building blocks of RankSphere's dashboard, as escaped HTML: a tile (light frame with icon chip
 * and heading, white panel inside; the focus tile is one amber surface), a figure, the change against
 * the period before (arrow + signed figure + words, never colour alone), a verdict line, status pills
 * and notes. Colours come from the tokens in assets/admin.css – the same values as RankSphere's
 * app.css. Icons are Lucide (ISC licence), like in RankSphere.
 *
 * Every function returns HTML built from escaped parts; out() prints it through wp_kses() with
 * exactly the tags these blocks use.
 */
final class Ui {

	/**
	 * Lucide icons used here (24×24, stroke).
	 */
	private const ICONS = array(
		'circle-check'    => '<circle cx="12" cy="12" r="10"/><path d="m9 12 2 2 4-4"/>',
		'circle-alert'    => '<circle cx="12" cy="12" r="10"/><line x1="12" x2="12" y1="8" y2="12"/><line x1="12" x2="12.01" y1="16" y2="16"/>',
		'triangle-alert'  => '<path d="m21.73 18-8-14a2 2 0 0 0-3.48 0l-8 14A2 2 0 0 0 4 21h16a2 2 0 0 0 1.73-3"/><path d="M12 9v4"/><path d="M12 17h.01"/>',
		'circle-dot'      => '<circle cx="12" cy="12" r="10"/><circle cx="12" cy="12" r="1"/>',
		'trending-up'     => '<polyline points="22 7 13.5 15.5 8.5 10.5 2 17"/><polyline points="16 7 22 7 22 13"/>',
		'trending-down'   => '<polyline points="22 17 13.5 8.5 8.5 13.5 2 7"/><polyline points="16 17 22 17 22 11"/>',
		'minus'           => '<path d="M5 12h14"/>',
		'arrow-up-right'  => '<path d="M7 7h10v10"/><path d="M7 17 17 7"/>',
		'arrow-left'      => '<path d="m12 19-7-7 7-7"/><path d="M19 12H5"/>',
		'eye'             => '<path d="M2.062 12.348a1 1 0 0 1 0-.696 10.75 10.75 0 0 1 19.876 0 1 1 0 0 1 0 .696 10.75 10.75 0 0 1-19.876 0"/><circle cx="12" cy="12" r="3"/>',
		'pointer'         => '<path d="M14 4.1 12 6"/><path d="m5.1 8-2.9-.8"/><path d="m6 12-1.9 2"/><path d="M7.2 2.2 8 5.1"/><path d="M9.037 9.69a.498.498 0 0 1 .653-.653l11 4.5a.5.5 0 0 1-.074.949l-4.349 1.041a1 1 0 0 0-.74.739l-1.04 4.35a.5.5 0 0 1-.95.074z"/>',
		'sparkles'        => '<path d="M9.937 15.5A2 2 0 0 0 8.5 14.063l-6.135-1.582a.5.5 0 0 1 0-.962L8.5 9.936A2 2 0 0 0 9.937 8.5l1.582-6.135a.5.5 0 0 1 .963 0L14.063 8.5A2 2 0 0 0 15.5 9.937l6.135 1.581a.5.5 0 0 1 0 .964L15.5 14.063a2 2 0 0 0-1.437 1.437l-1.582 6.135a.5.5 0 0 1-.963 0z"/><path d="M20 3v4"/><path d="M22 5h-4"/><path d="M4 17v2"/><path d="M5 18H3"/>',
		'list-checks'     => '<path d="m3 17 2 2 4-4"/><path d="m3 7 2 2 4-4"/><path d="M13 6h8"/><path d="M13 12h8"/><path d="M13 18h8"/>',
		'file-text'       => '<path d="M15 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V7Z"/><path d="M14 2v4a2 2 0 0 0 2 2h4"/><path d="M10 9H8"/><path d="M16 13H8"/><path d="M16 17H8"/>',
		'pen-line'        => '<path d="M12 20h9"/><path d="M16.376 3.622a1 1 0 0 1 3.002 3.002L7.368 18.635a2 2 0 0 1-.855.506l-2.872.838a.5.5 0 0 1-.62-.62l.838-2.872a2 2 0 0 1 .506-.854z"/>',
		'target'          => '<circle cx="12" cy="12" r="10"/><circle cx="12" cy="12" r="6"/><circle cx="12" cy="12" r="2"/>',
		'search'          => '<circle cx="11" cy="11" r="8"/><path d="m21 21-4.3-4.3"/>',
		'plus'            => '<path d="M5 12h14"/><path d="M12 5v14"/>',
		'question'        => '<path d="M7.9 20A9 9 0 1 0 4 16.1L2 22Z"/><path d="M9.09 9a3 3 0 0 1 5.83 1c0 2-3 3-3 3"/><path d="M12 17h.01"/>',
		'refresh'         => '<path d="M3 12a9 9 0 0 1 9-9 9.75 9.75 0 0 1 6.74 2.74L21 8"/><path d="M21 3v5h-5"/><path d="M21 12a9 9 0 0 1-9 9 9.75 9.75 0 0 1-6.74-2.74L3 16"/><path d="M8 16H3v5"/>',
		'clipboard-check' => '<rect width="8" height="4" x="8" y="2" rx="1" ry="1"/><path d="M16 4h2a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2h2"/><path d="m9 14 2 2 4-4"/>',
		'unlink'          => '<path d="m18.84 12.25 1.72-1.71h-.02a5.004 5.004 0 0 0-.12-7.07 5.006 5.006 0 0 0-6.95 0l-1.72 1.71"/><path d="m5.17 11.75-1.71 1.71a5.004 5.004 0 0 0 .12 7.07 5.006 5.006 0 0 0 6.95 0l1.71-1.71"/><line x1="8" x2="8" y1="2" y2="5"/><line x1="2" x2="5" y1="8" y2="8"/><line x1="16" x2="16" y1="19" y2="22"/><line x1="19" x2="22" y1="16" y2="16"/>',
		'loader'          => '<path d="M21 12a9 9 0 1 1-6.219-8.56"/>',
		'send'            => '<path d="M14.536 21.686a.5.5 0 0 0 .937-.024l6.5-19a.496.496 0 0 0-.635-.635l-19 6.5a.5.5 0 0 0-.024.937l7.93 3.18a2 2 0 0 1 1.112 1.11z"/><path d="m21.854 2.147-10.94 10.939"/>',
	);

	/** Icon per verdict tone – the shape carries the tone, the colour only supports it. */
	private const TONE_ICONS = array(
		'good'    => 'circle-check',
		'watch'   => 'circle-alert',
		'act'     => 'triangle-alert',
		'neutral' => 'circle-dot',
	);

	/**
	 * An icon as inline SVG (decorative: hidden from screen readers).
	 *
	 * @param string $name    Key of ICONS.
	 * @param string $classes Extra CSS classes.
	 */
	public static function icon( string $name, string $classes = '' ): string {
		$paths = self::ICONS[ $name ] ?? self::ICONS['circle-dot'];

		return '<svg class="rs-icon ' . esc_attr( $classes ) . '" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">' . $paths . '</svg>';
	}

	/**
	 * A tile: frame with icon chip and heading, white panel with the content. `focus`: one amber
	 * surface with the eyebrow in capitals and a large title (the one step that matters now).
	 *
	 * @param string               $eyebrow What is this? (plain text).
	 * @param string               $icon    Key of ICONS.
	 * @param string               $body    The panel's content (HTML).
	 * @param array<string, mixed> $options span (focus|wide|side|third|half|full|quarter), tone (default|focus), title, aside (HTML), href.
	 */
	public static function tile( string $eyebrow, string $icon, string $body, array $options = array() ): string {
		$span  = is_string( $options['span'] ?? null ) ? $options['span'] : 'third';
		$tone  = 'focus' === ( $options['tone'] ?? null ) ? 'focus' : 'default';
		$title = is_string( $options['title'] ?? null ) ? $options['title'] : '';
		$aside = is_string( $options['aside'] ?? null ) ? $options['aside'] : '';
		$href  = is_string( $options['href'] ?? null ) ? $options['href'] : '';

		$heading = 'focus' === $tone
			? '<h2 class="rs-eyebrow">' . esc_html( $eyebrow ) . '</h2>'
			: '<h2 class="rs-eyebrow"><span class="rs-chip">' . self::icon( $icon ) . '</span>' . esc_html( $eyebrow ) . '</h2>';

		if ( '' !== $href ) {
			$aside .= self::link( $href, '', 'rs-tile-link', __( 'Open in RankSphere', 'ranksphere' ) );
		}

		$head  = '<div class="rs-tile-head">' . $heading . ( '' !== $aside ? '<div class="rs-tile-aside">' . $aside . '</div>' : '' ) . '</div>';
		$title = '' !== $title ? '<p class="rs-tile-title">' . esc_html( $title ) . '</p>' : '';

		return 'focus' === $tone
			? '<section class="rs-tile rs-span-' . esc_attr( $span ) . ' rs-tile-focus">' . $head . $title . $body . '</section>'
			: '<section class="rs-tile rs-span-' . esc_attr( $span ) . '">' . $head . '<div class="rs-panel">' . $title . $body . '</div></section>';
	}

	/**
	 * The grid the tiles sit in.
	 *
	 * @param string $tiles The tiles (HTML).
	 */
	public static function grid( string $tiles ): string {
		return '<div class="rs-grid">' . $tiles . '</div>';
	}

	/**
	 * A large figure with an optional unit line.
	 *
	 * @param string $value Formatted value.
	 * @param string $label Small line under it.
	 */
	public static function figure( string $value, string $label = '' ): string {
		return '<p class="rs-figure">' . esc_html( $value ) . '</p>' . ( '' !== $label ? '<p class="rs-muted rs-small">' . esc_html( $label ) . '</p>' : '' );
	}

	/**
	 * The change against the period before: arrow + signed figure + words; "stable" below 3 %;
	 * "no comparison" without a fair basis.
	 *
	 * @param float|null $now            This period.
	 * @param float|null $before         The period before; null = no fair comparison.
	 * @param bool       $lower_is_better Position: smaller is better.
	 * @param bool       $absolute       Show the difference (position) instead of percent.
	 */
	public static function delta( ?float $now, ?float $before, bool $lower_is_better = false, bool $absolute = false ): string {
		$against = '<span class="rs-muted">' . esc_html__( 'vs. the period before', 'ranksphere' ) . '</span>';

		if ( null === $now || null === $before || ( ! $absolute && $before <= 0.0 ) ) {
			return '<span class="rs-delta rs-muted">' . esc_html__( 'no comparison', 'ranksphere' ) . '</span>';
		}

		$diff = $now - $before;
		$size = $absolute ? abs( $diff ) : abs( $diff / $before );

		if ( ( $absolute && $size < 0.5 ) || ( ! $absolute && $size < 0.03 ) ) {
			return '<span class="rs-delta"><span class="rs-delta-value rs-muted">' . self::icon( 'minus' ) . esc_html__( 'stable', 'ranksphere' ) . '</span> ' . $against . '</span>';
		}

		$good = $lower_is_better ? $diff < 0 : $diff > 0;
		$text = $absolute
			? sprintf(
				/* translators: %s: amount, e.g. 1.5 */
				$good ? __( '%s better', 'ranksphere' ) : __( '%s worse', 'ranksphere' ),
				number_format_i18n( $size, 1 )
			)
			: ( $diff > 0 ? '+' : '−' ) . Value::percent( $size );

		return '<span class="rs-delta"><span class="rs-delta-value ' . ( $good ? 'rs-good' : 'rs-critical' ) . '">'
			. self::icon( ( $absolute ? $good : $diff > 0 ) ? 'trending-up' : 'trending-down' ) . esc_html( $text ) . '</span> ' . $against . '</span>';
	}

	/**
	 * "Good or bad?" in one sentence, with the tone's icon and a word for screen readers.
	 *
	 * @param string $tone good|watch|act|neutral.
	 * @param string $text The sentence (plain text).
	 */
	public static function verdict( string $tone, string $text ): string {
		$tone = isset( self::TONE_ICONS[ $tone ] ) ? $tone : 'neutral';

		return '<p class="rs-verdict">' . self::icon( self::TONE_ICONS[ $tone ], 'rs-tone-' . $tone )
			. '<span><span class="screen-reader-text">' . esc_html( self::tone_word( $tone ) ) . ': </span>' . esc_html( $text ) . '</span></p>';
	}

	/**
	 * A small status pill.
	 *
	 * @param string $text The label.
	 * @param string $tone good|watch|act|neutral|primary.
	 */
	public static function pill( string $text, string $tone = 'neutral' ): string {
		return '<span class="rs-pill rs-pill-' . esc_attr( sanitize_key( $tone ) ) . '">' . esc_html( $text ) . '</span>';
	}

	/**
	 * A note on a soft surface (hint, warning, problem).
	 *
	 * @param string $tone good|watch|act|neutral.
	 * @param string $text The note (plain text).
	 */
	public static function note( string $tone, string $text ): string {
		$tone = isset( self::TONE_ICONS[ $tone ] ) ? $tone : 'neutral';

		return '<div class="rs-note rs-note-' . esc_attr( $tone ) . '">' . self::icon( self::TONE_ICONS[ $tone ] ) . '<p>' . esc_html( $text ) . '</p></div>';
	}

	/**
	 * A link; into RankSphere (absolute https) it opens a new tab.
	 *
	 * @param string $url     The address.
	 * @param string $label   Visible text ('' = icon only).
	 * @param string $classes CSS classes.
	 * @param string $sr      Text for screen readers when the label is empty.
	 */
	public static function link( string $url, string $label, string $classes = 'rs-link', string $sr = '' ): string {
		if ( '' === $url ) {
			return '';
		}

		$external = ! str_starts_with( $url, admin_url() );
		$attrs    = $external ? ' target="_blank" rel="noopener"' : '';
		$text     = '' !== $label ? esc_html( $label ) : '<span class="screen-reader-text">' . esc_html( $sr ) . '</span>';

		if ( $external && '' !== $label ) {
			$text .= '<span class="screen-reader-text"> ' . esc_html__( '(opens in a new tab)', 'ranksphere' ) . '</span>';
		}

		return '<a class="' . esc_attr( $classes ) . '" href="' . esc_url( $url ) . '"' . $attrs . '>' . $text . ( $external ? self::icon( 'arrow-up-right' ) : '' ) . '</a>';
	}

	/**
	 * Data for a script as `var name = {…};` before it. Unlike wp_localize_script() the values stay
	 * as they are: numbers stay numbers, and entities in titles or texts are not decoded.
	 *
	 * @param string       $handle The script.
	 * @param string       $name   The global variable.
	 * @param array<mixed> $data   The data.
	 */
	public static function script_data( string $handle, string $name, array $data ): void {
		wp_add_inline_script( $handle, 'var ' . $name . ' = ' . wp_json_encode( $data, JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE ) . ';', 'before' );
	}

	/**
	 * Prints HTML built here – through wp_kses() with the tags and attributes these blocks use.
	 *
	 * @param string $html The HTML.
	 */
	public static function out( string $html ): void {
		echo wp_kses( $html, self::allowed() );
	}

	/**
	 * Tags and attributes of the blocks (and the forms on RankSphere's pages).
	 *
	 * @return array<string, array<string, bool>>
	 */
	public static function allowed(): array {
		$common = array(
			'class'            => true,
			'id'               => true,
			'aria-hidden'      => true,
			'aria-label'       => true,
			'aria-live'        => true,
			'aria-describedby' => true,
			'role'             => true,
			'data-*'           => true,
			'hidden'           => true,
		);
		$svg    = array(
			'class'           => true,
			'xmlns'           => true,
			'viewbox'         => true,
			'fill'            => true,
			'stroke'          => true,
			'stroke-width'    => true,
			'stroke-linecap'  => true,
			'stroke-linejoin' => true,
			'aria-hidden'     => true,
			'focusable'       => true,
		);
		$shape  = array(
			'd'      => true,
			'cx'     => true,
			'cy'     => true,
			'r'      => true,
			'x'      => true,
			'y'      => true,
			'x1'     => true,
			'x2'     => true,
			'y1'     => true,
			'y2'     => true,
			'rx'     => true,
			'ry'     => true,
			'width'  => true,
			'height' => true,
			'points' => true,
		);
		$tags   = array();

		foreach ( array( 'div', 'section', 'span', 'p', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'ul', 'ol', 'li', 'strong', 'em', 'b', 'i', 'mark', 'small', 'sub', 'sup', 'del', 'ins', 'abbr', 'br', 'hr', 'blockquote', 'code', 'pre', 'table', 'thead', 'tbody', 'tr', 'td', 'dl', 'dt', 'dd', 'details', 'summary', 'fieldset', 'legend' ) as $tag ) {
			$tags[ $tag ] = $common;
		}

		$tags['th']       = $common + array(
			'scope'   => true,
			'colspan' => true,
			'rowspan' => true,
		);
		$tags['td']       = $common + array(
			'colspan' => true,
			'rowspan' => true,
		);
		$tags['a']        = $common + array(
			'href'   => true,
			'target' => true,
			'rel'    => true,
		);
		$tags['time']     = $common + array( 'datetime' => true );
		$tags['form']     = $common + array(
			'method' => true,
			'action' => true,
		);
		$tags['input']    = $common + array(
			'type'        => true,
			'name'        => true,
			'value'       => true,
			'placeholder' => true,
			'required'    => true,
			'maxlength'   => true,
			'minlength'   => true,
			'checked'     => true,
		);
		$tags['textarea'] = $common + array(
			'name'        => true,
			'rows'        => true,
			'placeholder' => true,
			'required'    => true,
			'maxlength'   => true,
			'minlength'   => true,
		);
		$tags['select']   = $common + array(
			'name'     => true,
			'required' => true,
		);
		$tags['option']   = $common + array(
			'value'    => true,
			'selected' => true,
		);
		$tags['label']    = $common + array( 'for' => true );
		$tags['button']   = $common + array(
			'type'     => true,
			'name'     => true,
			'value'    => true,
			'disabled' => true,
		);
		$tags['svg']      = $svg;

		foreach ( array( 'path', 'circle', 'line', 'polyline', 'rect' ) as $shape_tag ) {
			$tags[ $shape_tag ] = $shape;
		}

		return $tags;
	}

	/**
	 * The tone in words, for screen readers.
	 *
	 * @param string $tone good|watch|act|neutral.
	 */
	private static function tone_word( string $tone ): string {
		return match ( $tone ) {
			'good'  => __( 'Good', 'ranksphere' ),
			'watch' => __( 'Keep an eye on it', 'ranksphere' ),
			'act'   => __( 'Act', 'ranksphere' ),
			default => __( 'Note', 'ranksphere' ),
		};
	}
}
