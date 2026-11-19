<?php
/**
 * Libellés commerciaux des véhicules.
 *
 * Les titres WordPress restent la source de vérité technique (ils servent de
 * clé de correspondance et d'identifiant dans l'admin), mais le front affiche
 * un nom propre et un type de véhicule sous ce nom.
 *
 * Exemple : « MERCEDES VAN 8PAX » → « Mercedes Vito » / « Van ».
 *
 * @package Elite_Transfer_Booking
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ETB_Vehicle_Labels {

	/**
	 * Retourne le nom d'affichage et le type d'un véhicule à partir de son
	 * titre WordPress.
	 *
	 * @param string $title Titre du post `tour_vehicle`.
	 * @return array{name:string,type:string}
	 */
	public static function get( $title ) {
		$title = trim( (string) $title );
		$key   = self::normalize( $title );

		// L'ordre compte : « VIP Sprinter » avant « Sprinter ».
		if ( $key ) {
			if ( false !== strpos( $key, 'sprinter' ) ) {
				if ( false !== strpos( $key, 'vip' ) ) {
					return array( 'name' => 'Mercedes VIP Sprinter', 'type' => 'Jet Van' );
				}
				return array( 'name' => 'Mercedes Sprinter', 'type' => 'Limo Bus' );
			}
			if ( preg_match( '/\bs\s*class\b/', $key ) ) {
				return array( 'name' => 'Mercedes S-Class', 'type' => 'Luxury Sedan' );
			}
			if ( preg_match( '/\be\s*class\b/', $key ) ) {
				return array( 'name' => 'Mercedes E-Class', 'type' => 'Business Sedan' );
			}
			if ( preg_match( '/\bv\s*class\b/', $key ) ) {
				return array( 'name' => 'Mercedes V-Class', 'type' => 'Luxury Van' );
			}
			if ( preg_match( '/\b(vito|van)\b/', $key ) ) {
				return array( 'name' => 'Mercedes Vito', 'type' => 'Van' );
			}
		}

		return array( 'name' => $title, 'type' => '' );
	}

	/**
	 * Nom d'affichage seul (pratique pour les emails, le checkout, etc.).
	 *
	 * @param string $title Titre du post `tour_vehicle`.
	 * @return string
	 */
	public static function name( $title ) {
		$label = self::get( $title );
		return $label['name'];
	}

	/**
	 * Normalise un titre pour la correspondance : minuscules, sans accents,
	 * ponctuation remplacée par des espaces.
	 *
	 * @param string $title Titre brut.
	 * @return string
	 */
	protected static function normalize( $title ) {
		$key = function_exists( 'remove_accents' ) ? remove_accents( $title ) : $title;
		$key = strtolower( $key );
		$key = preg_replace( '/[^a-z0-9]+/', ' ', $key );
		return trim( preg_replace( '/\s+/', ' ', (string) $key ) );
	}
}
