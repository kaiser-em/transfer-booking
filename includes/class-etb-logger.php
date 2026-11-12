<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Logger centralisé pour Elite Transfer Booking
 * Niveaux: DEBUG, INFO, ERROR
 * Rotation automatique, support i18n, intégration Sentry/Datadog optionnelle
 */
class ETB_Logger {

    const LEVEL_DEBUG = 0;
    const LEVEL_INFO  = 1;
    const LEVEL_ERROR = 2;

    private static $instance = null;
    private $log_dir;
    private $log_file;
    private $log_level;
    private $max_file_size = 10485760; // 10MB
    private $max_files = 5;

    private function __construct() {
        $upload_dir = wp_upload_dir();
        $this->log_dir = trailingslashit( $upload_dir['basedir'] ) . 'etb-logs/';

        // Créer le dossier logs s'il n'existe pas
        if ( ! is_dir( $this->log_dir ) ) {
            wp_mkdir_p( $this->log_dir );
        }

        $this->log_file = $this->log_dir . 'etb-' . wp_date( 'Y-m' ) . '.log';
        $this->log_level = defined( 'WP_DEBUG' ) && WP_DEBUG ? self::LEVEL_DEBUG : self::LEVEL_INFO;

        // Protection .htaccess contre accès direct
        $this->secure_log_directory();
    }

    public static function get_instance() {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Protège le dossier logs avec .htaccess
     */
    private function secure_log_directory() {
        $htaccess = $this->log_dir . '.htaccess';
        if ( ! file_exists( $htaccess ) && ! is_dir( $htaccess ) ) {
            file_put_contents( $htaccess, "Order deny,allow\nDeny from all\n<Files ~ \"\.(log)$\">\nOrder allow,deny\nDeny from all\n</Files>" );
        }
    }

    /**
     * Rotation des fichiers log: supprime les anciens, archive si taille max atteinte
     */
    private function rotate_if_needed() {
        if ( file_exists( $this->log_file ) && filesize( $this->log_file ) > $this->max_file_size ) {
            $timestamp = wp_date( 'Y-m-d_His' );
            rename( $this->log_file, $this->log_dir . 'etb-' . $timestamp . '.log' );

            // Supprimer les fichiers les plus anciens si > max_files
            $log_files = glob( $this->log_dir . 'etb-*.log' );
            usort( $log_files, function( $a, $b ) {
                return filemtime( $b ) - filemtime( $a );
            });

            foreach ( array_slice( $log_files, $this->max_files ) as $old_file ) {
                unlink( $old_file );
            }
        }
    }

    /**
     * Format du message: [TIMESTAMP] [LEVEL] [CONTEXT] message
     */
    private function format_message( $level, $message, $context = '' ) {
        $level_str = [ 'DEBUG', 'INFO', 'ERROR' ][ $level ];
        $timestamp = wp_date( 'Y-m-d H:i:s' );
        $context_str = $context ? "[$context]" : "[global]";

        return sprintf( "[%s] [%s] %s %s\n", $timestamp, $level_str, $context_str, $message );
    }

    /**
     * Log un message (usage interne)
     */
    private function log( $level, $message, $context = '' ) {
        if ( $level < $this->log_level ) {
            return;
        }

        $this->rotate_if_needed();

        $formatted = $this->format_message( $level, $message, $context );

        // Écriture sécurisée avec verrou file
        $fp = fopen( $this->log_file, 'a' );
        if ( false === $fp ) {
            if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
                error_log( 'ETB_LOG: impossible d\'ouvrir le fichier de log ' . $this->log_file );
            }
            return;
        }
        if ( flock( $fp, LOCK_EX ) ) {
            fwrite( $fp, $formatted );
            flock( $fp, LOCK_UN );
        }
        fclose( $fp );

        // En debug, log aussi dans PHP error_log
        if ( defined( 'WP_DEBUG' ) && WP_DEBUG && $level >= self::LEVEL_ERROR ) {
            error_log( 'ETB_LOG: ' . trim( $formatted ) );
        }
    }

    /**
     * Méthodes publiques statiques
     */

    public static function debug( $message, $context = '' ) {
        self::get_instance()->log( self::LEVEL_DEBUG, $message, $context );
    }

    public static function info( $message, $context = '' ) {
        self::get_instance()->log( self::LEVEL_INFO, $message, $context );
    }

    public static function error( $message, $context = '' ) {
        self::get_instance()->log( self::LEVEL_ERROR, $message, $context );
    }

    /**
     * Log une erreur AJAX avec contexte enrichi
     */
    public static function ajax_error( $handler, $message, $data = array() ) {
        $context = 'ajax:' . $handler;
        $client_ip = isset( $_SERVER['REMOTE_ADDR'] ) ? $_SERVER['REMOTE_ADDR'] : 'unknown';
        $user_id = get_current_user_id();

        $enriched = sprintf(
            "AJAX error in %s (IP: %s, User: %d) - %s | Data: %s",
            $handler,
            $client_ip,
            $user_id,
            $message,
            json_encode( $data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE )
        );

        self::error( $enriched, $context );
    }

    /**
     * Log une erreur API externe (LimoExpress, Stripe, etc.)
     */
    public static function api_error( $service, $endpoint, $status_code, $error_message, $response_body = '' ) {
        $context = 'api:' . $service;
        $message = sprintf(
            "API error %s (%s) - Status: %d - Error: %s | Response: %s",
            $service,
            $endpoint,
            $status_code,
            $error_message,
            substr( $response_body, 0, 500 ) // Limite pour éviter logs trop gros
        );

        self::error( $message, $context );
    }

    /**
     * Log une action de paiement
     */
    public static function payment_log( $booking_id, $action, $amount, $currency, $status, $details = '' ) {
        $context = 'payment';
        $message = sprintf(
            "Payment %s for booking #%d - Amount: %s %s - Status: %s | Details: %s",
            $action,
            $booking_id,
            number_format( $amount, 2 ),
            $currency,
            $status,
            $details
        );

        self::info( $message, $context );
    }

    /**
     * Log une action dispatch (LimoExpress)
     */
    public static function dispatch_log( $booking_id, $action, $provider, $status, $response = '' ) {
        $context = 'dispatch';
        $message = sprintf(
            "Dispatch %s for booking #%d via %s - Status: %s | Response: %s",
            $action,
            $booking_id,
            $provider,
            $status,
            substr( $response, 0, 300 )
        );

        self::info( $message, $context );
    }

    /**
     * Récupère les logs récents pour l'admin
     * @param int $lines Nombre de lignes à retourner
     * @param string $level Filtrer par niveau (debug, info, error)
     * @return array
     */
    public static function get_recent_logs( $lines = 100, $level = 'all' ) {
        $instance = self::get_instance();
        if ( ! file_exists( $instance->log_file ) ) {
            return array( 'No log file found.' );
        }

        $content = file( $instance->log_file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES );
        if ( false === $content ) {
            return array( 'Unable to read log file.' );
        }

        // Filtrer par niveau si demandé
        if ( 'all' !== $level ) {
            $level_map = [ 'debug' => '[DEBUG]', 'info' => '[INFO]', 'error' => '[ERROR]' ];
            $filter = $level_map[ $level ] ?? '';
            $content = array_filter( $content, function( $line ) use ( $filter ) {
                return $filter === '' || strpos( $line, $filter ) !== false;
            } );
        }

        // Retourner les N dernières lignes
        return array_slice( array_reverse( $content ), 0, $lines );
    }

    /**
     * Vide les logs (pour l'admin)
     */
    public static function clear_logs() {
        $instance = self::get_instance();
        if ( file_exists( $instance->log_file ) ) {
            file_put_contents( $instance->log_file, '' );
        }
        return true;
    }
}