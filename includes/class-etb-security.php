<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class ETB_Security {

    const TIME_SALT = 'etb_security_timestamp_salt_v1';
    const HONEYPOT_SALT = 'etb_honeypot_field_salt_v1';

    /**
     * Récupération de l'adresse IP du client.
     *
     * REMOTE_ADDR fait foi par défaut. Les en-têtes proxy (X-Forwarded-For, etc.)
     * ne sont lus que si le serveur amont est explicitement déclaré de confiance :
     *
     *   add_filter( 'etb_trusted_proxies', fn() => array( '10.0.0.5' ) );
     *
     * Sans cette déclaration, X-Forwarded-For est fourni par le client et ne doit
     * jamais servir de clé de rate limiting.
     */
    public static function get_client_ip() {
        $remote = isset( $_SERVER['REMOTE_ADDR'] ) ? trim( $_SERVER['REMOTE_ADDR'] ) : '';
        $ip     = $remote;

        $trusted_proxies = (array) apply_filters( 'etb_trusted_proxies', array() );
        $is_trusted      = $remote && in_array( $remote, $trusted_proxies, true );

        if ( $is_trusted ) {
            if ( ! empty( $_SERVER['HTTP_X_FORWARDED_FOR'] ) ) {
                $forwarded = explode( ',', $_SERVER['HTTP_X_FORWARDED_FOR'] );
                $candidate = trim( $forwarded[0] );
                if ( filter_var( $candidate, FILTER_VALIDATE_IP ) ) {
                    $ip = $candidate;
                }
            } elseif ( ! empty( $_SERVER['HTTP_X_REAL_IP'] ) ) {
                $candidate = trim( $_SERVER['HTTP_X_REAL_IP'] );
                if ( filter_var( $candidate, FILTER_VALIDATE_IP ) ) {
                    $ip = $candidate;
                }
            }
        }

        $clean_ip = filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE );
        return $clean_ip ? $clean_ip : ( filter_var( $ip, FILTER_VALIDATE_IP ) ? $ip : '127.0.0.1' );
    }

    /**
     * Génère un nom de champ honeypot aléatoire et unique par session
     * Le nom est stocké dans un transient pour validation côté serveur
     */
    public static function generate_honeypot_field() {
        $session_id = wp_create_nonce( 'etb_session_' . self::get_client_ip() );
        $field_name = 'etb_hp_' . substr( md5( $session_id . self::HONEYPOT_SALT ), 0, 12 );

        // Stocker le nom du champ pour validation (valide 30 min)
        $transient_key = 'etb_honeypot_field_' . md5( self::get_client_ip() );
        set_transient( $transient_key, $field_name, 30 * MINUTE_IN_SECONDS );

        return $field_name;
    }

    /**
     * Valide que le champ honeypot généré est vide (non piégé par un bot)
     */
    public static function verify_honeypot() {
        $transient_key = 'etb_honeypot_field_' . md5( self::get_client_ip() );
        $expected_field = get_transient( $transient_key );

        // Si pas de champ généré (ex: cache navigateur), on accepte par tolérance
        if ( empty( $expected_field ) ) {
            return true;
        }

        // Le champ honeypot DOIT être absent ou vide
        if ( ! empty( $_POST[ $expected_field ] ) ) {
            return false; // Bot détecté : champ rempli
        }

        // Vérification additionnelle : champ antibot classique
        if ( ! empty( $_POST['etb_antibot_check'] ) ) {
            return false;
        }

        return true;
    }

    /**
     * Retourne le HTML pour le champ honeypot invisible
     * À insérer dans les formulaires
     */
    public static function get_honeypot_html() {
        $field_name = self::generate_honeypot_field();
        return sprintf(
            '<div style="position: absolute !important; left: -9999px !important; opacity: 0 !important; width: 0 !important; height: 0 !important; overflow: hidden !important;" aria-hidden="true"><input type="text" name="%1$s" value="" tabindex="-1" autocomplete="new-password" aria-hidden="true"></div>',
            esc_attr( $field_name )
        );
    }

    /**
     * Contrôle de fréquence (Rate Limiting) basé sur les Transients WordPress
     * Avec backoff exponentiel et fallback Redis si disponible
     *
     * @param string $action Nom de l'action (ex: 'booking', 'promo')
     * @param int $max_attempts Seuil d'autorisations tolérées
     * @param int $window_seconds Fenêtre temporelle en secondes
     * @return bool True si autorisé, False si limite atteinte
     */
    public static function check_rate_limit( $action, $max_attempts, $window_seconds ) {
        $ip             = self::get_client_ip();
        $window_seconds = max( 1, (int) $window_seconds );
        $max_attempts   = max( 1, (int) $max_attempts );

        // Fenêtre fixe : la clé change à chaque intervalle, ce qui évite les
        // dérives du compteur et rend l'incrément atomique avec un object cache.
        $bucket        = (int) floor( time() / $window_seconds );
        $transient_key = 'etb_rl_' . sanitize_key( $action ) . '_' . md5( $ip ) . '_' . $bucket;
        $ttl           = $window_seconds * 2;

        if ( function_exists( 'wp_cache_incr' ) && wp_using_ext_object_cache() ) {
            wp_cache_add( $transient_key, 0, 'etb_ratelimit', $ttl );
            $attempts = wp_cache_incr( $transient_key, 1, 'etb_ratelimit' );
            if ( false === $attempts ) {
                $attempts = (int) get_transient( $transient_key ) + 1;
                set_transient( $transient_key, $attempts, $ttl );
            }
        } else {
            $attempts = (int) get_transient( $transient_key ) + 1;
            set_transient( $transient_key, $attempts, $ttl );
        }

        if ( $attempts > $max_attempts ) {
            if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
                error_log( sprintf( 'ETB Rate Limit: %s blocked for IP %s (attempts: %d/%d)', $action, $ip, $attempts, $max_attempts ) );
            }
            return false;
        }

        return true;
    }

    /**
     * Incrémente le compteur d'échecs uniquement lors d'un code promo erroné
     */
    public static function record_failed_promo_attempt() {
        $ip = self::get_client_ip();
        $transient_key = 'etb_rl_promo_fail_' . md5( $ip );

        $fails = (int) get_transient( $transient_key );
        set_transient( $transient_key, $fails + 1, 10 * MINUTE_IN_SECONDS ); // Fenêtre de 10 minutes
    }

    /**
     * Vérifie si le quota d'échecs de codes promo a été dépassé
     * Seuil réduit à 5 (au lieu de 15) avec fenêtre 10 min
     */
    public static function is_promo_bruteforce_blocked( $max_failures = 5 ) {
        $ip = self::get_client_ip();
        $transient_key = 'etb_rl_promo_fail_' . md5( $ip );

        $fails = (int) get_transient( $transient_key );
        return ( $fails >= $max_failures );
    }

    /**
     * Génère un jeton d'horodatage signé pour valider le temps de remplissage
     */
    public static function generate_timestamp_token() {
        $timestamp = time();
        $token     = wp_hash( $timestamp . '|' . self::TIME_SALT, 'nonce' );

        return array(
            'time'  => $timestamp,
            'token' => $token,
        );
    }

    /**
     * Vérifie la validité du jeton d'horodatage et la vélocité de soumission
     *
     * @param int $submitted_time Timestamp envoyé par le formulaire
     * @param string $submitted_token Signature envoyée
     * @param int $min_seconds Temps minimum humain requis (ex: 3 secondes)
     * @param int $max_seconds Validité maximale du formulaire (ex: 86400 = 24h)
     * @return bool
     */
    public static function verify_timestamp_token( $submitted_time, $submitted_token, $min_seconds = 3, $max_seconds = 86400 ) {
        // 1. Si les champs de sécurité ne sont pas reçus, on ne bloque pas (tolérance cache)
        if ( empty( $submitted_time ) || empty( $submitted_token ) ) {
            return true;
        }

        // 2. Vérification de la signature cryptographique du serveur
        $expected_token = wp_hash( $submitted_time . '|' . self::TIME_SALT, 'nonce' );
        if ( ! hash_equals( $expected_token, $submitted_token ) ) {
            return false; // Signature falsifiée
        }

        $elapsed = time() - (int) $submitted_time;

        // 3. Rejet si soumis en moins de 3 secondes (Bot script ultra-rapide)
        if ( $elapsed < $min_seconds ) {
            return false;
        }

        // 4. Rejet si le formulaire a été ouvert il y a plus de 24 heures (Formulaire périmé)
        if ( $elapsed > $max_seconds ) {
            return false;
        }

        return true;
    }

    /**
     * Calcule la signature cryptographique HMAC pour sceller un montant et une réservation
     *
     * @param int   $booking_id ID de la réservation WordPress
     * @param float $amount     Montant fixé par le régulateur
     * @return string Empreinte SHA256 inviolable
     */
    public static function create_payment_signature( $booking_id, $amount ) {
        $clean_id     = absint( $booking_id );
        $clean_amount = number_format( (float) $amount, 2, '.', '' );
        $salt         = wp_salt( 'nonce' );

        return hash_hmac( 'sha256', $clean_id . '|' . $clean_amount, $salt );
    }

    /**
     * Vérifie si la signature fournie dans l'URL correspond au montant et au dossier
     *
     * @param int    $booking_id ID de la réservation WordPress
     * @param float  $amount     Montant reçu dans l'URL
     * @param string $signature  Signature reçue dans l'URL
     * @return bool True si valide, False si lien falsifié
     */
    public static function verify_payment_signature( $booking_id, $amount, $signature ) {
        if ( empty( $booking_id ) || empty( $amount ) || empty( $signature ) ) {
            return false;
        }

        $expected_sig = self::create_payment_signature( $booking_id, $amount );
        return hash_equals( $expected_sig, (string) $signature );
    }

    /**
     * Génère l'URL complète et sécurisée vers la page de paiement
     *
     * @param int   $booking_id ID de la réservation WordPress
     * @param float $amount     Montant fixé par le régulateur (facultatif si devis initial)
     * @return string URL prête à être envoyée par WhatsApp ou e-mail
     */
    public static function get_secure_payment_url( $booking_id, $amount = 0.0 ) {
        $gen_settings = get_option( 'etb_general_settings', array() );

        // Recherche de la page contenant le shortcode [etb_payment]
        $payment_page_url = ! empty( $gen_settings['payment_page_url'] )
            ? esc_url_raw( $gen_settings['payment_page_url'] )
            : home_url( '/payment/' );

        $clean_amount = floatval( $amount );

        if ( $clean_amount > 0 ) {
            $sig = self::create_payment_signature( $booking_id, $clean_amount );
            return add_query_arg( array(
                'booking_id' => absint( $booking_id ),
                'amount'     => number_format( $clean_amount, 2, '.', '' ),
                'sig'        => $sig,
            ), $payment_page_url );
        }

        // Si montant non encore arrêté, lien d'attente sécurisé
        $sig = self::create_payment_signature( $booking_id, 0.0 );
        return add_query_arg( array(
            'booking_id' => absint( $booking_id ),
            'sig'        => $sig,
        ), $payment_page_url );
    }
}