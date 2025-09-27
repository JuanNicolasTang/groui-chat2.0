<?php
/**
 * Plugin Name: GROUI Smart Assistant
 * Description: Floating AI assistant integrated with OpenAI GPT-5, WooCommerce, and site sitemap data.
 * Version: 1.0.0
 * Author: GROUI
 * Text Domain: groui-smart-assistant
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

if ( ! defined( 'GROUI_SMART_ASSISTANT_VERSION' ) ) {
    define( 'GROUI_SMART_ASSISTANT_VERSION', '1.0.0' );
}

if ( ! defined( 'GROUI_SMART_ASSISTANT_PATH' ) ) {
    define( 'GROUI_SMART_ASSISTANT_PATH', plugin_dir_path( __FILE__ ) );
}

if ( ! defined( 'GROUI_SMART_ASSISTANT_URL' ) ) {
    define( 'GROUI_SMART_ASSISTANT_URL', plugin_dir_url( __FILE__ ) );
}

require_once GROUI_SMART_ASSISTANT_PATH . 'includes/class-groui-smart-assistant.php';

function groui_smart_assistant() {
    return GROUI_Smart_Assistant::instance();
}

groui_smart_assistant();
