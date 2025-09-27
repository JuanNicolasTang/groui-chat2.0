<?php
/**
 * Plugin Name: GPT5 Shop Assistant (Onefile)
 * Description: Asistente de compra con RAG, streaming, catálogo con filtros/chips, mini-carrito y recomendaciones. Soporta proveedores OpenAI-compatibles (OpenAI, Azure, OpenRouter, Local).
 * Version: 1.5.2
 * Author: You
 * Text Domain: gpt5-sa
 * Domain Path: /languages
 */

if (!defined('ABSPATH')) { exit; }

class GPT5_Shop_Assistant_Onefile {
    const OPT_KEY = 'gpt5_sa_settings';
    const VERSION = '1.5.2';
    const DB_VERSION = '1.0.0';
    const NONCE_ACTION_PUBLIC = 'gpt5sa_public';
    const COOKIE_SID = 'gpt5sa_sid';
    const TABLE_EMBED = 'gpt5sa_embeddings';

    public function __construct() {
        add_action('plugins_loaded', [$this, 'i18n']);
        add_action('init', [$this, 'init']);
        add_action('admin_menu', [$this, 'admin_menu']);
        add_action('admin_init', [$this, 'register_settings']);
        add_action('wp_enqueue_scripts', [$this, 'enqueue_assets']);
        add_action('wp_footer', [$this, 'render_widget']);
        add_action('rest_api_init', [$this, 'register_routes']);
        add_filter('rest_pre_serve_request', function($served, $result, $request, $server){
            if (!($request instanceof WP_REST_Request)) {
                return $served;
            }

            $attributes = $request->get_attributes();
            $namespace = is_array($attributes) ? ($attributes['namespace'] ?? '') : '';
            $route = $request->get_route();
            $route_key = is_string($route) ? rtrim($route, '/') : '';

            $is_assistant_route = ($namespace === 'gpt5sa/v1');
            if (!$is_assistant_route && is_string($route)) {
                $is_assistant_route = (strpos($route, '/gpt5sa/v1') === 0);
            }

            if (!$is_assistant_route) {
                return $served;
            }

            $this->cors_header();

            $allowed_methods_map = [
                '/gpt5sa/v1/chat' => 'POST, OPTIONS',
                '/gpt5sa/v1/wc-add-to-cart' => 'POST, OPTIONS',
                '/gpt5sa/v1/wc-search' => 'GET, OPTIONS',
                '/gpt5sa/v1/wc-facets' => 'GET, OPTIONS',
                '/gpt5sa/v1/recs' => 'GET, OPTIONS',
            ];
            $allowed_methods = $allowed_methods_map[$route_key] ?? 'GET, POST, OPTIONS';

            header('Access-Control-Allow-Methods: ' . $allowed_methods);
            header('Access-Control-Allow-Headers: Content-Type, X-GPT5SA-Nonce');
            header('Access-Control-Max-Age: 600');
            header('Vary: Origin, Access-Control-Request-Headers');

            if ('OPTIONS' === ($_SERVER['REQUEST_METHOD'] ?? '')) {
                return true;
            }
            return $served;
        }, 10, 4);

        register_activation_hook(__FILE__, [$this, 'on_activate']);
        register_uninstall_hook(__FILE__, ['GPT5_Shop_Assistant_Onefile', 'on_uninstall']);
    }

    public function i18n() {
        load_plugin_textdomain('gpt5-sa', false, dirname(plugin_basename(__FILE__)).'/languages');
    }

    public function init() {
        if (empty($_COOKIE[self::COOKIE_SID])) {
            $sid = wp_generate_uuid4();
            setcookie(self::COOKIE_SID, $sid, time()+YEAR_IN_SECONDS, COOKIEPATH, COOKIE_DOMAIN, is_ssl(), true);
            $_COOKIE[self::COOKIE_SID] = $sid;
        }
    }

    public static function default_settings() {
        return [
            'provider' => 'openai', // openai|azure|openrouter|local
            'api_key' => '',
            'api_base' => 'https://api.openai.com/v1',
            'azure_deployment' => '',
            'azure_api_version' => '2024-06-01',
            'openrouter_site' => '',
            'openrouter_title' => 'GPT5 Shop Assistant',
            'model' => 'gpt-4o-mini',
            'max_tokens' => 512,
            'temperature' => 0.7,
            'allowed_origins' => home_url(),
            'privacy_strip_pii' => 1,
            'economy_mode' => 0,
            'rate_ip_burst' => 8,
            'rate_window_sec' => 30,
            'rate_user_burst' => 10,
            'enable_streaming' => 1,
            'enable_analytics' => 1,
            'enable_widget' => 1,
            'enable_catalog' => 1,
            'enable_recs' => 1,
            'wc_brand_attribute' => 'pa_brand',
        ];
    }

    public function get_settings() {
        $opts = get_option(self::OPT_KEY);
        if (!is_array($opts)) $opts = [];
        return wp_parse_args($opts, self::default_settings());
    }

    public function on_activate() {
        global $wpdb;
        $table = $wpdb->prefix . self::TABLE_EMBED;
        $charset_collate = $wpdb->get_charset_collate();
        $sql = "CREATE TABLE IF NOT EXISTS $table (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            content_hash CHAR(64) NOT NULL,
            source_url TEXT NULL,
            chunk_index INT NOT NULL DEFAULT 0,
            embedding MEDIUMBLOB NULL,
            content MEDIUMTEXT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY content_hash (content_hash)
        ) $charset_collate;";
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta($sql);
        if (!get_option(self::OPT_KEY)) {
            add_option(self::OPT_KEY, self::default_settings());
        }
    }

    public static function on_uninstall() {
        global $wpdb;
        delete_option(self::OPT_KEY);
        $table = $wpdb->prefix . self::TABLE_EMBED;
        $wpdb->query("DROP TABLE IF EXISTS $table");
        $wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_gpt5sa_%' OR option_name LIKE '_transient_timeout_gpt5sa_%'");
    }

    public function register_settings() {
        register_setting('gpt5_sa_group', self::OPT_KEY, [
            'type' => 'array',
            'sanitize_callback' => [$this, 'sanitize_settings'],
        ]);
        add_settings_section('gpt5_sa_main', __('Ajustes', 'gpt5-sa'), '__return_false', 'gpt5_sa');
        $fields = [
            ['provider', 'text', __('Proveedor (openai|azure|openrouter|local)', 'gpt5-sa')],
            ['api_base', 'text', __('API Base', 'gpt5-sa')],
            ['api_key', 'text', __('API Key', 'gpt5-sa')],
            ['azure_deployment', 'text', __('Azure Deployment', 'gpt5-sa')],
            ['azure_api_version', 'text', __('Azure API Version', 'gpt5-sa')],
            ['openrouter_site', 'text', __('OpenRouter Site (Referer)', 'gpt5-sa')],
            ['openrouter_title', 'text', __('OpenRouter Title', 'gpt5-sa')],
            ['model', 'text', __('Modelo', 'gpt5-sa')],
            ['max_tokens', 'number', __('Límite de tokens por turno', 'gpt5-sa')],
            ['temperature', 'number', __('Temperatura', 'gpt5-sa')],
            ['allowed_origins', 'text', __('Orígenes permitidos (coma)', 'gpt5-sa')],
            ['privacy_strip_pii', 'checkbox', __('No enviar PII', 'gpt5-sa')],
            ['economy_mode', 'checkbox', __('Modo económico', 'gpt5-sa')],
            ['enable_streaming', 'checkbox', __('Streaming', 'gpt5-sa')],
            ['enable_analytics', 'checkbox', __('Analítica en front', 'gpt5-sa')],
            ['enable_widget', 'checkbox', __('Mostrar widget', 'gpt5-sa')],
            ['enable_catalog', 'checkbox', __('Vista catálogo', 'gpt5-sa')],
            ['enable_recs', 'checkbox', __('Recomendaciones (Recs)', 'gpt5-sa')],
            ['wc_brand_attribute', 'text', __('Atributo de marca (WooCommerce)', 'gpt5-sa')],
            ['rate_ip_burst', 'number', __('Límite ráfaga por IP', 'gpt5-sa')],
            ['rate_user_burst', 'number', __('Límite ráfaga por usuario', 'gpt5-sa')],
            ['rate_window_sec', 'number', __('Ventana rate limit (s)', 'gpt5-sa')],
        ];
        foreach ($fields as $f) {
            add_settings_field($f[0], $f[2], function() use ($f) {
                $type = $f[1]; $key = $f[0]; $opts = $this->get_settings();
                $val = isset($opts[$key]) ? $opts[$key] : '';
                if ($type === 'checkbox') {
                    printf('<input type="checkbox" name="%s[%s]" value="1" %s>', self::OPT_KEY, esc_attr($key), checked($val, 1, false));
                } else {
                    printf('<input type="%s" name="%s[%s]" value="%s" class="regular-text">', esc_attr($type), self::OPT_KEY, esc_attr($key), esc_attr($val));
                }
            }, 'gpt5_sa', 'gpt5_sa_main');
        }
    }

    public function sanitize_settings($opts) {
        if (!is_array($opts)) {
            $opts = [];
        }

        $defaults = self::default_settings();
        $res = $defaults;

        $provider = isset($opts['provider']) ? sanitize_key($opts['provider']) : $defaults['provider'];
        if (!in_array($provider, ['openai', 'azure', 'openrouter', 'local'], true)) {
            $provider = $defaults['provider'];
        }
        $res['provider'] = $provider;

        $res['api_key'] = isset($opts['api_key']) ? sanitize_text_field($opts['api_key']) : '';
        $res['api_base'] = isset($opts['api_base']) ? esc_url_raw($opts['api_base']) : $defaults['api_base'];
        $res['azure_deployment'] = isset($opts['azure_deployment']) ? sanitize_text_field($opts['azure_deployment']) : '';
        $res['azure_api_version'] = isset($opts['azure_api_version']) ? sanitize_text_field($opts['azure_api_version']) : $defaults['azure_api_version'];
        $res['openrouter_site'] = isset($opts['openrouter_site']) ? esc_url_raw($opts['openrouter_site']) : '';
        $res['openrouter_title'] = isset($opts['openrouter_title']) ? sanitize_text_field($opts['openrouter_title']) : $defaults['openrouter_title'];
        $res['model'] = isset($opts['model']) ? sanitize_text_field($opts['model']) : $defaults['model'];

        $origins = [];
        if (!empty($opts['allowed_origins'])) {
            $candidates = array_filter(array_map('trim', explode(',', (string) $opts['allowed_origins'])));
            foreach ($candidates as $candidate) {
                if ($candidate === '*') {
                    $origins = ['*'];
                    break;
                }
                $normalized = $this->normalize_origin_str($candidate);
                if ($normalized !== '') {
                    $origins[] = $normalized;
                }
            }
        }
        if (empty($origins) && !empty($defaults['allowed_origins'])) {
            $fallback = $this->normalize_origin_str($defaults['allowed_origins']);
            if ($fallback !== '') {
                $origins[] = $fallback;
            }
        }
        $origins = array_values(array_unique(array_filter($origins))); // drop empties / duplicates
        $res['allowed_origins'] = implode(',', $origins);

        $res['max_tokens'] = max(64, intval($opts['max_tokens'] ?? $defaults['max_tokens']));
        $res['temperature'] = min(2.0, max(0.0, floatval($opts['temperature'] ?? $defaults['temperature'])));
        $res['rate_ip_burst'] = max(1, intval($opts['rate_ip_burst'] ?? $defaults['rate_ip_burst']));
        $res['rate_user_burst'] = max(1, intval($opts['rate_user_burst'] ?? $defaults['rate_user_burst']));
        $res['rate_window_sec'] = max(5, intval($opts['rate_window_sec'] ?? $defaults['rate_window_sec']));

        $res['wc_brand_attribute'] = isset($opts['wc_brand_attribute']) ? sanitize_key($opts['wc_brand_attribute']) : $defaults['wc_brand_attribute'];

        $checkboxes = [
            'privacy_strip_pii',
            'economy_mode',
            'enable_streaming',
            'enable_analytics',
            'enable_widget',
            'enable_catalog',
            'enable_recs',
        ];
        foreach ($checkboxes as $key) {
            $res[$key] = !empty($opts[$key]) ? 1 : 0;
        }

        return $res;
    }

    public function admin_menu() {
        add_options_page(__('GPT-5 Assistant', 'gpt5-sa'), __('GPT-5 Assistant', 'gpt5-sa'), 'manage_options', 'gpt5_sa', [$this, 'settings_page']);
    }

    public function settings_page() {
        ?>
        <div class="wrap">
            <h1><?php echo esc_html(__('GPT-5 Assistant', 'gpt5-sa')); ?></h1>
            <form method="post" action="options.php">
                <?php settings_fields('gpt5_sa_group'); do_settings_sections('gpt5_sa'); submit_button(); ?>
            </form>
            <hr/>
            <h2><?php echo esc_html(__('Indexado (RAG)', 'gpt5-sa')); ?></h2>
            <p><?php echo esc_html(__('Puedes lanzar un reindexado. Verás el progreso abajo.', 'gpt5-sa')); ?></p>
            <form method="post">
                <?php wp_nonce_field('gpt5sa_reindex'); ?>
                <input type="hidden" name="gpt5sa_action" value="reindex"/>
                <button class="button button-primary"><?php echo esc_html(__('Reindexar', 'gpt5-sa')); ?></button>
            </form>
            <?php
            if (!empty($_POST['gpt5sa_action']) && $_POST['gpt5sa_action'] === 'reindex' && check_admin_referer('gpt5sa_reindex')) {
                $this->simulate_reindex_progress();
            }
            $progress = get_transient('gpt5sa_progress');
            if ($progress === false) { $progress = 0; }
            ?>
            <div id="gpt5sa-progress" style="border:1px solid #ddd; height:20px; width:400px; position:relative;">
                <div style="position:absolute; left:0; top:0; bottom:0; width:<?php echo esc_attr(intval($progress)); ?>%; background:#2271b1;"></div>
            </div>
            <p><?php printf(esc_html__('Progreso: %s%%', 'gpt5-sa'), intval($progress)); ?></p>
        </div>
        <?php
    }

    private function simulate_reindex_progress() {
        $total = 10;
        for ($i=1; $i<=$total; $i++) {
            set_transient('gpt5sa_progress', intval(($i/$total)*100), 60);
            usleep(150000);
        }
        delete_transient('gpt5sa_progress');
    }

    public function enqueue_assets() {
        $opts = $this->get_settings();
        if (empty($opts['enable_widget'])) return;
        $nonce = wp_create_nonce(self::NONCE_ACTION_PUBLIC);
        wp_register_script('gpt5sa-js', '', [], self::VERSION, true);
        wp_add_inline_script('gpt5sa-js', $this->widget_js($nonce));
        wp_enqueue_script('gpt5sa-js');
        wp_register_style('gpt5sa-css', false);
        wp_add_inline_style('gpt5sa-css', $this->widget_css());
        wp_enqueue_style('gpt5sa-css');
    }

    private function widget_css() {
        return "
#gpt5sa-launcher{position:fixed;right:20px;bottom:20px;z-index:99999}
#gpt5sa-launcher button{border:none;border-radius:16px;padding:12px 16px;box-shadow:0 6px 20px rgba(0,0,0,.15);cursor:pointer;background:#111827;color:#fff}
#gpt5sa-panel{position:fixed;right:20px;bottom:80px;width:410px;max-width:95vw;height:580px;display:none;background:#fff;border-radius:16px;box-shadow:0 10px 30px rgba(0,0,0,.2);overflow:hidden;z-index:99999}
#gpt5sa-header{display:flex;align-items:center;justify-content:space-between;background:#111827;color:#fff;padding:10px 14px}
#gpt5sa-tabs{display:flex;gap:6px;padding:8px;border-bottom:1px solid #eee}
#gpt5sa-tabs button{border:none;background:#f3f4f6;padding:6px 10px;border-radius:999px;cursor:pointer}
#gpt5sa-tabs button.active{background:#111827;color:#fff}
#gpt5sa-messages{height:calc(100% - 240px);overflow:auto;padding:12px}
#gpt5sa-input{display:flex;gap:8px;padding:10px;border-top:1px solid #eee}
#gpt5sa-input textarea{flex:1;resize:none;height:42px;padding:8px;border-radius:10px;border:1px solid #ddd}
#gpt5sa-input button{border:none;border-radius:10px;padding:8px 12px;cursor:pointer;background:#111827;color:#fff}
.gpt5sa-msg{margin:8px 0}
.gpt5sa-msg.user{text-align:right}
.gpt5sa-badge{display:inline-block;background:#e5e7eb;color:#111827;padding:2px 8px;border-radius:999px;margin-right:6px}
#gpt5sa-filters{display:none;padding:10px;border-bottom:1px solid #eee;gap:8px;align-items:center;flex-wrap:wrap}
#gpt5sa-filters input,#gpt5sa-filters select{border:1px solid #ddd;border-radius:8px;padding:6px 8px}
#gpt5sa-chips{display:flex;gap:6px;flex-wrap:wrap;margin-top:6px}
#gpt5sa-chips .chip{background:#f3f4f6;border:1px solid #e5e7eb;border-radius:999px;padding:4px 10px;cursor:pointer;font-size:12px}
#gpt5sa-grid{display:none;grid-template-columns:repeat(2,minmax(0,1fr));gap:10px;padding:10px;overflow:auto;height:calc(100% - 210px)}
.gpt5sa-card{border:1px solid #eee;border-radius:12px;overflow:hidden;display:flex;flex-direction:column}
.gpt5sa-card img{width:100%;height:140px;object-fit:cover;background:#f9fafb}
.gpt5sa-card .body{padding:8px;display:flex;flex-direction:column;gap:6px;flex:1}
.gpt5sa-card .body .name{font-weight:600;font-size:14px;line-height:1.2;margin:0}
.gpt5sa-card .body .price{font-size:13px}
.gpt5sa-card .row{display:flex;align-items:center;justify-content:space-between;gap:8px}
.gpt5sa-card .btn{display:inline-block;background:#111827;color:#fff;padding:6px 10px;border-radius:8px;text-decoration:none;border:none;cursor:pointer}
.gpt5sa-card .muted{color:#6b7280;font-size:12px}
.gpt5sa-empty{padding:20px;text-align:center;color:#6b7280}
.gpt5sa-toast{position:fixed;right:20px;bottom:90px;background:#111827;color:#fff;padding:10px 12px;border-radius:10px;box-shadow:0 6px 20px rgba(0,0,0,.2);display:none;z-index:100000}
#gpt5sa-recs{display:none;padding:10px;overflow:auto;height:calc(100% - 210px)}
#gpt5sa-recs .title{font-weight:600;margin:2px 0 8px}
#gpt5sa-recs .list{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:10px}
";
    }

    
    private function normalize_origin_str($origin){
        $origin = trim($origin);
        if ($origin === '') {
            return '';
        }
        if ($origin === '*') {
            return '*';
        }
        $parts = wp_parse_url($origin);
        if (!$parts || empty($parts['scheme']) || empty($parts['host'])) {
            return '';
        }
        $scheme = strtolower($parts['scheme']);
        $host = strtolower($parts['host']);
        $port = '';
        if (!empty($parts['port'])) {
            $port_num = intval($parts['port']);
            $default_ports = ['http' => 80, 'https' => 443];
            if (!isset($default_ports[$scheme]) || $default_ports[$scheme] !== $port_num) {
                $port = ':' . $port_num;
            }
        }
        return $scheme . '://' . $host . $port;
    }

    private function cors_header() {
        $opts = $this->get_settings();
        $allowed = array_filter(array_map('trim', explode(',', (string) $opts['allowed_origins'])));
        if (empty($allowed)) {
            return;
        }

        if (in_array('*', $allowed, true)) {
            header('Access-Control-Allow-Origin: *');
            return;
        }

        $origin = $_SERVER['HTTP_ORIGIN'] ?? '';
        if (!$origin) {
            return;
        }
        $norm = $this->normalize_origin_str($origin);
        if ($norm === '') {
            return;
        }
        $allowed_norm = array_filter(array_map(function($x){ return $this->normalize_origin_str($x); }, $allowed));
        if (!in_array($norm, $allowed_norm, true)) {
            return;
        }
        header('Access-Control-Allow-Origin: ' . $origin);
        header('Access-Control-Allow-Credentials: true');
        header('Vary: Origin');
    }

    private function ensure_cart_ready() {
        if (!function_exists('WC')) {
            return new WP_Error('woocommerce_inactive', __('WooCommerce no está activo', 'gpt5-sa'), ['status' => 500]);
        }

        try {
            $wc = WC();
        } catch (\Throwable $e) {
            error_log('[gpt5sa] WooCommerce bootstrap error: ' . $e->getMessage());
            return new WP_Error('woocommerce_inactive', __('WooCommerce no está disponible', 'gpt5-sa'), ['status' => 500]);
        }

        if (!$wc) {
            return new WP_Error('woocommerce_inactive', __('WooCommerce no está disponible', 'gpt5-sa'), ['status' => 500]);
        }

        try {
            if (function_exists('wc_load_cart')) {
                wc_load_cart();
            } elseif (method_exists($wc, 'initialize_cart')) {
                $wc->initialize_cart();
            }

            if (isset($wc->session) && is_object($wc->session) && method_exists($wc->session, 'has_session')) {
                $has_session = $wc->session->has_session();
                if (!$has_session && method_exists($wc->session, 'init')) {
                    $wc->session->init();
                }
            }

            if ((empty($wc->cart) || !is_object($wc->cart)) && class_exists('WC_Cart')) {
                $wc->cart = new WC_Cart();
            }
        } catch (\Throwable $e) {
            error_log('[gpt5sa] WooCommerce cart init error: ' . $e->getMessage());
            return new WP_Error('cart_unavailable', __('No se pudo inicializar el carrito de WooCommerce.', 'gpt5-sa'), ['status' => 500]);
        }

        if (empty($wc->cart) || !is_object($wc->cart)) {
            return new WP_Error('cart_unavailable', __('No se pudo inicializar el carrito de WooCommerce.', 'gpt5-sa'), ['status' => 500]);
        }

        return $wc->cart;
    }

    private function rest_error_response(WP_Error $error) {
        $data = $error->get_error_data();
        $status = 500;
        if (is_array($data) && isset($data['status'])) {
            $status = intval($data['status']);
        }
        return new WP_REST_Response([
            'ok' => false,
            'code' => $error->get_error_code(),
            'message' => $error->get_error_message(),
        ], $status);
    }

    private function widget_js($nonce) {
        $rest_url = esc_url_raw( rest_url('gpt5sa/v1') );
        $opts = $this->get_settings();
        $enable_streaming = !empty($opts['enable_streaming']) ? 'true':'false';
        $enable_analytics = !empty($opts['enable_analytics']) ? 'true':'false';
        $enable_catalog = !empty($opts['enable_catalog']) ? 'true':'false';
        $enable_recs = !empty($opts['enable_recs']) ? 'true':'false';
        $nonce_js = esc_js($nonce);
        return "
(function(){
  if(document.getElementById('gpt5sa-launcher')) return;
  const launch = document.createElement('div'); launch.id='gpt5sa-launcher';
  launch.innerHTML = '<button aria-controls=\"gpt5sa-panel\" aria-expanded=\"false\" aria-label=\"Open assistant\" class=\"gpt5sa-open\">🤖 '+(window.gpt5sa_title||'Asistente')+'</button>';
  document.body.appendChild(launch);

  const panel = document.createElement('div'); panel.id='gpt5sa-panel'; panel.setAttribute('role','dialog'); panel.setAttribute('aria-modal','true'); panel.setAttribute('aria-labelledby','gpt5sa-title');
  panel.innerHTML = '<div id=\"gpt5sa-header\"><div><span class=\"gpt5sa-badge\" aria-live=\"polite\">beta</span><strong id=\"gpt5sa-title\">GPT-5 Assistant</strong></div><div style=\"display:flex;gap:8px;align-items:center\"><button id=\"gpt5sa-cart\" class=\"gpt5sa-open-cart\" aria-label=\"Ver carrito\">🛒</button><button class=\"gpt5sa-close\" aria-label=\"Close\">✖</button></div></div>' +
    '<div id=\"gpt5sa-tabs\"><button data-tab=\"chat\" class=\"active\">Chat</button><button data-tab=\"catalog\" '+($enable_catalog?'':'style=\"display:none\"')+'>Catálogo</button><button data-tab=\"recs\" '+($enable_recs?'':'style=\"display:none\"')+'>Recomendados</button></div>' +
    '<div id=\"gpt5sa-filters\"><div style=\"display:flex;gap:6px;align-items:center;flex-wrap:wrap\">' +
      '<input placeholder=\"Buscar…\" id=\"gpt5sa-q\"/> ' +
      '<input type=\"number\" id=\"gpt5sa-min\" placeholder=\"Min\" style=\"width:84px\"/> ' +
      '<input type=\"number\" id=\"gpt5sa-max\" placeholder=\"Max\" style=\"width:84px\"/> ' +
      '<input placeholder=\"Marca\" id=\"gpt5sa-brand\" style=\"width:100px\"/> ' +
      '<input placeholder=\"Categoría\" id=\"gpt5sa-cat\" style=\"width:100px\"/> ' +
      '<label style=\"display:flex;gap:6px;align-items:center;font-size:12px\"><input type=\"checkbox\" id=\"gpt5sa-instock\"/> Solo stock</label> ' +
      '<button id=\"gpt5sa-apply\">Filtrar</button></div>' +
      '<div id=\"gpt5sa-chips\"></div></div>' +
    '<div id=\"gpt5sa-messages\" aria-live=\"polite\" aria-relevant=\"additions\"></div>' +
    '<div id=\"gpt5sa-grid\"></div>' +
    '<div id=\"gpt5sa-recs\"><div class=\"title\">Recomendados para ti</div><div class=\"list\"></div></div>' +
    '<div id=\"gpt5sa-input\"><textarea placeholder=\"Escribe tu pregunta…\" aria-label=\"Mensaje\"></textarea><button class=\"gpt5sa-send\">Enviar</button></div>';
  document.body.appendChild(panel);

  const toast = document.createElement('div'); toast.className='gpt5sa-toast'; document.body.appendChild(toast);
  function showToast(msg){ toast.textContent = msg; toast.style.display='block'; setTimeout(()=>toast.style.display='none', 2000); }

  const gtagPush = (ev,detail)=>{ try{ if($enable_analytics && (window.dataLayer||window.gtag)){ (window.dataLayer=window.dataLayer||[]).push({event:ev, detail}); } window.dispatchEvent(new CustomEvent('gpt5sa:'+ev,{detail})); }catch(e){} };

  function openPanel(){ panel.style.display='block'; document.querySelector('#gpt5sa-launcher button').setAttribute('aria-expanded','true'); panel.querySelector('textarea').focus(); gtagPush('open',{time:Date.now()}); }
  function closePanel(){ panel.style.display='none'; document.querySelector('#gpt5sa-launcher button').setAttribute('aria-expanded','false'); gtagPush('close',{}); }
  document.querySelector('#gpt5sa-launcher button').addEventListener('click', openPanel);
  panel.querySelector('.gpt5sa-close').addEventListener('click', closePanel);

  const messages = panel.querySelector('#gpt5sa-messages');
  const textarea = panel.querySelector('textarea');
  const sendBtn = panel.querySelector('.gpt5sa-send');
  const tabs = panel.querySelectorAll('#gpt5sa-tabs button');
  const filters = panel.querySelector('#gpt5sa-filters');
  const grid = panel.querySelector('#gpt5sa-grid');
  const chips = panel.querySelector('#gpt5sa-chips');
  const recs = panel.querySelector('#gpt5sa-recs');
  const recsList = recs.querySelector('.list');

  tabs.forEach(btn=>btn.addEventListener('click', ()=>{
    tabs.forEach(b=>b.classList.remove('active'));
    btn.classList.add('active');
    const tab = btn.dataset.tab;
    const isCat = tab==='catalog';
    const isRecs = tab==='recs';
    filters.style.display = isCat ? 'block':'none';
    grid.style.display = isCat ? 'grid':'none';
    messages.style.display = (!isCat && !isRecs) ? 'block':'none';
    recs.style.display = isRecs ? 'block':'none';
    if(isCat){ buildDynamicChips(); runSearch(); }
    if(isRecs){ loadRecs(); }
  }));

  function addMsg(text,who){ const div=document.createElement('div'); div.className='gpt5sa-msg '+(who||'bot'); div.innerHTML=text; messages.appendChild(div); messages.scrollTop=messages.scrollHeight; }

  async function send(){
    const content = textarea.value.trim();
    if(!content) return;
    addMsg('<em>'+content.replace(/[&<>]/g, m=>({\"&\":\"&amp;\",\"<\":\"&lt;\",\">\":\"&gt;\"}[m]))+'</em>','user');
    textarea.value='';
    gtagPush('send',{length:content.length});
    const headers = {'Content-Type':'application/json','X-GPT5SA-Nonce':'$nonce_js'};
    const body = JSON.stringify({message:content, stream:$enable_streaming?1:0});
    try {
      if ($enable_streaming) {
        const resp = await fetch('$rest_url/chat', {method:'POST', headers, body, credentials:'include'});
        if (!resp.ok || !resp.body) {
          const data = await resp.json().catch(()=>({message:'Error'}));
          addMsg('<span>'+ (data.message||'Error') +'</span>','bot'); return;
        }
        const reader = resp.body.getReader();
        let acc = '';
        addMsg('<span class=\"gpt5sa-stream\"></span>','bot');
        const el = messages.querySelector('.gpt5sa-msg.bot .gpt5sa-stream:last-child');
        while(true){
          const {value, done} = await reader.read();
          if(done) break;
          const chunk = new TextDecoder().decode(value);
          chunk.split('\\n\\n').forEach(line=>{
            if(line.startsWith('data: ')){
              const data = line.slice(6);
              if(data === '[DONE]') return;
              try {
                const j = JSON.parse(data);
                if(j.delta){ acc += j.delta; el.textContent = acc; }
                if(j.error){ el.textContent = j.error; }
              }catch(e){} }
          });
        }
        gtagPush('stream_complete',{chars:acc.length});
      } else {
        const resp = await fetch('$rest_url/chat', {method:'POST', headers, body, credentials:'include'});
        const data = await resp.json();
        addMsg(data.message||''); 
      }
    } catch (e) {
      addMsg(''+e,'bot');
    }
  }
  sendBtn.addEventListener('click', send);
  textarea.addEventListener('keydown', (e)=>{ if(e.key==='Enter' && !e.shiftKey){ e.preventDefault(); send(); }});

  async function buildDynamicChips(){
    chips.innerHTML = '';
    try{
      const url = new URL('$rest_url/wc-facets');
      const resp = await fetch(url, {headers:{'X-GPT5SA-Nonce':'$nonce_js'}, credentials:'include'});
      const data = await resp.json();
      const items = [];
      (data.popular_brands||[]).slice(0,6).forEach(b=>items.push({label:'Marca: '+b, apply:()=>{document.getElementById('gpt5sa-brand').value=b;}}));
      (data.popular_cats||[]).slice(0,6).forEach(c=>items.push({label:c, apply:()=>{document.getElementById('gpt5sa-cat').value=c;}}));
      items.push({label:'<$50', apply:()=>{setVal('min',''); setVal('max',50);}});
      items.push({label:'Solo stock', apply:()=>{document.getElementById('gpt5sa-instock').checked=true;}});
      chips.innerHTML = items.map((c,i)=>'<span class=\"chip\" data-i=\"'+i+'\">'+c.label+'</span>').join('');
      chips.querySelectorAll('.chip').forEach((el,i)=>el.addEventListener('click', ()=>{ items[i].apply(); runSearch(); }));
    }catch(e){}
  }
  function setVal(k,v){ const ids = {min:'gpt5sa-min', max:'gpt5sa-max', brand:'gpt5sa-brand', cat:'gpt5sa-cat'}; const el = document.getElementById(ids[k]); if(el) el.value=v; }

  async function runSearch(){
    grid.innerHTML = '<div class=\"gpt5sa-empty\">Buscando…</div>';
    const q = document.getElementById('gpt5sa-q').value.trim();
    const min = document.getElementById('gpt5sa-min').value;
    const max = document.getElementById('gpt5sa-max').value;
    const brand = document.getElementById('gpt5sa-brand').value.trim();
    const cat = document.getElementById('gpt5sa-cat').value.trim();
    const instock = document.getElementById('gpt5sa-instock').checked;
    const url = new URL('$rest_url/wc-search');
    if(q) url.searchParams.set('q', q);
    if(min) url.searchParams.set('min_price', min);
    if(max) url.searchParams.set('max_price', max);
    if(brand) url.searchParams.set('brand', brand);
    if(cat) url.searchParams.set('category', cat);
    url.searchParams.set('limit', 12);
    if(instock) url.searchParams.set('instock', '1');
    try{
      const resp = await fetch(url, {headers:{'X-GPT5SA-Nonce':'$nonce_js'}, credentials:'include'});
      const data = await resp.json();
      renderGrid(data.items||[]);
    }catch(e){ grid.innerHTML = '<div class=\"gpt5sa-empty\">Error</div>'; }
  }

  function renderGrid(items){
    if(!items.length){ grid.innerHTML = '<div class=\"gpt5sa-empty\">Sin resultados</div>'; return; }
    grid.innerHTML = items.map(item=>{
      const add = item.stock ? '<button class=\"btn add\" data-id=\"'+item.id+'\">Agregar</button>' : '<span class=\"muted\">Agotado</span>';
      const varSel = (item.variations && item.variations.length) ?
        ('<select class=\"var\" data-id=\"'+item.id+'\">'+ item.variations.map(v=>'<option value=\"'+v.variation_id+'\">'+v.attributes.join(' / ')+'</option>').join('') +'</select>')
        : '';
      return '<div class=\"gpt5sa-card\">'+
        '<img src=\"'+(item.image||'')+'\" alt=\"\"/>'+
        '<div class=\"body\"><div class=\"name\">'+item.name+'</div>'+
        '<div class=\"price\">$'+(item.price||'—')+'</div>'+
        (varSel?('<div class=\"row\">'+varSel+'</div>'):'')+
        '<div class=\"row\"><a class=\"btn\" href=\"'+item.permalink+'\">Ver</a>'+add+'</div>'+
        '<div class=\"muted\">'+(item.brand||'')+'</div></div></div>';
    }).join('');
    grid.querySelectorAll('.btn.add').forEach(b=>b.addEventListener('click', ()=>addToCart(b)));
  }

  async function addToCart(btn){
    btn.disabled=true;
    try{
      const card = btn.closest('.gpt5sa-card');
      const varSel = card.querySelector('select.var');
      const pid = btn.dataset.id;
      const payload = varSel ? {variation_id: varSel.value, qty:1} : {product_id: pid, qty:1};
      const res = await fetch('$rest_url/wc-add-to-cart', {method:'POST', headers:{'Content-Type':'application/json','X-GPT5SA-Nonce':'$nonce_js'}, body: JSON.stringify(payload), credentials:'include'});
      const j = await res.json();
      if(res.ok){ showToast('Añadido al carrito'); } else { showToast(j.message||'Error'); }
    }catch(e){ showToast('Error'); } finally { btn.disabled=false; }
  }

  async function loadRecs(){
    recsList.innerHTML = '<div class=\"gpt5sa-empty\">Calculando recomendaciones…</div>';
    try{
      const url = new URL('$rest_url/recs');
      const resp = await fetch(url, {headers:{'X-GPT5SA-Nonce':'$nonce_js'}, credentials:'include'});
      const data = await resp.json();
      const items = data.items||[];
      if(!items.length){ recsList.innerHTML = '<div class=\"gpt5sa-empty\">Sin recomendaciones</div>'; return; }
      recsList.innerHTML = items.map(item=>{
        const add = item.stock ? '<button class=\"btn add\" data-id=\"'+item.id+'\">Agregar</button>' : '<span class=\"muted\">Agotado</span>';
        return '<div class=\"gpt5sa-card\">'+
          '<img src=\"'+(item.image||'')+'\" alt=\"\"/>'+
          '<div class=\"body\"><div class=\"name\">'+item.name+'</div>'+
          '<div class=\"price\">$'+(item.price||'—')+'</div>'+
          '<div class=\"row\"><a class=\"btn\" href=\"'+item.permalink+'\">Ver</a>'+add+'</div>'+
          '<div class=\"muted\">'+(item.brand||'')+'</div></div></div>';
      }).join('');
      recsList.querySelectorAll('.btn.add').forEach(b=>b.addEventListener('click', ()=>addToCart(b)));
    }catch(e){
      recsList.innerHTML = '<div class=\"gpt5sa-empty\">Error</div>';
    }
  }

  document.getElementById('gpt5sa-cart').addEventListener('click', ()=>{ try{ window.location.href = (window.gpt5sa_checkout||'/checkout'); }catch(e){} });

  document.getElementById('gpt5sa-apply').addEventListener('click', runSearch);
})();"
        .replace('$rest_url', $rest_url)
        .replace('$nonce_js', $nonce_js)
        .replace('$enable_streaming', $enable_streaming)
        .replace('$enable_analytics', $enable_analytics)
        .replace('$enable_catalog', $enable_catalog)
        .replace('$enable_recs', $enable_recs);
    }

    public function render_widget() {}

    public function register_routes() {
        register_rest_route('gpt5sa/v1', '/chat', [
            'methods' => 'POST',
            'callback' => [$this, 'route_chat'],
            'permission_callback' => '__return_true',
        ]);
        register_rest_route('gpt5sa/v1', '/wc-search', [
            'methods' => 'GET',
            'callback' => [$this, 'route_wc_search'],
            'permission_callback' => '__return_true',
        ]);
        register_rest_route('gpt5sa/v1', '/wc-add-to-cart', [
            'methods' => 'POST',
            'callback' => [$this, 'route_wc_add_to_cart'],
            'permission_callback' => '__return_true',
        ]);
        register_rest_route('gpt5sa/v1', '/wc-facets', [
            'methods' => 'GET',
            'callback' => [$this, 'route_wc_facets'],
            'permission_callback' => '__return_true',
        ]);
        register_rest_route('gpt5sa/v1', '/recs', [
            'methods' => 'GET',
            'callback' => [$this, 'route_recs'],
            'permission_callback' => '__return_true',
        ]);
    }

    private function check_security_headers() {
        $opts = $this->get_settings();
        $nonce = $_SERVER['HTTP_X_GPT5SA_NONCE'] ?? '';
        if (!wp_verify_nonce($nonce, self::NONCE_ACTION_PUBLIC)) {
            return new WP_Error('forbidden', __('Nonce inválido', 'gpt5-sa'), ['status' => 403]);
        }
        $allowed = array_filter(array_map('trim', explode(',', (string) $opts['allowed_origins'])));
        if (in_array('*', $allowed, true)) {
            $this->cors_header();
            return true;
        }
        $origin = $_SERVER['HTTP_ORIGIN'] ?? '';
        if ($origin) {
            $normalized_origin = $this->normalize_origin_str($origin);
            $allowed = array_filter(array_map(function($x){ return $this->normalize_origin_str($x); }, $allowed));
            if (!in_array($normalized_origin, $allowed, true)) {
                return new WP_Error('forbidden', __('Origen no permitido', 'gpt5-sa'), ['status' => 403]);
            }
        }
        $this->cors_header();
        return true;
    }

    private function rate_limit_check() {
        $opts = $this->get_settings();
        $ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
        $sid = $_COOKIE[self::COOKIE_SID] ?? 'nosid';
        $ip_key = 'gpt5sa_ip_' . md5($ip);
        $user_key = 'gpt5sa_user_' . md5($sid);
        $window = max(5, intval($opts['rate_window_sec']));
        $ip_burst = max(1, intval($opts['rate_ip_burst']));
        $user_burst = max(1, intval($opts['rate_user_burst']));
        $ip_count = (int) get_transient($ip_key);
        $user_count = (int) get_transient($user_key);
        if ($ip_count >= $ip_burst || $user_count >= $user_burst) {
            return new WP_Error('too_many', __('Demasiadas solicitudes. Intenta de nuevo en unos segundos.', 'gpt5-sa'), ['status' => 429]);
        }
        set_transient($ip_key, $ip_count + 1, $window);
        set_transient($user_key, $user_count + 1, $window);
        return true;
    }

    private function strip_pii($text) {
        $text = preg_replace('/[A-Z0-9._%+-]+@[A-Z0-9.-]+\.[A-Z]{2,}/i', '[email]', $text);
        $text = preg_replace('/\+?\d[\d \-\(\)]{7,}\d/', '[tel]', $text);
        return $text;
    }

    // -------- Chat (multi-proveedor OpenAI-compatible) --------
    public function route_chat(WP_REST_Request $req) {
        $sec = $this->check_security_headers();
        if (is_wp_error($sec)) return $sec;
        $rl = $this->rate_limit_check();
        if (is_wp_error($rl)) return $rl;

        $opts = $this->get_settings();
        $body = $req->get_json_params();
        $message = isset($body['message']) ? (string) $body['message'] : '';
        $stream = !empty($body['stream']);
        if ($opts['privacy_strip_pii']) $message = $this->strip_pii($message);

        $max_tokens = intval($opts['max_tokens']);
        $temperature = floatval($opts['temperature']);
        $model = $opts['model'] ?: 'gpt-4o-mini';

        if (empty($opts['api_key'])) {
            if ($stream) { $this->emit_sse_stub($message, $max_tokens); }
            return new WP_REST_Response(['message' => wp_kses_post($this->generate_reply_stub($message, $max_tokens))]);
        }

        $sys = 'Eres un asistente de compras para WooCommerce. Responde en Markdown y sugiere productos y categorías. Sé breve.';
        $payload = [
            'model' => $model,
            'temperature' => $temperature,
            'max_tokens' => $max_tokens,
            'stream' => $stream ? true : false,
            'messages' => [
                ['role'=>'system','content'=>$sys],
                ['role'=>'user','content'=>$message],
            ],
        ];

        $provider = $opts['provider'];
        if ($stream) {
            ignore_user_abort(true);
            @header('Content-Type: text/event-stream');
            @header('Cache-Control: no-cache');
            @header('Connection: keep-alive');
            $err = $this->proxy_stream_provider($payload, $opts, $provider);
            if ($err) {
                echo 'data: ' . json_encode(['error'=>$err], JSON_UNESCAPED_UNICODE) . "\n\n";
            }
            echo "data: [DONE]\n\n";
            exit;
        } else {
            $resp = $this->call_provider($payload, $opts, $provider);
            if (is_wp_error($resp)) return $resp;
            $text = $resp['text'] ?? '';
            return new WP_REST_Response(['message' => wp_kses_post($text)]);
        }
    }

    private function emit_sse_stub($message, $max_tokens){
        @header('Content-Type: text/event-stream');
        @header('Cache-Control: no-cache');
        @header('Connection: keep-alive');
        $reply = $this->generate_reply_stub($message, $max_tokens);
        $tokens = preg_split('/(\s+)/', $reply, -1, PREG_SPLIT_DELIM_CAPTURE);
        foreach ($tokens as $tok) {
            echo 'data: ' . json_encode(['delta' => $tok], JSON_UNESCAPED_UNICODE) . "\n\n";
            @ob_flush(); @flush(); usleep(25000);
        }
        echo "data: [DONE]\n\n";
        exit;
    }

    private function call_provider($payload, $opts, $provider){
        $headers = ['Content-Type'=>'application/json'];
        if ($provider === 'openai' || $provider === 'local') {
            $url = trailingslashit($opts['api_base']) . 'chat/completions';
            $headers['Authorization'] = 'Bearer '.$opts['api_key'];
        } elseif ($provider === 'azure') {
            $dep = $opts['azure_deployment'];
            $ver = $opts['azure_api_version'];
            $base = untrailingslashit($opts['api_base']);
            $url = $base . '/openai/deployments/' . rawurlencode($dep) . '/chat/completions?api-version=' . rawurlencode($ver);
            $headers['api-key'] = $opts['api_key'];
        } elseif ($provider === 'openrouter') {
            $url = 'https://openrouter.ai/api/v1/chat/completions';
            $headers['Authorization'] = 'Bearer '.$opts['api_key'];
            if (!empty($opts['openrouter_site'])) $headers['Referer'] = $opts['openrouter_site'];
            if (!empty($opts['openrouter_title'])) $headers['X-Title'] = $opts['openrouter_title'];
        } else {
            return new WP_Error('provider_unknown', __('Proveedor desconocido', 'gpt5-sa'), ['status'=>400]);
        }
        $args = ['headers'=>$headers, 'body'=>wp_json_encode($payload), 'timeout'=>60];
        $res = wp_remote_post($url, $args);
        if (is_wp_error($res)) return $res;
        $code = wp_remote_retrieve_response_code($res);
        $body = wp_remote_retrieve_body($res);
        if ($code >= 300) return new WP_Error('provider_error', $body, ['status'=>$code]);
        $j = json_decode($body, true);
        $text = $j['choices'][0]['message']['content'] ?? '';
        return ['text'=>$text];
    }

    private function proxy_stream_provider($payload, $opts, $provider){
        if ($provider === 'openrouter') {
            $url = 'https://openrouter.ai/api/v1/chat/completions';
            $headers = [
                'Authorization: Bearer '.$opts['api_key'],
                'Content-Type: application/json'
            ];
            if (!empty($opts['openrouter_site'])) $headers[] = 'Referer: '.$opts['openrouter_site'];
            if (!empty($opts['openrouter_title'])) $headers[] = 'X-Title: '.$opts['openrouter_title'];
        } elseif ($provider === 'azure') {
            $dep = $opts['azure_deployment'];
            $ver = $opts['azure_api_version'];
            $base = untrailingslashit($opts['api_base']);
            $url = $base . '/openai/deployments/' . rawurlencode($dep) . '/chat/completions?api-version=' . rawurlencode($ver);
            $headers = ['api-key: '.$opts['api_key'], 'Content-Type: application/json'];
        } else { // openai|local
            $url = trailingslashit($opts['api_base']) . 'chat/completions';
            $headers = ['Authorization: Bearer '.$opts['api_key'], 'Content-Type: application/json'];
        }

        if (!function_exists('curl_init') || !function_exists('curl_exec')) {
            $payload['stream'] = false;
            $resp = $this->call_provider($payload, $opts, $provider);
            if (is_wp_error($resp)) {
                return $resp;
            }
            $text = $resp['text'] ?? '';
            if ($text !== '') {
                echo 'data: ' . wp_json_encode(['delta' => $text]) . "\n\n";
                @ob_flush(); @flush();
            }
            return null;
        }

        $payload['stream'] = true;
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => wp_json_encode($payload),
            CURLOPT_WRITEFUNCTION => function($ch, $data){
                $lines = explode("\n", $data);
                foreach ($lines as $line) {
                    $line = trim($line);
                    if ($line === '' || stripos($line, 'event:') === 0) continue;
                    if (stripos($line, 'data:') === 0) {
                        $json = trim(substr($line, 5));
                        if ($json === '[DONE]') { echo "data: [DONE]\n\n"; @ob_flush(); @flush(); continue; }
                        $obj = json_decode($json, true);
                        if (isset($obj['choices'][0]['delta']['content'])) {
                            $delta = $obj['choices'][0]['delta']['content'];
                            echo 'data: ' . wp_json_encode(['delta'=>$delta]) . "\n\n";
                            @ob_flush(); @flush();
                        }
                    }
                }
                return strlen($data);
            },
            CURLOPT_HEADER => false,
            CURLOPT_RETURNTRANSFER => false,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_TIMEOUT => 0,
        ]);
        $ok = curl_exec($ch);
        if ($ok === false) {
            $err = curl_error($ch);
            curl_close($ch);
            return $err;
        }
        curl_close($ch);
        return null;
    }

    private function generate_reply_stub($message, $max_tokens) {
        $suggest = '';
        if (class_exists('WooCommerce')) {
            $url = esc_url( rest_url('gpt5sa/v1/recs') );
            $suggest = sprintf(__(' Mira **Recomendados** o GET %s para sugerencias.', 'gpt5-sa'), $url);
        }
        $out = sprintf(__('Ok. Dijiste: “%s”. Puedo buscar productos y sugerirte algunos en base a tus gustos.', 'gpt5-sa'), esc_html($message));
        if (mb_strlen($out) > $max_tokens*4) $out = mb_substr($out, 0, $max_tokens*4) . '…';
        return $out.$suggest;
    }

    // ------- Woo Facets --------
    public function route_wc_facets(WP_REST_Request $req) {
        if (!class_exists('WooCommerce')) {
            return new WP_REST_Response(['popular_brands'=>[], 'popular_cats'=>[]]);
        }
        $sec = $this->check_security_headers();
        if (is_wp_error($sec)) return $sec;
        $rl = $this->rate_limit_check();
        if (is_wp_error($rl)) return $rl;

        $brand_attr = $this->get_settings()['wc_brand_attribute'];
        $popular_brands = [];
        if (taxonomy_exists($brand_attr)) {
            $terms = get_terms(['taxonomy'=>$brand_attr, 'hide_empty'=>true, 'number'=>12]);
            foreach ($terms as $t) { $popular_brands[] = $t->name; }
        }
        $popular_cats = [];
        $cats = get_terms(['taxonomy'=>'product_cat', 'hide_empty'=>true, 'number'=>12]);
        foreach ($cats as $c) { $popular_cats[] = $c->name; }

        return new WP_REST_Response(['popular_brands'=>$popular_brands, 'popular_cats'=>$popular_cats]);
    }

    // ------- Woo Search (con variaciones) --------
    public function route_wc_search(WP_REST_Request $req) {
        if (!class_exists('WooCommerce')) {
            return new WP_REST_Response(['items'=>[], 'note'=>__('WooCommerce no está activo', 'gpt5-sa')]);
        }
        $sec = $this->check_security_headers();
        if (is_wp_error($sec)) return $sec;
        $rl = $this->rate_limit_check();
        if (is_wp_error($rl)) return $rl;

        $q = sanitize_text_field($req->get_param('q') ?: '');
        $min_price = floatval($req->get_param('min_price') ?: 0);
        $max_price = floatval($req->get_param('max_price') ?: 0);
        $brand = sanitize_text_field($req->get_param('brand') ?: '');
        $cat = sanitize_text_field($req->get_param('category') ?: '');
        $instock_only = !empty($req->get_param('instock'));
        $limit = max(1, min(50, intval($req->get_param('limit') ?: 12)));

        $args = [
            'limit' => $limit*5,
            'status' => 'publish',
            'type' => ['simple','variable'],
            'orderby' => 'relevance',
            'return' => 'ids',
            's' => $q,
        ];
        if ($min_price && $max_price) {
            $args['meta_query'] = [[
                'key' => '_price',
                'value' => [$min_price, $max_price],
                'compare' => 'BETWEEN',
                'type' => 'NUMERIC',
            ]];
        } elseif ($min_price) {
            $args['meta_query'] = [[
                'key' => '_price',
                'value' => $min_price,
                'compare' => '>=',
                'type' => 'NUMERIC',
            ]];
        } elseif ($max_price) {
            $args['meta_query'] = [[
                'key' => '_price',
                'value' => $max_price,
                'compare' => '<=',
                'type' => 'NUMERIC',
            ]];
        }
        if ($cat) $args['category'] = [$cat];
        $query = new WC_Product_Query($args);
        $ids = $query->get_products();

        $items = [];
        $brand_attr = $this->get_settings()['wc_brand_attribute'];
        foreach ($ids as $pid) {
            $p = wc_get_product($pid);
            if (!$p) continue;
            if ($max_price && floatval($p->get_price()) > $max_price) continue;
            if ($min_price && floatval($p->get_price()) < $min_price) continue;
            if ($instock_only && !$p->is_in_stock()) continue;
            if ($brand) {
                $terms = wc_get_product_terms($pid, $brand_attr, ['fields'=>'names']);
                if (!$terms || !in_array($brand, $terms, true)) continue;
            }
            $stock = $p->is_in_stock() ? 1 : 0;
            $sales = intval(get_post_meta($pid, 'total_sales', true));
            $score = ($stock?1000:0) + $sales;
            $variations = [];
            if ($p->is_type('variable')) {
                $children = $p->get_children();
                foreach ($children as $vid) {
                    $v = wc_get_product($vid);
                    if (!$v) continue;
                    $atts = [];
                    $vattrs = $v->get_variation_attributes();
                    foreach ($vattrs as $k=>$val) {
                        $atts[] = wc_attribute_label(str_replace('attribute_', '', $k)) . ': ' . $val;
                    }
                    $variations[] = ['variation_id'=>$vid, 'attributes'=>$atts];
                }
            }
            $items[] = [
                'id'=>$pid,
                'name'=>$p->get_name(),
                'price'=>$p->get_price(),
                'stock'=>$p->is_in_stock(),
                'permalink'=>get_permalink($pid),
                'image'=>wp_get_attachment_image_url($p->get_image_id(),'medium'),
                'score'=>$score,
                'brand'=>implode(', ', wc_get_product_terms($pid, $brand_attr, ['fields'=>'names'])),
                'variations'=>$variations,
            ];
        }
        usort($items, function($a,$b){ return $b['score'] <=> $a['score']; });
        $items = array_slice($items, 0, $limit);
        return new WP_REST_Response(['items'=>$items]);
    }

    // ------- Add to Cart (simple y variaciones con atributos) --------
    public function route_wc_add_to_cart(WP_REST_Request $req) {
        if (!class_exists('WooCommerce')) {
            return new WP_REST_Response(['message'=>__('WooCommerce no está activo', 'gpt5-sa')], 400);
        }
        $sec = $this->check_security_headers();
        if (is_wp_error($sec)) return $sec;
        $rl = $this->rate_limit_check();
        if (is_wp_error($rl)) return $rl;

        $cart_ready = $this->ensure_cart_ready();
        if (is_wp_error($cart_ready)) {
            return $this->rest_error_response($cart_ready);
        }
        $cart = $cart_ready;

        $params = $req->get_json_params();
        $pid = intval($params['product_id'] ?? 0);
        $vid = intval($params['variation_id'] ?? 0);
        $qty = max(1, intval($params['qty'] ?? 1));

        if ($vid) {
            $v = wc_get_product($vid);
            if (!$v) return new WP_REST_Response(['message'=>__('Variación no encontrada', 'gpt5-sa')], 404);
            if (!$v->is_in_stock()) return new WP_REST_Response(['message'=>__('Variación sin stock', 'gpt5-sa')], 409);
            $parent_id = $v->get_parent_id();
            $variation_data = $v->get_variation_attributes(); // pasa atributos completos
            $added = $cart->add_to_cart($parent_id, $qty, $vid, $variation_data);
        } else {
            if (!$pid) return new WP_REST_Response(['message'=>__('Falta product_id', 'gpt5-sa')], 400);
            $p = wc_get_product($pid);
            if (!$p) return new WP_REST_Response(['message'=>__('Producto no encontrado', 'gpt5-sa')], 404);
            if (!$p->is_in_stock()) return new WP_REST_Response(['message'=>__('Producto sin stock', 'gpt5-sa')], 409);
            $added = $cart->add_to_cart($pid, $qty);
        }
        if (!$added) return new WP_REST_Response(['message'=>__('No se pudo agregar al carrito', 'gpt5-sa')], 500);
        return new WP_REST_Response(['ok'=>true, 'cart_count'=>$cart->get_cart_contents_count()]);
    }

    // ------- Recomendaciones --------
    public function route_recs(WP_REST_Request $req) {
        if (!class_exists('WooCommerce')) {
            return new WP_REST_Response(['items'=>[]]);
        }
        $sec = $this->check_security_headers();
        if (is_wp_error($sec)) return $sec;
        $rl = $this->rate_limit_check();
        if (is_wp_error($rl)) return $rl;

        $limit = max(1, min(12, intval($req->get_param('limit') ?: 8)));
        $brand_attr = $this->get_settings()['wc_brand_attribute'];

        $cart_note = null;
        $cart_ready = $this->ensure_cart_ready();
        if (is_wp_error($cart_ready)) {
            $cart_note = $cart_ready->get_error_message();
            $cart = null;
        } else {
            $cart = $cart_ready;
        }

        $viewed = !empty($_COOKIE['woocommerce_recently_viewed']) ? array_filter(array_map('absint', explode('|', $_COOKIE['woocommerce_recently_viewed']))) : [];
        $viewed = array_reverse($viewed);
        $liked_brands = []; $liked_cats = [];
        foreach ($viewed as $pid) {
            $liked_brands = array_merge($liked_brands, wc_get_product_terms($pid, $brand_attr, ['fields'=>'names']));
            $liked_cats = array_merge($liked_cats, wc_get_product_terms($pid, 'product_cat', ['fields'=>'names']));
        }
        $liked_brands = array_unique($liked_brands);
        $liked_cats = array_unique($liked_cats);

        $cart_ids = [];
        if ($cart) {
            foreach ($cart->get_cart() as $item) { $cart_ids[] = $item['product_id']; }
        }

        $candidates = [];
        $best = wc_get_products([ 'status'=>'publish', 'limit'=>24, 'orderby'=>'meta_value_num', 'meta_key'=>'total_sales', 'order'=>'DESC', 'return'=>'ids' ]);
        $candidates = array_merge($candidates, $best);
        if (!empty($liked_cats)) {
            foreach ($liked_cats as $cat_name) {
                $term = get_term_by('name', $cat_name, 'product_cat');
                if ($term && !is_wp_error($term)) {
                    $ids = wc_get_products([ 'status'=>'publish', 'limit'=>12, 'category'=>[$term->slug], 'return'=>'ids' ]);
                    $candidates = array_merge($candidates, $ids);
                }
            }
        }
        $scored = [];
        foreach (array_unique($candidates) as $pid) {
            $p = wc_get_product($pid);
            if (!$p || !$p->is_purchasable()) continue;
            $score = 0;
            $score += intval(get_post_meta($pid, 'total_sales', true));
            if ($p->is_in_stock()) $score += 200;
            $p_brands = wc_get_product_terms($pid, $brand_attr, ['fields'=>'names']);
            $p_cats = wc_get_product_terms($pid, 'product_cat', ['fields'=>'names']);
            if (!empty(array_intersect($p_brands, $liked_brands))) $score += 150;
            if (!empty(array_intersect($p_cats, $liked_cats))) $score += 120;
            if (!empty($cart_ids) && in_array($pid, $cart_ids)) $score -= 50;
            $scored[] = ['id'=>$pid, 'score'=>$score];
        }
        usort($scored, function($a,$b){ return $b['score'] <=> $a['score']; });
        $scored = array_slice($scored, 0, $limit);

        $items = [];
        foreach ($scored as $row) {
            $pid = $row['id'];
            $p = wc_get_product($pid);
            $items[] = [
                'id'=>$pid,
                'name'=>$p->get_name(),
                'price'=>$p->get_price(),
                'stock'=>$p->is_in_stock(),
                'permalink'=>get_permalink($pid),
                'image'=>wp_get_attachment_image_url($p->get_image_id(),'medium'),
                'brand'=>implode(', ', wc_get_product_terms($pid, $brand_attr, ['fields'=>'names'])),
            ];
        }
        $payload = ['items'=>$items];
        if ($cart_note) {
            $payload['cart_warning'] = $cart_note;
        }
        return new WP_REST_Response($payload);
    }
}

new GPT5_Shop_Assistant_Onefile();
