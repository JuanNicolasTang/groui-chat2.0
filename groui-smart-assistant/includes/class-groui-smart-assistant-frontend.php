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
        $budget  = $this->extract_budget_range( $message );

        if ( ! empty( $budget ) ) {
            $context = $this->apply_budget_filter_to_context( $context, $budget );
        }

        $openai = new GROUI_Smart_Assistant_OpenAI();
        $result = $openai->query( $message, $context );

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

        $budget = $this->extract_budget_range( $query );
        $products = array();
        // Prioritize the products suggested by the model.
        if ( ! empty( $product_ids ) ) {
            foreach ( $product_ids as $product_id ) {
                $product = wc_get_product( $product_id );
                if ( ! $product instanceof WC_Product ) {
                    continue;
                }
                if ( ! $product->is_purchasable() || ! $product->is_in_stock() ) {
                    continue;
                }
                $price_amount = $this->get_product_price_amount( $product );
                if ( ! $this->product_matches_budget( $price_amount, $budget ) ) {
                    continue;
                }

                $products[ $product_id ] = $product;
            }
        }
        // If no products were suggested or none were purchasable, perform a search.
        if ( empty( $products ) ) {
            $brand_term_ids = array();
            $brand_taxonomies = array();

            if ( ! empty( $query ) ) {
                $candidate_taxonomies = array( 'product_brand', 'brand' );

                foreach ( $candidate_taxonomies as $taxonomy ) {
                    if ( taxonomy_exists( $taxonomy ) ) {
                        $brand_taxonomies[] = $taxonomy;
                    }
                }

                if ( ! empty( $brand_taxonomies ) ) {
                    $matching_terms = get_terms(
                        array(
                            'taxonomy'   => $brand_taxonomies,
                            'hide_empty' => false,
                            'name__like' => $query,
                            'fields'     => 'ids',
                        )
                    );

                    if ( ! is_wp_error( $matching_terms ) && ! empty( $matching_terms ) ) {
                        $brand_term_ids = array_map( 'absint', (array) $matching_terms );
                    }
                }
            }

            $args = array(
                'status'  => 'publish',
                'limit'   => 10,
                'orderby' => 'popularity',
            );
            if ( ! empty( $query ) ) {
                $args['s'] = $query;
            }
            if ( ! empty( $budget ) ) {
                if ( isset( $budget['min'] ) && null !== $budget['min'] ) {
                    $args['min_price'] = $budget['min'];
                }
                if ( isset( $budget['max'] ) && null !== $budget['max'] ) {
                    $args['max_price'] = $budget['max'];
                }
            }
            if ( ! empty( $brand_term_ids ) && ! empty( $brand_taxonomies ) ) {
                $tax_query = array( 'relation' => 'OR' );

                foreach ( $brand_taxonomies as $taxonomy ) {
                    $tax_query[] = array(
                        'taxonomy' => $taxonomy,
                        'field'    => 'term_id',
                        'terms'    => $brand_term_ids,
                    );
                }

                $args['tax_query'] = $tax_query;
            }
            $products = wc_get_products( $args );

            if ( empty( $products ) ) {
                $fallback_args = array(
                    'status'  => 'publish',
                    'limit'   => 10,
                    'orderby' => 'popularity',
                );

                if ( ! empty( $query ) ) {
                    $fallback_args['s'] = $query;
                }

                if ( ! empty( $budget ) ) {
                    if ( isset( $budget['min'] ) && null !== $budget['min'] ) {
                        $fallback_args['min_price'] = $budget['min'];
                    }
                    if ( isset( $budget['max'] ) && null !== $budget['max'] ) {
                        $fallback_args['max_price'] = $budget['max'];
                    }
                }

                if ( ! empty( $brand_term_ids ) && ! empty( $brand_taxonomies ) ) {
                    $tax_query = array( 'relation' => 'OR' );

                    foreach ( $brand_taxonomies as $taxonomy ) {
                        $tax_query[] = array(
                            'taxonomy' => $taxonomy,
                            'field'    => 'term_id',
                            'terms'    => $brand_term_ids,
                        );
                    }

                    $fallback_args['tax_query'] = $tax_query;
                }

                /**
                 * Allow developers to modify the fallback product query.
                 *
                 * @since 1.0.0
                 *
                 * @param array $fallback_args Default fallback arguments.
                 * @param array $original_args Original query arguments used before fallback.
                 * @param string $query Search term provided by the assistant/user.
                 */
                $fallback_args = apply_filters( 'groui_smart_assistant_fallback_products_args', $fallback_args, $args, $query );

                $products = wc_get_products( $fallback_args );
            }
        }

        $cards = array();
        foreach ( $products as $product ) {
            $price_amount = $this->get_product_price_amount( $product );

            if ( ! $this->product_matches_budget( $price_amount, $budget ) ) {
                continue;
            }

            $cards[] = array(
                'id'         => $product->get_id(),
                'name'       => $product->get_name(),
                'price'      => wp_strip_all_tags( $product->get_price_html() ),
                'price_amount' => $price_amount,
                'permalink'  => $product->get_permalink(),
                'image'      => wp_get_attachment_image_url( $product->get_image_id(), 'medium' ),
                'short_desc' => wp_trim_words( wp_strip_all_tags( $product->get_short_description() ), 30 ),
                'in_stock'   => $product->is_in_stock(),
            );
        }

        return $cards;
    }

    /**
     * Return the numeric price of a WooCommerce product if available.
     *
     * @param WC_Product $product Product instance.
     *
     * @return float|null
     */
    protected function get_product_price_amount( WC_Product $product ) {
        $price = $product->get_price();

        if ( '' === $price || null === $price ) {
            $price = $product->get_regular_price();
        }

        if ( '' === $price || null === $price ) {
            return null;
        }

        if ( function_exists( 'wc_get_price_to_display' ) ) {
            $display_price = wc_get_price_to_display( $product );
        } else {
            $display_price = $price;
        }

        if ( function_exists( 'wc_format_decimal' ) ) {
            $decimals       = function_exists( 'wc_get_price_decimals' ) ? wc_get_price_decimals() : 2;
            $display_price  = wc_format_decimal( $display_price, $decimals );
        }

        return (float) $display_price;
    }

    /**
     * Determine whether a price fits within the extracted budget range.
     *
     * @param float|null $price_amount Numeric product price.
     * @param array      $budget       Budget range array with `min` and `max` keys.
     *
     * @return bool
     */
    protected function product_matches_budget( $price_amount, array $budget ) {
        if ( empty( $budget ) ) {
            return true;
        }

        if ( null === $price_amount ) {
            // If we cannot determine the price, keep the product so the assistant can explain the limitation.
            return true;
        }

        if ( isset( $budget['min'] ) && null !== $budget['min'] && $price_amount < $budget['min'] ) {
            return false;
        }

        if ( isset( $budget['max'] ) && null !== $budget['max'] && $price_amount > $budget['max'] ) {
            return false;
        }

        return true;
    }

    /**
     * Parse a budget range (min/max) from a free-form text message.
     *
     * @param string $text Incoming message.
     *
     * @return array Empty array when no range is detected or an array with `min`/`max` keys.
     */
    protected function extract_budget_range( $text ) {
        $text = is_string( $text ) ? trim( $text ) : '';

        if ( '' === $text ) {
            return array();
        }

        $has_currency_symbol = (bool) preg_match( '/[\$€£¥₽₹₱₡₫₦₩₮₴]/u', $text );
        $normalized           = strtolower( $text );
        $has_budget_keyword   = (bool) preg_match( '/\b(presupuesto|budget|precio|costo|cuesta|barato|caro|hasta|menos|menor|máximo|maximo|minimo|mínimo|desde|rango|gastar|gasto)\b/u', $normalized );

        $pattern = '/(?:\$|€|£|¥|₽|₹|₱|mxn|usd|eur|cop|clp|ars|pen|s\/\.|soles|colones|gtq|crc|cad|aud|brl)?\s*(\d{1,3}(?:[\.\s]\d{3})*(?:[\.,]\d+)?|\d+(?:[\.,]\d+)?)/iu';
        preg_match_all( $pattern, $text, $matches );

        $numbers = array();

        if ( ! empty( $matches[1] ) ) {
            foreach ( $matches[1] as $candidate ) {
                $value = $this->normalize_amount_from_text( $candidate );

                if ( null !== $value ) {
                    $numbers[] = $value;
                }
            }
        }

        if ( empty( $numbers ) || ( ! $has_currency_symbol && ! $has_budget_keyword ) ) {
            return array();
        }

        $min = null;
        $max = null;

        if ( count( $numbers ) >= 2 ) {
            sort( $numbers, SORT_NUMERIC );
            $min = $numbers[0];
            $max = $numbers[ count( $numbers ) - 1 ];
        } else {
            $value = $numbers[0];

            if ( preg_match( '/(más de|mas de|mayor a|mayor que|desde|mínimo|minimo|al menos|arriba de)/u', $normalized ) ) {
                $min = $value;
            } elseif ( preg_match( '/(menos de|menor a|menor que|hasta|como máximo|maximo|máximo)/u', $normalized ) ) {
                $max = $value;
            } else {
                $max = $value;
            }
        }

        if ( null !== $min && null !== $max && $min > $max ) {
            $tmp = $min;
            $min = $max;
            $max = $tmp;
        }

        if ( null === $min && null === $max ) {
            return array();
        }

        return array(
            'min' => null !== $min ? (float) $min : null,
            'max' => null !== $max ? (float) $max : null,
        );
    }

    /**
     * Convert a textual number with localisation artefacts into a float.
     *
     * @param string $value Raw number string captured from the message.
     *
     * @return float|null
     */
    protected function normalize_amount_from_text( $value ) {
        if ( ! is_string( $value ) && ! is_numeric( $value ) ) {
            return null;
        }

        $value = trim( (string) $value );

        if ( '' === $value ) {
            return null;
        }

        $value = str_replace( array( ' ', "\xC2\xA0" ), '', $value );
        $value = preg_replace( '/[^0-9,\.]/u', '', $value );

        if ( '' === $value ) {
            return null;
        }

        $last_comma = strrpos( $value, ',' );
        $last_dot   = strrpos( $value, '.' );

        if ( false !== $last_comma && false !== $last_dot ) {
            if ( $last_comma > $last_dot ) {
                $value = str_replace( '.', '', $value );
                $value = str_replace( ',', '.', $value );
            } else {
                $value = str_replace( ',', '', $value );
            }
        } elseif ( false !== $last_comma ) {
            $value = str_replace( ',', '.', $value );
        } elseif ( false !== $last_dot ) {
            $decimal_digits = strlen( substr( $value, $last_dot + 1 ) );
            $dot_count      = substr_count( $value, '.' );

            if ( $dot_count > 1 || $decimal_digits === 3 ) {
                $value = str_replace( '.', '', $value );
            }
        }

        if ( ! preg_match( '/^\d+(?:\.\d+)?$/', $value ) ) {
            return null;
        }

        return (float) $value;
    }

    /**
     * Apply budget-based filtering to the context before sending it to OpenAI.
     *
     * @param array $context Context array built from the site data.
     * @param array $budget  Budget range with `min` and/or `max`.
     *
     * @return array Filtered context.
     */
    protected function apply_budget_filter_to_context( array $context, array $budget ) {
        if ( empty( $context['products'] ) || ! is_array( $context['products'] ) ) {
            return $context;
        }

        $filtered = array();

        foreach ( $context['products'] as $product ) {
            $price_amount = isset( $product['price_amount'] ) ? (float) $product['price_amount'] : null;

            if ( $this->product_matches_budget( $price_amount, $budget ) ) {
                $filtered[] = $product;
            }
        }

        if ( ! isset( $context['_meta'] ) || ! is_array( $context['_meta'] ) ) {
            $context['_meta'] = array();
        }

        $context['_meta']['budget_filter'] = array(
            'min'     => isset( $budget['min'] ) ? $budget['min'] : null,
            'max'     => isset( $budget['max'] ) ? $budget['max'] : null,
            'matched' => count( $filtered ),
        );

        if ( ! empty( $filtered ) ) {
            $context['products'] = array_values( $filtered );
        } else {
            $context['products'] = array();
        }

        return $context;
    }
}
