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
            'pages'      => $this->get_page_summaries( $settings['max_pages'] ),
            'faqs'       => $this->get_faqs_from_content(),
            'products'   => $this->get_product_summaries( $settings['max_products'] ),
            'categories' => $this->get_taxonomy_summaries(),
        );

        $context = $this->compact_context( $context );

        // Cache the built context for one hour.
        set_transient( GROUI_Smart_Assistant::CONTEXT_TRANSIENT, $context, HOUR_IN_SECONDS );

        return $context;
    }

    /**
     * Ensure the context stays within sane limits for the OpenAI API.
     *
     * Applies collection-specific maximums exposed via filters and, if the final
     * JSON-encoded payload exceeds the configured byte threshold, progressively
     * trims the lists and/or replaces them with summaries so the payload fits.
     * Metadata about the pruning is appended under the `_meta` key.
     *
     * @param array $context Raw context data.
     *
     * @return array Possibly compacted context.
     */
    protected function compact_context( array $context ) {
        $collections = array( 'pages', 'faqs', 'products', 'categories', 'sitemap' );
        $meta        = array(
            'warnings'      => array(),
            'errors'        => array(),
            'pruned_types'  => array(),
            'limit_bytes'   => 0,
            'bytes'         => 0,
        );
        $pruned      = false;

        foreach ( $collections as $collection ) {
            if ( empty( $context[ $collection ] ) || ! is_array( $context[ $collection ] ) ) {
                continue;
            }

            $limit = absint( apply_filters( "groui_smart_assistant_max_{$collection}", 0, $context ) );

            if ( $limit < 1 ) {
                continue;
            }

            if ( 'categories' === $collection ) {
                $original = $context[ $collection ];
                $changed  = array();

                foreach ( $context[ $collection ] as $taxonomy => $terms ) {
                    if ( ! is_array( $terms ) ) {
                        continue;
                    }

                    if ( count( $terms ) > $limit ) {
                        $context[ $collection ][ $taxonomy ] = array_slice( $terms, 0, $limit, true );

                        if ( isset( $original[ $taxonomy ] ) ) {
                            $changed[] = $taxonomy;
                        }
                    }
                }

                if ( ! empty( $changed ) ) {
                    $pruned = true;
                    if ( ! isset( $meta['pruned_types'][ $collection ] ) ) {
                        $meta['pruned_types'][ $collection ] = array();
                    }
                    $meta['pruned_types'][ $collection ][] = array(
                        'reason'   => 'filter_limit',
                        'limit'    => $limit,
                        'taxonomy' => $changed,
                    );
                }
            } else {
                $original_count = count( $context[ $collection ] );

                if ( $original_count > $limit ) {
                    $context[ $collection ] = array_slice( $context[ $collection ], 0, $limit, true );

                    $pruned = true;
                    if ( ! isset( $meta['pruned_types'][ $collection ] ) ) {
                        $meta['pruned_types'][ $collection ] = array();
                    }
                    $meta['pruned_types'][ $collection ][] = array(
                        'reason' => 'filter_limit',
                        'kept'   => $limit,
                        'total'  => $original_count,
                    );
                }
            }
        }

        $threshold = absint( apply_filters( 'groui_smart_assistant_context_max_bytes', 200 * 1024, $context ) );
        if ( $threshold < 1024 ) {
            $threshold = 200 * 1024;
        }

        $meta['limit_bytes'] = $threshold;

        $size = strlen( (string) wp_json_encode( $context ) );

        if ( $size > $threshold ) {
            $totals = array();

            foreach ( $collections as $collection ) {
                if ( ! isset( $context[ $collection ] ) || ! is_array( $context[ $collection ] ) ) {
                    $totals[ $collection ] = 0;
                    continue;
                }

                if ( 'categories' === $collection ) {
                    $totals[ $collection ] = array();
                    foreach ( $context[ $collection ] as $taxonomy => $terms ) {
                        $totals[ $collection ][ $taxonomy ] = is_array( $terms ) ? count( $terms ) : 0;
                    }
                } else {
                    $totals[ $collection ] = count( $context[ $collection ] );
                }
            }

            $iteration    = 0;
            $max_attempts = 25;

            while ( $size > $threshold && $iteration < $max_attempts ) {
                $iteration++;
                $modified = false;

                foreach ( $collections as $collection ) {
                    if ( $size <= $threshold ) {
                        break;
                    }

                    if ( empty( $context[ $collection ] ) || ! is_array( $context[ $collection ] ) ) {
                        continue;
                    }

                    if ( isset( $context[ $collection ]['summary'] ) ) {
                        continue;
                    }

                    if ( 'categories' === $collection ) {
                        $changed_taxonomies = array();

                        foreach ( $context[ $collection ] as $taxonomy => $terms ) {
                            if ( ! is_array( $terms ) ) {
                                continue;
                            }

                            $current_count = count( $terms );
                            if ( $current_count > 3 ) {
                                $new_count = max( 1, (int) floor( $current_count / 2 ) );

                                if ( $new_count < $current_count ) {
                                    $context[ $collection ][ $taxonomy ] = array_slice( $terms, 0, $new_count, true );
                                    $changed_taxonomies[ $taxonomy ]      = $new_count;
                                }
                            }
                        }

                        if ( ! empty( $changed_taxonomies ) ) {
                            $pruned = true;
                            if ( ! isset( $meta['pruned_types'][ $collection ] ) ) {
                                $meta['pruned_types'][ $collection ] = array();
                            }
                            $meta['pruned_types'][ $collection ][] = array(
                                'reason' => 'size_limit',
                                'kept'   => $changed_taxonomies,
                                'total'  => $totals[ $collection ],
                            );

                            $modified = true;
                            $size     = strlen( (string) wp_json_encode( $context ) );
                        }
                    } else {
                        $current_count = count( $context[ $collection ] );
                        if ( $current_count > 3 ) {
                            $new_count = max( 1, (int) floor( $current_count / 2 ) );

                            if ( $new_count < $current_count ) {
                                $context[ $collection ] = array_slice( $context[ $collection ], 0, $new_count, true );

                                $pruned = true;
                                if ( ! isset( $meta['pruned_types'][ $collection ] ) ) {
                                    $meta['pruned_types'][ $collection ] = array();
                                }
                                $meta['pruned_types'][ $collection ][] = array(
                                    'reason' => 'size_limit',
                                    'kept'   => $new_count,
                                    'total'  => $totals[ $collection ],
                                );

                                $modified = true;
                                $size     = strlen( (string) wp_json_encode( $context ) );
                            }
                        }
                    }
                }

                if ( $size <= $threshold ) {
                    break;
                }

                if ( ! $modified ) {
                    foreach ( $collections as $collection ) {
                        if ( $size <= $threshold ) {
                            break;
                        }

                        if ( empty( $context[ $collection ] ) || ! is_array( $context[ $collection ] ) ) {
                            continue;
                        }

                        $summary_payload = $this->build_collection_summary( $collection, $context[ $collection ], $totals[ $collection ] );

                        if ( null === $summary_payload ) {
                            continue;
                        }

                        $context[ $collection ] = array( 'summary' => $summary_payload );

                        $pruned = true;
                        if ( ! isset( $meta['pruned_types'][ $collection ] ) ) {
                            $meta['pruned_types'][ $collection ] = array();
                        }
                        $meta['pruned_types'][ $collection ][] = array(
                            'reason' => 'summary',
                            'total'  => $totals[ $collection ],
                        );

                        $modified = true;
                        $size     = strlen( (string) wp_json_encode( $context ) );
                    }
                }

                if ( ! $modified ) {
                    break;
                }
            }
        }

        $meta['bytes'] = $size;

        $has_content = false;

        if ( ! empty( $context['site'] ) || ! empty( $context['tagline'] ) ) {
            $has_content = true;
        }

        if ( ! $has_content ) {
            foreach ( $collections as $collection ) {
                if ( ! empty( $context[ $collection ] ) ) {
                    $has_content = true;
                    break;
                }
            }
        }

        if ( $pruned && ! empty( $meta['pruned_types'] ) ) {
            $meta['warnings'][] = array(
                'code'    => 'context_pruned',
                'message' => __( 'El contexto del sitio se recortó para cumplir los límites configurados.', 'groui-smart-assistant' ),
                'types'   => $meta['pruned_types'],
            );
        }

        if ( ! $has_content ) {
            $meta['errors'][] = array(
                'code'    => 'context_empty',
                'message' => __( 'El contexto se quedó vacío después de aplicar los límites.', 'groui-smart-assistant' ),
                'types'   => $meta['pruned_types'],
            );
        }

        $context['_meta'] = $meta;

        return $context;
    }

    /**
     * Build a compact summary for a specific collection when pruning is required.
     *
     * @param string     $collection Collection name (pages, faqs, products, categories, sitemap).
     * @param array      $data       Collection data.
     * @param int|array  $totals     Original totals captured before trimming.
     *
     * @return array|null Summary payload or null if the collection cannot be summarised.
     */
    protected function build_collection_summary( $collection, $data, $totals ) {
        if ( ! is_array( $data ) ) {
            return null;
        }

        if ( 'categories' === $collection ) {
            $total_taxonomies = count( $data );
            $total_terms      = 0;
            $samples          = array();

            foreach ( $data as $taxonomy => $terms ) {
                $term_list = is_array( $terms ) ? $terms : array();
                $term_count = count( $term_list );
                $total_terms += $term_count;

                $samples[] = array(
                    'taxonomy' => $taxonomy,
                    'total'    => $term_count,
                    'terms'    => array_slice( $term_list, 0, min( 3, $term_count ), true ),
                );
            }

            return array(
                'total_taxonomies' => $total_taxonomies,
                'total_terms'      => $total_terms,
                'samples'          => array_slice( $samples, 0, 3 ),
            );
        }

        $sample = array_slice( $data, 0, min( 3, count( $data ) ), true );

        return array(
            'total'  => is_array( $totals ) ? array_sum( array_map( 'intval', $totals ) ) : (int) $totals,
            'sample' => $sample,
        );
    }

    /**
     * Retrieve plugin settings with sensible defaults.
     *
     * @return array The settings array.
     */
    protected function get_settings() {
        $defaults = array(
            'openai_api_key' => '',
            'model'          => 'gpt-5',
            'sitemap_url'    => home_url( '/sitemap.xml' ),
            'enable_debug'   => false,
            // Use 0 to represent unlimited pages/products.
            'max_pages'      => 0,
            'max_products'   => 0,
            'openai_timeout' => 30,
        );

        // Merge stored options with defaults, falling back when keys are missing.
        return wp_parse_args( get_option( GROUI_Smart_Assistant::OPTION_KEY, array() ), $defaults );
    }

    /**
     * Build a simple sitemap summary by reading all URL entries from the sitemap.
     *
     * @param array $settings Plugin settings.
     *
     * @return array List of sitemap entries with `url` and `lastmod` keys.
     */
    protected function get_sitemap_summary( $settings ) {
        $sitemap_url = ! empty( $settings['sitemap_url'] ) ? esc_url_raw( $settings['sitemap_url'] ) : home_url( '/sitemap.xml' );
        $max_urls    = absint( apply_filters( 'groui_smart_assistant_max_sitemap_urls', 500, $settings ) );
        $max_files   = absint( apply_filters( 'groui_smart_assistant_max_sitemap_files', 25, $settings ) );
        $debug       = ! empty( $settings['enable_debug'] );

        $urls       = array();
        $queue      = array( $sitemap_url );
        $processed  = array();
        $fetched    = 0;

        while ( ! empty( $queue ) ) {
            if ( $max_urls > 0 && count( $urls ) >= $max_urls ) {
                break;
            }

            if ( $max_files > 0 && $fetched >= $max_files ) {
                break;
            }

            $current = array_shift( $queue );

            if ( isset( $processed[ $current ] ) ) {
                continue;
            }

            $processed[ $current ] = true;
            $fetched++;

            $response = wp_remote_get( $current, array( 'timeout' => 15 ) );

            if ( is_wp_error( $response ) ) {
                if ( $debug ) {
                    error_log( sprintf( '[GROUI Smart Assistant] Error obteniendo el sitemap %s: %s', $current, $response->get_error_message() ) );
                }

                return $urls;
            }

            $body = wp_remote_retrieve_body( $response );

            if ( empty( $body ) ) {
                if ( $debug ) {
                    error_log( sprintf( '[GROUI Smart Assistant] Respuesta vacía al obtener el sitemap %s', $current ) );
                }

                return $urls;
            }

            $previous_libxml_state = libxml_use_internal_errors( true );
            $xml                   = simplexml_load_string( $body );

            if ( ! $xml ) {
                if ( $debug ) {
                    $libxml_errors = libxml_get_errors();
                    $message       = ! empty( $libxml_errors ) ? trim( $libxml_errors[0]->message ) : 'Error desconocido al parsear XML.';
                    error_log( sprintf( '[GROUI Smart Assistant] Error parseando el sitemap %s: %s', $current, $message ) );
                }

                libxml_clear_errors();
                libxml_use_internal_errors( $previous_libxml_state );

                return $urls;
            }

            libxml_clear_errors();
            libxml_use_internal_errors( $previous_libxml_state );

            $root = strtolower( $xml->getName() );

            if ( 'sitemapindex' === $root || isset( $xml->sitemap ) ) {
                foreach ( $xml->sitemap as $child ) {
                    if ( $max_files > 0 && ( $fetched + count( $queue ) ) >= $max_files ) {
                        break;
                    }

                    $loc = isset( $child->loc ) ? trim( (string) $child->loc ) : '';

                    if ( empty( $loc ) || isset( $processed[ $loc ] ) ) {
                        continue;
                    }

                    $queue[] = $loc;
                }

                continue;
            }

            if ( 'urlset' === $root || isset( $xml->url ) ) {
                foreach ( $xml->url as $entry ) {
                    if ( $max_urls > 0 && count( $urls ) >= $max_urls ) {
                        break 2;
                    }

                    $loc = isset( $entry->loc ) ? trim( (string) $entry->loc ) : '';

                    if ( empty( $loc ) ) {
                        continue;
                    }

                    $urls[] = array(
                        'url'     => $loc,
                        'lastmod' => isset( $entry->lastmod ) ? (string) $entry->lastmod : '',
                    );

                    if ( $max_urls > 0 && count( $urls ) >= $max_urls ) {
                        break 2;
                    }
                }
            }
        }

        return $urls;
    }

    /**
     * Collect summaries for the most important pages on the site.
     *
     * @param int $limit Number of pages to include. 0 means unlimited.
     *
     * @return array List of page summaries with `title`, `url` and `excerpt` keys.
     */
    protected function get_page_summaries( $limit ) {
        global $wpdb;

        // We'll fetch published pages in chunks to avoid running into timeouts when
        // the site has hundreds or thousands of pages.  When a non‑zero limit is
        // specified, we can rely on WordPress to limit the query natively.  When
        // $limit is 0, we treat it as unlimited and paginate through the pages in
        // chunks of 200 posts at a time.
        $limit = isset( $limit ) ? absint( $limit ) : 0;
        $per_page = 200; // Number of pages to fetch per batch when unlimited.
        $paged    = 1;
        $collected = array();

        if ( $limit > 0 ) {
            // Use get_posts with posts_per_page to constrain to the requested
            // number.  It respects pagination internals and is more flexible
            // than get_pages.
            $posts = get_posts( array(
                'post_type'      => 'page',
                'post_status'    => 'publish',
                'posts_per_page' => $limit,
                'orderby'        => 'menu_order',
                'order'          => 'ASC',
                'fields'         => 'ids',
            ) );

            foreach ( $posts as $post_id ) {
                $collected[] = array(
                    'title'   => get_the_title( $post_id ),
                    'url'     => get_permalink( $post_id ),
                    'excerpt' => wp_trim_words( wp_strip_all_tags( get_post_field( 'post_content', $post_id ) ), 55 ),
                );
            }
        } else {
            // Unlimited: loop through all pages in batches until no more are found.
            do {
                $query = new WP_Query( array(
                    'post_type'      => 'page',
                    'post_status'    => 'publish',
                    'posts_per_page' => $per_page,
                    'paged'          => $paged,
                    'orderby'        => 'menu_order',
                    'order'          => 'ASC',
                    'fields'         => 'ids',
                ) );

                if ( ! $query->have_posts() ) {
                    break;
                }

                foreach ( $query->posts as $post_id ) {
                    $collected[] = array(
                        'title'   => get_the_title( $post_id ),
                        'url'     => get_permalink( $post_id ),
                        'excerpt' => wp_trim_words( wp_strip_all_tags( get_post_field( 'post_content', $post_id ) ), 55 ),
                    );
                }

                $paged++;

                // Free WP_Query resources.
                wp_reset_postdata();

                // Break out if we've collected a very large number of pages (10000) to
                // prevent runaway loops from extremely large sites.
                if ( count( $collected ) >= 10000 ) {
                    break;
                }

            } while ( true );
        }

        return $collected;
    }

    /**
     * Extract FAQ-like headings from pages and posts.
     *
     * @return array List of FAQs with `question` and `source` keys.
     */
    protected function get_faqs_from_content() {
        $faqs       = array();
        $paged      = 1;
        $reached_max = false;

        $batch_size = absint( apply_filters( 'groui_smart_assistant_faq_batch_size', 200 ) );
        if ( $batch_size < 1 ) {
            $batch_size = 200;
        }

        $max_faqs = absint( apply_filters( 'groui_smart_assistant_max_faqs', 0 ) );

        $query_args = array(
            'post_type'      => array( 'page', 'post' ),
            'post_status'    => 'publish',
            'orderby'        => 'ID',
            'order'          => 'ASC',
            'fields'         => 'ids',
            'posts_per_page' => $batch_size,
        );

        do {
            $query_args['paged'] = $paged;

            $query = new WP_Query( $query_args );

            if ( ! $query->have_posts() ) {
                break;
            }

            $batch_ids = $query->posts;

            if ( empty( $batch_ids ) ) {
                break;
            }

            $posts = get_posts( array(
                'post__in'       => $batch_ids,
                'post_type'      => array( 'page', 'post' ),
                'post_status'    => 'publish',
                'orderby'        => 'post__in',
                'posts_per_page' => count( $batch_ids ),
            ) );

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

                    if ( $max_faqs > 0 && count( $faqs ) >= $max_faqs ) {
                        $reached_max = true;
                        break;
                    }
                }

                if ( $reached_max ) {
                    break;
                }
            }

            wp_reset_postdata();

            if ( $reached_max ) {
                break;
            }

            $paged++;
        } while ( true );

        return $faqs;
    }

    /**
     * Gather WooCommerce product summaries.
     *
     * @param int $limit Number of products to include. 0 means unlimited.
     *
     * @return array List of product summaries with keys such as `id`, `name`, `price`, `permalink`, `image`, `short_desc` and `categories`.
     */
    protected function get_product_summaries( $limit ) {
        // Ensure WooCommerce is active before querying products.
        if ( ! class_exists( 'WooCommerce' ) ) {
            return array();
        }

        $limit     = isset( $limit ) ? absint( $limit ) : 0;
        $per_page  = 200;
        $page      = 1;
        $collected = array();

        // Allow preserving the legacy behaviour where product selections were
        // randomized every time the context was rebuilt.  This keeps the
        // optimised pagination logic while avoiding the expensive SQL RAND()
        // for large catalogues.
        $should_randomize = apply_filters( 'groui_smart_assistant_randomize_products', true, $limit );

        // Build base arguments shared across batches.  Fetch only published,
        // in-stock products to mirror the legacy context builder.
        $base_args = array(
            'status'       => 'publish',
            'stock_status' => 'instock',
        );

        if ( $limit > 0 ) {
            // Constrain to the requested number of products.  To preserve the
            // historic random product sampling we use orderby=rand only when a
            // finite limit is requested.  This mirrors the legacy behaviour
            // without impacting the unbounded pagination path.
            $args = $base_args;
            $args['limit'] = $limit;

            if ( $should_randomize ) {
                $args['orderby'] = 'rand';
            } else {
                $args['orderby'] = 'id';
                $args['order']   = 'ASC';
            }

            $products = wc_get_products( $args );

            foreach ( $products as $product ) {
                $collected[] = array(
                    'id'         => $product->get_id(),
                    'name'       => $product->get_name(),
                    'price'      => wp_strip_all_tags( $product->get_price_html() ),
                    'permalink'  => $product->get_permalink(),
                    'image'      => wp_get_attachment_image_url( $product->get_image_id(), 'medium' ),
                    'short_desc' => wp_trim_words( wp_strip_all_tags( $product->get_short_description() ), 30 ),
                    'categories' => array_map( 'intval', $product->get_category_ids() ),
                );
            }
        } else {
            // Unlimited: iterate through all products in batches.  We'll
            // increment the page parameter until no more products are returned.
            $base_args['orderby'] = 'id';
            $base_args['order']   = 'ASC';

            do {
                $args            = $base_args;
                $args['limit']   = $per_page;
                $args['page']    = $page;

                $products = wc_get_products( $args );
                if ( empty( $products ) ) {
                    break;
                }

                foreach ( $products as $product ) {
                    $collected[] = array(
                        'id'         => $product->get_id(),
                        'name'       => $product->get_name(),
                        'price'      => wp_strip_all_tags( $product->get_price_html() ),
                        'permalink'  => $product->get_permalink(),
                        'image'      => wp_get_attachment_image_url( $product->get_image_id(), 'medium' ),
                        'short_desc' => wp_trim_words( wp_strip_all_tags( $product->get_short_description() ), 30 ),
                        'categories' => array_map( 'intval', $product->get_category_ids() ),
                    );
                }

                $page++;

                // Safety: if we have unexpectedly many products (e.g. >10000), break
                // to avoid unbounded loops.  10000 is an arbitrary high ceiling
                // chosen to prevent runaway indexing on very large catalogs.
                if ( count( $collected ) >= 10000 ) {
                    break;
                }
            } while ( true );
        }

        // Shuffle the collected products to provide variety in the context
        // when randomisation is enabled.  Unlimited contexts are shuffled in
        // PHP to avoid expensive RAND() queries while still giving assistants a
        // varied set of examples.
        if ( $should_randomize && count( $collected ) > 1 ) {
            shuffle( $collected );
        }

        return $collected;
    }

    /**
     * Build taxonomy summaries for product categories and other relevant taxonomies.
     *
     * @return array Associative array keyed by taxonomy name with lists of term summaries.
     */
    protected function get_taxonomy_summaries() {
        // Build a more comprehensive list of relevant taxonomies.  Always include
        // default WooCommerce taxonomies and core categories.  Then append all
        // attribute taxonomies (prefixed with "pa_") and a "brand" taxonomy if
        // available.  This ensures the assistant indexes categories, tags, brands
        // and custom attributes without requiring manual updates.
        $taxonomies = array( 'product_cat', 'product_tag', 'category', 'product_brand' );

        // Append all attribute taxonomies registered by WooCommerce (these are
        // prefixed with "pa_" and returned by wc_get_attribute_taxonomy_names()).
        if ( function_exists( 'wc_get_attribute_taxonomy_names' ) ) {
            $attribute_taxonomies = wc_get_attribute_taxonomy_names();
            if ( is_array( $attribute_taxonomies ) ) {
                $taxonomies = array_merge( $taxonomies, $attribute_taxonomies );
            }
        }

        // Include a generic brand taxonomy if it exists (for example,
        // provided by YITH Brands or other branding plugins).
        $brand_taxonomies = array( 'brand', 'pwb-brand', 'yith_product_brand' );

        foreach ( $brand_taxonomies as $brand_taxonomy ) {
            if ( taxonomy_exists( $brand_taxonomy ) ) {
                $taxonomies[] = $brand_taxonomy;
            }
        }

        // Remove any duplicates.
        $taxonomies = array_unique( $taxonomies );

        $summary = array();
        foreach ( $taxonomies as $taxonomy ) {
            if ( ! taxonomy_exists( $taxonomy ) ) {
                continue;
            }
            $terms = get_terms( array(
                'taxonomy'   => $taxonomy,
                'hide_empty' => true,
                'number'     => 0, // 0 = fetch all terms.
            ) );
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