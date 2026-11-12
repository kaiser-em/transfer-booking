<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Import / Export de la configuration complète du plugin.
 *
 * Exporte : réglages (options WP) + véhicules, options, points de départ, codes promo
 * (CPTs + meta + images). Les réservations (tour_booking) ne sont jamais exportées.
 */
class ETB_Import_Export {

    const FORMAT_VERSION = 1;
    const NONCE_ACTION   = 'etb_import_export';
    const MAX_FILE_SIZE  = 5242880; // 5 Mo

    /** CPTs de configuration, dans l'ordre d'import (les extras avant les véhicules). */
    const CONFIG_POST_TYPES = array( 'tour_extra', 'tour_pickup', 'tour_promo', 'tour_vehicle' );

    /** Clés de réglages considérées comme sensibles (exclues si l'admin le demande). */
    const SECRET_SETTING_KEYS = array(
        'stripe_secret_key',
        'stripe_webhook_secret',
        'google_maps_api_key',
        'mapbox_token',
        'limo_api_token',
    );

    /** Meta internes WordPress à ne jamais exporter. */
    const SKIP_META_KEYS = array(
        '_edit_lock',
        '_edit_last',
        '_wp_old_slug',
        '_wp_page_template',
        '_thumbnail_id',
        '_encloseme',
        '_pingme',
    );

    public function __construct() {
        add_action( 'admin_post_etb_export_config', array( $this, 'handle_export' ) );
        add_action( 'admin_post_etb_import_config', array( $this, 'handle_import' ) );
    }

    // ---------------------------------------------------------------------
    // EXPORT
    // ---------------------------------------------------------------------

    public function handle_export() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( 'Autorisation refusée.', 403 );
        }
        check_admin_referer( self::NONCE_ACTION );

        $include_secrets = ! empty( $_POST['include_secrets'] );
        $payload         = $this->build_export_payload( $include_secrets );

        $filename = 'etb-config-' . gmdate( 'Ymd-His' ) . '.json';

        nocache_headers();
        header( 'Content-Type: application/json; charset=utf-8' );
        header( 'Content-Disposition: attachment; filename="' . $filename . '"' );

        echo wp_json_encode( $payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
        exit;
    }

    /**
     * Construit le tableau complet de configuration.
     */
    public function build_export_payload( $include_secrets = true ) {
        $general = get_option( 'etb_general_settings', array() );
        $form    = get_option( 'etb_form_settings', array() );

        if ( ! $include_secrets && is_array( $general ) ) {
            foreach ( self::SECRET_SETTING_KEYS as $secret_key ) {
                unset( $general[ $secret_key ] );
            }
        }

        $content = array();
        foreach ( self::CONFIG_POST_TYPES as $post_type ) {
            $content[ $post_type ] = $this->export_post_type( $post_type );
        }

        return array(
            'plugin'         => 'elite-transfer-booking',
            'format_version' => self::FORMAT_VERSION,
            'plugin_version' => defined( 'ETB_VERSION' ) ? ETB_VERSION : '',
            'exported_at'    => gmdate( 'c' ),
            'site_url'       => home_url(),
            'settings'       => array(
                'etb_general_settings' => is_array( $general ) ? $general : array(),
                'etb_form_settings'    => is_array( $form ) ? $form : array(),
            ),
            'content'        => $content,
        );
    }

    private function export_post_type( $post_type ) {
        $posts = get_posts( array(
            'post_type'      => $post_type,
            'post_status'    => array( 'publish', 'draft', 'pending', 'private' ),
            'posts_per_page' => -1,
            'orderby'        => 'menu_order title',
            'order'          => 'ASC',
        ) );

        $items = array();
        foreach ( $posts as $post ) {
            $item = array(
                'title'      => $post->post_title,
                'slug'       => $post->post_name,
                'status'     => $post->post_status,
                'menu_order' => (int) $post->menu_order,
                'meta'       => $this->export_post_meta( $post->ID ),
            );

            $thumb_id = get_post_thumbnail_id( $post->ID );
            if ( $thumb_id ) {
                $item['thumbnail_url'] = wp_get_attachment_url( $thumb_id );
            }

            // Les liaisons véhicule → options utilisent des IDs : on les exporte en slugs.
            if ( 'tour_vehicle' === $post_type ) {
                $allowed = get_post_meta( $post->ID, '_etb_allowed_extras', true );
                $slugs   = array();
                if ( is_array( $allowed ) ) {
                    foreach ( $allowed as $extra_id ) {
                        $extra = get_post( absint( $extra_id ) );
                        if ( $extra && 'tour_extra' === $extra->post_type ) {
                            $slugs[] = $extra->post_name ? $extra->post_name : $extra->post_title;
                        }
                    }
                }
                $item['allowed_extras'] = $slugs;
                unset( $item['meta']['_etb_allowed_extras'] );
            }

            $items[] = $item;
        }

        return $items;
    }

    private function export_post_meta( $post_id ) {
        $all  = get_post_meta( $post_id );
        $meta = array();

        foreach ( $all as $key => $values ) {
            if ( in_array( $key, self::SKIP_META_KEYS, true ) ) {
                continue;
            }
            $meta[ $key ] = get_post_meta( $post_id, $key, true );
        }

        return $meta;
    }

    // ---------------------------------------------------------------------
    // IMPORT
    // ---------------------------------------------------------------------

    public function handle_import() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( 'Autorisation refusée.', 403 );
        }
        check_admin_referer( self::NONCE_ACTION );

        $redirect = admin_url( 'edit.php?post_type=tour_booking&page=etb-settings&tab=tools' );

        if ( empty( $_FILES['etb_import_file']['tmp_name'] ) || ! is_uploaded_file( $_FILES['etb_import_file']['tmp_name'] ) ) {
            $this->redirect_with_notice( $redirect, 'error', 'Aucun fichier reçu. Sélectionnez un fichier JSON exporté par le plugin.' );
        }

        if ( $_FILES['etb_import_file']['size'] > self::MAX_FILE_SIZE ) {
            $this->redirect_with_notice( $redirect, 'error', 'Fichier trop volumineux (maximum 5 Mo).' );
        }

        $raw  = file_get_contents( $_FILES['etb_import_file']['tmp_name'] );
        $data = json_decode( $raw, true );

        if ( ! is_array( $data ) || empty( $data['plugin'] ) || 'elite-transfer-booking' !== $data['plugin'] ) {
            $this->redirect_with_notice( $redirect, 'error', 'Fichier invalide : ce n\'est pas un export Elite Transfer Booking.' );
        }

        $version = isset( $data['format_version'] ) ? (int) $data['format_version'] : 0;
        if ( $version < 1 || $version > self::FORMAT_VERSION ) {
            $this->redirect_with_notice( $redirect, 'error', 'Version de format non supportée (' . $version . ').' );
        }

        $replace = ! empty( $_POST['replace_existing'] );
        $images  = ! empty( $_POST['import_images'] );

        $result = $this->import_payload( $data, $replace, $images );

        $summary = sprintf(
            'Import terminé : %d véhicule(s), %d option(s), %d point(s) de départ, %d code(s) promo. Réglages mis à jour.',
            $result['tour_vehicle'],
            $result['tour_extra'],
            $result['tour_pickup'],
            $result['tour_promo']
        );
        if ( ! empty( $result['warnings'] ) ) {
            $summary .= ' Avertissements : ' . implode( ' | ', array_slice( $result['warnings'], 0, 5 ) );
        }

        $this->redirect_with_notice( $redirect, 'success', $summary );
    }

    /**
     * Applique un payload d'import. Retourne les compteurs par CPT.
     */
    public function import_payload( array $data, $replace = false, $import_images = true ) {
        $counts   = array_fill_keys( self::CONFIG_POST_TYPES, 0 );
        $warnings = array();

        if ( $replace ) {
            foreach ( self::CONFIG_POST_TYPES as $post_type ) {
                $existing = get_posts( array(
                    'post_type'      => $post_type,
                    'post_status'    => 'any',
                    'posts_per_page' => -1,
                    'fields'         => 'ids',
                ) );
                foreach ( $existing as $old_id ) {
                    wp_delete_post( $old_id, true );
                }
            }
        }

        // 1. Réglages
        if ( ! empty( $data['settings'] ) && is_array( $data['settings'] ) ) {
            foreach ( array( 'etb_general_settings', 'etb_form_settings' ) as $option_name ) {
                if ( isset( $data['settings'][ $option_name ] ) && is_array( $data['settings'][ $option_name ] ) ) {
                    $clean    = $this->sanitize_recursive( $data['settings'][ $option_name ] );
                    $existing = get_option( $option_name, array() );
                    update_option( $option_name, array_merge( is_array( $existing ) ? $existing : array(), $clean ) );
                }
            }
        }

        // 2. Contenus (extras d'abord pour permettre le remappage des liaisons)
        $slug_map = array(); // slug d'extra => nouvel ID
        $content  = isset( $data['content'] ) && is_array( $data['content'] ) ? $data['content'] : array();

        foreach ( self::CONFIG_POST_TYPES as $post_type ) {
            if ( empty( $content[ $post_type ] ) || ! is_array( $content[ $post_type ] ) ) {
                continue;
            }

            foreach ( $content[ $post_type ] as $item ) {
                if ( ! is_array( $item ) || empty( $item['title'] ) ) {
                    continue;
                }

                $post_id = $this->upsert_post( $post_type, $item );
                if ( ! $post_id ) {
                    $warnings[] = 'Échec import : ' . $item['title'];
                    continue;
                }

                $counts[ $post_type ]++;

                if ( 'tour_extra' === $post_type ) {
                    $key = ! empty( $item['slug'] ) ? $item['slug'] : $item['title'];
                    $slug_map[ $key ] = $post_id;
                }

                if ( 'tour_vehicle' === $post_type && isset( $item['allowed_extras'] ) && is_array( $item['allowed_extras'] ) ) {
                    $allowed_ids = array();
                    foreach ( $item['allowed_extras'] as $extra_key ) {
                        if ( isset( $slug_map[ $extra_key ] ) ) {
                            $allowed_ids[] = (int) $slug_map[ $extra_key ];
                        }
                    }
                    update_post_meta( $post_id, '_etb_allowed_extras', $allowed_ids );
                }

                if ( $import_images ) {
                    $this->import_images( $post_id, $item, $warnings );
                }
            }
        }

        $counts['warnings'] = $warnings;
        return $counts;
    }

    /**
     * Retrouve un post par slug (puis par titre) ou le crée. Applique title/status/order/meta.
     */
    private function upsert_post( $post_type, array $item ) {
        $title  = sanitize_text_field( $item['title'] );
        $slug   = ! empty( $item['slug'] ) ? sanitize_title( $item['slug'] ) : sanitize_title( $title );
        $status = in_array( $item['status'] ?? 'publish', array( 'publish', 'draft', 'pending', 'private' ), true )
            ? $item['status'] : 'publish';

        $existing = get_posts( array(
            'post_type'      => $post_type,
            'post_status'    => 'any',
            'name'           => $slug,
            'posts_per_page' => 1,
            'fields'         => 'ids',
        ) );

        if ( empty( $existing ) ) {
            $by_title = get_posts( array(
                'post_type'      => $post_type,
                'post_status'    => 'any',
                'title'          => $title,
                'posts_per_page' => 1,
                'fields'         => 'ids',
            ) );
            $existing = $by_title;
        }

        $postarr = array(
            'post_type'   => $post_type,
            'post_title'  => $title,
            'post_name'   => $slug,
            'post_status' => $status,
            'menu_order'  => isset( $item['menu_order'] ) ? (int) $item['menu_order'] : 0,
        );

        if ( ! empty( $existing ) ) {
            $postarr['ID'] = (int) $existing[0];
            $post_id       = wp_update_post( $postarr, true );
        } else {
            $post_id = wp_insert_post( $postarr, true );
        }

        if ( is_wp_error( $post_id ) || ! $post_id ) {
            return 0;
        }

        if ( ! empty( $item['meta'] ) && is_array( $item['meta'] ) ) {
            foreach ( $item['meta'] as $key => $value ) {
                if ( ! is_string( $key ) || in_array( $key, self::SKIP_META_KEYS, true ) ) {
                    continue;
                }
                if ( '_etb_allowed_extras' === $key ) {
                    continue; // géré via les slugs
                }
                update_post_meta( $post_id, $key, $this->sanitize_recursive( $value ) );
            }
        }

        return (int) $post_id;
    }

    /**
     * Télécharge l'image mise en avant et l'image de survol depuis le site source.
     */
    private function import_images( $post_id, array $item, array &$warnings ) {
        require_once ABSPATH . 'wp-admin/includes/media.php';
        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/image.php';

        if ( ! empty( $item['thumbnail_url'] ) ) {
            $attachment_id = $this->sideload( $item['thumbnail_url'], $post_id );
            if ( $attachment_id ) {
                set_post_thumbnail( $post_id, $attachment_id );
            } else {
                $warnings[] = 'Image non importée pour « ' . $item['title'] . ' » (source injoignable).';
            }
        }

        if ( ! empty( $item['meta']['_etb_hover_image'] ) ) {
            $hover_id = $this->sideload( $item['meta']['_etb_hover_image'], $post_id );
            if ( $hover_id ) {
                update_post_meta( $post_id, '_etb_hover_image', wp_get_attachment_url( $hover_id ) );
            }
        }
    }

    private function sideload( $url, $post_id ) {
        $url = esc_url_raw( $url );
        if ( ! $url || ! preg_match( '#^https?://#i', $url ) ) {
            return 0;
        }

        // Réimport du même fichier : on réutilise l'attachment déjà téléchargé.
        $existing = get_posts( array(
            'post_type'      => 'attachment',
            'post_status'    => 'inherit',
            'posts_per_page' => 1,
            'fields'         => 'ids',
            'meta_key'       => '_etb_import_source_url',
            'meta_value'     => $url,
        ) );
        if ( ! empty( $existing ) ) {
            return (int) $existing[0];
        }

        $result = media_sideload_image( $url, $post_id, null, 'id' );
        if ( is_wp_error( $result ) || ! $result ) {
            return 0;
        }
        update_post_meta( (int) $result, '_etb_import_source_url', $url );
        return (int) $result;
    }

    /**
     * Nettoyage récursif des valeurs importées (chaînes, tableaux, scalaires).
     */
    private function sanitize_recursive( $value ) {
        if ( is_array( $value ) ) {
            $clean = array();
            foreach ( $value as $key => $item ) {
                $clean[ sanitize_key( $key ) ] = $this->sanitize_recursive( $item );
            }
            return $clean;
        }

        if ( is_string( $value ) ) {
            return sanitize_textarea_field( $value );
        }

        if ( is_bool( $value ) || is_int( $value ) || is_float( $value ) || null === $value ) {
            return $value;
        }

        return '';
    }

    private function redirect_with_notice( $redirect, $type, $message ) {
        set_transient( 'etb_import_notice_' . get_current_user_id(), array(
            'type'    => $type,
            'message' => $message,
        ), 60 );

        wp_safe_redirect( $redirect );
        exit;
    }

    // ---------------------------------------------------------------------
    // UI (onglet Réglages → Import / Export)
    // ---------------------------------------------------------------------

    public static function render_tab() {
        $counts = array();
        foreach ( self::CONFIG_POST_TYPES as $post_type ) {
            $found = wp_count_posts( $post_type );
            $counts[ $post_type ] = (int) ( $found->publish ?? 0 ) + (int) ( $found->draft ?? 0 );
        }

        $notice = get_transient( 'etb_import_notice_' . get_current_user_id() );
        if ( $notice ) {
            delete_transient( 'etb_import_notice_' . get_current_user_id() );
        }

        $labels = array(
            'tour_vehicle' => 'Véhicules',
            'tour_extra'   => 'Options',
            'tour_pickup'  => 'Points de départ',
            'tour_promo'   => 'Codes promo',
        );
        ?>
        <?php if ( $notice ) : ?>
            <div class="notice notice-<?php echo 'success' === $notice['type'] ? 'success' : 'error'; ?> is-dismissible" style="margin: 15px 0;">
                <p><?php echo esc_html( $notice['message'] ); ?></p>
            </div>
        <?php endif; ?>

        <div class="etb-dashboard" style="display:block;">
            <div class="etb-content" style="max-width: 900px;">

                <h2 class="etb-section-title">Exporter la configuration</h2>
                <p class="description" style="margin-bottom: 15px;">
                    Génère un fichier JSON contenant tous les réglages, véhicules, options, points de départ et codes promo.
                    Les réservations clients ne sont jamais incluses.
                </p>

                <table class="form-table" style="margin-bottom: 10px;">
                    <tr>
                        <th scope="row">Contenu actuel</th>
                        <td>
                            <?php foreach ( $labels as $type => $label ) : ?>
                                <span style="display:inline-block; background:#f1f5f9; border:1px solid #e2e8f0; border-radius:20px; padding:4px 12px; margin:0 6px 6px 0; font-size:12px; font-weight:600; color:#334155;">
                                    <?php echo esc_html( $label ); ?> : <?php echo (int) $counts[ $type ]; ?>
                                </span>
                            <?php endforeach; ?>
                        </td>
                    </tr>
                </table>

                <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
                    <input type="hidden" name="action" value="etb_export_config">
                    <?php wp_nonce_field( self::NONCE_ACTION ); ?>
                    <label style="display:flex; align-items:center; gap:8px; margin-bottom:15px; cursor:pointer;">
                        <input type="checkbox" name="include_secrets" value="1" checked>
                        <span style="font-size:13px; color:#334155;">Inclure les clés API (Stripe, Google Maps, Mapbox, LimoExpress)</span>
                    </label>
                    <p class="description" style="margin:-8px 0 15px 26px;">
                        Décochez pour partager une configuration sans secrets. Le fichier exporté contient des données sensibles : conservez-le en lieu sûr.
                    </p>
                    <button type="submit" class="button button-primary">⬇ Télécharger l'export JSON</button>
                </form>

                <hr style="margin: 35px 0; border: none; border-top: 1px solid #e2e8f0;">

                <h2 class="etb-section-title">Importer une configuration</h2>
                <p class="description" style="margin-bottom: 15px;">
                    Restaure un fichier exporté depuis un autre site. Les éléments existants sont mis à jour par correspondance de slug (ou de titre), les nouveaux sont créés.
                </p>

                <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" enctype="multipart/form-data" id="etb-import-form">
                    <input type="hidden" name="action" value="etb_import_config">
                    <?php wp_nonce_field( self::NONCE_ACTION ); ?>

                    <table class="form-table">
                        <tr>
                            <th scope="row"><label for="etb_import_file">Fichier JSON</label></th>
                            <td>
                                <input type="file" id="etb_import_file" name="etb_import_file" accept=".json,application/json" required>
                                <p class="description">Fichier <code>etb-config-*.json</code> (5 Mo maximum).</p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row">Images</th>
                            <td>
                                <label style="display:flex; align-items:center; gap:8px; cursor:pointer;">
                                    <input type="checkbox" name="import_images" value="1" checked>
                                    <span style="font-size:13px; color:#334155;">Télécharger les images depuis le site source</span>
                                </label>
                                <p class="description">Le site d'origine doit être accessible publiquement pendant l'import. Sinon, décochez et réaffectez les images manuellement.</p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row">Mode</th>
                            <td>
                                <label style="display:flex; align-items:center; gap:8px; cursor:pointer;">
                                    <input type="checkbox" name="replace_existing" value="1" id="etb-replace-existing">
                                    <span style="font-size:13px; color:#b91c1c; font-weight:600;">Remplacer toute la configuration existante</span>
                                </label>
                                <p class="description">⚠ Supprime définitivement les véhicules, options, points de départ et codes promo actuels avant l'import. Les réservations ne sont pas touchées.</p>
                            </td>
                        </tr>
                    </table>

                    <button type="submit" class="button button-primary" style="margin-top:10px;">⬆ Importer la configuration</button>
                </form>

                <script>
                document.getElementById('etb-import-form').addEventListener('submit', function (e) {
                    var replace = document.getElementById('etb-replace-existing');
                    if (replace && replace.checked) {
                        if (!window.confirm('ATTENTION : tous les véhicules, options, points de départ et codes promo actuels seront supprimés et remplacés par le fichier importé.\n\nContinuer ?')) {
                            e.preventDefault();
                        }
                    }
                });
                </script>
            </div>
        </div>
        <?php
    }
}
