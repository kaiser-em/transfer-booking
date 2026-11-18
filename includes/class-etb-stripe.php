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
     * Recherche les customers Stripe existants pour un email.
     * Le plugin créait auparavant un customer à chaque paiement, ce qui produisait
     * des doublons : Kymark (qui résout le client par email) pouvait tomber sur un
     * profil vide et afficher « no saved card ».
     *
     * @return array Liste des customers (vide si aucun ou en cas d'erreur).
     */
    private static function search_customers_by_email( $email ) {
        $email = sanitize_email( $email );
        if ( empty( $email ) ) {
            return array();
        }

        // 1) Search API (indexée, la plus fiable), 2) repli sur la liste filtrée.
        $query   = "email:'" . str_replace( "'", "\\'", $email ) . "'";
        $results = self::request( 'customers/search?query=' . rawurlencode( $query ) . '&limit=10', 'GET' );

        if ( is_wp_error( $results ) || empty( $results['data'] ) ) {
            $results = self::request( 'customers?email=' . rawurlencode( $email ) . '&limit=10', 'GET' );
        }

        if ( is_wp_error( $results ) || empty( $results['data'] ) ) {
            return array();
        }

        return $results['data'];
    }

    /**
     * Retourne le customer Stripe à utiliser pour cet email : réutilise un profil
     * existant (en priorité celui qui possède déjà une carte) au lieu d'en créer un
     * nouveau à chaque paiement. Zéro régression : comportement identique si aucun
     * customer n'existe.
     */
    public static function find_or_create_customer( $name, $email, $phone = '', $country = '' ) {
        $existing = self::search_customers_by_email( $email );

        if ( ! empty( $existing ) ) {
            $newest    = null;
            $newest_ts = -1;
            $with_card = null;
            $with_default = null;

            foreach ( $existing as $customer ) {
                $ts = (int) ( $customer['created'] ?? 0 );
                if ( $ts > $newest_ts ) {
                    $newest_ts = $ts;
                    $newest    = $customer;
                }
                if ( ! empty( $customer['invoice_settings']['default_payment_method'] ) && ! $with_default ) {
                    $with_default = $customer;
                }
            }

            // Priorité : carte par défaut > customer le plus récent ayant une carte
            // > customer le plus récent. C'est ce profil que Kymark pourra débiter.
            if ( $with_default ) {
                return $with_default;
            }

            if ( $newest ) {
                $pms = self::request( 'payment_methods?customer=' . rawurlencode( $newest['id'] ) . '&type=card&limit=1', 'GET' );
                if ( ! is_wp_error( $pms ) && ! empty( $pms['data'] ) ) {
                    $with_card = $newest;
                }
            }

            if ( ! $with_card ) {
                foreach ( $existing as $customer ) {
                    if ( ! empty( $customer['invoice_settings']['default_payment_method'] ) ) {
                        continue;
                    }
                    $pms = self::request( 'payment_methods?customer=' . rawurlencode( $customer['id'] ) . '&type=card&limit=1', 'GET' );
                    if ( ! is_wp_error( $pms ) && ! empty( $pms['data'] ) ) {
                        $with_card = $customer;
                        break;
                    }
                }
            }

            if ( $with_card ) {
                return $with_card;
            }
            if ( $newest ) {
                return $newest;
            }
        }

        return self::create_customer( $name, $email, $phone, $country );
    }

    /**
     * Rattache explicitement la carte au customer et la définit comme moyen de
     * paiement par défaut. Certains outils (Kymark) lisent
     * invoice_settings.default_payment_method plutôt que la liste des cartes.
     *
     * @return bool True si la carte est utilisable comme carte enregistrée.
     */
    public static function register_saved_card( $customer_id, $pm_id ) {
        $customer_id = trim( (string) $customer_id );
        $pm_id       = trim( (string) $pm_id );

        if ( '' === $customer_id || '' === $pm_id ) {
            return false;
        }

        // Attachement explicite (idempotent : Stripe renvoie une erreur bénigne si
        // la carte est déjà rattachée à ce customer, on l'ignore).
        self::request( 'payment_methods/' . rawurlencode( $pm_id ) . '/attach', 'POST', array(
            'customer' => $customer_id,
        ) );

        // Définition comme carte par défaut du profil client.
        $defaulted = self::request( 'customers/' . rawurlencode( $customer_id ), 'POST', array(
            'invoice_settings[default_payment_method]' => $pm_id,
        ) );

        return ! is_wp_error( $defaulted );
    }

    /**
     * Crée une intention de paiement (PaymentIntent) avec capture différée (Modèle Blacklane)
     *
     * @param float  $amount Montant en euros (ex: 197.00)
     * @param string $currency Code devise (ex: 'eur')
     * @param string $customer_id ID client Stripe (ex: 'cus_XXXX')
     * @param array  $metadata Données de traçabilité (Dossier WP, trajet...)
     * @param string $capture_method 'manual' pour pré-autorisation Blacklane, 'automatic' pour débit direct
     * @param bool   $save_card Vault la carte sur le customer (off_session) pour surcharges ultérieures
     */
    public static function create_payment_intent( $amount, $currency = 'eur', $customer_id = '', $metadata = array(), $capture_method = 'manual', $save_card = false ) {
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

            // Vault de la carte : la méthode de paiement reste attachée au customer
            // et devient réutilisable hors session (surcharge post-course).
            if ( $save_card ) {
                $data['setup_future_usage'] = 'off_session';
            }
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
