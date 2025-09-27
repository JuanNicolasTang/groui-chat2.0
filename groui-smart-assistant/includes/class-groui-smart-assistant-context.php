<?php
/**
 * Knowledge context builder for the assistant.
 *
 * @package GROUI_Smart_Assistant
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class GROUI_Smart_Assistant_Context {

    /**
     * Singleton instance.
     *
     * @var GROUI_Smart_Assistant_Context
     */
    protected static $instance;

    /**
     * Retrieve singleton instance.
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
     * @param bool $force Whether to force refresh ignoring cached value.
     *
     * @return array
     */
    public function refresh_context( $force = false ) {
        $context = get_transient( GROUI_Smart_Assistant::CONTEXT_TRANSIENT );

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

        set_transient( GROUI_Smart_Assistant::CONTEXT_TRANSIENT, $context, HOUR_IN_SECONDS );

        return $context;
    }

    /**
     * Retrieve plugin settings.
     *
     * @return array
     */
    protected function get_settings() {
        $defaults = array(
            'openai_api_key'    => '',
            'model'             => 'gpt-5.1',
            'sitemap_url'       => home_url( '/sitemap.xml' ),
            'enable_debug'      => false,
            'max_pages'         => 12,
            'max_products'      => 12,
        );

        return wp_parse_args( get_option( GROUI_Smart_Assistant::OPTION_KEY, array() ), $defaults );
    }

    /**
     * Build sitemap summary.
     *
     * @param array $settings Settings.
     *
     * @return array
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

            if ( count( $urls ) >= 20 ) {
                break;
            }
        }

        return $urls;
    }

    /**
     * Collect page summaries.
     *
     * @param int $limit Number of pages to include.
     *
     * @return array
     */
    protected function get_page_summaries( $limit ) {
        $pages = get_pages( array(
            'post_status' => 'publish',
            'sort_column' => 'menu_order',
            'number'      => absint( $limit ),
        ) );

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
     * Extract FAQs from content headings.
     *
     * @return array
     */
    protected function get_faqs_from_content() {
        $faqs  = array();
        $posts = get_posts( array(
            'post_type'      => array( 'page', 'post' ),
            'posts_per_page' => 20,
            'post_status'    => 'publish',
        ) );

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
            }
        }

        return $faqs;
    }

    /**
     * Gather WooCommerce product summaries.
     *
     * @param int $limit Number of products to include.
     *
     * @return array
     */
    protected function get_product_summaries( $limit ) {
        if ( ! class_exists( 'WooCommerce' ) ) {
            return array();
        }

        $products = wc_get_products( array(
            'status' => 'publish',
            'limit'  => absint( $limit ),
            'orderby'=> 'date',
            'order'  => 'DESC',
        ) );

        $summaries = array();

        foreach ( $products as $product ) {
            $summaries[] = array(
                'id'         => $product->get_id(),
                'name'       => $product->get_name(),
                'price'      => $product->get_price_html(),
                'permalink'  => $product->get_permalink(),
                'image'      => wp_get_attachment_image_url( $product->get_image_id(), 'medium' ),
                'short_desc' => wp_trim_words( wp_strip_all_tags( $product->get_short_description() ), 30 ),
                'categories' => array_map( 'intval', $product->get_category_ids() ),
            );
        }

        return $summaries;
    }

    /**
     * Build taxonomy summaries for product categories and brands.
     *
     * @return array
     */
    protected function get_taxonomy_summaries() {
        $taxonomies = array( 'product_cat', 'product_tag', 'brand', 'category' );
        $summary    = array();

        foreach ( $taxonomies as $taxonomy ) {
            if ( ! taxonomy_exists( $taxonomy ) ) {
                continue;
            }

            $terms = get_terms( array(
                'taxonomy'   => $taxonomy,
                'hide_empty' => true,
                'number'     => 20,
            ) );

            if ( is_wp_error( $terms ) || empty( $terms ) ) {
                continue;
            }

            $summary[ $taxonomy ] = array_map(
                function( $term ) {
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
