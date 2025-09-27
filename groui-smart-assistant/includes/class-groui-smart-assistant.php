<?php
/**
 * Main plugin bootstrap.
 *
 * @package GROUI_Smart_Assistant
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class GROUI_Smart_Assistant {

    /**
     * Singleton instance.
     *
     * @var GROUI_Smart_Assistant
     */
    protected static $instance;

    /**
     * Plugin settings option key.
     */
    const OPTION_KEY = 'groui_smart_assistant_settings';

    /**
     * Cached context transient key.
     */
    const CONTEXT_TRANSIENT = 'groui_smart_assistant_context';

    /**
     * Initialize singleton instance.
     *
     * @return GROUI_Smart_Assistant
     */
    public static function instance() {
        if ( null === static::$instance ) {
            static::$instance = new static();
        }

        return static::$instance;
    }

    /**
     * Constructor.
     */
    protected function __construct() {
        $this->includes();
        $this->hooks();
    }

    /**
     * Include dependencies.
     */
    protected function includes() {
        require_once GROUI_SMART_ASSISTANT_PATH . 'includes/class-groui-smart-assistant-admin.php';
        require_once GROUI_SMART_ASSISTANT_PATH . 'includes/class-groui-smart-assistant-frontend.php';
        require_once GROUI_SMART_ASSISTANT_PATH . 'includes/class-groui-smart-assistant-context.php';
        require_once GROUI_SMART_ASSISTANT_PATH . 'includes/class-groui-smart-assistant-openai.php';
    }

    /**
     * Register hooks.
     */
    protected function hooks() {
        register_activation_hook( GROUI_SMART_ASSISTANT_PATH . 'groui-smart-assistant.php', array( $this, 'on_activate' ) );
        register_deactivation_hook( GROUI_SMART_ASSISTANT_PATH . 'groui-smart-assistant.php', array( $this, 'on_deactivate' ) );
        add_action( 'plugins_loaded', array( $this, 'init_plugin' ) );
    }

    /**
     * Initialize plugin pieces.
     */
    public function init_plugin() {
        new GROUI_Smart_Assistant_Admin();
        new GROUI_Smart_Assistant_Frontend();
    }

    /**
     * Activation tasks.
     */
    public function on_activate() {
        if ( ! wp_next_scheduled( 'groui_smart_assistant_refresh_context' ) ) {
            wp_schedule_event( time(), 'hourly', 'groui_smart_assistant_refresh_context' );
        }

        // Prime the knowledge context to avoid slow first request.
        $context = GROUI_Smart_Assistant_Context::instance();
        $context->refresh_context( true );
    }

    /**
     * Deactivation tasks.
     */
    public function on_deactivate() {
        $timestamp = wp_next_scheduled( 'groui_smart_assistant_refresh_context' );
        if ( $timestamp ) {
            wp_unschedule_event( $timestamp, 'groui_smart_assistant_refresh_context' );
        }

        delete_transient( self::CONTEXT_TRANSIENT );
    }
}
