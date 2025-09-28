<?php
/**
 * Plugin Name: GROUI Smart Assistant
 * Description: Floating AI assistant integrated with OpenAI GPT‑5, WooCommerce and the site sitemap for contextual answers and product recommendations.
 * Version: 2.2.2
 * Author: GROUI TANG EL MEJOR
 * Text Domain: groui-smart-assistant
 * Domain Path: /languages
 *
 * Este es el archivo principal del plugin. Define constantes, carga las clases
 * requeridas y arranca la instancia singleton del asistente. Se añadió el
 * campo Domain Path para permitir traducciones y se actualizó la versión a
 * 1.0.1.  Mantener este archivo lo más ligero posible; toda la lógica se
 * encuentra en las clases del directorio `includes`.
 */

// Salir si WordPress no está cargado.
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// Definir constantes del plugin solo si no están definidas.
if ( ! defined( 'GROUI_SMART_ASSISTANT_VERSION' ) ) {
    define( 'GROUI_SMART_ASSISTANT_VERSION', '1.0.1' );
}

if ( ! defined( 'GROUI_SMART_ASSISTANT_PATH' ) ) {
    define( 'GROUI_SMART_ASSISTANT_PATH', plugin_dir_path( __FILE__ ) );
}

if ( ! defined( 'GROUI_SMART_ASSISTANT_URL' ) ) {
    define( 'GROUI_SMART_ASSISTANT_URL', plugin_dir_url( __FILE__ ) );
}

/*
 * Fallback definitions for WordPress helper functions.
 *
 * When running outside of a full WordPress environment (for example, in a
 * stand‑alone PHPUnit context), functions like plugin_dir_path() and
 * register_activation_hook() may not exist. Providing lightweight stubs
 * prevents fatal errors while still allowing unit tests to include this
 * file and inspect constants. In a normal WordPress installation these
 * definitions are ignored because the functions already exist.
 */
if ( ! function_exists( 'plugin_dir_path' ) ) {
    function plugin_dir_path( $file ) {
        return dirname( $file ) . '/';
    }
}
if ( ! function_exists( 'plugin_dir_url' ) ) {
    function plugin_dir_url( $file ) {
        return '';
    }
}
if ( ! function_exists( 'register_activation_hook' ) ) {
    function register_activation_hook( $file, $callback ) {
        // No‑op when WordPress is not loaded.
    }
}
if ( ! function_exists( 'register_deactivation_hook' ) ) {
    function register_deactivation_hook( $file, $callback ) {
        // No‑op when WordPress is not loaded.
    }
}
if ( ! function_exists( 'add_action' ) ) {
    function add_action( $hook, $callback, $priority = 10, $accepted_args = 1 ) {
        // No‑op when WordPress is not loaded.
    }
}

// Cargar la clase principal del plugin.
// Solo incluir y ejecutar el plugin si WordPress está cargado.  Comprobamos
// funciones básicas como add_action() para determinar si se trata de un
// entorno WordPress.
if ( function_exists( 'add_action' ) ) {
    require_once GROUI_SMART_ASSISTANT_PATH . 'includes/class-groui-smart-assistant.php';

    /**
     * Obtener instancia del plugin.
     *
     * Esta función envuelve la llamada al método estático de la clase principal
     * para mantener un único punto de entrada. Devuelve la instancia
     * singleton de GROUI_Smart_Assistant.
     *
     * @return GROUI_Smart_Assistant
     */
    function groui_smart_assistant() {
        return GROUI_Smart_Assistant::instance();
    }

    // Iniciar el plugin.
    groui_smart_assistant();
}


