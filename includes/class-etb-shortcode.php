<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class ETB_Shortcode {

    public function __construct() {
        add_shortcode( 'etb_transfer', array( $this, 'render_transfer_widget' ) );
        add_shortcode( 'etb_checkout', array( $this, 'render_checkout_view' ) );
        add_shortcode( 'etb_payment', array( $this, 'render_payment_view' ) ); // <-- NOUVEAU SHORTCODE PAIEMENT DÉDIÉ
    }
    /**
     * Shortcode [etb_checkout] : Formulaire de réservation détaillée & Checkout VIP Blacklane
     */
    public function render_checkout_view( $atts = array() ) {
        ob_start();
        $template_file = ETB_PATH . 'templates/checkout-view.php';
        if ( file_exists( $template_file ) ) {
            include $template_file;
        } else {
            echo '<p>Gabarit checkout-view.php introuvable.</p>';
        }
        return ob_get_clean();
    }

    /**
     * Shortcode [etb_payment] : Page de paiement dédiée et sécurisée pour solde et devis validés
     */
    public function render_payment_view( $atts = array() ) {
        ob_start();
        $template_file = ETB_PATH . 'templates/payment-view.php';
        if ( file_exists( $template_file ) ) {
            include $template_file;
        } else {
            echo '<p>Gabarit payment-view.php introuvable.</p>';
        }
        return ob_get_clean();
    }

    /**
     * Shortcode [etb_transfer] : Widget minimaliste pour transferts et mise à disposition
     */
    public function render_transfer_widget( $atts = array() ) {
        ob_start();
        $template_file = ETB_PATH . 'templates/transfer-widget.php';
        if ( file_exists( $template_file ) ) {
            include $template_file;
        } else {
            echo '<p>Gabarit transfer-widget.php introuvable.</p>';
        }
        return ob_get_clean();
    }
}