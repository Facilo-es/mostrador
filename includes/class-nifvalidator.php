<?php
/**
 * Spanish NIF, NIE and CIF check characters.
 *
 * @package Mostrador
 */

namespace Mostrador;

defined( 'ABSPATH' ) || exit;

/**
 * NifValidator.
 */
class NifValidator {

	const LETTERS = 'TRWAGMYFPDXBNJZSQVHLCKE';

	/**
	 * Uppercase, without spaces, dots, dashes, slashes or a leading ES.
	 *
	 * @param string $value Raw.
	 * @return string
	 */
	public static function normalise( $value ) {
		$value = strtoupper( preg_replace( '/[\s.\-\/]+/u', '', (string) $value ) );
		if ( 0 === strpos( $value, 'ES' ) && strlen( $value ) > 9 ) {
			$value = substr( $value, 2 );
		}
		return $value;
	}

	/**
	 * Type: dni, nie, cif, klm or ''.
	 *
	 * @param string $value Value.
	 * @return string
	 */
	public static function type( $value ) {
		$v = self::normalise( $value );
		if ( preg_match( '/^\d{8}[A-Z]$/', $v ) ) {
			return 'dni';
		}
		if ( preg_match( '/^[XYZ]\d{7}[A-Z]$/', $v ) ) {
			return 'nie';
		}
		if ( preg_match( '/^[KLM]\d{7}[A-Z]$/', $v ) ) {
			return 'klm';
		}
		if ( preg_match( '/^[ABCDEFGHJNPQRSUVW]\d{7}[0-9A-J]$/', $v ) ) {
			return 'cif';
		}
		return '';
	}

	/**
	 * Whether the number and its check character are right.
	 *
	 * @param string $value Value.
	 * @return bool
	 */
	public static function is_valid( $value ) {
		$v = self::normalise( $value );
		switch ( self::type( $v ) ) {
			case 'dni':
				return self::LETTERS[ (int) substr( $v, 0, 8 ) % 23 ] === $v[8];
			case 'nie':
				$n = strpos( 'XYZ', $v[0] ) . substr( $v, 1, 7 );
				return self::LETTERS[ (int) $n % 23 ] === $v[8];
			case 'klm':
				// AEAT: K, L and M numbers carry a DNI-style letter over their 7 digits.
				return self::LETTERS[ (int) substr( $v, 1, 7 ) % 23 ] === $v[8];
			case 'cif':
				return self::cif_ok( $v );
		}
		return false;
	}

	/**
	 * CIF control character.
	 *
	 * @param string $v Normalised CIF.
	 * @return bool
	 */
	private static function cif_ok( $v ) {
		$digits = substr( $v, 1, 7 );
		$sum    = 0;
		for ( $i = 0; $i < 7; $i++ ) {
			$d = (int) $digits[ $i ];
			if ( 0 === $i % 2 ) {
				$d   *= 2;
				$sum += intdiv( $d, 10 ) + $d % 10;
			} else {
				$sum += $d;
			}
		}
		$c       = ( 10 - $sum % 10 ) % 10;
		$control = $v[8];
		$letter  = 'JABCDEFGHI'[ $c ];
		$first   = $v[0];
		if ( false !== strpos( 'PQRSNW', $first ) ) {
			return $control === $letter;
		}
		if ( false !== strpos( 'ABEH', $first ) ) {
			return (string) $c === $control;
		}
		return (string) $c === $control || $letter === $control;
	}

	/**
	 * Whether the number belongs to a company (first letter A or B).
	 *
	 * @param string $value Value.
	 * @return bool
	 */
	public static function is_company( $value ) {
		$v = self::normalise( $value );
		return 'cif' === self::type( $v ) && in_array( $v[0], array( 'A', 'B' ), true );
	}

	/**
	 * Format check for a non-Spanish EU VAT number.
	 *
	 * @param string $value Value with or without country prefix.
	 * @return bool
	 */
	public static function eu_format_ok( $value ) {
		$v = strtoupper( preg_replace( '/[\s.\-\/]+/u', '', (string) $value ) );
		return (bool) preg_match( '/^([A-Z]{2})?[0-9A-Z+*]{2,13}$/', $v ) && preg_match( '/\d/', $v );
	}
}
