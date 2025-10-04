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
            'model'          => 'gpt-5',
            'sitemap_url'    => home_url( '/sitemap.xml' ),
            'enable_debug'   => false,
            // Use 0 to represent unlimited pages/products.
            'max_pages'      => 0,
            'max_products'   => 0,
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
        $response    = wp_remote_get( $sitemap_url, array( 'timeout' => 15 ) );

        if ( is_wp_error( $response ) ) {
            return array();
        }

        $body = wp_remote_retrieve_body( $response );
        if ( empty( $body ) ) {
            return array();
        }

        libxml_use_internal_errors( true );
        $xml = simplexml_load_string( $body );
        libxml_clear_errors();

        if ( ! $xml ) {
            return array();
        }

        $urls = array();
        foreach ( $xml->url as $entry ) {
            $loc = isset( $entry->loc ) ? (string) $entry->loc : '';
            if ( empty( $loc ) ) {
                continue;
            }

            $urls[] = array(
                'url'     => $loc,
                'lastmod' => isset( $entry->lastmod ) ? (string) $entry->lastmod : '',
            );
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
        $args = array(
            'post_status' => 'publish',
            'sort_column' => 'menu_order',
        );
        // If limit > 0, restrict to that number; else fetch all.
        if ( $limit > 0 ) {
            $args['number'] = absint( $limit );
        } else {
            // Using 0 returns all pages.
            $args['number'] = 0;
        }
        $pages = get_pages( $args );

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
    protected function get_faqs_from_content() {
        $faqs  = array();
        $posts = get_posts( array(
            'post_type'      => array( 'page', 'post' ),
            'posts_per_page' => -1, // Fetch all posts and pages.
            'post_status'    => 'publish',
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
            }
        }

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
        if ( ! class_exists( 'WooCommerce' ) ) {
            return array();
        }

        $args = array(
            'status'      => 'publish',
            // Randomize product order to provide a more varied sampling.  When
            // building the context we don't care about recency; random ordering
            // prevents the same products from always appearing at the top of
            // the list and allows the assistant to draw from across the catalog.
            'orderby'     => 'rand',
            // Only include in‑stock products.
            'stock_status'=> 'instock',
        );
        if ( $limit > 0 ) {
            $args['limit'] = absint( $limit );
        } else {
            // -1 returns all products.
            $args['limit'] = -1;
        }

        $products = wc_get_products( $args );

        $summaries = array();
        foreach ( $products as $product ) {
            $summaries[] = array(
                'id'         => $product->get_id(),
                'name'       => $product->get_name(),
                'price'      => wp_strip_all_tags( $product->get_price_html() ),
                'permalink'  => $product->get_permalink(),
                'image'      => wp_get_attachment_image_url( $product->get_image_id(), 'medium' ),
                'short_desc' => wp_trim_words( wp_strip_all_tags( $product->get_short_description() ), 30 ),
                'categories' => array_map( 'intval', $product->get_category_ids() ),
            );
        }

        return $summaries;
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
        $taxonomies = array( 'product_cat', 'product_tag', 'category' );

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
        if ( taxonomy_exists( 'brand' ) ) {
            $taxonomies[] = 'brand';
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