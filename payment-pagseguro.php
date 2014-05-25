<?php
/**
 * PassagemIngresso PagSeguro Payment Class.
 */
if ( ! defined( 'ABSPATH' ) ) exit; // Exit if accessed directly.

/**
 * Implements the PagSeguro payment gateway.
 */
class PassagemIngresso_Payment_Method_PagSeguro extends PassagemIngresso_Payment_Method {

    /**
     * Payment variables.
     */
    public $id = 'pagseguro';
    public $name = 'PagSeguro';
    public $description = 'PagSeguro';
    public $supported_currencies = array( 'BRL' );

    protected $pagseguro_checkout_url = 'https://ws.pagseguro.uol.com.br/v2/checkout/';
    protected $pagseguro_payment_url = 'https://pagseguro.uol.com.br/v2/checkout/payment.html?code=';
    protected $pagseguro_notify_url = 'https://ws.pagseguro.uol.com.br/v2/transactions/notifications/';

    /**
     * Store the options.
     */
    protected $options;

    /**
     * Init the gataway.
     *
     * @return void
     */
    public function passagemingresso_init() {
        $this->options = array_merge(
            array(
                'email' => '',
                'token' => '',
            ),
            $this->get_payment_options()
        );

        // Fix the description for translate.
        $this->description = __( 'PagSeguro Gateway works by sending the user to PagSeguro to enter their payment information.', 'ctpagseguro' );
        add_action( 'template_redirect', array( $this, 'template_redirect' ) );
    }

    /**
     * Sets payment settings fields.
     *
     * @return void
     */
    public function payment_settings_fields() {
        $this->add_settings_field_helper( 'email', __( 'Email', 'ctpagseguro' ), array( $this, 'field_text' ) );
        $this->add_settings_field_helper( 'token', __( 'Token', 'ctpagseguro' ), array( $this, 'field_text' ) );
    }

    /**
     * Validate options.
     *
     * @param array $input Options.
     *
     * @return array       Valide options.
     */
    public function validate_options( $input ) {
        $output = $this->options;

        if ( ! empty( $input['email'] ) && is_email( $input['email'] ) )
            $output['email'] = $input['email'];

        if ( ! empty( $input['token'] ) )
            $output['token'] = $input['token'];

        return $output;
    }

    /**
     * Sets the template redirect.
     *
     * @return void
     */
    public function template_redirect() {

        // Test the request.
        if ( empty( $_REQUEST['tix_payment_method'] ) || 'pagseguro' !== $_REQUEST['ingresso_payment_method'] )
            return;

        // Payment return.
        if ( isset( $_GET['ingresso_action'] ) && 'payment_return' == $_GET['tix_action'] )
            return $this->payment_return();

        // Payment notify.
        if ( ! empty( $_POST['notificationCode'] ) && ! empty( $_POST['notificationType'] ) )
            return $this->payment_notify();
    }

    /**
     * PagSeguro currency format.
     *
     * @param int $number Current number.
     *
     * @return float      Formated number.
     */
    protected function currency_format( $number ) {
        return number_format( (float) $number, 2, '.', '' );
    }

    /**
     * Generate the PagSeguro order.
     *
     * @param array  $args          Payment arguments.
     * @param string $payment_token Payment token.
     *
     * @return mixed                Code of payment or false if it fails.
     */
    protected function generate_order( $args, $payment_token ) {
        $body = http_build_query( $args, '', '&' );

        // Sets the post params.
        $params = array(
            'body'    => $body,
            'timeout' => 30,
        );

        // Gets the PagSeguro response.
        $response = wp_remote_post( $this->pagseguro_checkout_url, $params );

        // Check to see if the request was valid.
        if ( ! is_wp_error( $response ) && $response['response']['code'] >= 200 && $response['response']['code'] < 300 ) {

            $data = new SimpleXmlElement( $response['body'], LIBXML_NOCDATA );

            $this->log( 'PagSeguro payment link created with success!', 0, $response );

            // Payment code.
            return (string) $data->code;
        }

        $this->log( 'Failed to generate the PagSeguro payment link', 0, $response );

        return false;
    }

    /**
     * Check notify.
     *
     * @param string $code PagSeguro transaction code.
     *
     * @return mixed       On success returns an array with the payment data or failure returns false.
     */
    protected function check_notify( $code ) {

        // Generate the PagSeguro url.
        $url = sprintf(
            '%s%s?email=%s&token=%s',
            $this->pagseguro_notify_url,
            esc_attr( $code ),
            $this->options['email'],
            $this->options['token']
        );

        // Sets the post params.
        $params = array(
            'timeout' => 30,
        );

        // Gets the PagSeguro response.
        $response = wp_remote_get( esc_url_raw( $url ), $params );

        // Check to see if the request was valid.
        if ( ! is_wp_error( $response ) && $response['response']['code'] >= 200 && $response['response']['code'] < 300 ) {
            // Read the PagSeguro return.
            $data = new SimpleXmlElement( $response['body'], LIBXML_NOCDATA );

            $this->log( sprintf( 'Received PagSeguro IPN with success. PagSeguro Payment Code: %s', $code ), 0, $response );
            return $data;

        } else {
            $this->log( sprintf( 'Could not verify PagSeguro IPN. PagSeguro Payment Code: %s', $code ), 0, $response );
            return false;
        }
    }

    /**
     * Process the payment checkout.
     *
     * @param string $payment_token Payment Token.
     *
     * @return mixed                On success redirects to PagSeguro if fails cancels the purchase.
     */
    public function payment_checkout( $payment_token ) {
        global $passagemingresso;

        if ( empty( $payment_token ) )
            return false;

        if ( ! in_array( $this->passagemingresso_options['currency'], $this->supported_currencies ) )
            die( __( 'The selected currency is not supported by this payment method.', 'ctpagseguro' ) );

        // Process $order and do something.
        $order = $this->get_order( $payment_token );
        do_action( 'passagemingresso_before_payment', $payment_token );

        // Sets the PagSeguro item description.
        $item_description = __( 'Event', 'ctpagseguro' );
        if ( ! empty( $this->passagemingresso_options['event_name'] ) )
            $item_description = $this->passagemingresso_options['event_name'];

        foreach ( $order['items'] as $key => $value )
            $item_description .= sprintf( ', %sx %s %s', $value['quantity'], $value['name'], $this->currency_format( $value['price'] ) );

        $pagseguro_args = array(
            'email'            => $this->options['email'],
            'token'            => $this->options['token'],
            'currency'         => $this->passagemingresso_options['currency'],
            'charset'          => 'UTF-8',
            'reference'        => $payment_token,
            'itemId1'          => '0001',
            'itemDescription1' => trim( substr( $item_description, 0, 95 ) ),
            'itemAmount1'      => $this->currency_format( $order['total'] ),
            'itemQuantity1'    => '1',
        );

        // Checks if is localhost. PagSeguro not accept localhost urls!
        if ( ! in_array( $_SERVER['HTTP_HOST'], array( 'localhost', '127.0.0.1' ) ) ) {
            $pagseguro_args['redirectURL'] = add_query_arg( array(
                'tix_action'         => 'payment_return',
                'tix_payment_token'  => $payment_token,
                'tix_payment_method' => 'pagseguro',
            ), $this->get_tickets_url() );

            $pagseguro_args['notificationURL'] = add_query_arg( array(
                'tix_payment_method' => 'pagseguro',
            ), $this->get_tickets_url() );
        }

        $pagseguro_order = $this->generate_order( $pagseguro_args, $payment_token );

        if ( $pagseguro_order ) {
            wp_redirect( esc_url_raw( $this->pagseguro_payment_url . $pagseguro_order ) );
            die();
        } else {
            return $this->payment_result( $payment_token, PassagemIngresso_Plugin::PAYMENT_STATUS_FAILED );
        }
    }

    /**
     * Convert payment statuses from PagSeguro responses to PassagemIngresso payment statuses.
     *
     * @param int $payment_status PagSeguro payment status.
     *
     * @return int                PassagemiIngresso payment status.
     */
    protected function get_payment_status( $payment_status ) {
        $statuses = array(
            1 => PassagemIngresso_Plugin::PAYMENT_STATUS_PENDING,
            2 => PassagemIngresso_Plugin::PAYMENT_STATUS_PENDING,
            3 => PassagemIngresso_Plugin::PAYMENT_STATUS_COMPLETED,
            4 => PassagemIngresso_Plugin::PAYMENT_STATUS_COMPLETED,
            5 => PassagemIngresso_Plugin::PAYMENT_STATUS_REFUNDED,
            6 => PassagemIngresso_Plugin::PAYMENT_STATUS_REFUNDED,
            7 => PassagemIngresso_Plugin::PAYMENT_STATUS_CANCELLED
        );

        // Return pending for unknows statuses.
        if ( ! isset( $statuses[ $payment_status ] ) )
            $payment_status = 1;

        return $statuses[ $payment_status ];
    }

    /**
     * Process the payment return.
     *
     * @return void Update the order status and/or redirect to order page.
     */
    protected function payment_return() {
        global $passagemingresso;

        $payment_token = ( ! empty( $_REQUEST['ingresso_payment_token'] ) ) ? trim( $_REQUEST['ingresso_payment_token'] ) : '';
        if ( empty( $payment_token ) )
            return;

        $attendees = get_posts(
            array(
                'posts_per_page' => 1,
                'post_type' => 'ingresso_attendee',
                'post_status' => array( 'draft', 'pending', 'publish', 'cancel', 'refund', 'failed' ),
                'meta_query' => array(
                    array(
                        'key' => 'ingresso_payment_token',
                        'compare' => '=',
                        'value' => $payment_token,
                        'type' => 'CHAR',
                    ),
                ),
            )
        );

        if ( empty( $attendees ) )
            return;

        $attendee = reset( $attendees );

        if ( 'draft' == $attendee->post_status ) {
            return $this->payment_result( $payment_token, PassagemIngresso_Plugin::PAYMENT_STATUS_PENDING );
        } else {
            $access_token = get_post_meta( $attendee->ID, 'ingresso_access_token', true );
            $url = add_query_arg( array(
                'ingresso_action' => 'access_tickets',
                'ingresso_access_token' => $access_token,
            ), $passagemingresso->get_tickets_url() );

            wp_safe_redirect( esc_url_raw( $url . '#ingresso' ) );
            die();
        }
    }

    /**
     * Process the payment notify.
     *
     * @return void Update the order status.
     */
    public function payment_notify() {
        if ( empty( $_POST['notificationCode' ] ) )
            return;

        $data = $this->check_notify( $_POST['notificationCode'] );

        if ( $data ) {
            $payment_token = (string) $data->reference;
            $status = $this->get_payment_status( (int) $data->status );

            $payment_data = array(
                'transaction_id' => (string) $data->code,
                'transaction_details' => array(
                    'raw' => array(
                        'data' => (string) $data->date
                    ),
                ),
            );


            return $this->payment_result( $payment_token, $status, $payment_data );
        }
    }

} // Close PassagemIngresso_Payment_Method_PagSeguro class.

/**
 * Register the Gateway in PassagemIngresso.
 */
passagemingresso_register_addon( 'PassagemIngresso_Payment_Method_PagSeguro' );
