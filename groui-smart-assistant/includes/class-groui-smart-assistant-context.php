<?php
/**
 * Knowledge context builder for the GROUI Smart Assistant.
 *
 * This class is responsible for aggregating relevant information from the
 * WordPress installation (pages, FAQs, products and taxonomy data) and
 * exposing it in a structured format. The context is cached via a transient
 * to avoid expensive rebuilds on every request. Consumers can force a
 * rebuild by passing $force to refresh_context().
 *
 * @package GROUI_Smart_Assistant
 */

// Bail if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Class GROUI_Smart_Assistant_Context
 */
class GROUI_Smart_Assistant_Context {

    /**
     * Singleton instance.
     *
     * @var GROUI_Smart_Assistant_Context|null
     */
    protected static $instance;

    /**
     * Retrieve the singleton instance.
     *
     * @return GROUI_Smart_Assistant_Context
     */
    public static function instance() {
        if ( null === static::$instance ) {
            static::$instance = new static();
        }

        return static::$instance;
    }

    /**
     * Refresh the cached context.
     *
     * @param bool $force Whether to force refresh ignoring the cached value.
     *
     * @return array The freshly built context.
     */
    public function refresh_context( $force = false ) {
        $context = get_transient( GROUI_Smart_Assistant::CONTEXT_TRANSIENT );

        // If a cached context exists and no force refresh is requested, return it.
        if ( ! $force && false !== $context ) {
            return $context;
        }

        $settings = $this->get_settings();

        $context = array(
            'site'       => get_bloginfo( 'name' ),
            'tagline'    => get_bloginfo( 'description' ),
            'sitemap'    => $this->get_sitemap_summary( $settings ),
 codex/add-filter-for-wp_remote_post-args-zyr1kv
            'pages'      => $this->get_page_summaries( $settings['max_pages'], $settings ),
            'faqs'       => $this->get_faqs_from_content( $settings ),
            'products'   => $this->get_product_summaries( $settings['max_products'], $settings ),
            'categories' => $this->get_taxonomy_summaries( $settings ),
            'pages'      => $this->get_page_summaries( $settings['max_pages'] ),
            'faqs'       => $this->get_faqs_from_content(),
            'products'   => $this->get_product_summaries( $settings['max_products'], $settings ),
            'categories' => $this->get_taxonomy_summaries(),
 main
        );

        // Cache the built context for one hour.
        set_transient( GROUI_Smart_Assistant::CONTEXT_TRANSIENT, $context, HOUR_IN_SECONDS );

        return $context;
    }

    /**
     * Retrieve plugin settings with sensible defaults.
     *
     * @return array The settings array.
     */
    protected function get_settings() {
        $defaults = array(
            'openai_api_key' => '',
            'model'          => 'gpt-5.1',
            'sitemap_url'    => home_url( '/sitemap.xml' ),
            'enable_debug'   => false,
            'max_pages'      => 12,
            'max_products'   => 12,
            'deep_context_mode' => false,
        );

        // Merge stored options with defaults, falling back when keys are missing.
        return wp_parse_args( get_option( GROUI_Smart_Assistant::OPTION_KEY, array() ), $defaults );
    }

    /**
     * Build a simple sitemap summary by reading up to 20 URL entries from the sitemap.
     *
     * @param array $settings Plugin settings.
     *
     * @return array List of sitemap entries with `url` and `lastmod` keys.
     */
    protected function get_sitemap_summary( $settings ) {
        $sitemap_url = ! empty( $settings['sitemap_url'] ) ? esc_url_raw( $settings['sitemap_url'] ) : home_url( '/sitemap.xml' );

        $timeout = absint( apply_filters( 'groui_smart_assistant_sitemap_timeout', 45, $settings ) );
        if ( $timeout < 15 ) {
            $timeout = 15;
        }

        $response_limit = absint( apply_filters( 'groui_smart_assistant_sitemap_response_limit', 1024 * 1024, $settings ) );
        if ( $response_limit < 50 * 1024 ) {
            $response_limit = 50 * 1024;
        }

        $request_args = apply_filters(
            'groui_smart_assistant_sitemap_request_args',
            array(
                'timeout'             => $timeout,
                'limit_response_size' => $response_limit,
            ),
            $settings
        );

        $response = wp_remote_get( $sitemap_url, $request_args );

        if ( is_wp_error( $response ) ) {
            if ( ! empty( $settings['enable_debug'] ) ) {
                $this->log_debug(
                    sprintf( 'Sitemap request failed for %s: %s', $sitemap_url, $response->get_error_message() ),
                    $response->get_error_data(),
                    $settings
                );
            }
            return array();
        }

        $body = wp_remote_retrieve_body( $response );
        if ( empty( $body ) ) {
            if ( ! empty( $settings['enable_debug'] ) ) {
                $this->log_debug( sprintf( 'Sitemap request returned empty body for %s', $sitemap_url ), null, $settings );
            }
            return array();
        }

        libxml_use_internal_errors( true );
        $xml = simplexml_load_string( $body );
        libxml_clear_errors();

        if ( ! $xml ) {
            if ( ! empty( $settings['enable_debug'] ) ) {
                $this->log_debug( 'Failed to parse sitemap XML, attempting fallback parser.', null, $settings );
            }

            $urls = $this->parse_sitemap_fallback( $body, $settings );

            if ( empty( $urls ) && ! empty( $settings['enable_debug'] ) ) {
                $this->log_debug( 'Fallback parser did not extract sitemap entries.', null, $settings );
            }

            return $urls;
        }

        $urls = array();
        $max_entries = max( 1, absint( apply_filters( 'groui_smart_assistant_sitemap_max_entries', 20, $settings ) ) );

        foreach ( $xml->url as $entry ) {
            $loc = isset( $entry->loc ) ? (string) $entry->loc : '';
            if ( empty( $loc ) ) {
                continue;
            }

            $urls[] = array(
                'url'     => $loc,
                'lastmod' => isset( $entry->lastmod ) ? (string) $entry->lastmod : '',
            );

            if ( count( $urls ) >= $max_entries ) {
                break;
            }
        }

        return $urls;
    }

    /**
     * Fallback parser for sitemap XML when SimpleXML cannot handle the response.
     *
     * Attempts to extract <url> entries using regular expressions and a very
     * small custom parser so that a truncated or partial response (which is
     * common when limiting the download size) still yields useful data.
     *
     * @param string $body      Raw sitemap body.
     * @param array  $settings  Plugin settings array.
     *
     * @return array List of sitemap summaries.
     */
    protected function parse_sitemap_fallback( $body, $settings ) {
        $max_entries = max( 1, absint( apply_filters( 'groui_smart_assistant_sitemap_max_entries', 20, $settings ) ) );

        if ( ! preg_match_all( '/<url>(.*?)<\/url>/is', $body, $matches ) ) {
            return array();
        }

        $urls = array();

        foreach ( $matches[1] as $chunk ) {
            if ( ! preg_match( '/<loc>\s*([^<]+)\s*<\/loc>/i', $chunk, $loc_match ) ) {
                continue;
            }

            $entry = array(
                'url' => trim( $loc_match[1] ),
            );

            if ( preg_match( '/<lastmod>\s*([^<]+)\s*<\/lastmod>/i', $chunk, $lastmod_match ) ) {
                $entry['lastmod'] = trim( $lastmod_match[1] );
            } else {
                $entry['lastmod'] = '';
            }

            $urls[] = $entry;

            if ( count( $urls ) >= $max_entries ) {
                break;
            }
        }

        return $urls;
    }

    /**
     * Log debug information when the feature is enabled.
     *
     * @param string     $message  Message to write to the debug log.
     * @param mixed      $context  Optional context data.
     * @param array|null $settings Plugin settings array.
     *
     * @return void
     */
    protected function log_debug( $message, $context = null, $settings = null ) {
        if ( null === $settings ) {
            $settings = $this->get_settings();
        }

        if ( empty( $settings['enable_debug'] ) ) {
            return;
        }

        if ( null !== $context ) {
            $message .= ' ' . wp_json_encode( $context );
        }

        error_log( '[GROUI Smart Assistant] ' . $message );
    }

    /**
     * Collect summaries for the most important pages on the site.
     *
     * @param int $limit Number of pages to include.
     *
     * @return array List of page summaries with `title`, `url` and `excerpt` keys.
     */
    protected function get_page_summaries( $limit, $settings = array() ) {
        $settings         = wp_parse_args( $settings, array( 'deep_context_mode' => false ) );
        $use_full_context = ! empty( $settings['deep_context_mode'] );

        if ( $use_full_context ) {
            $query_args = array(
                'post_status' => 'publish',
                'sort_column' => 'menu_order',
                'number'      => 0,
            );
        } else {
            $limit = max( 1, absint( $limit ) );
            $query_args = array(
                'post_status' => 'publish',
                'sort_column' => 'menu_order',
                'number'      => $limit,
            );
        }

        /**
         * Filter the arguments passed to `get_pages()` when building summaries.
         *
         * @param array $query_args       Page query arguments.
         * @param array $settings         Plugin settings array.
         * @param bool  $use_full_context Whether full-context mode is active.
         */
        $query_args = apply_filters( 'groui_smart_assistant_context_page_query_args', $query_args, $settings, $use_full_context );

        $pages = get_pages( $query_args );

        $summaries = array();
        foreach ( $pages as $page ) {
            $summaries[] = array(
                'title'   => $page->post_title,
                'url'     => get_permalink( $page ),
                'excerpt' => wp_trim_words( wp_strip_all_tags( $page->post_content ), 55 ),
            );
        }

        return $summaries;
    }

    /**
     * Extract FAQ-like headings from pages and posts.
     *
     * @return array List of FAQs with `question` and `source` keys.
     */
    protected function get_faqs_from_content( $settings = array() ) {
        $faqs  = array();
        $settings         = wp_parse_args( $settings, array( 'deep_context_mode' => false ) );
        $use_full_context = ! empty( $settings['deep_context_mode'] );

        $query_args = array(
            'post_type'      => array( 'page', 'post' ),
            'post_status'    => 'publish',
            'posts_per_page' => $use_full_context ? -1 : 20,
        );

        /**
         * Filter the arguments passed to `get_posts()` for FAQ extraction.
         *
         * @param array $query_args       Post query arguments.
         * @param array $settings         Plugin settings array.
         * @param bool  $use_full_context Whether full-context mode is active.
         */
        $query_args = apply_filters( 'groui_smart_assistant_context_faq_query_args', $query_args, $settings, $use_full_context );

        $posts = get_posts( $query_args );

        foreach ( $posts as $post ) {
            // Match headings (h2–h4) within the post content.
            preg_match_all( '/<h[2-4][^>]*>(.*?)<\/h[2-4]>/', $post->post_content, $matches );
            if ( empty( $matches[1] ) ) {
                continue;
            }
            foreach ( $matches[1] as $heading ) {
                $clean = wp_strip_all_tags( $heading );
                if ( empty( $clean ) ) {
                    continue;
                }
                $faqs[] = array(
                    'question' => $clean,
                    'source'   => get_permalink( $post ),
                );
            }
        }

        return $faqs;
    }

    /**
     * Gather WooCommerce product summaries.
     *
     * @param int   $limit    Número de productos a incluir cuando el contexto se refina por relevancia.
     * @param array $settings Ajustes del plugin, usados para detectar el modo de contexto completo.
     *
     * @return array List of product summaries with keys such as `id`, `name`, `price`, `permalink`, `image`, `short_desc`, `categories` and `category_names`.
     */
    protected function get_product_summaries( $limit, $settings = array() ) {
        if ( ! class_exists( 'WooCommerce' ) ) {
            return array();
        }

        $settings          = wp_parse_args( $settings, array( 'deep_context_mode' => false ) );
        $use_full_context  = ! empty( $settings['deep_context_mode'] );
        $limit             = max( 0, absint( $limit ) );

        /**
         * Adjust the number of products indexed when the assistant recorta por relevancia.
         *
         * @param int   $limit            Limit requested via plugin settings.
         * @param array $settings         Plugin settings array.
         * @param bool  $use_full_context Whether full-context mode is active.
         */
        $limit = apply_filters( 'groui_smart_assistant_context_product_limit', $limit, $settings, $use_full_context );
        $limit = max( 0, absint( $limit ) );
        $query_args        = array(
            'status'  => 'publish',
            'orderby' => 'date',
            'order'   => 'DESC',
        );

        if ( $use_full_context ) {
            /**
             * Define a safety cap when the full WooCommerce catálogo is indexed.
             *
             * Return -1 (default) to cargar todos los productos publicados o un entero positivo para limitar la cifra.
             *
             * @param int   $max_catalog Maximum number of products to include, -1 for unlimited.
             * @param array $settings    Plugin settings array.
             */
            $max_catalog = apply_filters( 'groui_smart_assistant_context_maximum_products', -1, $settings );
            $max_catalog = (int) $max_catalog;

            if ( 0 === $max_catalog ) {
                return array();
            }

            $query_args['limit'] = ( $max_catalog > 0 ) ? absint( $max_catalog ) : -1;
        } else {
            if ( $limit < 1 ) {
                $limit = 12;
            }

            $query_args['limit'] = $limit;
        }

        /**
         * Filter the arguments passed to `wc_get_products()` when building the contexto de WooCommerce.
         *
         * @param array $query_args       Default WooCommerce query arguments.
         * @param array $settings         Plugin settings array.
         * @param bool  $use_full_context Whether full-context mode is active.
         */
        $query_args = apply_filters( 'groui_smart_assistant_context_product_query_args', $query_args, $settings, $use_full_context );

        $products = wc_get_products( $query_args );

        if ( isset( $products['products'] ) && is_array( $products['products'] ) ) {
            $product_objects = $products['products'];
        } else {
            $product_objects = $products;
        }

        if ( ! $use_full_context && $limit > 0 && count( $product_objects ) > $limit ) {
            $product_objects = array_slice( $product_objects, 0, $limit );
        }

        if ( $use_full_context ) {
            $max_catalog = isset( $max_catalog ) ? $max_catalog : -1;

            if ( $max_catalog > 0 && count( $product_objects ) > $max_catalog ) {
                $product_objects = array_slice( $product_objects, 0, $max_catalog );
            }
        }

        $summaries = array();

        foreach ( $product_objects as $product ) {
            if ( ! $product || ! is_object( $product ) ) {
                continue;
            }

            $summary = $this->build_product_summary( $product );

            if ( ! empty( $summary ) ) {
                $summaries[] = $summary;
            }
        }

        return $summaries;
    }

    /**
     * Build a summary for a WooCommerce product.
     *
     * @param WC_Product $product Product object to summarise.
     *
     * @return array Summary data ready to be stored in the assistant context.
     */
    protected function build_product_summary( $product ) {
        if ( ! is_a( $product, 'WC_Product' ) ) {
            return array();
        }

        $category_ids = array_map( 'intval', $product->get_category_ids() );

        return array(
            'id'             => $product->get_id(),
            'name'           => $product->get_name(),
            'price'          => wp_strip_all_tags( $product->get_price_html() ),
            'permalink'      => $product->get_permalink(),
            'image'          => wp_get_attachment_image_url( $product->get_image_id(), 'medium' ),
            'short_desc'     => wp_trim_words( wp_strip_all_tags( $product->get_short_description() ), 30 ),
            'categories'     => $category_ids,
            'category_names' => $this->get_product_category_names( $category_ids ),
        );
    }

    /**
     * Retrieve product category names for the provided IDs.
     *
     * Results are cached in-memory for the duration of the request to avoid
     * repeated lookups when many products share the same term.
     *
     * @param array $category_ids List of product category term IDs.
     *
     * @return array List of category names.
     */
    protected function get_product_category_names( $category_ids ) {
        static $cache = array();

        $names = array();

        foreach ( $category_ids as $category_id ) {
            $category_id = absint( $category_id );

            if ( ! $category_id ) {
                continue;
            }

            if ( ! array_key_exists( $category_id, $cache ) ) {
                $term = get_term( $category_id, 'product_cat' );

                if ( $term && ! is_wp_error( $term ) ) {
                    if ( is_object( $term ) && is_a( $term, 'WP_Term' ) && isset( $term->name ) ) {
                        $cache[ $category_id ] = $term->name;
                    } elseif ( isset( $term->name ) ) {
                        // Ensure compatibility with mocks that might not return WP_Term instances.
                        $cache[ $category_id ] = (string) $term->name;
                    } else {
                        $cache[ $category_id ] = (string) $term;
                    }
                } else {
                    $cache[ $category_id ] = '';
                }
            }

            if ( ! empty( $cache[ $category_id ] ) ) {
                $names[] = $cache[ $category_id ];
            }
        }

        return $names;
    }

    /**
     * Build taxonomy summaries for product categories and other relevant taxonomies.
     *
     * @return array Associative array keyed by taxonomy name with lists of term summaries.
     */
    protected function get_taxonomy_summaries( $settings = array() ) {
        $settings         = wp_parse_args( $settings, array( 'deep_context_mode' => false ) );
        $use_full_context = ! empty( $settings['deep_context_mode'] );

        $taxonomies = array( 'product_cat', 'product_tag', 'brand', 'category' );
        /**
         * Filter the list of taxonomies considered for summaries.
         *
         * @param array $taxonomies       Default taxonomy list.
         * @param array $settings         Plugin settings array.
         * @param bool  $use_full_context Whether full-context mode is active.
         */
        $taxonomies = apply_filters( 'groui_smart_assistant_context_taxonomies', $taxonomies, $settings, $use_full_context );
        $summary    = array();

        foreach ( $taxonomies as $taxonomy ) {
            if ( ! taxonomy_exists( $taxonomy ) ) {
                continue;
            }
            $term_args = array(
                'taxonomy'   => $taxonomy,
                'hide_empty' => true,
                'number'     => $use_full_context ? 0 : 20,
            );

            /**
             * Filter the arguments passed to `get_terms()` for taxonomy summaries.
             *
             * @param array  $term_args        Term query arguments.
             * @param string $taxonomy         Current taxonomy slug.
             * @param array  $settings         Plugin settings array.
             * @param bool   $use_full_context Whether full-context mode is active.
             */
            $term_args = apply_filters( 'groui_smart_assistant_context_taxonomy_query_args', $term_args, $taxonomy, $settings, $use_full_context );

            $terms = get_terms( $term_args );
            if ( is_wp_error( $terms ) || empty( $terms ) ) {
                continue;
            }
            $summary[ $taxonomy ] = array_map(
                static function( $term ) {
                    return array(
                        'name'        => $term->name,
                        'description' => wp_trim_words( $term->description, 25 ),
                        'url'         => get_term_link( $term ),
                    );
                },
                $terms
            );
        }

        return $summary;
    }
}
