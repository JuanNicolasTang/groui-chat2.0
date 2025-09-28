<?php
/**
 * Admin settings for the GROUI Smart Assistant.
 *
 * This class registers a top‑level menu in the WordPress admin where site
 * administrators can configure the plugin. It defines and sanitizes the
 * available options, renders the settings page and displays notices when
 * required configuration (such as an OpenAI API key) is missing.
 *
 * @package GROUI_Smart_Assistant
 */

defined( 'ABSPATH' ) || exit;

class GROUI_Smart_Assistant_Admin {

    /**
     * Constructor.
     *
     * Hooks into WordPress to register admin screens, settings and notices.
     */
    public function __construct() {
        add_action( 'admin_menu', array( $this, 'register_menu' ) );
        add_action( 'admin_init', array( $this, 'register_settings' ) );
        add_action( 'admin_notices', array( $this, 'maybe_show_missing_key_notice' ) );
        add_action( 'groui_smart_assistant_refresh_context', array( $this, 'refresh_context_cron' ) );
    }

    /**
     * Register the plugin menu in the dashboard.
     *
     * @return void
     */
    public function register_menu() {
        add_menu_page(
            __( 'GROUI Assistant', 'groui-smart-assistant' ),
            __( 'GROUI Assistant', 'groui-smart-assistant' ),
            'manage_options',
            'groui-smart-assistant',
            array( $this, 'render_settings_page' ),
            'dashicons-format-chat'
        );
    }

    /**
     * Register plugin settings and fields.
     *
     * @return void
     */
    public function register_settings() {
        register_setting( 'groui-smart-assistant', GROUI_Smart_Assistant::OPTION_KEY, array( $this, 'sanitize_settings' ) );

        // General section.
        add_settings_section(
            'groui_smart_assistant_general',
            __( 'Configuración General', 'groui-smart-assistant' ),
            '__return_false',
            'groui-smart-assistant'
        );

        // API key.
        add_settings_field(
            'openai_api_key',
            __( 'OpenAI API Key', 'groui-smart-assistant' ),
            array( $this, 'render_text_field' ),
            'groui-smart-assistant',
            'groui_smart_assistant_general',
            array(
                'id'          => 'openai_api_key',
                'description' => __( 'Introduce tu clave de OpenAI con acceso a GPT‑5.', 'groui-smart-assistant' ),
            )
        );

        // Model.
        add_settings_field(
            'model',
            __( 'Modelo de OpenAI', 'groui-smart-assistant' ),
            array( $this, 'render_text_field' ),
            'groui-smart-assistant',
            'groui_smart_assistant_general',
            array(
                'id'          => 'model',
                // Provide guidance on the default OpenAI model.  Use GPT‑5 unless overridden.
                'description' => __( 'Por defecto: gpt‑5', 'groui-smart-assistant' ),
            )
        );

        // Sitemap URL.
        add_settings_field(
            'sitemap_url',
            __( 'URL del sitemap', 'groui-smart-assistant' ),
            array( $this, 'render_text_field' ),
            'groui-smart-assistant',
            'groui_smart_assistant_general',
            array(
                'id'          => 'sitemap_url',
                'description' => __( 'Se usará para extraer conocimiento de la web.', 'groui-smart-assistant' ),
            )
        );

        // Max pages.
        add_settings_field(
            'max_pages',
            __( 'Máximo de páginas a indexar', 'groui-smart-assistant' ),
            array( $this, 'render_number_field' ),
            'groui-smart-assistant',
            'groui_smart_assistant_general',
            array(
                'id'          => 'max_pages',
                'description' => __( 'Límite de páginas para el contexto.', 'groui-smart-assistant' ),
                'min'         => 5,
                'max'         => 50,
            )
        );

        // Max products.
        add_settings_field(
            'max_products',
            __( 'Máximo de productos a indexar', 'groui-smart-assistant' ),
            array( $this, 'render_number_field' ),
            'groui-smart-assistant',
            'groui_smart_assistant_general',
            array(
                'id'          => 'max_products',
                'description' => __( 'Limita la cantidad de productos que se envían al modelo.', 'groui-smart-assistant' ),
                'min'         => 5,
                'max'         => 50,
            )
        );

        // Debug toggle.
        add_settings_field(
            'enable_debug',
            __( 'Habilitar modo depuración', 'groui-smart-assistant' ),
            array( $this, 'render_checkbox_field' ),
            'groui-smart-assistant',
            'groui_smart_assistant_general',
            array(
                'id'          => 'enable_debug',
                'description' => __( 'Registra información adicional en el log.', 'groui-smart-assistant' ),
            )
        );
    }

    /**
     * Sanitize settings callback.
     *
     * Ensures all options are sanitized and constrained to expected ranges.
     *
     * @param array $settings Raw settings.
     *
     * @return array Sanitized values.
     */
    public function sanitize_settings( $settings ) {
        $sanitized = array();
        $sanitized['openai_api_key'] = isset( $settings['openai_api_key'] ) ? trim( sanitize_text_field( $settings['openai_api_key'] ) ) : '';

        $model = isset( $settings['model'] ) ? sanitize_text_field( $settings['model'] ) : '';
        $model = $this->normalize_model_value( $model );

        $allowed_models = apply_filters(
            'groui_smart_assistant_allowed_models',
            array( 'gpt-5', 'gpt-5-mini', 'gpt-5-nano' )
        );

        if ( empty( $model ) || ( ! empty( $allowed_models ) && ! in_array( $model, $allowed_models, true ) ) ) {
            $model = 'gpt-5';
        }

        $sanitized['model']        = $model;
        $sanitized['sitemap_url']    = isset( $settings['sitemap_url'] ) ? esc_url_raw( $settings['sitemap_url'] ) : home_url( '/sitemap.xml' );
        $sanitized['max_pages']      = isset( $settings['max_pages'] ) ? min( 50, max( 5, absint( $settings['max_pages'] ) ) ) : 12;
        $sanitized['max_products']   = isset( $settings['max_products'] ) ? min( 50, max( 5, absint( $settings['max_products'] ) ) ) : 12;
        $sanitized['enable_debug']   = ! empty( $settings['enable_debug'] );

        // Flush cached context whenever settings change.
        delete_transient( GROUI_Smart_Assistant::CONTEXT_TRANSIENT );
        return $sanitized;
    }

    /**
     * Render a generic text field.
     *
     * @param array $args Field arguments.
     * @return void
     */
    public function render_text_field( $args ) {
        $options = get_option( GROUI_Smart_Assistant::OPTION_KEY, array() );
        $value   = isset( $options[ $args['id'] ] ) ? esc_attr( $options[ $args['id'] ] ) : '';
        printf( '<input type="text" class="regular-text" id="%1$s" name="%2$s[%1$s]" value="%3$s" />', esc_attr( $args['id'] ), esc_attr( GROUI_Smart_Assistant::OPTION_KEY ), $value );
        if ( ! empty( $args['description'] ) ) {
            printf( '<p class="description">%s</p>', esc_html( $args['description'] ) );
        }
    }

    /**
     * Normalize the model value to match OpenAI expectations.
     *
     * Converts to lowercase, replaces consecutive whitespace with hyphens and
     * strips invalid characters so that values like "GPT 5 Mini" or
     * " gPt-5 " end up as "gpt-5-mini".
     *
     * @param string $value Raw model value entered by the user.
     *
     * @return string Normalized model slug.
     */
    protected function normalize_model_value( $value ) {
        $value = strtolower( trim( (string) $value ) );
        $value = preg_replace( '/\s+/', '-', $value );
        // Allow word characters, dots and hyphens.
        $value = preg_replace( '/[^a-z0-9\-.]/', '', $value );

        return $value;
    }

    /**
     * Render a number field.
     *
     * @param array $args Field arguments.
     * @return void
     */
    public function render_number_field( $args ) {
        $options = get_option( GROUI_Smart_Assistant::OPTION_KEY, array() );
        $value   = isset( $options[ $args['id'] ] ) ? absint( $options[ $args['id'] ] ) : '';
        $min     = isset( $args['min'] ) ? absint( $args['min'] ) : 0;
        $max     = isset( $args['max'] ) ? absint( $args['max'] ) : 100;
        printf(
            '<input type="number" class="small-text" id="%1$s" name="%2$s[%1$s]" value="%3$s" min="%4$s" max="%5$s" />',
            esc_attr( $args['id'] ),
            esc_attr( GROUI_Smart_Assistant::OPTION_KEY ),
            esc_attr( $value ),
            esc_attr( $min ),
            esc_attr( $max )
        );
        if ( ! empty( $args['description'] ) ) {
            printf( '<p class="description">%s</p>', esc_html( $args['description'] ) );
        }
    }

    /**
     * Render a checkbox field.
     *
     * @param array $args Field arguments.
     * @return void
     */
    public function render_checkbox_field( $args ) {
        $options = get_option( GROUI_Smart_Assistant::OPTION_KEY, array() );
        $value   = ! empty( $options[ $args['id'] ] );
        printf(
            '<label><input type="checkbox" id="%1$s" name="%2$s[%1$s]" value="1" %3$s /> %4$s</label>',
            esc_attr( $args['id'] ),
            esc_attr( GROUI_Smart_Assistant::OPTION_KEY ),
            checked( $value, true, false ),
            ! empty( $args['description'] ) ? esc_html( $args['description'] ) : ''
        );
    }

    /**
     * Render the plugin settings page.
     *
     * @return void
     */
    public function render_settings_page() {
        ?>
        <div class="wrap">
            <h1><?php esc_html_e( 'GROUI Smart Assistant', 'groui-smart-assistant' ); ?></h1>
            <form method="post" action="options.php">
                <?php
                settings_fields( 'groui-smart-assistant' );
                do_settings_sections( 'groui-smart-assistant' );
                submit_button( __( 'Guardar cambios', 'groui-smart-assistant' ) );
                ?>
            </form>
        </div>
        <?php
    }

    /**
     * Display an admin notice if the API key is missing.
     *
     * @return void
     */
    public function maybe_show_missing_key_notice() {
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }
        $settings = get_option( GROUI_Smart_Assistant::OPTION_KEY, array() );
        if ( empty( $settings['openai_api_key'] ) ) {
            printf(
                '<div class="notice notice-warning"><p>%s</p></div>',
                esc_html__( 'GROUI Smart Assistant necesita una API Key de OpenAI para funcionar.', 'groui-smart-assistant' )
            );
        }
    }

    /**
     * Cron task to refresh the knowledge context.
     *
     * @return void
     */
    public function refresh_context_cron() {
        $context = GROUI_Smart_Assistant_Context::instance();
        $context->refresh_context( true );
    }
}
