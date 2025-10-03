<?php
/**
 * Main plugin bootstrap.
 *
 * This class wires up the different pieces of the GROUI Smart Assistant
 * plugin. It follows a singleton pattern to ensure only one instance exists
 * during the request lifecycle. On construction it loads the component
 * classes and registers WordPress hooks for activation, deactivation and
 * runtime initialization.
 *
 * @package GROUI_Smart_Assistant
 */

defined( 'ABSPATH' ) || exit;

class GROUI_Smart_Assistant {

    /**
     * Holds the singleton instance.
     *
     * @var self|null
     */
    protected static $instance = null;

    /**
     * Option key for plugin settings stored in wp_options.
     *
     * @var string
     */
    public const OPTION_KEY = 'groui_smart_assistant_settings';

    /**
     * Transient key used to cache the computed context.
     *
     * @var string
     */
    public const CONTEXT_TRANSIENT = 'groui_smart_assistant_context';

    /**
     * Return the singleton instance.
     *
     * @return self
     */
    public static function instance() {
        if ( null === static::$instance ) {
            static::$instance = new static();
        }
        return static::$instance;
    }

    /**
     * GROUI_Smart_Assistant constructor.
     *
     * Loads dependencies and registers hooks. Marked as protected to prevent
     * instantiation from outside the class.
     */
    protected function __construct() {
        $this->includes();
        $this->hooks();
    }

    /**
     * Include required class files.
     */
    protected function includes() {
        require_once GROUI_SMART_ASSISTANT_PATH . 'includes/class-groui-smart-assistant-admin.php';
        require_once GROUI_SMART_ASSISTANT_PATH . 'includes/class-groui-smart-assistant-frontend.php';
        // Load the context class. Note the concatenation operator is '.' in PHP.
        require_once GROUI_SMART_ASSISTANT_PATH . 'includes/class-groui-smart-assistant-context.php';
        require_once GROUI_SMART_ASSISTANT_PATH . 'includes/class-groui-smart-assistant-openai.php';
    }

    /**
     * Register activation/deactivation hooks and runtime actions.
     */
    protected function hooks() {
        register_activation_hook( GROUI_SMART_ASSISTANT_PATH . 'groui-smart-assistant.php', array( $this, 'on_activate' ) );
        register_deactivation_hook( GROUI_SMART_ASSISTANT_PATH . 'groui-smart-assistant.php', array( $this, 'on_deactivate' ) );
        add_action( 'plugins_loaded', array( $this, 'init_plugin' ) );

        add_action( 'save_post', array( $this, 'maybe_invalidate_context_on_post_change' ), 10, 3 );
        add_action( 'deleted_post', array( $this, 'invalidate_context_cache' ) );
        add_action( 'trashed_post', array( $this, 'invalidate_context_cache' ) );
        add_action( 'untrashed_post', array( $this, 'invalidate_context_cache' ) );

        add_action( 'created_term', array( $this, 'maybe_invalidate_context_on_term_change' ), 10, 3 );
        add_action( 'edited_term', array( $this, 'maybe_invalidate_context_on_term_change' ), 10, 3 );
        add_action( 'delete_term', array( $this, 'maybe_invalidate_context_on_term_change' ), 10, 5 );
    }

    /**
     * Load the admin and frontend components once all plugins have loaded.
     *
     * @return void
     */
    public function init_plugin() {
        new GROUI_Smart_Assistant_Admin();
        new GROUI_Smart_Assistant_Frontend();
    }

    /**
     * Activation callback.
     *
     * Schedules the context refresh cron and primes the context cache to
     * prevent a slow first request.
     *
     * @return void
     */
    public function on_activate() {
        if ( ! wp_next_scheduled( 'groui_smart_assistant_refresh_context' ) ) {
            wp_schedule_event( time(), 'hourly', 'groui_smart_assistant_refresh_context' );
        }
        $context = GROUI_Smart_Assistant_Context::instance();
        $context->refresh_context( true );
    }

    /**
     * Deactivation callback.
     *
     * Clears the scheduled event and removes any cached context.
     *
     * @return void
     */
    public function on_deactivate() {
        $timestamp = wp_next_scheduled( 'groui_smart_assistant_refresh_context' );
        if ( $timestamp ) {
            wp_unschedule_event( $timestamp, 'groui_smart_assistant_refresh_context' );
        }
        delete_transient( self::CONTEXT_TRANSIENT );
    }

    /**
     * Invalidate the cached context when relevant posts are created or updated.
     *
     * @param int      $post_id Post ID.
     * @param object    $post    Post object (or data compatible with WP_Post).
     * @param bool     $update  Whether this is an update.
     *
     * @return void
     */
    public function maybe_invalidate_context_on_post_change( $post_id, $post, $update ) {
        unset( $update );

        if ( wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) ) {
            return;
        }

        if ( ! is_object( $post ) || empty( $post->post_type ) ) {
            $post = get_post( $post_id );
        }

        if ( ! is_object( $post ) || empty( $post->post_type ) ) {
            return;
        }

        $post_type = $post->post_type;

        $relevant_post_types = apply_filters(
            'groui_smart_assistant_relevant_post_types',
            array( 'page', 'post', 'product' ),
            $post
        );

        if ( in_array( $post_type, $relevant_post_types, true ) ) {
            $this->invalidate_context_cache();
        }
    }

    /**
     * Invalidate the cached context when taxonomy terms change.
     *
     * @param int    $term_id Term ID.
     * @param int    $tt_id   Term taxonomy ID.
     * @param string $taxonomy Taxonomy slug.
     * @param mixed  ...$args Additional arguments supplied by hooks such as delete_term.
     *
     * @return void
     */
    public function maybe_invalidate_context_on_term_change( $term_id, $tt_id = 0, $taxonomy = '', ...$args ) {
        unset( $term_id, $tt_id, $args );

        if ( function_exists( 'sanitize_key' ) ) {
            $taxonomy = sanitize_key( $taxonomy );
        } else {
            $taxonomy = strtolower( preg_replace( '/[^a-z0-9_\-]/', '', (string) $taxonomy ) );
        }

        if ( empty( $taxonomy ) ) {
            return;
        }

        $relevant_taxonomies = apply_filters(
            'groui_smart_assistant_relevant_taxonomies',
            array( 'product_cat', 'product_tag', 'product_brand', 'pwb-brand', 'brand', 'category' ),
            $taxonomy
        );

        if ( in_array( $taxonomy, $relevant_taxonomies, true ) ) {
            $this->invalidate_context_cache();
        }
    }

    /**
     * Remove the cached context and queue a quick refresh.
     *
     * @return void
     */
    public function invalidate_context_cache( ...$unused ) {
        unset( $unused );
        delete_transient( self::CONTEXT_TRANSIENT );
        $this->schedule_context_refresh();
    }

    /**
     * Schedule a near-immediate context refresh through WP-Cron.
     *
     * @return void
     */
    protected function schedule_context_refresh() {
        if ( ! wp_next_scheduled( 'groui_smart_assistant_refresh_context_single' ) ) {
            $delay = apply_filters( 'groui_smart_assistant_context_refresh_delay', 30 );
            $delay = max( 5, absint( $delay ) );
            wp_schedule_single_event( time() + $delay, 'groui_smart_assistant_refresh_context_single' );
        }
    }
}
