<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Intégration Kymark — Application de dispatch tierce.
 *
 * Calquée sur ETB_LimoExpress. Appelle l'API publique Kymark :
 *   Base URL : https://api.kymark.app/functions/v1/public-api/v1
 *   Auth     : header X-API-Key (pas de Bearer)
 *   Create   : POST /v1/shuttle_bookings  (body JSON)
 *
 * TODO (à affiner une fois l'API Kymark testée en vrai) :
 *  - Confirmer les noms exacts des champs de réponse (id / reference / booking_id / trip_id)
 *  - Confirmer s'il faut envoyer trip_id ou si Kymark le génère
 *  - Ajouter GET /v1/shuttle_bookings/{id} pour get_booking_details()
 *  - Lier véhicules WordPress ↔ véhicules Kymark (méta _etb_kymark_vehicle_id)
 */
class ETB_Kymark {

    /**
     * Endpoint API Kymark — création de réservation (shuttle booking)
     */
    const API_ENDPOINT = 'https://api.kymark.app/functions/v1/public-api/v1/shuttle_bookings';

    /**
     * Base de l'API publique Kymark (toutes les ressources : pricing_zones,
     * zone_pricing_routes, vehicles, trips, shuttle_bookings...)
     */
    const API_BASE = 'https://api.kymark.app/functions/v1/public-api/v1';

    /**
     * Durée du cache des listes de pricing (zones, routes tarifaires, véhicules).
     * 6 h : les données ne changent que quand la config Kymark est modifiée,
     * et chaque refresh coûte 5 crédits GET (1 par ressource).
     */
    const CACHE_TTL = 6 * HOUR_IN_SECONDS;

    /** Cache des règles de majoration (surge) et de leurs liens pricing groups. */
    const SURGE_CACHE_KEY = 'etb_kymark_surge_cache';

    /**
     * Mode simulation : si true, aucune requête HTTP n'est envoyée à Kymark.
     * À passer à false une fois les vrais endpoints validés.
     */
    const SIMULATE = false;

    /**
     * Branche le module Kymark sur le socle ETB (Hooks WordPress)
     */
    public static function init_hooks() {
        add_filter( 'etb_sanitize_dispatcher_settings', array( __CLASS__, 'sanitize_settings' ), 10, 2 );
        add_action( 'etb_render_dispatcher_settings', array( __CLASS__, 'render_settings' ) );

        // Page d'admin des notifications Kymark
        add_action( 'admin_menu', array( __CLASS__, 'register_notification_admin_page' ) );

        // AJAX : activer/désactiver une notification Kymark
        add_action( 'wp_ajax_etb_kymark_toggle_notification', array( __CLASS__, 'ajax_toggle_notification' ) );
    }

    /**
     * Sauvegarde des réglages propres à Kymark
     */
    public static function sanitize_settings( $new_input, $raw_input ) {
        if ( isset( $new_input['active_dispatcher'] ) && 'kymark' === $new_input['active_dispatcher'] ) {
            $new_input['kymark_api_token']          = ! empty( $raw_input['kymark_api_token'] ) ? sanitize_text_field( trim( $raw_input['kymark_api_token'] ) ) : '';
            $new_input['kymark_client_id']          = ! empty( $raw_input['kymark_client_id'] ) ? sanitize_text_field( trim( $raw_input['kymark_client_id'] ) ) : '';
            $new_input['kymark_booking_type_id']    = ! empty( $raw_input['kymark_booking_type_id'] ) ? sanitize_text_field( trim( $raw_input['kymark_booking_type_id'] ) ) : '';
            $new_input['kymark_booking_status_id']  = ! empty( $raw_input['kymark_booking_status_id'] ) ? sanitize_text_field( trim( $raw_input['kymark_booking_status_id'] ) ) : '';
            $new_input['kymark_simulate']           = ! empty( $raw_input['kymark_simulate'] ) ? '1' : '0';

            // Purge automatique des caches
            delete_transient( 'etb_kymark_clients_cache' );
            delete_transient( 'etb_kymark_classes_cache' );
            delete_transient( 'etb_kymark_types_cache' );
            delete_transient( 'etb_kymark_statuses_cache' );

            // Purge du cache pricing (zones / routes / véhicules)
            self::refresh_pricing_cache();
        }
        return $new_input;
    }

    /**
     * Affiche l'interface de réglages Kymark dans WordPress
     */
    public static function render_settings( $options ) {
        if ( empty( $options['active_dispatcher'] ) || 'kymark' !== $options['active_dispatcher'] ) {
            return;
        }

        // Bouton « Rafraîchir les données Kymark » (5 crédits GET : zones + routes
        // + véhicules + règles de majoration + liens pricing groups)
        $kymark_refreshed = false;
        $kymark_refresh_error = '';
        if ( ! empty( $_GET['refresh_kymark'] ) ) {
            $refresh_nonce = isset( $_GET['_wpnonce'] ) ? sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) ) : '';
            if ( wp_verify_nonce( $refresh_nonce, 'etb_refresh_kymark' ) ) {
                self::refresh_pricing_cache();
                $zones_check = self::get_zones();
                if ( is_wp_error( $zones_check ) ) {
                    $kymark_refresh_error = $zones_check->get_error_message();
                } else {
                    $kymark_refreshed = true;
                }
            }
        }
        $kymark_refresh_url = wp_nonce_url( add_query_arg( 'refresh_kymark', '1' ), 'etb_refresh_kymark' );
        ?>
        <tr class="etb-dispatcher-row kymark-row">
            <th scope="row" colspan="2">
                <div style="background: #fff8ec; border-left: 4px solid #ba6827; padding: 12px; margin-top: 10px;">
                    <h4 style="margin: 0 0 5px 0; color: #92400e;">🟠 Configuration Kymark</h4>
                    <p style="margin: 0; font-size: 13px;">Module connecté. Renseignez votre clé API depuis le dashboard admin Kymark.</p>
                </div>
            </th>
        </tr>
        <tr class="etb-dispatcher-row kymark-row">
            <th scope="row">Données de tarification</th>
            <td>
                <?php if ( $kymark_refreshed ) : ?>
                    <p style="color: #166534; margin-top: 0;">✅ Cache Kymark rafraîchi (zones, routes tarifaires, véhicules, règles de majoration).</p>
                <?php elseif ( ! empty( $kymark_refresh_error ) ) : ?>
                    <p style="color: #991b1b; margin-top: 0;">⚠️ Erreur Kymark : <?php echo esc_html( $kymark_refresh_error ); ?></p>
                <?php endif; ?>
                <a href="<?php echo esc_url( $kymark_refresh_url ); ?>" class="button button-secondary">
                    🔄 Rafraîchir les données Kymark
                </a>
                <p class="description">Recharge les zones tarifaires, les routes A→B, les véhicules et les règles de majoration (surge) depuis l'API Kymark. Coût : 5 crédits GET (1 par ressource). Cache valable 6 h — les visiteurs du site ne consomment aucun crédit.</p>
            </td>
        </tr>
        <tr class="etb-dispatcher-row kymark-row">
            <th scope="row">Clé API Kymark</th>
            <td>
                <input type="password" name="etb_general_settings[kymark_api_token]" value="<?php echo esc_attr( $options['kymark_api_token'] ?? '' ); ?>" class="regular-text" placeholder="Clé API Kymark...">
                <p class="description">À récupérer dans le dashboard admin Kymark. Envoyée dans l'en-tête X-API-Key.</p>
            </td>
        </tr>
        <tr class="etb-dispatcher-row kymark-row">
            <th scope="row">Client par défaut Kymark</th>
            <td>
                <input type="text" name="etb_general_settings[kymark_client_id]" value="<?php echo esc_attr( $options['kymark_client_id'] ?? '' ); ?>" class="regular-text" placeholder="Optionnel">
                <p class="description">ID du client par défaut dans Kymark (laissé vide = détection automatique).</p>
            </td>
        </tr>
        <tr class="etb-dispatcher-row kymark-row">
            <th scope="row">Type de réservation Kymark</th>
            <td>
                <input type="text" name="etb_general_settings[kymark_booking_type_id]" value="<?php echo esc_attr( $options['kymark_booking_type_id'] ?? '' ); ?>" class="regular-text" placeholder="ID du type de résa">
                <p class="description">Type utilisé pour les trajets standards (à adapter selon le dashboard Kymark).</p>
            </td>
        </tr>
        <tr class="etb-dispatcher-row kymark-row">
            <th scope="row">Statut initial Kymark</th>
            <td>
                <input type="text" name="etb_general_settings[kymark_booking_status_id]" value="<?php echo esc_attr( $options['kymark_booking_status_id'] ?? '' ); ?>" class="regular-text" placeholder="ID du statut initial">
                <p class="description">Statut attribué à une nouvelle réservation envoyée à Kymark.</p>
            </td>
        </tr>
        <tr class="etb-dispatcher-row kymark-row">
            <th scope="row">Mode simulation (développement)</th>
            <td>
                <label>
                    <input type="checkbox" name="etb_general_settings[kymark_simulate]" value="1" <?php checked( ! empty( $options['kymark_simulate'] ) && '1' === $options['kymark_simulate'] ); ?>>
                    Activer la simulation (aucune requête HTTP vers Kymark)
                </label>
                <p class="description">À cocher tant que les vrais endpoints Kymark ne sont pas validés en production.</p>
            </td>
        </tr>
        <?php
    }

    /**
     * Transmet une réservation WordPress vers Kymark
     *
     * @param int   $booking_id ID de la réservation WordPress
     * @param array $data       Données de réservation (vehicles, pricing, passenger, etc.)
     * @return bool
     */
    public static function send_booking( $booking_id, $data ) {
        $settings = get_option( 'etb_general_settings', array() );

        // 1. Contrôle d'activation
        $is_active = ( ! empty( $settings['active_dispatcher'] ) && 'kymark' === $settings['active_dispatcher'] );
        if ( ! $is_active ) {
            update_post_meta( $booking_id, '_etb_kymark_status', 'failed' );
            update_post_meta( $booking_id, '_etb_kymark_error', 'Kymark n\'est pas sélectionné comme application active.' );
            return false;
        }

        $api_token = $settings['kymark_api_token'] ?? '';
        $simulate  = ( ! empty( $settings['kymark_simulate'] ) && '1' === $settings['kymark_simulate'] ) || self::SIMULATE;
        $currency_symbol = ! empty( $settings['currency'] ) ? sanitize_text_field( $settings['currency'] ) : '€';

        if ( empty( $api_token ) && ! $simulate ) {
            update_post_meta( $booking_id, '_etb_kymark_status', 'failed' );
            update_post_meta( $booking_id, '_etb_kymark_error', 'Clé API Kymark manquante dans les réglages.' );
            return false;
        }

        // 2. Mode simulation : succès factice pour développement
        if ( $simulate ) {
            $fake_id = 'KYM-' . wp_rand( 10000, 99999 );
            update_post_meta( $booking_id, '_etb_kymark_status', 'synced' );
            update_post_meta( $booking_id, '_etb_kymark_booking_id', $fake_id );
            update_post_meta( $booking_id, '_etb_kymark_uuid', $fake_id );
            delete_post_meta( $booking_id, '_etb_kymark_error' );
            return true;
        }

        // 4. Données de réservation
        $name_parts   = explode( ' ', trim( $data['name'] ?? '' ), 2 );
        $first_name   = ! empty( $name_parts[0] ) ? $name_parts[0] : 'Client';
        $last_name    = ! empty( $name_parts[1] ) ? $name_parts[1] : '--';
        $rider_name   = trim( $first_name . ' ' . $last_name );
        $rider_email  = ! empty( $data['email'] ) ? $data['email'] : '';
        $rider_phone  = ! empty( $data['phone'] ) ? $data['phone'] : '';

        $total_passengers = intval( $data['adults'] ?? 0 ) + intval( $data['children'] ?? 0 );
        if ( $total_passengers <= 0 ) {
            $total_passengers = 1;
        }

        $tip_amount_val = ! empty( $data['tip_amount'] ) ? floatval( $data['tip_amount'] ) : 0.0;
        if ( isset( $data['pricing']['base_fare'] ) && '' !== $data['pricing']['base_fare'] ) {
            $base_ride_price = floatval( $data['pricing']['base_fare'] );
        } else {
            $grand_val = floatval( $data['pricing']['grand_total'] ?? 0.0 );
            $base_ride_price = ( $tip_amount_val > 0 && $grand_val > $tip_amount_val ) ? ( $grand_val - $tip_amount_val ) : $grand_val;
        }
        $grand_total = floatval( $data['pricing']['grand_total'] ?? 0.0 );

        // 5. Notes opérationnelles (comme LimoExpress : tout le terrain, zéro finance)
        $notes = ! empty( $data['client_note'] ) ? trim( $data['client_note'] ) : '';
        if ( ! empty( $data['waiting_board_text'] ) ) {
            $notes .= "\n🪧 PANCARTE : " . trim( $data['waiting_board_text'] );
        }
        if ( ! empty( $data['flight_number'] ) ) {
            $notes .= "\n✈️ VOL : " . trim( $data['flight_number'] );
        }
        if ( ! empty( $data['cost_center'] ) ) {
            $notes .= "\n🏢 RÉF : " . trim( $data['cost_center'] );
        }

        // 👶 Sièges bébé / rehausseurs
        $baby_seat_count   = absint( $data['baby_seat_count'] ?? 0 );
        $booster_seat_count = absint( $data['booster_seat_count'] ?? 0 );
        if ( $baby_seat_count > 0 || $booster_seat_count > 0 ) {
            $seats_parts = array();
            if ( $baby_seat_count > 0 ) {
                $seats_parts[] = $baby_seat_count . ' siège(s) bébé';
            }
            if ( $booster_seat_count > 0 ) {
                $seats_parts[] = $booster_seat_count . ' rehausseur(s)';
            }
            $notes .= "\n👶 SIÈGES BÉBÉ : " . implode( ', ', $seats_parts );
        }

        // 🎁 Extras / options sélectionnés (transmis dans les notes opérationnelles)
        $extra_fees = (array) ( $data['extra_fees'] ?? array() );
        if ( ! empty( $extra_fees ) ) {
            $extra_lines = array();
            foreach ( $extra_fees as $fee ) {
                if ( ! empty( $fee['active'] ) && ! empty( $fee['category'] ) ) {
                    $amount = isset( $fee['amount'] ) ? floatval( $fee['amount'] ) : 0;
                    $extra_lines[] = sprintf( '%s : %s %s', ucfirst( sanitize_text_field( $fee['category'] ) ), number_format( $amount, 2 ), $currency_symbol );
                }
            }
            if ( ! empty( $extra_lines ) ) {
                $notes .= "\n🎁 EXTRAS : " . implode( ', ', $extra_lines );
            }
        }

        // 6. Résolution véhicule WP → véhicule Kymark (type de course)
        $vehicle_type        = '';
        $kymark_vehicle_uuid = '';
        $wp_vehicle_ids      = array_keys( (array) ( $data['vehicles'] ?? array() ) );
        $wp_vehicle_id       = ! empty( $wp_vehicle_ids[0] ) ? absint( $wp_vehicle_ids[0] ) : 0;
        if ( $wp_vehicle_id ) {
            $kymark_vehicle_uuid = trim( get_post_meta( $wp_vehicle_id, '_etb_kymark_vehicle_id', true ) );
        }
        if ( ! empty( $kymark_vehicle_uuid ) ) {
            $kymark_vehicles = self::get_vehicles();
            if ( ! is_wp_error( $kymark_vehicles ) ) {
                foreach ( $kymark_vehicles as $kv ) {
                    if ( ( $kv['id'] ?? '' ) === $kymark_vehicle_uuid ) {
                        $vehicle_type = (string) ( $kv['type'] ?? '' );
                        break;
                    }
                }
            }
        }

        // 7. Date/heure de pickup au format ISO 8601 (timezone du site)
        $pickup_date_raw = trim( (string) ( $data['date'] ?? '' ) );
        $pickup_time_raw = trim( (string) ( $data['time'] ?? '' ) );
        $pickup_iso      = '';
        if ( $pickup_date_raw ) {
            $tz = wp_timezone();
            if ( $pickup_time_raw && preg_match( '/^\d{1,2}:\d{2}/', $pickup_time_raw ) ) {
                $pickup_dt = DateTime::createFromFormat( 'Y-m-d H:i', $pickup_date_raw . ' ' . substr( $pickup_time_raw, 0, 5 ), $tz );
            } else {
                $pickup_dt = DateTime::createFromFormat( 'Y-m-d', $pickup_date_raw, $tz );
            }
            if ( $pickup_dt ) {
                $pickup_iso = $pickup_dt->format( 'c' );
            }
        }

        $pickup_address  = trim( (string) ( $data['pickup_address'] ?? '' ) );
        $dropoff_address = trim( (string) ( $data['dropoff_info'] ?? '' ) );

        if ( empty( $pickup_iso ) || empty( $pickup_address ) || empty( $dropoff_address ) ) {
            update_post_meta( $booking_id, '_etb_kymark_status', 'failed' );
            update_post_meta( $booking_id, '_etb_kymark_error', 'Données de course incomplètes pour Kymark (adresse ou date/heure).' );
            return false;
        }

        $is_paid = ! empty( $data['paid'] ) || ! empty( $data['payment_intent_id'] );

        // Extraction du siège bébé depuis les extra_fees pour l'inclure dans le prix du trip
        $child_seat_fee_trip = 0.0;
        $extra_fees_trip = (array) ( $data['extra_fees'] ?? array() );
        foreach ( $extra_fees_trip as $fee ) {
            if ( ! empty( $fee['active'] ) && ! empty( $fee['category'] ) && 'child_seat_fee' === $fee['category'] ) {
                $child_seat_fee_trip = floatval( $fee['amount'] ?? 0 );
                break;
            }
        }

        // 8. ÉTAPE 1 — Création de la course : POST /v1/trips
        //    Le 'price' inclut le tarif de transport + siège bébé (facturés comme prix de course).
        //    Le tip est envoyé séparément via 'gratuity'. Le total client reste
        //    dans le shuttle_booking (total_price).
        $trip_payload = array(
            'pickup_address'  => $pickup_address,
            'dropoff_address' => $dropoff_address,
            'pickup_date'     => $pickup_iso,
            'passenger_count' => $total_passengers,
            'price'           => (float) round( (float) ( $data['pricing']['base_fare'] ?? $grand_total ) + $child_seat_fee_trip, 2 ),
            'status'          => 'confirmed',
            'payment_status'  => $is_paid ? 'paid' : 'unpaid',
            'notes'           => $notes,
        );
        if ( ! empty( $vehicle_type ) ) {
            $trip_payload['vehicle_type'] = $vehicle_type;
        }
        // Assignation du véhicule de flotte Kymark (bloc « Vehicle » du trip dans le
        // dashboard). Champ writable confirmé dans la doc POST /v1/trips : « ID of an
        // existing record in /v1/vehicles ». Sans lui, le trip reste « No vehicle yet ».
        if ( ! empty( $kymark_vehicle_uuid ) ) {
            $trip_payload['vehicle_id'] = $kymark_vehicle_uuid;
        }

        // 💰 Pourboire transmis à Kymark (champ gratuity sur /v1/trips)
        $tip_amount_val = ! empty( $data['tip_amount'] ) ? floatval( $data['tip_amount'] ) : 0.0;
        if ( $tip_amount_val > 0 ) {
            $trip_payload['gratuity'] = (float) round( $tip_amount_val, 2 );
        }

        // 8bis. Customer Kymark rattaché au passager (pour que le nom apparaisse sur le trip)
        $customer_record_id = self::get_or_create_customer( $data, $settings );
        if ( ! empty( $customer_record_id ) ) {
            $trip_payload['customer_record_id'] = $customer_record_id;
        }

        $trip_res = self::api_request( 'POST', '/trips', $trip_payload );
        if ( is_wp_error( $trip_res ) ) {
            update_post_meta( $booking_id, '_etb_kymark_status', 'failed' );
            update_post_meta( $booking_id, '_etb_kymark_error', 'Création du trip Kymark : ' . $trip_res->get_error_message() );
            return false;
        }

        $trip_uuid = (string) ( $trip_res['data']['id'] ?? '' );
        if ( empty( $trip_uuid ) ) {
            update_post_meta( $booking_id, '_etb_kymark_status', 'failed' );
            update_post_meta( $booking_id, '_etb_kymark_error', 'Trip Kymark créé mais sans ID retourné.' );
            return false;
        }
        update_post_meta( $booking_id, '_etb_kymark_trip_uuid', $trip_uuid );
        if ( ! empty( $trip_res['data']['trip_number'] ) ) {
            update_post_meta( $booking_id, '_etb_kymark_trip_number', (string) $trip_res['data']['trip_number'] );
        }

        // 9. ÉTAPE 2 — Réservation des sièges rattachée au trip : POST /v1/shuttle_bookings
        //    Champs validés : trip_id, rider_*, seat_*, total_price obligatoires
        $payload = array(
            'trip_id'        => $trip_uuid,
            'rider_name'     => $rider_name,
            'rider_email'    => $rider_email,
            'rider_phone'    => $rider_phone,
            'seat_count'     => $total_passengers,
            'seat_price'     => (float) round( $base_ride_price, 2 ),
            'total_price'    => (float) round( $grand_total, 2 ),
            'status'         => 'confirmed',
            'payment_status' => $is_paid ? 'paid' : 'pending',
            'notes'          => $notes,
        );
        if ( ! empty( $data['payment_intent_id'] ) ) {
            $payload['stripe_payment_intent_id'] = (string) $data['payment_intent_id'];
            $payload['stripe_charge_id']         = (string) $data['payment_intent_id'];
        }

        $res = self::api_request( 'POST', '/shuttle_bookings', $payload );
        if ( is_wp_error( $res ) ) {
            update_post_meta( $booking_id, '_etb_kymark_status', 'failed' );
            update_post_meta( $booking_id, '_etb_kymark_error', 'Réservation Kymark : ' . $res->get_error_message() );
            return false;
        }

        $kymark_id = (string) ( $res['data']['id'] ?? '' );
        update_post_meta( $booking_id, '_etb_kymark_status', 'synced' );
        update_post_meta( $booking_id, '_etb_kymark_booking_id', ! empty( $kymark_id ) ? $kymark_id : 'KYM-' . $booking_id );
        if ( ! empty( $kymark_id ) ) {
            update_post_meta( $booking_id, '_etb_kymark_uuid', $kymark_id );
        }
        delete_post_meta( $booking_id, '_etb_kymark_error' );
        return true;
    }

    /**
     * Requête POST / PATCH sur l'API Kymark.
     *
     * @param string $method  'POST' ou 'PATCH'
     * @param string $path    Chemin après la base (ex: '/trips')
     * @param array  $payload Body JSON
     * @return array|WP_Error Body décodé ou erreur (message d'API Kymark inclus)
     */
    private static function api_request( $method, $path, $payload ) {
        $settings = get_option( 'etb_general_settings', array() );
        $token    = $settings['kymark_api_token'] ?? '';
        if ( empty( $token ) ) {
            return new WP_Error( 'etb_kymark_no_token', 'Clé API Kymark manquante dans les réglages.' );
        }

        $args = array(
            'headers' => array(
                'Content-Type' => 'application/json',
                'Accept'       => 'application/json',
                'X-API-Key'    => $token,
            ),
            'body'    => wp_json_encode( $payload ),
            'timeout' => 15,
        );

        if ( 'PATCH' === $method ) {
            $response = wp_remote_request( self::API_BASE . $path, array_merge( $args, array( 'method' => 'PATCH' ) ) );
        } else {
            $response = wp_remote_post( self::API_BASE . $path, $args );
        }

        if ( is_wp_error( $response ) ) {
            return $response;
        }

        $status_code = wp_remote_retrieve_response_code( $response );
        $raw_body    = wp_remote_retrieve_body( $response );
        $body        = json_decode( $raw_body, true );

        if ( $status_code < 200 || $status_code >= 300 ) {
            $err_details = '';
            if ( ! empty( $body['error'] ) ) {
                $err_details = $body['error'];
            } elseif ( ! empty( $body['message'] ) ) {
                $err_details = $body['message'];
            } elseif ( ! empty( $body['fields'] ) && is_array( $body['fields'] ) ) {
                $parts = array();
                foreach ( $body['fields'] as $f ) {
                    $parts[] = ( $f['field'] ?? '' ) . ': ' . ( $f['message'] ?? '' );
                }
                $err_details = implode( '; ', $parts );
            } else {
                $err_details = substr( strip_tags( $raw_body ), 0, 200 );
            }
            return new WP_Error( 'etb_kymark_api', 'HTTP ' . $status_code . ( $err_details ? ' : ' . $err_details : '' ) );
        }

        return is_array( $body ) ? $body : array();
    }

    /**
     * Après paiement Stripe : PATCH /v1/shuttle_bookings/{id}
     * payment_status 'paid' + status 'confirmed' + IDs Stripe.
     *
     * @param int    $booking_id      Réservation WordPress
     * @param string $stripe_intent_id PaymentIntent Stripe (optionnel)
     * @return array { success: bool, message: string }
     */
    public static function mark_booking_paid( $booking_id, $stripe_intent_id = '' ) {
        $booking_id = absint( $booking_id );
        if ( ! $booking_id ) {
            return array( 'success' => false, 'message' => 'Réservation WordPress invalide.' );
        }

        $settings = get_option( 'etb_general_settings', array() );
        $simulate = ( ! empty( $settings['kymark_simulate'] ) && '1' === $settings['kymark_simulate'] ) || self::SIMULATE;

        // Devise explicite pour les trip_payments Kymark : sans ce champ, Kymark
        // applique son défaut base (USD), alors que le site facture en EUR.
        $kymark_currency_map = array( '€' => 'EUR', '$' => 'USD', '£' => 'GBP', 'CHF' => 'CHF' );
        $site_currency_sym   = ! empty( $settings['currency'] ) ? trim( (string) $settings['currency'] ) : '€';
        $kymark_currency     = $kymark_currency_map[ $site_currency_sym ] ?? 'EUR';

        if ( $simulate ) {
            update_post_meta( $booking_id, '_etb_kymark_payment_status', 'paid (simulation)' );
            return array( 'success' => true, 'message' => 'Mode simulation : paiement Kymark simulé.' );
        }

        $booking_uuid = get_post_meta( $booking_id, '_etb_kymark_uuid', true );
        if ( empty( $booking_uuid ) ) {
            update_post_meta( $booking_id, '_etb_kymark_error', 'Aucun ID shuttle_booking Kymark à mettre à jour.' );
            return array( 'success' => false, 'message' => 'Aucun ID Kymark enregistré pour cette réservation.' );
        }

        $payload = array(
            'payment_status' => 'paid',
            'status'         => 'confirmed',
        );
        if ( ! empty( $stripe_intent_id ) ) {
            $payload['stripe_payment_intent_id'] = (string) $stripe_intent_id;
            $payload['stripe_charge_id']         = (string) $stripe_intent_id;
        }

        $res = self::api_request( 'PATCH', '/shuttle_bookings/' . rawurlencode( $booking_uuid ), $payload );
        if ( is_wp_error( $res ) ) {
            update_post_meta( $booking_id, '_etb_kymark_status', 'failed' );
            update_post_meta( $booking_id, '_etb_kymark_error', 'Mise à jour paiement Kymark : ' . $res->get_error_message() );
            return array( 'success' => false, 'message' => $res->get_error_message() );
        }

        update_post_meta( $booking_id, '_etb_kymark_payment_status', 'paid' );
        delete_post_meta( $booking_id, '_etb_kymark_error' );

        $trip_uuid = get_post_meta( $booking_id, '_etb_kymark_trip_uuid', true );

        // 8bis. CRÉATION DU PAIEMENT DANS KYMARK (POST /v1/trip_payments)
        // C'est cet enregistrement qui alimente le bloc "Payments / Total Due"
        // du trip dans le dashboard Kymark. Le PATCH payment_status seul ne suffit pas.
        // Doc : amount + payment_source_name + trip_id requis.
        if ( ! empty( $trip_uuid ) ) {
            $payment_uuid = get_post_meta( $booking_id, '_etb_kymark_trip_payment_uuid', true );

            if ( ! empty( $payment_uuid ) ) {
                // Déjà créé (idempotence) — ne pas recréer pour éviter un doublon.
                delete_post_meta( $booking_id, '_etb_kymark_trip_payment_error' );
            } else {
                // Montant réellement payé par le client : total WP (inclut sièges bébé + tip + extras),
                // avec repli sur base + tip si le total n'est pas renseigné.
                $paid_amount = floatval( get_post_meta( $booking_id, '_etb_total_price', true ) );
                if ( $paid_amount <= 0 ) {
                    $paid_amount = floatval( get_post_meta( $booking_id, '_etb_base_price', true ) )
                        + floatval( get_post_meta( $booking_id, '_etb_tip_amount', true ) );
                }

                $payment_payload = array(
                    'amount'              => (float) round( $paid_amount, 2 ),
                    'currency'            => $kymark_currency,
                    'payment_source_name' => 'Stripe',
                    'trip_id'             => (string) $trip_uuid,
                    'notes'               => 'Paiement en ligne via WordPress (Elite Transfer Booking)',
                );
                if ( ! empty( $stripe_intent_id ) ) {
                    $payment_payload['stripe_payment_intent_id'] = (string) $stripe_intent_id;
                    $payment_payload['stripe_charge_id']         = (string) $stripe_intent_id;
                }

                $payment_res = self::api_request( 'POST', '/trip_payments', $payment_payload );
                if ( is_wp_error( $payment_res ) ) {
                    // Non bloquant : les statuts (patchs ci-dessous) sont quand même poussés.
                    update_post_meta( $booking_id, '_etb_kymark_trip_payment_error', 'Création trip_payment Kymark : ' . $payment_res->get_error_message() );
                } else {
                    delete_post_meta( $booking_id, '_etb_kymark_trip_payment_error' );
                    $new_payment_uuid = '';
                    if ( is_array( $payment_res ) ) {
                        $new_payment_uuid = (string) ( $payment_res['data']['id'] ?? $payment_res['id'] ?? '' );
                    }
                    if ( ! empty( $new_payment_uuid ) ) {
                        update_post_meta( $booking_id, '_etb_kymark_trip_payment_uuid', $new_payment_uuid );
                    }
                    // Débogage : réponse brute de la création du paiement
                    update_post_meta( $booking_id, '_etb_kymark_trip_payment_response', wp_json_encode( $payment_res ) );
                }
            }
        }

        // 8ter. Marquer aussi le trip comme payé (PATCH /v1/trips/{id})
        // NOTE : stripe_payment_intent_id / stripe_charge_id ne sont PAS writable
        // sur /v1/trips (seulement sur /v1/shuttle_bookings et /v1/trip_payments).
        // On ne les garde que sur le shuttle_booking et le trip_payment.
        if ( ! empty( $trip_uuid ) ) {
            $trip_payload = array(
                'payment_status' => 'paid',
                'status'         => 'confirmed',
            );
            $trip_res = self::api_request( 'PATCH', '/trips/' . rawurlencode( $trip_uuid ), $trip_payload );
            if ( is_wp_error( $trip_res ) ) {
                update_post_meta( $booking_id, '_etb_kymark_trip_patch_error', $trip_res->get_error_message() );
            } else {
                delete_post_meta( $booking_id, '_etb_kymark_trip_patch_error' );
                // Débogage : stocker la réponse brute du PATCH trip
                update_post_meta( $booking_id, '_etb_kymark_trip_patch_response', wp_json_encode( $trip_res ) );
                update_post_meta( $booking_id, '_etb_kymark_trip_patch_code', '200' );
            }
        }

        return array( 'success' => true, 'message' => 'Paiement synchronisé vers Kymark.' );
    }

    /**
     * Référence client affichable pour une réservation : le numéro de trip Kymark
     * (ex: 1016) si dispo, sinon '' (l'appelant retombe sur sa référence par défaut).
     *
     * @param int $booking_id ID du dossier WP.
     * @return string Numéro de trip Kymark ou ''.
     */
    public static function get_display_ref( $booking_id ) {
        $number = get_post_meta( absint( $booking_id ), '_etb_kymark_trip_number', true );
        $number = trim( (string) $number );
        return ( '' !== $number ) ? $number : '';
    }

    /**
     * Récupère ou crée le client voyageur dans Kymark
     */
    public static function get_or_create_client( $data, $settings, $booking_id = 0 ) {
        $token      = $settings['kymark_api_token'] ?? '';
        $fallback_id = ! empty( $settings['kymark_client_id'] ) ? trim( $settings['kymark_client_id'] ) : '';

        if ( empty( $token ) && ! self::SIMULATE ) {
            return $fallback_id;
        }

        // Mémoire locale WordPress (anti-doublon)
        if ( $booking_id ) {
            $saved = get_post_meta( $booking_id, '_etb_kymark_client_id', true );
            if ( ! empty( $saved ) ) {
                return trim( $saved );
            }
        }

        // À ADAPTER : recherche / création client via l'API Kymark
        // Exemple : GET /v1/customers?search={email} puis POST /v1/customers si absent

        return $fallback_id;
    }

    /**
     * Recherche ou crée un customer Kymark rattaché au passager.
     * Retourne l'ID du customer (ou '' si échec non bloquant).
     *
     * @param array $data   Données de réservation (name, email, phone)
     * @param array $settings Réglages WP (contient kymark_api_token)
     * @return string UUID du customer ou ''
     */
    public static function get_or_create_customer( $data, $settings ) {
        $token      = $settings['kymark_api_token'] ?? '';
        $email      = ! empty( $data['email'] ) ? trim( $data['email'] ) : '';
        $name       = ! empty( $data['name'] ) ? trim( $data['name'] ) : 'Client';
        $phone      = ! empty( $data['phone'] ) ? trim( $data['phone'] ) : '';
        if ( empty( $token ) || empty( $email ) ) {
            return '';
        }

        // Split name into first/last
        $name_parts   = explode( ' ', $name, 2 );
        $first_name   = ! empty( $name_parts[0] ) ? $name_parts[0] : 'Client';
        $last_name    = ! empty( $name_parts[1] ) ? $name_parts[1] : '';

        // 1. Recherche par email (paramètre ?search= confirmé fonctionnel)
        $search = self::api_request( 'GET', '/customers?search=' . rawurlencode( $email ), array() );
        if ( ! is_wp_error( $search ) && ! empty( $search['data'] ) && is_array( $search['data'] ) ) {
            foreach ( $search['data'] as $c ) {
                if ( ! empty( $c['email'] ) && strcasecmp( $c['email'], $email ) === 0 ) {
                    return (string) ( $c['id'] ?? '' );
                }
            }
            // Aucun match exact mais des résultats existent : prendre le premier
            return (string) ( $search['data'][0]['id'] ?? '' );
        }

        // 2. Création du customer (champs selon doc API : first_name requis, last_name, email, primary_phone)
        $create = self::api_request( 'POST', '/customers', array(
            'first_name'   => $first_name,
            'last_name'    => $last_name,
            'email'        => $email,
            'primary_phone'=> $phone,
        ) );
        if ( is_wp_error( $create ) ) {
            return '';
        }
        return (string) ( $create['data']['id'] ?? $create['id'] ?? '' );
    }

    /**
     * Récupère le détail d'une réservation Kymark (prix, statut, confirmation)
     * À ADAPTER : endpoint GET /v1/shuttle_bookings/{id} à confirmer
     */
    public static function get_booking_details( $kymark_booking_id, $wp_booking_id = 0 ) {
        $result = array(
            'price'     => 0.0,
            'paid'      => false,
            'confirmed' => false,
            'reference' => '',
        );

        $settings = get_option( 'etb_general_settings', array() );
        $token    = $settings['kymark_api_token'] ?? '';
        if ( empty( $token ) && ! self::SIMULATE ) {
            return $result;
        }

        // À ADAPTER : appel GET sur l'API Kymark

        return $result;
    }

    /**
     * Listes de référence Kymark (clients, classes, types, statuts) — avec cache 1h
     * À ADAPTER selon les endpoints disponibles dans le dashboard Kymark.
     */
    public static function get_clients( $force_refresh = false ) {
        if ( ! $force_refresh ) {
            $cached = get_transient( 'etb_kymark_clients_cache' );
            if ( false !== $cached && is_array( $cached ) ) {
                return $cached;
            }
        }
        set_transient( 'etb_kymark_clients_cache', array(), HOUR_IN_SECONDS );
        return array();
    }

    public static function get_vehicle_classes( $force_refresh = false ) {
        if ( ! $force_refresh ) {
            $cached = get_transient( 'etb_kymark_classes_cache' );
            if ( false !== $cached && is_array( $cached ) ) {
                return $cached;
            }
        }
        set_transient( 'etb_kymark_classes_cache', array(), HOUR_IN_SECONDS );
        return array();
    }

    public static function get_booking_types( $force_refresh = false ) {
        if ( ! $force_refresh ) {
            $cached = get_transient( 'etb_kymark_types_cache' );
            if ( false !== $cached && is_array( $cached ) ) {
                return $cached;
            }
        }
        set_transient( 'etb_kymark_types_cache', array(), HOUR_IN_SECONDS );
        return array();
    }

    public static function get_booking_statuses( $force_refresh = false ) {
        if ( ! $force_refresh ) {
            $cached = get_transient( 'etb_kymark_statuses_cache' );
            if ( false !== $cached && is_array( $cached ) ) {
                return $cached;
            }
        }
        set_transient( 'etb_kymark_statuses_cache', array(), HOUR_IN_SECONDS );
        return array();
    }

    /* ════════════════════════════════════════════════════════════════════
     *  MODULE PRICING — tarifs de transfert A→B depuis l'API Kymark
     *
     *  Modèle de données Kymark (confirmé par l'API réelle) :
     *   - /v1/pricing_zones       : zones géographiques avec polygones (zone_data.polygon)
     *   - /v1/zone_pricing_routes : prix par (pricing_group_id, from_zone_id, to_zone_id)
     *   - /v1/vehicles            : véhicules, chacun lié à un pricing_group_id
     *
     *  Une adresse (lat/lng) → zone par point-dans-polygone → prix de la route
     *  pour le pricing group du véhicule. Aucun calcul local : les prix viennent
     *  exclusivement de Kymark, toujours synchronisés.
     * ════════════════════════════════════════════════════════════════════ */

    /**
     * Requête GET paginée sur une ressource Kymark (1 crédit par page).
     *
     * @param string $resource Ex: 'pricing_zones', 'zone_pricing_routes', 'vehicles'
     * @return array|WP_Error  Lignes brutes ou erreur
     */
    private static function api_list_all( $resource ) {
        $settings = get_option( 'etb_general_settings', array() );
        $token    = $settings['kymark_api_token'] ?? '';
        if ( empty( $token ) ) {
            return new WP_Error( 'etb_kymark_no_token', 'Clé API Kymark manquante dans les réglages.' );
        }

        $all  = array();
        $page = 1;
        do {
            $response = wp_remote_get( self::API_BASE . '/' . $resource . '?page=' . $page . '&per_page=100', array(
                'headers' => array(
                    'Accept'    => 'application/json',
                    'X-API-Key' => $token,
                ),
                'timeout' => 15,
            ) );

            if ( is_wp_error( $response ) ) {
                return $response;
            }

            $status_code = wp_remote_retrieve_response_code( $response );
            $raw_body    = wp_remote_retrieve_body( $response );
            $body        = json_decode( $raw_body, true );

            if ( $status_code < 200 || $status_code >= 300 ) {
                $msg = '';
                if ( ! empty( $body['error'] ) ) {
                    $msg = $body['error'];
                } elseif ( ! empty( $body['message'] ) ) {
                    $msg = $body['message'];
                }
                return new WP_Error( 'etb_kymark_api', 'HTTP ' . $status_code . ( $msg ? ' : ' . $msg : '' ) );
            }

            $rows = isset( $body['data'] ) && is_array( $body['data'] ) ? $body['data'] : array();
            foreach ( $rows as $row ) {
                $all[] = $row;
            }
            $page++;
        } while ( count( $rows ) >= 100 && $page <= 20 );

        return $all;
    }

    /**
     * Cache générique pour les listes de pricing (6 h)
     *
     * @param string $transient Clé du transient
     * @param string $resource  Ressource Kymark
     * @param bool   $force_refresh Forcer le re-fetch (bouton Rafraîchir)
     * @return array|WP_Error
     */
    private static function fetch_list_cached( $transient, $resource, $force_refresh = false ) {
        if ( ! $force_refresh ) {
            $cached = get_transient( $transient );
            if ( false !== $cached && is_array( $cached ) ) {
                return $cached;
            }
        }

        $items = self::api_list_all( $resource );
        if ( is_wp_error( $items ) ) {
            return $items;
        }

        set_transient( $transient, $items, self::CACHE_TTL );
        return $items;
    }

    /** Zones tarifaires Kymark (polygones lat/lng) — cache 6 h */
    public static function get_zones( $force_refresh = false ) {
        return self::fetch_list_cached( 'etb_kymark_zones_cache', 'pricing_zones', $force_refresh );
    }

    /** Routes tarifaires A→B par pricing group — cache 6 h */
    public static function get_routes( $force_refresh = false ) {
        return self::fetch_list_cached( 'etb_kymark_routes_cache', 'zone_pricing_routes', $force_refresh );
    }

    /** Véhicules Kymark (liés à un pricing group) — cache 6 h */
    public static function get_vehicles( $force_refresh = false ) {
        return self::fetch_list_cached( 'etb_kymark_vehicles_cache', 'vehicles', $force_refresh );
    }

    /** Purge manuelle du cache pricing (bouton dans les réglages) */
    public static function refresh_pricing_cache() {
        delete_transient( 'etb_kymark_zones_cache' );
        delete_transient( 'etb_kymark_routes_cache' );
        delete_transient( 'etb_kymark_vehicles_cache' );
        delete_transient( self::SURGE_CACHE_KEY );
    }

    /* ════════════════════════════════════════════════════════════════════
     *  MODULE SURGE — majorations de prix (événements, périodes, jours…)
     *
     *  Kymark applique des règles de majoration aux prix des véhicules.
     *  L'API publique ne renvoie que la définition des règles :
     *   - /v1/surge_pricing_rules        : la règle (type, valeur, période)
     *   - /v1/surge_rule_pricing_groups  : les pricing groups ciblés
     *
     *  Moteur volontairement extensible :
     *   - nouveau `rule_type`  → ajouter un case dans surge_rule_matches()
     *   - nouveau `surge_type` → ajouter un case dans get_surge_modifier()
     *   - règle exotique gérée par un module externe → filtres
     *     `etb_kymark_surge_rule_matches` / `etb_kymark_surge_modifier`
     *
     *  Règles de combinaison (par défaut, surchargeables par filtre) :
     *   - multiplicateurs : cumulés (1.25 × 1.10)
     *   - montants fixes  : additionnés
     *   - prix final      = prix_route × multiplicateur + montant_fixe
     * ════════════════════════════════════════════════════════════════════ */

    /**
     * Règles de majoration + liens vers les pricing groups (cache 6 h).
     *
     * @param bool $force_refresh
     * @return array|WP_Error  Règles normalisées
     */
    public static function get_surge_rules( $force_refresh = false ) {
        if ( ! $force_refresh ) {
            $cached = get_transient( self::SURGE_CACHE_KEY );
            if ( is_array( $cached ) ) {
                return $cached;
            }
        }

        $raw_rules = self::api_list_all( 'surge_pricing_rules' );
        if ( is_wp_error( $raw_rules ) ) {
            // Fail-open : en cas d'incident API on ne bloque pas les ventes.
            // Cache court pour éviter de consommer un crédit à chaque visiteur.
            set_transient( self::SURGE_CACHE_KEY, array(), 10 * MINUTE_IN_SECONDS );
            if ( class_exists( 'ETB_Logger' ) ) {
                ETB_Logger::error( 'Surge rules Kymark indisponibles : ' . $raw_rules->get_error_message(), 'kymark' );
            }
            return array();
        }

        // Les liens sont optionnels : sans lien, la règle est globale.
        $links = self::api_list_all( 'surge_rule_pricing_groups' );
        if ( is_wp_error( $links ) ) {
            $links = array();
        }

        // Indexation tolérante : les noms exacts des colonnes de lien ne sont pas
        // documentés, on accepte les variantes plausibles.
        $groups_by_rule = array();
        foreach ( $links as $link ) {
            if ( ! is_array( $link ) ) {
                continue;
            }
            $rule_id  = (string) ( $link['surge_rule_id'] ?? $link['surge_pricing_rule_id'] ?? $link['rule_id'] ?? '' );
            $group_id = (string) ( $link['pricing_group_id'] ?? $link['pricing_group'] ?? $link['group_id'] ?? '' );
            if ( '' !== $rule_id && '' !== $group_id ) {
                $groups_by_rule[ $rule_id ][] = $group_id;
            }
        }

        $normalized = array();
        foreach ( $raw_rules as $rule ) {
            if ( ! is_array( $rule ) || empty( $rule['id'] ) ) {
                continue;
            }
            $id = (string) $rule['id'];

            $normalized[] = array(
                'id'           => $id,
                'name'         => (string) ( $rule['name'] ?? '' ),
                'enabled'      => ! empty( $rule['enabled'] ),
                'surge_type'   => strtolower( (string) ( $rule['surge_type'] ?? 'multiplier' ) ),
                'surge_value'  => (float) ( $rule['surge_value'] ?? 0 ),
                'rule_type'    => strtolower( (string) ( $rule['rule_type'] ?? '' ) ),
                'start_date'   => (string) ( $rule['start_date'] ?? '' ),
                'end_date'     => (string) ( $rule['end_date'] ?? '' ),
                'start_time'   => (string) ( $rule['start_time'] ?? '' ),
                'end_time'     => (string) ( $rule['end_time'] ?? '' ),
                'days_of_week' => array_values( array_map( 'intval', (array) ( $rule['days_of_week'] ?? array() ) ) ),
                'group_ids'    => $groups_by_rule[ $id ] ?? array(),
                'is_demo'      => ! empty( $rule['is_demo'] ),
            );
        }

        set_transient( self::SURGE_CACHE_KEY, $normalized, self::CACHE_TTL );
        return $normalized;
    }

    /** Purge ciblée du cache surge */
    public static function refresh_surge_cache() {
        delete_transient( self::SURGE_CACHE_KEY );
    }

    /**
     * Convertit une date + heure de prise en charge (fuseau du site) en timestamp.
     * Accepte "09:00 PM" (sélecteur 12 h du widget) comme "21:00".
     */
    public static function parse_pickup_timestamp( $date, $time = '' ) {
        $date = trim( (string) $date );
        $time = trim( (string) $time );

        if ( '' === $date ) {
            return time();
        }
        if ( '' === $time ) {
            $time = '00:00';
        }

        try {
            $dt = new DateTimeImmutable( $date . ' ' . $time, wp_timezone() );
            return $dt->getTimestamp();
        } catch ( Exception $e ) {
            return time();
        }
    }

    /**
     * Modificateur applicable à un pricing group à un instant donné.
     *
     * @param string   $group_id  Pricing group du véhicule
     * @param int|null $timestamp Prise en charge (défaut : maintenant)
     * @param array|null $rules   Règles pré-chargées (évite N lectures de cache)
     * @return array{ multiplier: float, surcharge: float, applied: array }
     */
    public static function get_surge_modifier( $group_id, $timestamp = null, $rules = null ) {
        $modifier = array(
            'multiplier' => 1.0,
            'surcharge'  => 0.0,
            'applied'    => array(),
        );

        if ( null === $rules ) {
            $rules = self::get_surge_rules();
        }
        if ( is_wp_error( $rules ) || empty( $rules ) ) {
            return $modifier;
        }

        $timestamp = ! empty( $timestamp ) ? (int) $timestamp : time();

        foreach ( $rules as $rule ) {
            if ( ! self::surge_rule_matches( $rule, $timestamp, $group_id ) ) {
                continue;
            }

            $value = (float) $rule['surge_value'];
            if ( $value <= 0 ) {
                continue;
            }

            switch ( $rule['surge_type'] ) {
                case 'multiplier':
                case 'factor':
                    $modifier['multiplier'] *= $value;
                    break;

                case 'percentage':
                case 'percent':
                    $modifier['multiplier'] *= ( 1 + ( $value / 100 ) );
                    break;

                case 'fixed':
                case 'amount':
                case 'fixed_amount':
                case 'surcharge':
                    $modifier['surcharge'] += $value;
                    break;

                default:
                    // Type inconnu → ignoré (jamais de surtaxe par erreur)
                    continue 2;
            }

            $modifier['applied'][] = array(
                'id'    => $rule['id'],
                'name'  => $rule['name'],
                'type'  => $rule['surge_type'],
                'value' => $value,
            );
        }

        return apply_filters( 'etb_kymark_surge_modifier', $modifier, $group_id, $timestamp );
    }

    /** Applique un modificateur à un prix de route. */
    public static function apply_surge( $price, $modifier ) {
        $price = (float) $price;
        if ( $price <= 0 || ! is_array( $modifier ) ) {
            return $price;
        }

        $multiplier = isset( $modifier['multiplier'] ) ? (float) $modifier['multiplier'] : 1.0;
        $surcharge  = isset( $modifier['surcharge'] ) ? (float) $modifier['surcharge'] : 0.0;

        if ( $multiplier <= 0 ) {
            $multiplier = 1.0;
        }

        return (float) round( ( $price * $multiplier ) + $surcharge, 2 );
    }

    /**
     * Une règle s'applique-t-elle à ce pricing group à cet instant ?
     * Chaque `rule_type` est isolé pour que l'ajout d'un nouveau type soit trivial.
     */
    private static function surge_rule_matches( $rule, $timestamp, $group_id ) {
        if ( empty( $rule['enabled'] ) ) {
            return false;
        }

        // Périmètre : règle liée à des pricing groups → uniquement ceux-là.
        // Aucun lien → règle globale (comportement observé : la règle Cannes Lions
        // s'applique alors que surge_rule_pricing_groups est vide).
        if ( ! empty( $rule['group_ids'] ) && ! in_array( (string) $group_id, $rule['group_ids'], true ) ) {
            return false;
        }

        $dt = ( new DateTimeImmutable( '@' . (int) $timestamp ) )->setTimezone( wp_timezone() );

        switch ( $rule['rule_type'] ) {
            case 'specific_dates':
            case 'date_range':
            case 'dates':
                $matched = self::surge_match_date_range( $rule, $dt );
                break;

            case 'days_of_week':
            case 'weekly':
                $matched = self::surge_match_days( $rule, $dt );
                break;

            case 'time_range':
            case 'time':
                $matched = self::surge_match_time( $rule, $dt );
                break;

            case 'always':
            case 'all':
                $matched = true;
                break;

            default:
                $matched = false;
                break;
        }

        return (bool) apply_filters( 'etb_kymark_surge_rule_matches', $matched, $rule, $timestamp, $group_id );
    }

    /** Période de dates (bornes incluses) + éventuelle plage horaire. */
    private static function surge_match_date_range( $rule, DateTimeImmutable $dt ) {
        $start = (string) $rule['start_date'];
        if ( '' === $start ) {
            return false;
        }
        $end  = '' !== (string) $rule['end_date'] ? (string) $rule['end_date'] : $start;
        $date = $dt->format( 'Y-m-d' );

        if ( $date < $start || $date > $end ) {
            return false;
        }
        return self::surge_match_time( $rule, $dt );
    }

    /** Jours de la semaine (0-6 dimanche=0, ou 1-7 ISO) + dates/plage horaire. */
    private static function surge_match_days( $rule, DateTimeImmutable $dt ) {
        $days = (array) $rule['days_of_week'];

        if ( ! empty( $days ) ) {
            $iso = false;
            foreach ( $days as $d ) {
                if ( (int) $d >= 7 ) {
                    $iso = true;
                    break;
                }
            }
            $today = (int) $dt->format( 'w' ); // 0 = dimanche
            $match = false;
            foreach ( $days as $d ) {
                $normalized = $iso ? ( (int) $d % 7 ) : (int) $d;
                if ( $normalized === $today ) {
                    $match = true;
                    break;
                }
            }
            if ( ! $match ) {
                return false;
            }
        }

        // Si une période de dates est également renseignée, elle reste prioritaire.
        if ( '' !== (string) $rule['start_date'] ) {
            $end  = '' !== (string) $rule['end_date'] ? (string) $rule['end_date'] : (string) $rule['start_date'];
            $date = $dt->format( 'Y-m-d' );
            if ( $date < $rule['start_date'] || $date > $end ) {
                return false;
            }
        }

        return self::surge_match_time( $rule, $dt );
    }

    /** Plage horaire optionnelle (gère le chevauchement minuit). */
    private static function surge_match_time( $rule, DateTimeImmutable $dt ) {
        $start = (string) $rule['start_time'];
        $end   = (string) $rule['end_time'];

        if ( '' === $start && '' === $end ) {
            return true; // pas de restriction horaire → toute la journée
        }

        $now   = $dt->format( 'H:i:s' );
        $start = '' !== $start ? $start : '00:00:00';
        $end   = '' !== $end ? $end : '23:59:59';

        if ( $start <= $end ) {
            return ( $now >= $start && $now <= $end );
        }
        return ( $now >= $start || $now <= $end );
    }

    /**
     * Test point-dans-polygone (ray casting) sur un polygone Kymark
     * polygon = [ { lat, lng }, ... ]
     */
    private static function point_in_polygon( $lat, $lng, $polygon ) {
        if ( empty( $polygon ) || ! is_array( $polygon ) ) {
            return false;
        }
        $inside = false;
        $n      = count( $polygon );
        for ( $i = 0, $j = $n - 1; $i < $n; $j = $i++ ) {
            $lat_i = (float) $polygon[ $i ]['lat'];
            $lng_i = (float) $polygon[ $i ]['lng'];
            $lat_j = (float) $polygon[ $j ]['lat'];
            $lng_j = (float) $polygon[ $j ]['lng'];

            if ( ( ( $lat_i > $lat ) !== ( $lat_j > $lat ) ) &&
                 ( $lng < ( $lng_j - $lng_i ) * ( $lat - $lat_i ) / ( $lat_j - $lat_i ) + $lng_i ) ) {
                $inside = ! $inside;
            }
        }
        return $inside;
    }

    /**
     * Trouve la zone Kymark contenant un point (lat/lng)
     *
     * @return array|null La zone (id, name...) ou null si hors zone
     */
    public static function resolve_zone( $lat, $lng, $zones ) {
        if ( ! is_array( $zones ) ) {
            return null;
        }
        foreach ( $zones as $zone ) {
            $zone_data = isset( $zone['zone_data'] ) && is_array( $zone['zone_data'] ) ? $zone['zone_data'] : array();
            $polygon   = isset( $zone_data['polygon'] ) && is_array( $zone_data['polygon'] ) ? $zone_data['polygon'] : array();
            if ( self::point_in_polygon( (float) $lat, (float) $lng, $polygon ) ) {
                return $zone;
            }
        }
        return null;
    }

    /**
     * Tarifs de transfert A→B pour tous les véhicules Kymark.
     *
     * @param float $from_lat / $from_lng / $to_lat / $to_lng
     * @param int|null $pickup_ts Timestamp de prise en charge (pour les règles de majoration)
     * @return array {
     *     zone_found: bool — false si un point est hors des zones Kymark,
     *     from_zone / to_zone: noms des zones résolues,
     *     surge_active: bool — au moins une règle de majoration appliquée,
     *     vehicles: [ { kymark_vehicle_id, name, capacity, type, base_price, price, surge }, ... ]
     * }
     * @return WP_Error en cas d'échec API
     */
    public static function get_transfer_quote( $from_lat, $from_lng, $to_lat, $to_lng, $pickup_ts = null ) {
        $zones    = self::get_zones();
        if ( is_wp_error( $zones ) ) {
            return $zones;
        }
        $routes   = self::get_routes();
        if ( is_wp_error( $routes ) ) {
            return $routes;
        }
        $vehicles = self::get_vehicles();
        if ( is_wp_error( $vehicles ) ) {
            return $vehicles;
        }

        $result = array(
            'zone_found'   => false,
            'from_zone'    => '',
            'to_zone'      => '',
            'surge_active' => false,
            'vehicles'     => array(),
        );

        $from_zone = self::resolve_zone( $from_lat, $from_lng, $zones );
        $to_zone   = self::resolve_zone( $to_lat, $to_lng, $zones );
        if ( empty( $from_zone ) || empty( $to_zone ) ) {
            return $result; // Hors zone → l'appelant bascule en mode Devis / Upon Request
        }

        $result['zone_found'] = true;
        $result['from_zone']  = $from_zone['name'] ?? '';
        $result['to_zone']    = $to_zone['name'] ?? '';

        // Prix par pricing group pour la route (from_zone → to_zone)
        $prices_by_group = array();
        foreach ( $routes as $route ) {
            if ( ( $route['from_zone_id'] ?? '' ) === $from_zone['id'] && ( $route['to_zone_id'] ?? '' ) === $to_zone['id'] ) {
                $group_id = $route['pricing_group_id'] ?? '';
                $price    = floatval( $route['price'] ?? 0 );
                if ( $group_id && $price > 0 ) {
                    $prices_by_group[ $group_id ] = $price;
                }
            }
        }

        // Véhicules disponibles — TOUS sont renvoyés (même sans tarif pour cette route) :
        // ceux avec un prix Kymark sont tarifés, ceux à 0 sont gérés côté JS
        // (affichés « Upon Request » si la carte WP est marquée data-transfer-quote-only,
        // sinon masqués). Un véhicule sans tarif ne doit jamais disparaître s'il est
        // forcé « Upon Request » dans l'admin WP.
        // Règles de majoration chargées une seule fois pour tout le devis
        // (cache 6 h ; fail-open : en cas d'erreur, aucun supplément appliqué).
        $surge_rules = self::get_surge_rules();
        if ( is_wp_error( $surge_rules ) ) {
            $surge_rules = array();
        }
        $pickup_ts = ! empty( $pickup_ts ) ? (int) $pickup_ts : time();

        foreach ( $vehicles as $vehicle ) {
            if ( strtolower( (string) ( $vehicle['status'] ?? '' ) ) !== 'available' ) {
                continue;
            }
            $group_id  = $vehicle['pricing_group_id'] ?? '';
            $base_price = isset( $prices_by_group[ $group_id ] ) ? (float) $prices_by_group[ $group_id ] : 0.0;

            // Majoration éventuelle (événement, période, jour de la semaine…)
            $modifier = self::get_surge_modifier( $group_id, $pickup_ts, $surge_rules );
            $price    = self::apply_surge( $base_price, $modifier );

            if ( ! empty( $modifier['applied'] ) && $base_price > 0 ) {
                $result['surge_active'] = true;
            }

            $result['vehicles'][] = array(
                'kymark_vehicle_id' => $vehicle['id'] ?? '',
                'name'              => $vehicle['name'] ?? '',
                'capacity'          => intval( $vehicle['capacity'] ?? 0 ),
                'type'              => $vehicle['type'] ?? '',
                'base_price'        => $base_price,
                'price'             => $price,
                'surge'             => ! empty( $modifier['applied'] ) ? $modifier : null,
            );
        }

        return $result;
    }

    /* ═══════════════════════════════════════════════════════════════════
     *  MODULE NOTIFICATIONS KYMARK — emails automatiques comme le widget natif
     *
     *  Configure les préférences de notification et email automations de Kymark
     *  pour que Kymark envoie les emails de création, modification, statut
     *  et paiement, exactement comme son widget natif.
     * ════════════════════════════════════════════════════════════════════ */

    const NOTIF_CACHE_KEY = 'etb_kymark_notification_preferences';
    const EMAIL_AUTO_CACHE_KEY = 'etb_kymark_email_automations';

    /**
     * Enregistre la page d'admin des notifications Kymark.
     */
    public static function register_notification_admin_page() {
        add_submenu_page(
            'edit.php?post_type=tour_booking',
            'Notifications Kymark',
            'Notifications Kymark',
            'manage_options',
            'etb-kymark-notifications',
            array( __CLASS__, 'render_notification_settings' )
        );
    }

    /**
     * Récupère les préférences de notification Kymark.
     * GET /v1/notification_preferences
     */
    public static function get_notification_preferences( $force_refresh = false ) {
        if ( ! $force_refresh ) {
            $cached = get_transient( self::NOTIF_CACHE_KEY );
            if ( false !== $cached && is_array( $cached ) ) {
                return $cached;
            }
        }

        $settings = get_option( 'etb_general_settings', array() );
        $token    = $settings['kymark_api_token'] ?? '';
        if ( empty( $token ) ) {
            return new WP_Error( 'etb_kymark_no_token', 'Clé API Kymark manquante.' );
        }

        $response = wp_remote_get( self::API_BASE . '/notification_preferences', array(
            'headers' => array(
                'Accept'    => 'application/json',
                'X-API-Key' => $token,
            ),
            'timeout' => 15,
        ) );

        if ( is_wp_error( $response ) ) {
            return $response;
        }

        $status_code = wp_remote_retrieve_response_code( $response );
        $body        = json_decode( wp_remote_retrieve_body( $response ), true );

        if ( $status_code < 200 || $status_code >= 300 ) {
            return new WP_Error( 'etb_kymark_api', 'HTTP ' . $status_code );
        }

        $prefs = isset( $body['data'] ) && is_array( $body['data'] ) ? $body['data'] : array();
        set_transient( self::NOTIF_CACHE_KEY, $prefs, HOUR_IN_SECONDS );
        return $prefs;
    }

    /**
     * Récupère les automatisations email Kymark.
     * GET /v1/email_automations
     */
    public static function get_email_automations( $force_refresh = false ) {
        if ( ! $force_refresh ) {
            $cached = get_transient( self::EMAIL_AUTO_CACHE_KEY );
            if ( false !== $cached && is_array( $cached ) ) {
                return $cached;
            }
        }

        $settings = get_option( 'etb_general_settings', array() );
        $token    = $settings['kymark_api_token'] ?? '';
        if ( empty( $token ) ) {
            return new WP_Error( 'etb_kymark_no_token', 'Clé API Kymark manquante.' );
        }

        $response = wp_remote_get( self::API_BASE . '/email_automations', array(
            'headers' => array(
                'Accept'    => 'application/json',
                'X-API-Key' => $token,
            ),
            'timeout' => 15,
        ) );

        if ( is_wp_error( $response ) ) {
            return $response;
        }

        $status_code = wp_remote_retrieve_response_code( $response );
        $body        = json_decode( wp_remote_retrieve_body( $response ), true );

        if ( $status_code < 200 || $status_code >= 300 ) {
            return new WP_Error( 'etb_kymark_api', 'HTTP ' . $status_code );
        }

        $autos = isset( $body['data'] ) && is_array( $body['data'] ) ? $body['data'] : array();
        set_transient( self::EMAIL_AUTO_CACHE_KEY, $autos, HOUR_IN_SECONDS );
        return $autos;
    }

    /**
     * Met à jour une préférence de notification Kymark.
     * PATCH /v1/notification_preferences/{id}
     *
     * Les champs envoyés sont ceux retournés par GET (pas de devinage).
     */
    public static function update_notification_preference( $id, $data ) {
        $settings = get_option( 'etb_general_settings', array() );
        $token    = $settings['kymark_api_token'] ?? '';
        if ( empty( $token ) ) {
            return new WP_Error( 'etb_kymark_no_token', 'Clé API Kymark manquante.' );
        }

        $response = self::api_request( 'PATCH', '/notification_preferences/' . rawurlencode( (string) $id ), $data );
        if ( is_wp_error( $response ) ) {
            return $response;
        }

        delete_transient( self::NOTIF_CACHE_KEY );
        return $response;
    }

    /**
     * Met à jour une automatisation email Kymark.
     * PATCH /v1/email_automations/{id}
     */
    public static function update_email_automation( $id, $data ) {
        $settings = get_option( 'etb_general_settings', array() );
        $token    = $settings['kymark_api_token'] ?? '';
        if ( empty( $token ) ) {
            return new WP_Error( 'etb_kymark_no_token', 'Clé API Kymark manquante.' );
        }

        $response = self::api_request( 'PATCH', '/email_automations/' . rawurlencode( (string) $id ), $data );
        if ( is_wp_error( $response ) ) {
            return $response;
        }

        delete_transient( self::EMAIL_AUTO_CACHE_KEY );
        return $response;
    }

    /**
     * Affiche la page d'admin des notifications Kymark.
     */
    public static function render_notification_settings() {
        $settings = get_option( 'etb_general_settings', array() );
        if ( empty( $settings['kymark_api_token'] ) ) {
            echo '<div class="notice notice-error"><p>Veuillez configurer la clé API Kymark d\'abord.</p></div>';
            return;
        }

        $prefs = self::get_notification_preferences();
        $autos = self::get_email_automations();

        if ( is_wp_error( $prefs ) ) {
            echo '<div class="notice notice-error"><p>Erreur Kymark : ' . esc_html( $prefs->get_error_message() ) . '</p></div>';
            return;
        }

        ?>
        <div class="wrap">
            <h1>Notifications Kymark</h1>
            <p>Configurez les emails envoyés automatiquement par Kymark (création, modification, statut, paiement), exactement comme son widget natif.</p>

            <h2>Préférences de notification</h2>
            <?php if ( empty( $prefs ) ) : ?>
                <p>Aucune préférence de notification retournée par l'API Kymark.</p>
            <?php else : ?>
                <table class="wp-list-table widefixed fixed striped">
                    <thead><tr><th>ID</th><th>Type</th><th>Événement</th><th>Activé</th><th>Action</th></tr></thead>
                    <tbody>
                    <?php foreach ( $prefs as $pref ) : ?>
                        <tr>
                            <td><?php echo esc_html( $pref['id'] ?? '' ); ?></td>
                            <td><?php echo esc_html( $pref['type'] ?? $pref['name'] ?? '' ); ?></td>
                            <td><?php echo esc_html( $pref['event'] ?? $pref['name'] ?? '' ); ?></td>
                            <td><?php echo ! empty( $pref['enabled'] ) ? 'Oui' : 'Non'; ?></td>
                            <td>
                                <button class="button button-small etb-kymark-toggle-notif" data-id="<?php echo esc_attr( $pref['id'] ?? '' ); ?>" data-enabled="<?php echo ! empty( $pref['enabled'] ) ? '1' : '0'; ?>" data-type="preference"><?php echo ! empty( $pref['enabled'] ) ? 'Désactiver' : 'Activer'; ?></button>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>

            <h2>Email Automations</h2>
            <?php if ( empty( $autos ) ) : ?>
                <p>Aucune automatisation email retournée par l'API Kymark.</p>
            <?php else : ?>
                <table class="wp-list-table widefixed fixed striped">
                    <thead><tr><th>ID</th><th>Nom</th><th>Déclencheur</th><th>Activé</th><th>Action</th></tr></thead>
                    <tbody>
                    <?php foreach ( $autos as $auto ) : ?>
                        <tr>
                            <td><?php echo esc_html( $auto['id'] ?? '' ); ?></td>
                            <td><?php echo esc_html( $auto['name'] ?? '' ); ?></td>
                            <td><?php echo esc_html( $auto['trigger'] ?? $auto['event'] ?? '' ); ?></td>
                            <td><?php echo ! empty( $auto['enabled'] ) ? 'Oui' : 'Non'; ?></td>
                            <td>
                                <button class="button button-small etb-kymark-toggle-notif" data-id="<?php echo esc_attr( $auto['id'] ?? '' ); ?>" data-enabled="<?php echo ! empty( $auto['enabled'] ) ? '1' : '0'; ?>" data-type="email_automation"><?php echo ! empty( $auto['enabled'] ) ? 'Désactiver' : 'Activer'; ?></button>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>

        <script>
        jQuery(document).ready(function($) {
            $('.etb-kymark-toggle-notif').on('click', function() {
                var btn = $(this);
                var id = btn.data('id');
                var currentEnabled = btn.data('enabled') === '1';
                var newEnabled = !currentEnabled;
                var type = btn.data('type') || 'preference';

                btn.prop('disabled', true).text('...');

                $.post(ajaxurl, {
                    action: 'etb_kymark_toggle_notification',
                    id: id,
                    enabled: newEnabled ? 1 : 0,
                    type: type,
                    nonce: '<?php echo wp_create_nonce('etb_kymark_notification'); ?>'
                }, function(response) {
                    btn.prop('disabled', false);
                    if (response.success) {
                        btn.data('enabled', newEnabled ? '1' : '0');
                        btn.text(newEnabled ? 'Désactiver' : 'Activer');
                    } else {
                        alert('Erreur : ' + (response.data && response.data.message ? response.data.message : 'inconnue'));
                    }
                });
            });
        });
        </script>
        <?php
    }

    /**
     * AJAX handler pour activer/désactiver une notification Kymark.
     */
    public static function ajax_toggle_notification() {
        check_ajax_referer( 'etb_kymark_notification', 'nonce' );

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( array( 'message' => 'Permission refusée.' ) );
        }

        $id      = sanitize_text_field( $_POST['id'] ?? '' );
        $enabled = ! empty( $_POST['enabled'] ) && '1' === $_POST['enabled'];
        $type    = sanitize_text_field( $_POST['type'] ?? 'preference' );

        if ( empty( $id ) ) {
            wp_send_json_error( array( 'message' => 'ID manquant.' ) );
        }

        $data = array( 'enabled' => $enabled );

        if ( 'preference' === $type ) {
            $res = self::update_notification_preference( $id, $data );
        } else {
            $res = self::update_email_automation( $id, $data );
        }

        if ( is_wp_error( $res ) ) {
            wp_send_json_error( array( 'message' => $res->get_error_message() ) );
        }

        wp_send_json_success( array( 'message' => 'Mis à jour.' ) );
    }
}
