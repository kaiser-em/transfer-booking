<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class ETB_Stripe {

    const API_BASE = 'https://api.stripe.com/v1/';

    /**
     * Récupère la clé secrète Stripe configurée
     */
    public static function get_secret_key() {
        $settings = get_option( 'etb_general_settings', array() );
        return ! empty( $settings['stripe_secret_key'] ) ? trim( $settings['stripe_secret_key'] ) : '';
    }

    /**
     * Récupère le secret de signature du endpoint webhook (whsec_...).
     * Distinct de la clé API : Stripe signe les événements avec ce secret.
     */
    public static function get_webhook_secret() {
        $settings = get_option( 'etb_general_settings', array() );
        return ! empty( $settings['stripe_webhook_secret'] ) ? trim( $settings['stripe_webhook_secret'] ) : '';
    }

    /**
     * Vérifie la signature d'un webhook Stripe sans dépendre du SDK PHP.
     *
     * En-tête attendu : "t=1234567890,v1=abcdef..."
     * Charge signée    : "{t}.{payload}", HMAC-SHA256 avec le secret whsec_.
     *
     * @param string $payload    Corps brut de la requête
     * @param string $sig_header Valeur de HTTP_STRIPE_SIGNATURE
     * @param string $secret     Secret du endpoint (whsec_...)
     * @param int    $tolerance  Tolérance en secondes (anti-rejeu)
     * @return bool
     */
    public static function verify_webhook_signature( $payload, $sig_header, $secret = '', $tolerance = 300 ) {
        if ( '' === (string) $payload || '' === (string) $sig_header ) {
            return false;
        }

        if ( '' === $secret ) {
            $secret = self::get_webhook_secret();
        }
        if ( '' === $secret ) {
            return false;
        }

        $timestamp  = null;
        $signatures = array();

        foreach ( explode( ',', $sig_header ) as $part ) {
            $pair = explode( '=', trim( $part ), 2 );
            if ( 2 !== count( $pair ) ) {
                continue;
            }
            if ( 't' === $pair[0] ) {
                $timestamp = (int) $pair[1];
            } elseif ( 'v1' === $pair[0] ) {
                $signatures[] = $pair[1];
            }
        }

        if ( empty( $timestamp ) || empty( $signatures ) ) {
            return false;
        }

        // Tolérance anti-rejeu (horloge serveur vs timestamp Stripe)
        if ( abs( time() - $timestamp ) > (int) $tolerance ) {
            return false;
        }

        $expected = hash_hmac( 'sha256', $timestamp . '.' . $payload, $secret );

        foreach ( $signatures as $signature ) {
            if ( hash_equals( $expected, $signature ) ) {
                return true;
            }
        }

        return false;
    }

    /**
     * Exécute une requête HTTP sécurisée vers l'API REST Stripe
     */
    private static function request( $endpoint, $method = 'POST', $data = array() ) {
        $secret_key = self::get_secret_key();
        if ( empty( $secret_key ) ) {
            return new WP_Error( 'stripe_no_key', 'Clé secrète Stripe non configurée.' );
        }

        $url  = self::API_BASE . ltrim( $endpoint, '/' );
        $args = array(
            'method'    => $method,
            'headers'   => array(
                'Authorization' => 'Bearer ' . $secret_key,
                'Content-Type'  => 'application/x-www-form-urlencoded',
            ),
            'timeout'   => 20,
            'sslverify' => true,
        );

        if ( 'POST' === $method && ! empty( $data ) ) {
            $args['body'] = http_build_query( $data );
        }

        $response = wp_remote_request( $url, $args );

        if ( is_wp_error( $response ) ) {
            return $response;
        }

        $code = wp_remote_retrieve_response_code( $response );
        $body = json_decode( wp_remote_retrieve_body( $response ), true );

        if ( $code >= 200 && $code < 300 ) {
            return $body;
        }

        $error_msg = $body['error']['message'] ?? 'Erreur Stripe inconnue.';
        return new WP_Error( 'stripe_error', $error_msg, array( 'status' => $code ) );
    }


    /**
     * Crée un profil client dans Stripe avec Pays et Téléphone officiels
     */
    public static function create_customer( $name, $email, $phone = '', $country = '' ) {
        $data = array(
            'name'  => $name,
            'email' => $email,
        );
        if ( ! empty( $phone ) ) {
            $data['phone'] = $phone;
        }
        if ( ! empty( $country ) ) {
            $data['address[country]'] = strtoupper( trim( $country ) );
        }

        return self::request( 'customers', 'POST', $data );
    }

    /**
     * Crée une intention de paiement (PaymentIntent) avec capture différée (Modèle Blacklane)
     *
     * @param float  $amount Montant en euros (ex: 197.00)
     * @param string $currency Code devise (ex: 'eur')
     * @param string $customer_id ID client Stripe (ex: 'cus_XXXX')
     * @param array  $metadata Données de traçabilité (Dossier WP, trajet...)
     * @param string $capture_method 'manual' pour pré-autorisation Blacklane, 'automatic' pour débit direct
     */
    public static function create_payment_intent( $amount, $currency = 'eur', $customer_id = '', $metadata = array(), $capture_method = 'manual' ) {
        // Stripe attend les montants en centimes d'euro entiers (197.00 € = 19700 centimes)
        $amount_in_cents = round( floatval( $amount ) * 100 );

        $data = array(
            'amount'               => $amount_in_cents,
            'currency'             => strtolower( $currency ),
            'capture_method'       => $capture_method, // 'manual' bloque les fonds sans débiter
            'payment_method_types' => array( 'card' ),
        );

        if ( ! empty( $customer_id ) ) {
            $data['customer'] = $customer_id;
        }

        if ( ! empty( $metadata ) && is_array( $metadata ) ) {
            $data['metadata'] = $metadata;
        }

        return self::request( 'payment_intents', 'POST', $data );
    }

    /**
     * Capture / Débite définitivement une empreinte pré-autorisée
     */
    public static function capture_payment_intent( $payment_intent_id, $amount = null ) {
        $data = array();
        if ( null !== $amount ) {
            $data['amount_to_capture'] = round( floatval( $amount ) * 100 );
        }

       return self::request( 'payment_intents/' . urlencode( $payment_intent_id ) . '/capture', 'POST', $data );
    }

    /**
     * Récupère et vérifie les détails d'un PaymentIntent depuis l'API Stripe (GET)
     *
     * @param string $payment_intent_id Identifiant Stripe (ex: pi_3Mxxx)
     * @return array|WP_Error Données de l'intention ou WP_Error en cas d'échec
     */
    public static function retrieve_payment_intent( $payment_intent_id ) {
        $clean_id = sanitize_text_field( trim( $payment_intent_id ) );

        if ( empty( $clean_id ) ) {
            return new WP_Error( 'stripe_invalid_id', 'Identifiant PaymentIntent manquant.' );
        }

        return self::request( 'payment_intents/' . urlencode( $clean_id ), 'GET' );
    }
}
