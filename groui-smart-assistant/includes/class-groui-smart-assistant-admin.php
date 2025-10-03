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
        add_action( 'groui_smart_assistant_refresh_context_single', array( $this, 'refresh_context_cron' ) );
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
                // Allow the site owner to index all pages by entering 0.  A value of 0 (displayed as 0 in the UI) is
                // stored internally as -1 and treated as unlimited.  When the limit is greater than zero the number
                // entered will be used directly.  The min attribute remains -1 to allow the user to set unlimited.
                'description' => __( 'Introduce 0 para enviar todas las páginas disponibles.', 'groui-smart-assistant' ),
                'min'         => -1,
                // Step is kept to 1 to restrict input to integers.  The max attribute is intentionally omitted to avoid
                // capping the user‑defined value in the browser.
                'step'        => 1,
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
                // Allow unlimited product indexing when the value is 0 (internally stored as -1).  Removing the max
                // attribute prevents the browser from capping the number of products that can be entered.
                'description' => __( 'Introduce 0 para indexar todo el catálogo de WooCommerce.', 'groui-smart-assistant' ),
                'min'         => -1,
                'step'        => 1,
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

        // Deep context toggle.
        add_settings_field(
            'deep_context_mode',
            __( 'Modo de contexto completo', 'groui-smart-assistant' ),
            array( $this, 'render_checkbox_field' ),
            'groui-smart-assistant',
            'groui_smart_assistant_general',
            array(
                'id'          => 'deep_context_mode',
                'description' => __( 'Envía siempre todo el contexto recopilado al modelo (consume más tokens).', 'groui-smart-assistant' ),
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
        $sanitized['sitemap_url']      = isset( $settings['sitemap_url'] ) ? esc_url_raw( $settings['sitemap_url'] ) : home_url( '/sitemap.xml' );
        $sanitized['max_pages']        = $this->sanitize_limit_setting( isset( $settings['max_pages'] ) ? $settings['max_pages'] : null, -1 );
        $sanitized['max_products']     = $this->sanitize_limit_setting( isset( $settings['max_products'] ) ? $settings['max_products'] : null, -1 );
        $sanitized['max_posts']        = $this->sanitize_limit_setting( isset( $settings['max_posts'] ) ? $settings['max_posts'] : null, -1 );
        $sanitized['enable_debug']   = ! empty( $settings['enable_debug'] );
        $sanitized['deep_context_mode'] = ! empty( $settings['deep_context_mode'] );

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
        $value = isset( $options[ $args['id'] ] ) ? intval( $options[ $args['id'] ] ) : '';

        if ( -1 === $value ) {
            $value = 0;
        }

        $min  = isset( $args['min'] ) ? (int) $args['min'] : null;
        $max  = isset( $args['max'] ) ? (int) $args['max'] : null;
        $step = isset( $args['step'] ) ? (int) $args['step'] : 1;

        $attributes = '';
        if ( null !== $min ) {
            $attributes .= ' min="' . esc_attr( $min ) . '"';
        }
        if ( null !== $max ) {
            $attributes .= ' max="' . esc_attr( $max ) . '"';
        }

        printf(
            '<input type="number" class="small-text" id="%1$s" name="%2$s[%1$s]" value="%3$s"%4$s step="%5$s" />',
            esc_attr( $args['id'] ),
            esc_attr( GROUI_Smart_Assistant::OPTION_KEY ),
            esc_attr( '' === $value ? '' : $value ),
            $attributes,
            esc_attr( max( 1, $step ) )
        );
        if ( ! empty( $args['description'] ) ) {
            printf( '<p class="description">%s</p>', esc_html( $args['description'] ) );
        }
    }

    /**
     * Sanitize numeric limits allowing "0" or negative values to disable the cap.
     *
     * @param mixed $value   Raw submitted value.
     * @param int   $default Default value when the field is empty.
     * @param int   $min     Minimum positive value allowed when the limit is active.
     * @param int|null   $max     Maximum positive value allowed when the limit is active.  Null for no maximum.
     *
     * @return int Sanitized limit.
     */
    protected function sanitize_limit_setting( $value, $default = -1, $min = 1, $max = null ) {
        if ( null === $value || '' === $value ) {
            return (int) $default;
        }

        if ( is_string( $value ) ) {
            $value = trim( $value );
        }

        if ( '' === $value ) {
            return (int) $default;
        }

        if ( is_numeric( $value ) ) {
            $value = (int) $value;

            // A value of zero or a negative integer disables the limit and is stored as -1.
            if ( $value <= 0 ) {
                return -1;
            }

            // Apply maximum if defined; skip if $max is null.
            if ( null !== $max ) {
                $value = min( (int) $max, $value );
            }

            // Apply minimum positive value; ensures at least one item is returned when a limit is active.
            if ( null !== $min ) {
                $value = max( (int) $min, $value );
            } else {
                $value = max( 1, $value );
            }

            return $value;
        }

        return (int) $default;
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