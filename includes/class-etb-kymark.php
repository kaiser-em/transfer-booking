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
     * et chaque refresh coûte 3 crédits GET (1 par ressource).
     */
    const CACHE_TTL = 6 * HOUR_IN_SECONDS;

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

        // Bouton « Rafraîchir les données Kymark » (3 crédits GET : zones + routes + véhicules)
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
                    <p style="color: #166534; margin-top: 0;">✅ Cache Kymark rafraîchi (zones, routes tarifaires, véhicules).</p>
                <?php elseif ( ! empty( $kymark_refresh_error ) ) : ?>
                    <p style="color: #991b1b; margin-top: 0;">⚠️ Erreur Kymark : <?php echo esc_html( $kymark_refresh_error ); ?></p>
                <?php endif; ?>
                <a href="<?php echo esc_url( $kymark_refresh_url ); ?>" class="button button-secondary">
                    🔄 Rafraîchir les données Kymark
                </a>
                <p class="description">Recharge les zones tarifaires, les routes A→B et les véhicules depuis l'API Kymark. Coût : 3 crédits GET (1 par ressource). Cache valable 6 h — les visiteurs du site ne consomment aucun crédit.</p>
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

        // 8. ÉTAPE 1 — Création de la course : POST /v1/trips
        //    Champs validés : pickup_address, dropoff_address, pickup_date (obligatoires)
        $trip_payload = array(
            'pickup_address'  => $pickup_address,
            'dropoff_address' => $dropoff_address,
            'pickup_date'     => $pickup_iso,
            'passenger_count' => $total_passengers,
            'price'           => (float) round( $grand_total, 2 ),
            'status'          => 'confirmed',
            'payment_status'  => $is_paid ? 'paid' : 'unpaid',
            'notes'           => $notes,
        );
        if ( ! empty( $vehicle_type ) ) {
            $trip_payload['vehicle_type'] = $vehicle_type;
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

        // 8bis. Marquer aussi le trip comme payé (PATCH /v1/trips/{id})
        $trip_uuid = get_post_meta( $booking_id, '_etb_kymark_trip_uuid', true );
        if ( ! empty( $trip_uuid ) ) {
            $trip_payload = array(
                'payment_status' => 'paid',
                'status'         => 'confirmed',
            );
            if ( ! empty( $stripe_intent_id ) ) {
                $trip_payload['stripe_payment_intent_id'] = (string) $stripe_intent_id;
                $trip_payload['stripe_charge_id']         = (string) $stripe_intent_id;
            }
            $trip_res = self::api_request( 'PATCH', '/trips/' . rawurlencode( $trip_uuid ), $trip_payload );
            if ( is_wp_error( $trip_res ) ) {
                // Non bloquant : le shuttle_booking est déjà à jour. On note l'avertissement.
                update_post_meta( $booking_id, '_etb_kymark_trip_patch_error', $trip_res->get_error_message() );
            } else {
                delete_post_meta( $booking_id, '_etb_kymark_trip_patch_error' );
            }
        }

        return array( 'success' => true, 'message' => 'Paiement synchronisé vers Kymark.' );
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

        // 1. Recherche par email
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

        // 2. Création du customer (champs standards — à adapter si l'API exige d'autres noms de champs)
        $create = self::api_request( 'POST', '/customers', array(
            'name'  => $name,
            'email' => $email,
            'phone' => $phone,
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
     * @return array {
     *     zone_found: bool — false si un point est hors des zones Kymark,
     *     from_zone / to_zone: noms des zones résolues,
     *     vehicles: [ { kymark_vehicle_id, name, capacity, type, price }, ... ] (uniquement les véhicules tarifés)
     * }
     * @return WP_Error en cas d'échec API
     */
    public static function get_transfer_quote( $from_lat, $from_lng, $to_lat, $to_lng ) {
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
            'zone_found' => false,
            'from_zone'  => '',
            'to_zone'    => '',
            'vehicles'   => array(),
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

        // Véhicules disponibles dont le pricing group a un tarif pour cette route
        foreach ( $vehicles as $vehicle ) {
            if ( strtolower( (string) ( $vehicle['status'] ?? '' ) ) !== 'available' ) {
                continue;
            }
            $group_id = $vehicle['pricing_group_id'] ?? '';
            if ( ! $group_id || ! isset( $prices_by_group[ $group_id ] ) ) {
                continue;
            }
            $result['vehicles'][] = array(
                'kymark_vehicle_id' => $vehicle['id'] ?? '',
                'name'              => $vehicle['name'] ?? '',
                'capacity'          => intval( $vehicle['capacity'] ?? 0 ),
                'type'              => $vehicle['type'] ?? '',
                'price'             => $prices_by_group[ $group_id ],
            );
        }

        return $result;
    }
}
