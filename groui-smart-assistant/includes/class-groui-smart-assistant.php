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
}
