<?php
/**
 * Plugin Name: GROUI CHAT
 * Description: Asistente de compra groui
 * Version: 1.5.2
 * Author: GROUI
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
            'model' => 'gpt-5.1-mini',
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
            ['wc_brand_attribute', 'text', __('Atributo de marca (WooCommerce, usar slug completo. Ej: pa_brand)', 'gpt5-sa')],
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
#gpt5sa-launcher{position:fixed;right:20px;bottom:20px;z-index:99999;font-family:'Inter',sans-serif}
#gpt5sa-launcher button{border:none;border-radius:999px;padding:14px 20px;box-shadow:0 24px 45px rgba(17,24,39,.18);cursor:pointer;background:linear-gradient(135deg,#6366f1,#8b5cf6);color:#fff;font-weight:600;font-size:15px;display:flex;align-items:center;gap:10px;transition:transform .2s ease,box-shadow .2s ease}
#gpt5sa-launcher button:hover{transform:translateY(-2px);box-shadow:0 30px 60px rgba(17,24,39,.25)}
#gpt5sa-launcher button .pulse{display:inline-block;width:8px;height:8px;border-radius:50%;background:#34d399;box-shadow:0 0 0 6px rgba(52,211,153,.35);animation:gpt5sapulse 1.8s infinite}
@keyframes gpt5sapulse{0%{transform:scale(.9)}50%{transform:scale(1.3);opacity:.7}100%{transform:scale(.9);opacity:1}}
#gpt5sa-panel{position:fixed;right:20px;bottom:90px;width:430px;max-width:95vw;height:620px;display:none;background:#fff;border-radius:20px;box-shadow:0 30px 60px rgba(15,23,42,.28);overflow:hidden;z-index:99999;font-family:'Inter',sans-serif}
#gpt5sa-header{display:flex;align-items:center;justify-content:space-between;background:linear-gradient(135deg,#111827,#1f2937);color:#fff;padding:14px 18px}
#gpt5sa-header strong{font-size:16px}
#gpt5sa-header button{background:transparent;border:none;color:#fff;font-size:18px;cursor:pointer}
#gpt5sa-tabs{display:flex;gap:8px;padding:12px 16px;border-bottom:1px solid rgba(148,163,184,.2);background:#f9fafb}
#gpt5sa-tabs button{border:none;background:#e2e8f0;padding:7px 14px;border-radius:999px;cursor:pointer;font-size:13px;font-weight:600;color:#334155;transition:all .2s ease}
#gpt5sa-tabs button.active{background:#111827;color:#fff;box-shadow:0 8px 20px rgba(15,23,42,.18)}
#gpt5sa-messages{height:calc(100% - 270px);overflow:auto;padding:16px;background:#fff}
#gpt5sa-input{display:flex;gap:10px;padding:14px 16px;border-top:1px solid rgba(148,163,184,.2);background:#f8fafc}
#gpt5sa-input textarea{flex:1;resize:none;height:52px;padding:12px;border-radius:14px;border:1px solid rgba(148,163,184,.5);font-family:inherit;font-size:14px;box-shadow:inset 0 1px 2px rgba(15,23,42,.08)}
#gpt5sa-input textarea:focus{outline:none;border-color:#6366f1;box-shadow:0 0 0 3px rgba(99,102,241,.2)}
#gpt5sa-input button{border:none;border-radius:14px;padding:12px 18px;cursor:pointer;background:linear-gradient(135deg,#6366f1,#8b5cf6);color:#fff;font-weight:600;font-size:14px;display:flex;align-items:center;gap:6px;transition:transform .2s ease,box-shadow .2s ease}
#gpt5sa-input button:hover{transform:translateY(-1px);box-shadow:0 18px 28px rgba(99,102,241,.3)}
.gpt5sa-msg{margin:10px 0;font-size:14px;line-height:1.5;color:#1f2937}
.gpt5sa-msg.user{text-align:right}
.gpt5sa-msg.user em{background:rgba(99,102,241,.08);padding:8px 12px;border-radius:16px 16px 4px 16px;display:inline-block}
.gpt5sa-msg.bot{display:flex;align-items:flex-start;gap:10px}
.gpt5sa-msg.bot:before{content:'🤖';display:inline-flex;width:32px;height:32px;border-radius:50%;background:#e0e7ff;align-items:center;justify-content:center;font-size:17px}
.gpt5sa-msg.bot .gpt5sa-stream{display:inline-block;white-space:pre-wrap;background:#f1f5f9;padding:8px 12px;border-radius:16px 16px 16px 4px}
.gpt5sa-badge{display:inline-flex;background:rgba(255,255,255,.2);color:#fff;padding:4px 10px;border-radius:999px;margin-right:6px;font-size:11px;text-transform:uppercase;letter-spacing:.08em}
#gpt5sa-filters{display:none;padding:14px 16px;border-bottom:1px solid rgba(148,163,184,.2);gap:10px;align-items:center;flex-wrap:wrap;background:#f9fafb}
#gpt5sa-filters input,#gpt5sa-filters select{border:1px solid rgba(148,163,184,.4);border-radius:12px;padding:8px 12px;font-size:13px;background:#fff;min-width:100px}
#gpt5sa-filters label{font-size:12px;color:#475569}
#gpt5sa-chips{display:flex;gap:8px;flex-wrap:wrap;margin-top:6px}
#gpt5sa-chips .chip{background:#e0e7ff;color:#312e81;border-radius:999px;padding:5px 12px;cursor:pointer;font-size:12px;font-weight:600;transition:transform .2s ease,box-shadow .2s ease}
#gpt5sa-chips .chip:hover{transform:translateY(-1px);box-shadow:0 10px 18px rgba(99,102,241,.2)}
#gpt5sa-grid{display:none;padding:12px 16px;overflow:hidden;height:calc(100% - 230px);background:#fff}
.gpt5sa-carousel{display:flex;gap:12px;overflow-x:auto;scroll-snap-type:x mandatory;padding-bottom:6px}
.gpt5sa-carousel::-webkit-scrollbar{height:6px}
.gpt5sa-carousel::-webkit-scrollbar-thumb{background:#cbd5f5;border-radius:999px}
.gpt5sa-carousel .gpt5sa-card{min-width:190px;max-width:190px;scroll-snap-align:start;background:#fff;border-radius:16px;box-shadow:0 16px 30px rgba(15,23,42,.12);border:1px solid rgba(148,163,184,.25);overflow:hidden;display:flex;flex-direction:column;transition:transform .2s ease,box-shadow .2s ease}
.gpt5sa-carousel .gpt5sa-card:hover{transform:translateY(-4px);box-shadow:0 26px 42px rgba(15,23,42,.18)}
.gpt5sa-card img{width:100%;height:140px;object-fit:cover;background:#f8fafc}
.gpt5sa-card .body{padding:12px;display:flex;flex-direction:column;gap:8px;flex:1}
.gpt5sa-card .body .name{font-weight:600;font-size:15px;line-height:1.3;margin:0;color:#111827}
.gpt5sa-card .body .price{font-size:14px;color:#4338ca;font-weight:600}
.gpt5sa-card .row{display:flex;align-items:center;justify-content:space-between;gap:8px}
.gpt5sa-card .btn{display:inline-flex;align-items:center;justify-content:center;background:#111827;color:#fff;padding:7px 12px;border-radius:10px;text-decoration:none;border:none;cursor:pointer;font-size:12px;font-weight:600;transition:background .2s ease}
.gpt5sa-card .btn:hover{background:#312e81}
.gpt5sa-card .muted{color:#6b7280;font-size:12px}
.gpt5sa-card select.var{width:100%;border-radius:10px;border:1px solid rgba(148,163,184,.4);padding:7px 10px;font-size:12px}
.gpt5sa-empty{padding:24px;text-align:center;color:#64748b;font-size:14px;border-radius:16px;background:#f8fafc}
.gpt5sa-toast{position:fixed;right:24px;bottom:110px;background:#111827;color:#fff;padding:12px 16px;border-radius:14px;box-shadow:0 16px 30px rgba(15,23,42,.28);display:none;z-index:100000;font-size:13px;font-weight:600}
#gpt5sa-recs{display:none;padding:12px 16px;overflow:hidden;height:calc(100% - 230px);background:#fff}
#gpt5sa-recs .title{font-weight:600;margin:6px 0 12px;font-size:15px;color:#1f2937}
#gpt5sa-recs .list{display:flex;gap:12px;overflow-x:auto;scroll-snap-type:x mandatory;padding-bottom:6px}
#gpt5sa-recs .list .gpt5sa-card{min-width:190px}
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

        if (function_exists('wc_load_cart')) {
            wc_load_cart();
        }

        $wc = WC();
        if (!$wc) {
            return new WP_Error('woocommerce_inactive', __('WooCommerce no está disponible', 'gpt5-sa'), ['status' => 500]);
        }

        if (method_exists($wc, 'initialize_cart')) {
            $wc->initialize_cart();
        }

        if (empty($wc->cart) && class_exists('WC_Cart')) {
            $wc->cart = new WC_Cart();
        }

        if (empty($wc->cart)) {
            return new WP_Error('cart_unavailable', __('No se pudo inicializar el carrito de WooCommerce.', 'gpt5-sa'), ['status' => 500]);
        }

        return true;
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
  launch.innerHTML = '<button aria-controls=\"gpt5sa-panel\" aria-expanded=\"false\" aria-label=\"Abrir asistente GPT-5\" class=\"gpt5sa-open\"><span class=\"pulse\" aria-hidden=\"true\"></span><span class=\"label\">🤖 '+(window.gpt5sa_title||'Asistente GPT-5')+'</span></button>';
  document.body.appendChild(launch);

  const panel = document.createElement('div'); panel.id='gpt5sa-panel'; panel.setAttribute('role','dialog'); panel.setAttribute('aria-modal','true'); panel.setAttribute('aria-labelledby','gpt5sa-title');
  panel.innerHTML = '<div id=\"gpt5sa-header\"><div><span class=\"gpt5sa-badge\" aria-live=\"polite\">AI SHOP</span><strong id=\"gpt5sa-title\">GPT-5 Assistant</strong></div><div style=\"display:flex;gap:8px;align-items:center\"><button id=\"gpt5sa-cart\" class=\"gpt5sa-open-cart\" aria-label=\"Ver carrito\">🛒</button><button class=\"gpt5sa-close\" aria-label=\"Cerrar asistente\">✕</button></div></div>' +
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
    grid.style.display = isCat ? 'block':'none';
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
    const cards = items.map(item=>{
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
    grid.innerHTML = '<div class=\"gpt5sa-carousel\">'+cards+'</div>';
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
      const cards = items.map(item=>{
        const add = item.stock ? '<button class=\"btn add\" data-id=\"'+item.id+'\">Agregar</button>' : '<span class=\"muted\">Agotado</span>';
        return '<div class=\"gpt5sa-card\">'+
          '<img src=\"'+(item.image||'')+'\" alt=\"\"/>'+
          '<div class=\"body\"><div class=\"name\">'+item.name+'</div>'+
          '<div class=\"price\">$'+(item.price||'—')+'</div>'+
          '<div class=\"row\"><a class=\"btn\" href=\"'+item.permalink+'\">Ver</a>'+add+'</div>'+
          '<div class=\"muted\">'+(item.brand||'')+'</div></div></div>';
      }).join('');
      recsList.innerHTML = '<div class=\"gpt5sa-carousel\">'+cards+'</div>';
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

    private function build_rag_context($message) {
        $context = [];
        $keywords = $this->extract_keywords($message);

        if (class_exists('WooCommerce')) {
            $context = array_merge($context, $this->collect_taxonomy_context());
            $context = array_merge($context, $this->context_from_products($message, $keywords));
        }

        $context = array_merge($context, $this->context_from_content($message));
        $context = array_merge($context, $this->build_sitemap_context($keywords));

        $context = array_values(array_filter(array_unique($context)));
        return array_slice($context, 0, 6);
    }

    private function collect_taxonomy_context() {
        $context = [];
        $settings = $this->get_settings();
        $brand_attr = isset($settings['wc_brand_attribute']) ? $settings['wc_brand_attribute'] : '';

        if ($brand_attr && taxonomy_exists($brand_attr)) {
            $brand_terms = get_terms([
                'taxonomy' => $brand_attr,
                'hide_empty' => true,
                'number' => 6,
                'orderby' => 'count',
                'order' => 'DESC',
            ]);
            if (!is_wp_error($brand_terms) && !empty($brand_terms)) {
                $brand_names = array_slice(wp_list_pluck($brand_terms, 'name'), 0, 6);
                $taxonomy_obj = get_taxonomy($brand_attr);
                $label = ($taxonomy_obj && !empty($taxonomy_obj->labels->name)) ? $taxonomy_obj->labels->name : $brand_attr;
                $context[] = sprintf(__('Marcas populares (%1$s): %2$s', 'gpt5-sa'), $label, implode(', ', $brand_names));
            }
        }

        if (taxonomy_exists('product_cat')) {
            $category_terms = get_terms([
                'taxonomy' => 'product_cat',
                'hide_empty' => true,
                'number' => 6,
                'orderby' => 'count',
                'order' => 'DESC',
            ]);
            if (!is_wp_error($category_terms) && !empty($category_terms)) {
                $category_names = array_slice(wp_list_pluck($category_terms, 'name'), 0, 6);
                $context[] = sprintf(__('Categorías populares: %s', 'gpt5-sa'), implode(', ', $category_names));
            }
        }

        return $context;
    }

    private function extract_keywords($message) {
        $message = strtolower((string) $message);
        $parts = preg_split('/[^a-z0-9áéíóúñü]+/u', $message);
        $parts = array_filter(array_map('trim', (array) $parts), function($w) {
            return mb_strlen($w) >= 3;
        });
        $parts = array_values(array_unique($parts));
        return array_slice($parts, 0, 10);
    }

    private function context_from_products($message, $keywords) {
        $results = [];
        $args = [
            'post_type' => 'product',
            'posts_per_page' => 5,
            's' => $message,
            'post_status' => 'publish',
        ];
        $query = new WP_Query($args);
        $brand_attr = $this->get_settings()['wc_brand_attribute'];

        foreach ($query->posts as $post) {
            $product = wc_get_product($post->ID);
            if (!$product) continue;
            if (!empty($keywords)) {
                $matched = false;
                $haystack = strtolower($product->get_name() . ' ' . $product->get_short_description() . ' ' . $product->get_description());
                foreach ($keywords as $kw) {
                    if (mb_stripos($haystack, $kw) !== false) { $matched = true; break; }
                }
                if (!$matched) continue;
            }

            $price_raw = $product->get_price();
            $price = $price_raw !== '' ? strip_tags(wc_price($price_raw)) : __('Consultar', 'gpt5-sa');
            $brands = implode(', ', wc_get_product_terms($product->get_id(), $brand_attr, ['fields' => 'names']));
            $cats = implode(', ', wc_get_product_terms($product->get_id(), 'product_cat', ['fields' => 'names']));
            $description = $product->get_short_description();
            if (!$description) $description = $product->get_description();
            $description = wp_strip_all_tags($description);
            if (mb_strlen($description) > 260) {
                $description = mb_substr($description, 0, 260) . '…';
            }

            $results[] = sprintf(
                __('Producto destacado: %1$s | Precio: %2$s | Marca: %3$s | Categorías: %4$s | URL: %5$s | Resumen: %6$s', 'gpt5-sa'),
                $product->get_name(),
                $price,
                $brands ?: __('Sin marca', 'gpt5-sa'),
                $cats ?: __('Sin categoría', 'gpt5-sa'),
                get_permalink($product->get_id()),
                $description ?: __('Sin descripción', 'gpt5-sa')
            );
        }
        wp_reset_postdata();
        return $results;
    }

    private function context_from_content($message) {
        $args = [
            'post_type' => ['page', 'post'],
            'posts_per_page' => 4,
            's' => $message,
            'post_status' => 'publish',
        ];
        $posts = get_posts($args);
        $context = [];
        foreach ($posts as $post) {
            $summary = $post->post_excerpt ?: wp_trim_words(wp_strip_all_tags($post->post_content), 40, '…');
            $context[] = sprintf(
                __('Contenido relacionado: %1$s (%2$s) - %3$s', 'gpt5-sa'),
                get_the_title($post),
                get_permalink($post),
                $summary
            );
        }
        return $context;
    }

    private function build_sitemap_context($keywords) {
        $entries = $this->get_sitemap_urls();
        if (empty($entries)) {
            return [];
        }
        $matched = [];
        foreach ($entries as $entry) {
            $loc = $entry['loc'];
            foreach ($keywords as $kw) {
                if (mb_stripos($loc, $kw) !== false) {
                    $matched[] = $entry;
                    break;
                }
            }
        }
        if (empty($matched)) {
            $matched = array_slice($entries, 0, 5);
        }
        $matched = array_slice($matched, 0, 5);
        $context = [];
        foreach ($matched as $entry) {
            $label = $entry['title'] ? $entry['title'] . ' - ' . $entry['loc'] : $entry['loc'];
            $context[] = sprintf(
                __('Mapa del sitio: %1$s (Última actualización: %2$s)', 'gpt5-sa'),
                $label,
                $entry['lastmod'] ?: __('N/D', 'gpt5-sa')
            );
        }
        return $context;
    }

    private function get_sitemap_urls() {
        $cached = get_transient('gpt5sa_sitemap_cache');
        if ($cached !== false && is_array($cached)) {
            return $cached;
        }

        $urls = [];
        $candidates = [home_url('/sitemap_index.xml'), home_url('/sitemap.xml')];
        foreach ($candidates as $candidate) {
            $response = wp_remote_get($candidate, ['timeout' => 10]);
            if (is_wp_error($response)) {
                continue;
            }
            $body = wp_remote_retrieve_body($response);
            if (!$body) {
                continue;
            }
            $xml = @simplexml_load_string($body);
            if (!$xml) {
                continue;
            }
            if (isset($xml->sitemap)) {
                foreach ($xml->sitemap as $node) {
                    $loc = (string) $node->loc;
                    if (!$loc) continue;
                    $child = wp_remote_get($loc, ['timeout' => 10]);
                    if (is_wp_error($child)) continue;
                    $child_body = wp_remote_retrieve_body($child);
                    if (!$child_body) continue;
                    $child_xml = @simplexml_load_string($child_body);
                    if (!$child_xml || !isset($child_xml->url)) continue;
                    foreach ($child_xml->url as $url) {
                        if (count($urls) >= 120) break 3;
                        $loc_url = (string) $url->loc;
                        if (!$loc_url) continue;
                        $urls[] = [
                            'loc' => $loc_url,
                            'lastmod' => isset($url->lastmod) ? (string) $url->lastmod : '',
                            'title' => $this->infer_title_from_url($loc_url),
                        ];
                    }
                }
            } elseif (isset($xml->url)) {
                foreach ($xml->url as $url) {
                    if (count($urls) >= 120) break;
                    $loc = (string) $url->loc;
                    if (!$loc) continue;
                    $urls[] = [
                        'loc' => $loc,
                        'lastmod' => isset($url->lastmod) ? (string) $url->lastmod : '',
                        'title' => $this->infer_title_from_url($loc),
                    ];
                }
            }
            if (!empty($urls)) {
                break;
            }
        }

        set_transient('gpt5sa_sitemap_cache', $urls, 12 * HOUR_IN_SECONDS);
        return $urls;
    }

    private function infer_title_from_url($url) {
        $parts = wp_parse_url($url);
        if (!$parts || empty($parts['path'])) {
            return '';
        }
        $segments = array_values(array_filter(explode('/', $parts['path'])));
        if (empty($segments)) {
            return '';
        }
        $last = end($segments);
        $last = preg_replace('/\.[a-z0-9]+$/i', '', $last);
        $last = str_replace(['-', '_'], ' ', $last);
        $last = trim($last);
        if ($last === '') {
            return '';
        }
        return mb_convert_case($last, MB_CASE_TITLE, 'UTF-8');
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
        $model = $opts['model'] ?: 'gpt-5.1-mini';

        $context_snippets = $this->build_rag_context($message);

        if (empty($opts['api_key'])) {
            if ($stream) { $this->emit_sse_stub($message, $max_tokens, $context_snippets); }
            return new WP_REST_Response(['message' => wp_kses_post($this->generate_reply_stub($message, $max_tokens, $context_snippets))]);
        }

        $sys = 'Eres un asistente de compras para WooCommerce. Responde en Markdown y sugiere productos y categorías. Sé breve.';
        $messages = [
            ['role'=>'system','content'=>$sys],
        ];
        foreach ($context_snippets as $ctx_line) {
            $messages[] = ['role'=>'system','content'=>'Datos del sitio: '.$ctx_line];
        }
        $messages[] = ['role'=>'user','content'=>$message];

        $payload = [
            'model' => $model,
            'temperature' => $temperature,
            'max_tokens' => $max_tokens,
            'stream' => $stream ? true : false,
            'messages' => $messages,
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

    private function emit_sse_stub($message, $max_tokens, $context = []){
        @header('Content-Type: text/event-stream');
        @header('Cache-Control: no-cache');
        @header('Connection: keep-alive');
        $reply = $this->generate_reply_stub($message, $max_tokens, $context);
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

    private function generate_reply_stub($message, $max_tokens, $context = []) {
        $suggest = '';
        if (class_exists('WooCommerce')) {
            $url = esc_url( rest_url('gpt5sa/v1/recs') );
            $suggest = sprintf(__(' Mira **Recomendados** o GET %s para sugerencias.', 'gpt5-sa'), $url);
        }
        $ctx = '';
        if (!empty($context)) {
            $ctx = ' ' . sprintf(__('Contexto del sitio: %s.', 'gpt5-sa'), implode(' | ', array_slice($context, 0, 3)));
        }
        $out = sprintf(__('Ok. Dijiste: “%s”. Puedo buscar productos y sugerirte algunos en base a tus gustos.', 'gpt5-sa'), esc_html($message));
        $out .= $ctx;
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
            return $cart_ready;
        }
        $cart = WC()->cart;

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

        $cart_ready = $this->ensure_cart_ready();
        $cart = (!is_wp_error($cart_ready) && function_exists('WC')) ? WC()->cart : null;

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
        return new WP_REST_Response(['items'=>$items]);
    }
}

new GPT5_Shop_Assistant_Onefile();
