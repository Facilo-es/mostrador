<?php
/**
 * Translations: bundled files are used only when no language pack is installed,
 * and Spanish regional locales, Catalan, Galician and Basque fall back to es_ES.
 *
 * @package Mostrador
 */

namespace Mostrador;

defined( 'ABSPATH' ) || exit;

/**
 * I18n.
 */
class I18n {

	const DOMAINS = array( 'mostrador', 'mostrador-pro' );

	/**
	 * Hooks.
	 */
	public static function boot() {
		add_action( 'init', array( __CLASS__, 'load' ), 1 );
		add_filter( 'load_textdomain_mofile', array( __CLASS__, 'mofile' ), 10, 2 );
		add_filter( 'load_translation_file', array( __CLASS__, 'translation_file' ), 10, 3 );
	}

	/**
	 * Whether a locale falls back to es_ES.
	 *
	 * @param string $locale Locale.
	 * @return bool
	 */
	public static function falls_back( $locale ) {
		return 'es_ES' !== $locale && ( 0 === strpos( $locale, 'es_' ) || 'es' === $locale || 0 === strpos( $locale, 'ca' ) || 0 === strpos( $locale, 'gl' ) || 0 === strpos( $locale, 'eu' ) );
	}

	/**
	 * Loads the bundled file when WordPress has no language pack for the domain.
	 */
	public static function load() {
		$locale = determine_locale();
		$pack   = WP_LANG_DIR . '/plugins/mostrador-' . $locale . '.mo';
		if ( file_exists( $pack ) || file_exists( substr( $pack, 0, -3 ) . '.l10n.php' ) ) {
			return;
		}
		$file = self::bundled( 'mostrador', MOSTRADOR_DIR . 'languages', $locale );
		if ( $file ) {
			load_textdomain( 'mostrador', $file, $locale );
		}
	}

	/**
	 * Best bundled file for a locale, or ''.
	 *
	 * @param string $domain Text domain.
	 * @param string $dir    Directory.
	 * @param string $locale Locale.
	 * @return string
	 */
	public static function bundled( $domain, $dir, $locale ) {
		$file = trailingslashit( $dir ) . $domain . '-' . $locale . '.mo';
		if ( file_exists( $file ) ) {
			return $file;
		}
		if ( self::falls_back( $locale ) ) {
			$file = trailingslashit( $dir ) . $domain . '-es_ES.mo';
			if ( file_exists( $file ) ) {
				return $file;
			}
		}
		return '';
	}

	/**
	 * Remaps a missing .mo of our domains to its es_ES sibling for the fallback locales.
	 *
	 * @param string $mofile Path.
	 * @param string $domain Domain.
	 * @return string
	 */
	public static function mofile( $mofile, $domain ) {
		if ( ! in_array( $domain, self::DOMAINS, true ) || ! is_string( $mofile ) || file_exists( $mofile ) ) {
			return $mofile;
		}
		return self::remap( $mofile, $domain, '.mo' );
	}

	/**
	 * Same for the PHP translation files of WordPress 6.5+.
	 *
	 * @param string $file   Path.
	 * @param string $domain Domain.
	 * @param string $locale Locale.
	 * @return string
	 */
	public static function translation_file( $file, $domain, $locale = '' ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- filter signature.
		if ( ! in_array( $domain, self::DOMAINS, true ) || ! is_string( $file ) || file_exists( $file ) ) {
			return $file;
		}
		$ext = '.l10n.php' === substr( $file, -9 ) ? '.l10n.php' : '.mo';
		$out = self::remap( $file, $domain, $ext );
		if ( $out === $file && '.l10n.php' === $ext ) {
			$mo = self::remap( substr( $file, 0, -9 ) . '.mo', $domain, '.mo' );
			if ( file_exists( $mo ) ) {
				return $mo;
			}
		}
		return $out;
	}

	/**
	 * Replaces -{locale}{ext} with -es_ES{ext} when that file exists.
	 *
	 * @param string $file   Path.
	 * @param string $domain Domain.
	 * @param string $ext    Extension.
	 * @return string
	 */
	private static function remap( $file, $domain, $ext ) {
		$base = basename( $file, $ext );
		$pos  = strrpos( $base, '-' );
		if ( false === $pos ) {
			return $file;
		}
		$locale = substr( $base, $pos + 1 );
		if ( ! self::falls_back( $locale ) ) {
			return $file;
		}
		$alt = dirname( $file ) . '/' . substr( $base, 0, $pos ) . '-es_ES' . $ext;
		return file_exists( $alt ) ? $alt : $file;
	}
}
