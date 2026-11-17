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

        // 3. Client Kymark (création ou détection)
        $client_id = self::get_or_create_client( $data, $settings, $booking_id );

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

        $is_paid = ! empty( $data['paid'] ) || ! empty( $data['payment_intent_id'] );

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

        // 6. Construction du payload Kymark (champs vus dans l'exemple POST /v1/shuttle_bookings)
        // trip_id est omis : généré par Kymark à la création (à confirmer)
        $payload = array(
            'rider_name'             => $rider_name,
            'rider_email'            => $rider_email,
            'rider_phone'            => $rider_phone,
            'seat_count'             => $total_passengers,
            'seat_price'             => (float) round( $base_ride_price, 2 ),
            'total_price'            => (float) round( $grand_total, 2 ),
            'status'                 => $is_paid ? 'confirmed' : 'pending',
            'payment_status'         => $is_paid ? 'paid' : 'pending',
            'notes'                  => $notes,
            'stripe_payment_intent_id' => ! empty( $data['payment_intent_id'] ) ? $data['payment_intent_id'] : '',
            'stripe_charge_id'       => ! empty( $data['payment_intent_id'] ) ? $data['payment_intent_id'] : '',
        );

        // 7. Envoi vers l'API Kymark
        $response = wp_remote_post( self::API_ENDPOINT, array(
            'headers' => array(
                'Content-Type'  => 'application/json',
                'Accept'        => 'application/json',
                'X-API-Key'     => $api_token,
            ),
            'body'    => wp_json_encode( $payload ),
            'timeout' => 15,
        ) );

        if ( is_wp_error( $response ) ) {
            update_post_meta( $booking_id, '_etb_kymark_status', 'failed' );
            update_post_meta( $booking_id, '_etb_kymark_error', 'Erreur HTTP Kymark: ' . $response->get_error_message() );
            return false;
        }

        $status_code = wp_remote_retrieve_response_code( $response );
        $raw_body    = wp_remote_retrieve_body( $response );
        $body        = json_decode( $raw_body, true );

        if ( $status_code >= 200 && $status_code < 300 ) {
            // Extraction défensive de l'ID retourné par Kymark (champ exact à confirmer)
            $kymark_id   = '';
            $kymark_ref  = '';

            if ( ! empty( $body['data'] ) && is_array( $body['data'] ) ) {
                $kymark_id = ! empty( $body['data']['id'] ) ? sanitize_text_field( $body['data']['id'] ) : '';
                $kymark_ref = ! empty( $body['data']['reference'] ) ? sanitize_text_field( $body['data']['reference'] ) :
                              ( ! empty( $body['data']['booking_id'] ) ? sanitize_text_field( $body['data']['booking_id'] ) : '' );
            } else {
                $kymark_id = ! empty( $body['id'] ) ? sanitize_text_field( $body['id'] ) : '';
                $kymark_ref = ! empty( $body['reference'] ) ? sanitize_text_field( $body['reference'] ) :
                              ( ! empty( $body['booking_id'] ) ? sanitize_text_field( $body['booking_id'] ) : '' );
            }

            if ( empty( $kymark_ref ) && ! empty( $kymark_id ) ) {
                $kymark_ref = $kymark_id;
            }

            update_post_meta( $booking_id, '_etb_kymark_status', 'synced' );
            update_post_meta( $booking_id, '_etb_kymark_booking_id', $kymark_ref ?: 'synced-' . $booking_id );
            if ( ! empty( $kymark_id ) ) {
                update_post_meta( $booking_id, '_etb_kymark_uuid', $kymark_id );
            }
            delete_post_meta( $booking_id, '_etb_kymark_error' );
            return true;
        }

        // Échec : extraction du message d'erreur
        $err_details = '';
        if ( ! empty( $body['error'] ) ) {
            $err_details = $body['error'];
        } elseif ( ! empty( $body['message'] ) ) {
            $err_details = $body['message'];
        } elseif ( ! empty( $body['fields'] ) && is_array( $body['fields'] ) ) {
            $parts = array();
            foreach ( $body['fields'] as $f ) {
                $parts[] = ! empty( $f['field'] ) ? $f['field'] . ': ' . ( $f['message'] ?? '' ) : '';
            }
            $err_details = implode( '; ', $parts );
        } else {
            $err_details = substr( strip_tags( $raw_body ), 0, 200 );
        }

        update_post_meta( $booking_id, '_etb_kymark_status', 'failed' );
        update_post_meta( $booking_id, '_etb_kymark_error', sprintf( 'HTTP %s (Kymark): %s', $status_code, $err_details ) );
        return false;
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
}
