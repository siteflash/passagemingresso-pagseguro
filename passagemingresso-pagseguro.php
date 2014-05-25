<?php
/**
 * Plugin Name: passagemingresso PagSeguro
 * Plugin URI: https://github.com/siteflash/passagemingresso-pagseguro.git
 * Description: PagSeguro Gateway para passagemingresso
 * Author: Andre Webmaster, SITEFLASH.S.A
 * Author URI: http://andrewebmaster.com.br
 * Version: 1.0
 * License: GPLv2 or later
 * Text Domain: ctpagseguro
 * Domain Path: /languages/
 */

/**
 * PassagemIngresso fallback notice.
 *
 * @return string HTML Message.
 */
function ctpagseguro_admin_notice() {
    $html = '<div class="error">';
        $html .= '<p>' . sprintf( __( 'PassagemIngresso PagSeguro Gateway depends on the last version of %s to work!', 'ctpagseguro' ), '<a href="http://wordpress.org/extend/plugins/passagemingresso/">PassagemIngresso</a>' ) . '</p>';
    $html .= '</div>';

    echo $html;
}

/**
 * Load functions.
 *
 * @return void
 */
function ctpagseguro_plugins_loaded() {
    load_plugin_textdomain( 'ctpagseguro', false, dirname( plugin_basename( __FILE__ ) ) . '/languages/' );

    if ( ! class_exists( 'PassagemIngresso_Plugin' ) || ! class_exists( 'PassagemIngresso_Payment_Method' ) ) {
        add_action( 'admin_notices', 'ctpagseguro_admin_notice' );
        return;
    }

    add_action( 'passagemingresso_load_addons', 'ctpagseguro_passagemingresso_load_addons' );
}

add_action( 'plugins_loaded', 'ctpagseguro_plugins_loaded' );

/**
 * Include PagSeguro Payment on PassagemIngresso load addons.
 *
 * @return void
 */
function ctpagseguro_passagemingresso_load_addons() {
    require_once plugin_dir_path( __FILE__ ) . 'payment-pagseguro.php';
}

/**
 * Adds custom settings url in plugins page.
 *
 * @param  array $links Default links.
 *
 * @return array        Default links and settings link.
 */
function ctpagseguro_action_links( $links ) {

    $settings = array(
        'settings' => sprintf(
            '<a href="%s">%s</a>',
            admin_url( 'edit.php?post_type=ingresso_ticket&page=passagemingresso_options&ingresso_section=payment' ),
            __( 'Settings', 'ctpagseguro' )
        )
    );

    return array_merge( $settings, $links );
}

add_filter( 'plugin_action_links_' . plugin_basename( __FILE__ ), 'ctpagseguro_action_links' );
