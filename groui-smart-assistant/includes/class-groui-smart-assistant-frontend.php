<?php
/**
 * Frontend assets and AJAX endpoints for GROUI Smart Assistant.
 *
 * This class handles the registration of styles and scripts, localization of
 * dynamic data (AJAX URL, nonce and debug flag) and exposes two AJAX
 * endpoints for chat and product requests. It also prepares product
 * information for the assistant’s carousel.
 *
 * @package GROUI_Smart_Assistant
 */

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Class GROUI_Smart_Assistant_Frontend
 */
class GROUI_Smart_Assistant_Frontend {

    /**
     * Constructor.
     *
     * Hooks into WordPress to register assets and AJAX handlers.
     */
    public function __construct() {
        add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_assets' ) );
        add_action( 'wp_footer', array( $this, 'render_widget_container' ) );

        add_action( 'wp_ajax_groui_smart_assistant_chat', array( $this, 'handle_chat_request' ) );
        add_action( 'wp_ajax_nopriv_groui_smart_assistant_chat', array( $this, 'handle_chat_request' ) );

        add_action( 'wp_ajax_groui_smart_assistant_products', array( $this, 'handle_products_request' ) );
        add_action( 'wp_ajax_nopriv_groui_smart_assistant_products', array( $this, 'handle_products_request' ) );
    }

    /**
     * Enqueue front‑end assets.
     *
     * Registers the plugin’s CSS and JS files on the front‑end and localizes
     * dynamic variables to the JS script. Includes a debug flag based on
     * plugin settings.
     */
    public function enqueue_assets() {
        wp_enqueue_style(
            'groui-smart-assistant',
            GROUI_SMART_ASSISTANT_URL . 'assets/css/groui-smart-assistant.css',
            array(),
            GROUI_SMART_ASSISTANT_VERSION
        );

        wp_enqueue_script(
            'groui-smart-assistant',
            GROUI_SMART_ASSISTANT_URL . 'assets/js/groui-smart-assistant.js',
            array( 'jquery' ),
            GROUI_SMART_ASSISTANT_VERSION,
            true
        );

        $settings = get_option( GROUI_Smart_Assistant::OPTION_KEY, array() );

        wp_localize_script(
            'groui-smart-assistant',
            'GROUISmartAssistant',
            array(
                'ajaxUrl'        => admin_url( 'admin-ajax.php' ),
                'nonce'          => wp_create_nonce( 'groui_smart_assistant_nonce' ),
                'hasWooCommerce' => class_exists( 'WooCommerce' ),
                'debug'          => ! empty( $settings['enable_debug'] ),
            )
        );
    }

    /**
     * Render the floating button container in the footer.
     *
     * Outputs a minimal container that the front‑end script will populate
     * dynamically. Uses `aria-live` to improve accessibility.
     */
    public function render_widget_container() {
        echo '<div id="groui-smart-assistant-root" class="groui-smart-assistant-root" aria-live="polite"></div>';
    }

    /**
     * Handle chat requests from the client.
     *
     * Validates the nonce, sanitizes the incoming message and sends the
     * request to the OpenAI client. On success, returns a JSON response
     * containing the assistant’s answer and any product suggestions. If an
     * error occurs, returns a translated error message.
     */
    public function handle_chat_request() {
        check_ajax_referer( 'groui_smart_assistant_nonce', 'nonce' );

        $message = isset( $_POST['message'] ) ? sanitize_textarea_field( wp_unslash( $_POST['message'] ) ) : '';
        if ( empty( $message ) ) {
            wp_send_json_error( array( 'message' => __( 'Escribe una pregunta para continuar.', 'groui-smart-assistant' ) ) );
        }

        $context = GROUI_Smart_Assistant_Context::instance()->refresh_context();
        $openai  = new GROUI_Smart_Assistant_OpenAI();
        $result  = $openai->query( $message, $context );

        // If the API returned a WP_Error, forward the message to the client.
        if ( is_wp_error( $result ) ) {
            wp_send_json_error( array(
                'message' => $result->get_error_message(),
                'data'    => $result->get_error_data(),
            ) );
        }

        $response = array(
            'answer'   => isset( $result['content']['answer'] ) ? wp_kses_post( $result['content']['answer'] ) : '',
            'products' => isset( $result['content']['products'] ) ? array_map( 'absint', (array) $result['content']['products'] ) : array(),
        );

        // Format products into display cards for the carousel.
        $response['productCards'] = $this->format_products_for_display( $response['products'], $message );

        wp_send_json_success( $response );
    }

    /**
     * Handle explicit product carousel refresh.
     *
     * Called via AJAX when the assistant wants to refresh the product
     * suggestions. Accepts a `query` parameter to search for matching
     * products. Returns formatted product cards.
     */
    public function handle_products_request() {
        check_ajax_referer( 'groui_smart_assistant_nonce', 'nonce' );

        $query = isset( $_POST['query'] ) ? sanitize_text_field( wp_unslash( $_POST['query'] ) ) : '';

        wp_send_json_success( array(
            'products' => $this->format_products_for_display( array(), $query ),
        ) );
    }

    /**
     * Prepare product data for the carousel.
     *
     * If a list of product IDs is provided, fetch those products. If the
     * resulting list is empty, perform a fallback query using the supplied
     * search term. Only purchasable, in‑stock products are included.
     *
     * @param array  $product_ids IDs suggested by the model (optional).
     * @param string $query       Fallback search query (optional).
     *
     * @return array List of product card data.
     */
    protected function format_products_for_display( $product_ids = array(), $query = '' ) {
        if ( ! class_exists( 'WooCommerce' ) ) {
            return array();
        }

        $products = array();
        // Prioritize the products suggested by the model.
        if ( ! empty( $product_ids ) ) {
            foreach ( $product_ids as $product_id ) {
                $product = wc_get_product( $product_id );
                if ( ! $product instanceof WC_Product ) {
                    continue;
                }
                if ( ! $product->is_purchasable() |) {
                    continue;
                }
                $products[ $product_id ] = $product;
            }
        }
        // If no products were suggested or none were purchasable, perform a search.
        if ( empty( $products ) ) {
            $args = array(
                'status'  => 'publish',
                'limit'   => 10,
                'orderby' => 'popularity',
            );
            if ( ! empty( $query ) ) {
                $args['s'] = $query;            }
            $products = wc_get_products( $args );
        }

        $cards = array();
        foreach ( $products as $product ) {
            $cards[] = array(
                'id'         => $product->get_id(),
                'name'       => $product->get_name(),
                'price'      => wp_strip_all_tags( $product->get_price_html() ),
                'permalink'  => $product->get_permalink(),
                'image'      => wp_get_attachment_image_url( $product->get_image_id(), 'medium' ),
                'short_desc' => wp_trim_words( wp_strip_all_tags( $product->get_short_description() ), 30 ),
                'in_stock'   => $product->is_in_stock(),
            );
        }

        return $cards;
    }
}
