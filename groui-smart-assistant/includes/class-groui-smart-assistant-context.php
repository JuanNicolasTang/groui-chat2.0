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
        $cached = get_transient( GROUI_Smart_Assistant::CONTEXT_TRANSIENT );

        // If a cached context exists and no force refresh is requested, return it.
        if ( ! $force && false !== $cached ) {
            return $cached;
        }

        $settings = $this->get_settings();

        $this->prepare_environment_for_context_build( $settings );

        try {
            $context = array(
                'site'       => get_bloginfo( 'name' ),
                'tagline'    => get_bloginfo( 'description' ),
                'sitemap'    => $this->get_sitemap_summary( $settings ),
                'pages'      => $this->get_page_summaries( $settings['max_pages'], $settings ),
                'faqs'       => $this->get_faqs_from_content( $settings ),
                'products'   => $this->get_product_summaries( $settings['max_products'], $settings ),
                'categories' => $this->get_taxonomy_summaries( $settings ),
            );

            // Cache the built context for one hour.
            set_transient( GROUI_Smart_Assistant::CONTEXT_TRANSIENT, $context, HOUR_IN_SECONDS );

            return $context;
        } catch ( \Throwable $error ) {
            if ( ! empty( $settings['enable_debug'] ) ) {
                $this->log_debug(
                    'Context refresh failed: ' . $error->getMessage(),
                    array( 'trace' => $error->getTraceAsString() ),
                    $settings
                );
            }

            if ( is_array( $cached ) ) {
                return $cached;
            }

            return array(
                'site'       => get_bloginfo( 'name' ),
                'tagline'    => get_bloginfo( 'description' ),
                'sitemap'    => array(),
                'pages'      => array(),
                'faqs'       => array(),
                'products'   => array(),
                'categories' => array(),
            );
        }
    }

    /**
     * Raise resource limits when building large contexts.
     *
     * @param array $settings Plugin settings.
     *
     * @return void
     */
    protected function prepare_environment_for_context_build( $settings ) {
        if ( empty( $settings['deep_context_mode'] ) ) {
            return;
        }

        if ( function_exists( 'wp_raise_memory_limit' ) ) {
            wp_raise_memory_limit( 'admin' );
        }

        $time_limit = absint( apply_filters( 'groui_smart_assistant_context_time_limit', 120, $settings ) );

        if ( $time_limit > 0 && $this->can_adjust_time_limit() ) {
            $current_limit = $this->get_current_execution_time_limit();

            if ( 0 === $current_limit || $time_limit > $current_limit ) {
                try {
                    set_time_limit( $time_limit );
                } catch ( \Throwable $error ) {
                    if ( ! empty( $settings['enable_debug'] ) ) {
                        $this->log_debug(
                            'Unable to adjust execution time limit: ' . $error->getMessage(),
                            array(
                                'requested_limit' => $time_limit,
                                'previous_limit'  => $current_limit,
                            ),
                            $settings
                        );
                    }
                }
            }
        }
    }

    /**
     * Determine whether the environment allows calling set_time_limit().
     *
     * @return bool
     */
    protected function can_adjust_time_limit() {
        if ( ! function_exists( 'set_time_limit' ) ) {
            return false;
        }

        if ( ! function_exists( 'ini_get' ) ) {
            return true;
        }

        $disabled = ini_get( 'disable_functions' );

        if ( empty( $disabled ) ) {
            return true;
        }

        $disabled_functions = array_map( 'trim', explode( ',', $disabled ) );

        return ! in_array( 'set_time_limit', $disabled_functions, true );
    }

    /**
     * Retrieve the current max_execution_time without triggering errors on locked-down hosts.
     *
     * @return int
     */
    protected function get_current_execution_time_limit() {
        if ( ! function_exists( 'ini_get' ) ) {
            return 0;
        }

        return (int) ini_get( 'max_execution_time' );
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
        $limit            = max( 1, absint( $limit ) );

        $query_args = array(
            'post_type'      => 'page',
            'post_status'    => 'publish',
            'orderby'        => array(
                'menu_order' => 'ASC',
                'date'       => 'DESC',
            ),
            'posts_per_page' => $use_full_context ? 50 : min( 20, $limit ),
            'paged'          => 1,
        );

        /**
         * Filter the arguments passed to `get_posts()` when building page summaries.
         *
         * @param array $query_args       Page query arguments.
         * @param array $settings         Plugin settings array.
         * @param bool  $use_full_context Whether full-context mode is active.
         */
        $query_args = apply_filters( 'groui_smart_assistant_context_page_query_args', $query_args, $settings, $use_full_context );

        $per_page = isset( $query_args['posts_per_page'] ) ? absint( $query_args['posts_per_page'] ) : 0;
        if ( $per_page < 1 ) {
            $per_page = $use_full_context ? 50 : min( 20, $limit );
        }
        $query_args['posts_per_page'] = $per_page;
        $current_page                  = isset( $query_args['paged'] ) ? max( 1, absint( $query_args['paged'] ) ) : 1;

        if ( $use_full_context ) {
            $max_pages = (int) apply_filters( 'groui_smart_assistant_context_maximum_pages', -1, $settings );

            if ( 0 === $max_pages ) {
                return array();
            }

            $target_total = ( $max_pages > 0 ) ? $max_pages : PHP_INT_MAX;
        } else {
            $target_total = $limit;
        }

        $summaries = array();

        while ( PHP_INT_MAX === $target_total || count( $summaries ) < $target_total ) {
            $paged_args          = $query_args;
            $paged_args['paged'] = $current_page;

            if ( PHP_INT_MAX !== $target_total ) {
                $remaining = $target_total - count( $summaries );

                if ( $remaining <= 0 ) {
                    break;
                }

                $paged_args['posts_per_page'] = min( $per_page, $remaining );
            }

            $pages = get_posts( $paged_args );

            if ( empty( $pages ) ) {
                break;
            }

            foreach ( $pages as $page ) {
                $summaries[] = array(
                    'title'   => $page->post_title,
                    'url'     => get_permalink( $page ),
                    'excerpt' => wp_trim_words( wp_strip_all_tags( $page->post_content ), 55 ),
                );

                if ( PHP_INT_MAX !== $target_total && count( $summaries ) >= $target_total ) {
                    break 2;
                }
            }

            if ( count( $pages ) < $paged_args['posts_per_page'] ) {
                break;
            }

            $current_page++;
        }

        wp_reset_postdata();

        return $summaries;
    }

    /**
     * Extract FAQ-like headings from pages and posts.
     *
     * @return array List of FAQs with `question` and `source` keys.
     */
    protected function get_faqs_from_content( $settings = array() ) {
        $settings         = wp_parse_args( $settings, array( 'deep_context_mode' => false ) );
        $use_full_context = ! empty( $settings['deep_context_mode'] );

        $query_args = array(
            'post_type'      => array( 'page', 'post' ),
            'post_status'    => 'publish',
            'orderby'        => 'date',
            'order'          => 'DESC',
            'posts_per_page' => $use_full_context ? 25 : 20,
            'paged'          => 1,
        );

        /**
         * Filter the arguments passed to `get_posts()` when collecting FAQs.
         *
         * @param array $query_args       Post query arguments.
         * @param array $settings         Plugin settings array.
         * @param bool  $use_full_context Whether full-context mode is active.
         */
        $query_args = apply_filters( 'groui_smart_assistant_context_faq_query_args', $query_args, $settings, $use_full_context );

        $per_page = isset( $query_args['posts_per_page'] ) ? absint( $query_args['posts_per_page'] ) : 0;
        if ( $per_page < 1 ) {
            $per_page = $use_full_context ? 25 : 20;
        }
        $query_args['posts_per_page'] = $per_page;
        $current_page                  = isset( $query_args['paged'] ) ? max( 1, absint( $query_args['paged'] ) ) : 1;

        if ( $use_full_context ) {
            $max_posts = (int) apply_filters( 'groui_smart_assistant_context_maximum_faq_posts', -1, $settings );

            if ( 0 === $max_posts ) {
                return array();
            }

            $target_total = ( $max_posts > 0 ) ? $max_posts : PHP_INT_MAX;
        } else {
            $target_total = $per_page;
        }

        $faqs = array();

        while ( PHP_INT_MAX === $target_total || count( $faqs ) < $target_total ) {
            $paged_args          = $query_args;
            $paged_args['paged'] = $current_page;

            if ( PHP_INT_MAX !== $target_total ) {
                $remaining = $target_total - count( $faqs );

                if ( $remaining <= 0 ) {
                    break;
                }

                $paged_args['posts_per_page'] = min( $per_page, $remaining );
            }

            $posts = get_posts( $paged_args );

            if ( empty( $posts ) ) {
                break;
            }

            foreach ( $posts as $post ) {
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

                    if ( PHP_INT_MAX !== $target_total && count( $faqs ) >= $target_total ) {
                        break 3;
                    }

                }
            }

            if ( count( $posts ) < $paged_args['posts_per_page'] ) {
                break;
            }

            $current_page++;
        }

        wp_reset_postdata();

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

        $settings         = wp_parse_args( $settings, array( 'deep_context_mode' => false ) );
        $use_full_context = ! empty( $settings['deep_context_mode'] );
        $limit            = max( 0, absint( $limit ) );

        /**
         * Adjust the number of products indexed when the assistant focuses on relevancy.
         *
         * @param int   $limit            Limit requested via plugin settings.
         * @param array $settings         Plugin settings array.
         * @param bool  $use_full_context Whether full-context mode is active.
         */
        $limit = apply_filters( 'groui_smart_assistant_context_product_limit', $limit, $settings, $use_full_context );
        $limit = max( 0, absint( $limit ) );

        $query_args = array(
            'status'   => 'publish',
            'orderby'  => 'date',
            'order'    => 'DESC',
            'paginate' => true,
            'limit'    => $use_full_context ? 50 : ( $limit > 0 ? min( $limit, 20 ) : 12 ),
            'page'     => 1,
        );

        if ( $use_full_context ) {
            /**
             * Define a safety cap when indexing the entire WooCommerce catalogue.
             *
             * Return -1 (default) to include every published product or a positive integer to limit the collection.
             *
             * @param int   $max_catalog Maximum number of products to include, -1 for unlimited.
             * @param array $settings    Plugin settings array.
             */
            $max_catalog = apply_filters( 'groui_smart_assistant_context_maximum_products', -1, $settings );
            $max_catalog = (int) $max_catalog;

            if ( 0 === $max_catalog ) {
                return array();
            }

            $target_total = ( $max_catalog > 0 ) ? $max_catalog : PHP_INT_MAX;
        } else {
            if ( $limit < 1 ) {
                $limit = 12;
            }

            $target_total = $limit;
        }

        if ( ! $use_full_context && $target_total <= 0 ) {
            return array();
        }

        /**
         * Filter the arguments passed to `wc_get_products()` when building the WooCommerce context.
         *
         * @param array $query_args       Default WooCommerce query arguments.
         * @param array $settings         Plugin settings array.
         * @param bool  $use_full_context Whether full-context mode is active.
         */
        $query_args = apply_filters( 'groui_smart_assistant_context_product_query_args', $query_args, $settings, $use_full_context );

        $per_page = isset( $query_args['limit'] ) ? absint( $query_args['limit'] ) : 0;
        if ( $per_page < 1 ) {
            $per_page = $use_full_context ? 50 : min( max( 1, $target_total ), 20 );
        }

        $query_args['limit']    = $per_page;
        $query_args['paginate'] = true;
        $current_page           = isset( $query_args['page'] ) ? max( 1, absint( $query_args['page'] ) ) : 1;

        $summaries = array();

        while ( PHP_INT_MAX === $target_total || count( $summaries ) < $target_total ) {
            $paged_args         = $query_args;
            $paged_args['page'] = $current_page;

            if ( PHP_INT_MAX !== $target_total ) {
                $remaining = $target_total - count( $summaries );

                if ( $remaining <= 0 ) {
                    break;
                }

                $paged_args['limit'] = min( $per_page, $remaining );
            }

            $products = wc_get_products( $paged_args );

            if ( is_wp_error( $products ) ) {
                break;
            }

            if ( isset( $products['products'] ) && is_array( $products['products'] ) ) {
                $product_objects = $products['products'];
            } else {
                $product_objects = $products;
            }

            if ( empty( $product_objects ) ) {
                break;
            }

            foreach ( $product_objects as $product ) {
                if ( ! $product || ! is_object( $product ) ) {
                    continue;
                }

                $summary = $this->build_product_summary( $product );

                if ( ! empty( $summary ) ) {
                    $summaries[] = $summary;
                }

                if ( PHP_INT_MAX !== $target_total && count( $summaries ) >= $target_total ) {
                    break 2;
                }

            }

            if ( count( $product_objects ) < $paged_args['limit'] ) {
                break;
            }

            $current_page++;
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
                'number'     => $use_full_context ? 50 : 20,
                'offset'     => 0,
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

            $per_page = isset( $term_args['number'] ) ? absint( $term_args['number'] ) : 0;
            if ( $per_page < 1 ) {
                $per_page = $use_full_context ? 50 : 20;
            }

            $term_args['number'] = $per_page;
            $offset              = isset( $term_args['offset'] ) ? max( 0, absint( $term_args['offset'] ) ) : 0;

            if ( $use_full_context ) {
                /**
                 * Limit the number of terms fetched per taxonomy when deep-context mode is enabled.
                 *
                 * Return -1 for unlimited (default) or a positive integer to cap the collection.
                 *
                 * @param int    $max_terms Maximum number of terms to include, -1 for unlimited.
                 * @param string $taxonomy  Current taxonomy slug.
                 * @param array  $settings  Plugin settings array.
                 */
                $max_terms = apply_filters( 'groui_smart_assistant_context_maximum_terms', -1, $taxonomy, $settings );
                $max_terms = (int) $max_terms;

                if ( 0 === $max_terms ) {
                    continue;
                }

                $target_total = ( $max_terms > 0 ) ? $max_terms : PHP_INT_MAX;
            } else {
                $target_total = $per_page;
            }

            $collected = array();

            while ( PHP_INT_MAX === $target_total || count( $collected ) < $target_total ) {
                $paged_args           = $term_args;
                $paged_args['offset'] = $offset;

                if ( PHP_INT_MAX !== $target_total ) {
                    $remaining = $target_total - count( $collected );

                    if ( $remaining <= 0 ) {
                        break;
                    }

                    $paged_args['number'] = min( $per_page, $remaining );
                }

                $terms = get_terms( $paged_args );

                if ( is_wp_error( $terms ) || empty( $terms ) ) {
                    break;
                }

                foreach ( $terms as $term ) {
                    $link = get_term_link( $term );
                    if ( is_wp_error( $link ) ) {
                        $link = '';
                    }

                    $collected[] = array(
                        'name'        => $term->name,
                        'description' => wp_trim_words( $term->description, 25 ),
                        'url'         => $link,
                    );

                    if ( PHP_INT_MAX !== $target_total && count( $collected ) >= $target_total ) {
                        break 2;
                    }

                }

                if ( count( $terms ) < $paged_args['number'] ) {
                    break;
                }

                $offset += count( $terms );
            }

            if ( ! empty( $collected ) ) {
                $summary[ $taxonomy ] = $collected;
            }

        }

        return $summary;
    }

}
