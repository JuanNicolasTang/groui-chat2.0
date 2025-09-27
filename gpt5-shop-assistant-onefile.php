<?php
/**
 * Plugin Name: GROUI CHAT
 * Description: Asistente de compra groui
 * Version: 2.0.0
 * Author: GROUI
 * Text Domain: gpt5-sa
 * Domain Path: /languages
 */

if (!defined('ABSPATH')) {
    exit;
}

class GPT5_Shop_Assistant_Onefile {
    const OPT_KEY = 'gpt5_sa_settings';
    const NONCE_ACTION = 'gpt5sa_public';

    public function __construct() {
        add_action('plugins_loaded', [$this, 'i18n']);
        add_action('admin_menu', [$this, 'admin_menu']);
        add_action('admin_init', [$this, 'register_settings']);
        add_action('wp_enqueue_scripts', [$this, 'enqueue_assets']);
        add_action('wp_footer', [$this, 'render_widget']);
        add_action('rest_api_init', [$this, 'register_routes']);
    }

    public function i18n() {
        load_plugin_textdomain('gpt5-sa', false, dirname(plugin_basename(__FILE__)) . '/languages');
    }

    public static function default_settings() {
        return [
            'api_key' => '',
            'api_base' => 'https://api.openai.com/v1',
            'model' => 'gpt-5.1-mini',
            'temperature' => 0.7,
            'max_tokens' => 512,
            'allowed_origins' => '',
            'enable_widget' => 1,
            'brand_attribute' => 'pa_brand',
        ];
    }

    public function get_settings() {
        $opts = get_option(self::OPT_KEY);
        if (!is_array($opts)) {
            $opts = [];
        }
        return wp_parse_args($opts, self::default_settings());
    }

    public function register_settings() {
        register_setting('gpt5_sa_group', self::OPT_KEY, [
            'type' => 'array',
            'sanitize_callback' => [$this, 'sanitize_settings'],
        ]);

        add_settings_section('gpt5_sa_main', __('Ajustes principales', 'gpt5-sa'), '__return_false', 'gpt5_sa');

        $fields = [
            ['api_key', 'password', __('API Key de OpenAI', 'gpt5-sa')],
            ['api_base', 'text', __('URL base de la API', 'gpt5-sa')],
            ['model', 'text', __('Modelo (GPT-5)', 'gpt5-sa')],
            ['temperature', 'number', __('Temperatura', 'gpt5-sa')],
            ['max_tokens', 'number', __('Máximo de tokens', 'gpt5-sa')],
            ['allowed_origins', 'text', __('Orígenes permitidos (coma)', 'gpt5-sa')],
            ['brand_attribute', 'text', __('Atributo de marca (WooCommerce)', 'gpt5-sa')],
            ['enable_widget', 'checkbox', __('Mostrar widget flotante', 'gpt5-sa')],
        ];

        foreach ($fields as $field) {
            add_settings_field(
                $field[0],
                $field[2],
                function () use ($field) {
                    $opts = $this->get_settings();
                    $key = $field[0];
                    $value = isset($opts[$key]) ? $opts[$key] : '';
                    $type = $field[1];

                    if ($type === 'checkbox') {
                        printf(
                            '<label><input type="checkbox" name="%1$s[%2$s]" value="1" %3$s/> %4$s</label>',
                            esc_attr(self::OPT_KEY),
                            esc_attr($key),
                            checked($value, 1, false),
                            esc_html__('Activado', 'gpt5-sa')
                        );
                        return;
                    }

                    $attrs = $type === 'number' ? ' step="0.1" min="0"' : '';
                    printf(
                        '<input type="%1$s" name="%2$s[%3$s]" value="%4$s" class="regular-text"%5$s/>',
                        esc_attr($type),
                        esc_attr(self::OPT_KEY),
                        esc_attr($key),
                        esc_attr($value),
                        $attrs
                    );
                },
                'gpt5_sa',
                'gpt5_sa_main'
            );
        }
    }

    public function sanitize_settings($opts) {
        if (!is_array($opts)) {
            $opts = [];
        }

        $defaults = self::default_settings();
        $res = $defaults;

        $res['api_key'] = isset($opts['api_key']) ? sanitize_text_field($opts['api_key']) : '';
        $res['api_base'] = isset($opts['api_base']) ? esc_url_raw($opts['api_base']) : $defaults['api_base'];
        $res['model'] = isset($opts['model']) ? sanitize_text_field($opts['model']) : $defaults['model'];
        $res['temperature'] = isset($opts['temperature']) ? min(2, max(0, floatval($opts['temperature']))) : $defaults['temperature'];
        $res['max_tokens'] = isset($opts['max_tokens']) ? max(64, intval($opts['max_tokens'])) : $defaults['max_tokens'];
        $res['brand_attribute'] = isset($opts['brand_attribute']) ? sanitize_key($opts['brand_attribute']) : $defaults['brand_attribute'];
        $res['enable_widget'] = empty($opts['enable_widget']) ? 0 : 1;

        $origins = [];
        if (!empty($opts['allowed_origins'])) {
            $candidates = array_map('trim', explode(',', (string) $opts['allowed_origins']));
            foreach ($candidates as $candidate) {
                if ($candidate === '*') {
                    $origins = ['*'];
                    break;
                }
                $normalized = $this->normalize_origin($candidate);
                if ($normalized) {
                    $origins[] = $normalized;
                }
            }
        }
        $origins = array_unique(array_filter($origins));
        $res['allowed_origins'] = implode(',', $origins);

        return $res;
    }

    public function admin_menu() {
        add_options_page(
            __('GPT-5 Assistant', 'gpt5-sa'),
            __('GPT-5 Assistant', 'gpt5-sa'),
            'manage_options',
            'gpt5_sa',
            [$this, 'settings_page']
        );
    }

    public function settings_page() {
        ?>
        <div class="wrap">
            <h1><?php esc_html_e('GPT-5 Assistant', 'gpt5-sa'); ?></h1>
            <p><?php esc_html_e('Configura la clave de OpenAI y los parámetros básicos del asistente.', 'gpt5-sa'); ?></p>
            <form method="post" action="options.php">
                <?php
                settings_fields('gpt5_sa_group');
                do_settings_sections('gpt5_sa');
                submit_button();
                ?>
            </form>
        </div>
        <?php
    }

    public function enqueue_assets() {
        $opts = $this->get_settings();
        if (empty($opts['enable_widget'])) {
            return;
        }

        $nonce = wp_create_nonce(self::NONCE_ACTION);
        $rest = esc_url_raw(rest_url('gpt5sa/v1'));
        $config = wp_json_encode([
            'rest' => trailingslashit($rest),
            'nonce' => $nonce,
        ]);

        wp_register_style('gpt5sa-css', false, [], '2.0.0');
        wp_add_inline_style('gpt5sa-css', $this->widget_css());
        wp_enqueue_style('gpt5sa-css');

        wp_register_script('gpt5sa-js', '', [], '2.0.0', true);
        wp_add_inline_script('gpt5sa-js', 'window.GPT5SA_CONFIG = ' . $config . ';');
        wp_add_inline_script('gpt5sa-js', $this->widget_js());
        wp_enqueue_script('gpt5sa-js');
    }

    public function render_widget() {
        $opts = $this->get_settings();
        if (empty($opts['enable_widget'])) {
            return;
        }
        echo '<div id="gpt5sa-root" aria-live="polite"></div>';
    }

    private function widget_css() {
        return 'body{font-family:"Inter",sans-serif}#gpt5sa-root{position:fixed;bottom:24px;right:24px;z-index:99999}#gpt5sa-launcher{border:none;border-radius:40px;background:linear-gradient(135deg,#4338ca,#6366f1);color:#fff;padding:14px 20px;font-weight:600;cursor:pointer;box-shadow:0 14px 26px rgba(67,56,202,.25);display:flex;align-items:center;gap:10px}#gpt5sa-panel{display:none;position:fixed;bottom:100px;right:24px;width:360px;max-width:92vw;height:520px;background:#fff;border-radius:20px;box-shadow:0 30px 55px rgba(15,23,42,.28);overflow:hidden;font-family:"Inter",sans-serif}#gpt5sa-panel header{display:flex;justify-content:space-between;align-items:center;padding:16px;background:#111827;color:#fff}#gpt5sa-panel header h3{margin:0;font-size:16px;font-weight:600}#gpt5sa-panel header button{background:none;border:none;color:#fff;font-size:20px;cursor:pointer}#gpt5sa-panel .gpt5sa-body{display:flex;flex-direction:column;height:calc(100% - 52px)}#gpt5sa-messages{flex:1;padding:16px;overflow-y:auto;background:#f9fafb;font-size:14px;color:#1f2937}#gpt5sa-messages .msg{margin-bottom:12px;line-height:1.5}#gpt5sa-messages .msg.user{text-align:right}#gpt5sa-messages .msg.user span{display:inline-block;background:#e0e7ff;color:#312e81;padding:8px 12px;border-radius:16px 16px 4px 16px}#gpt5sa-messages .msg.bot span{display:inline-block;background:#fff;padding:10px 14px;border-radius:16px 16px 16px 4px;box-shadow:0 6px 20px rgba(15,23,42,.06)}#gpt5sa-products{padding:12px 16px;background:#fff;border-top:1px solid #e5e7eb}#gpt5sa-products h4{margin:0 0 10px;font-size:14px;color:#111827}#gpt5sa-slider{display:flex;gap:12px;overflow-x:auto;scroll-snap-type:x mandatory;padding-bottom:6px}#gpt5sa-slider::-webkit-scrollbar{height:6px}#gpt5sa-slider::-webkit-scrollbar-thumb{background:#cbd5f5;border-radius:40px}.gpt5sa-card{scroll-snap-align:start;min-width:170px;background:#f8fafc;border-radius:16px;padding:12px;box-shadow:0 12px 30px rgba(15,23,42,.12);display:flex;flex-direction:column;gap:8px}.gpt5sa-card img{width:100%;height:120px;object-fit:cover;border-radius:12px;background:#fff}.gpt5sa-card strong{font-size:14px;color:#111827}.gpt5sa-card .price{font-weight:600;color:#4338ca}.gpt5sa-card .actions{display:flex;gap:8px;align-items:center}.gpt5sa-card a{flex:1;text-align:center;text-decoration:none;background:#111827;color:#fff;padding:6px 0;border-radius:10px;font-size:12px}.gpt5sa-card button{border:none;background:#4338ca;color:#fff;padding:6px 10px;border-radius:10px;font-size:12px;cursor:pointer}.gpt5sa-card .muted{font-size:12px;color:#6b7280}#gpt5sa-input{padding:14px 16px;background:#f3f4f6;border-top:1px solid #e5e7eb;display:flex;gap:10px}#gpt5sa-input textarea{flex:1;border-radius:14px;border:1px solid #cbd5f5;padding:10px;resize:none;height:50px;font-family:"Inter",sans-serif;font-size:14px}#gpt5sa-input button{border:none;border-radius:14px;background:linear-gradient(135deg,#4338ca,#6366f1);color:#fff;font-weight:600;padding:0 18px;cursor:pointer}#gpt5sa-search{display:flex;gap:8px;margin-bottom:12px}#gpt5sa-search input{flex:1;border-radius:10px;border:1px solid #d1d5db;padding:8px 10px;font-size:13px}#gpt5sa-search button{border:none;background:#111827;color:#fff;padding:8px 12px;border-radius:10px;font-size:13px;cursor:pointer}@media(max-width:640px){#gpt5sa-panel{right:12px;left:12px;width:auto}}
';
    }

    private function widget_js() {
        return "(function(){const root=document.getElementById('gpt5sa-root');if(!root||!window.GPT5SA_CONFIG)return;const cfg=window.GPT5SA_CONFIG;const panel=document.createElement('div');panel.id='gpt5sa-panel';panel.innerHTML='<header><h3>Asistente GROUI</h3><button type=\"button\" aria-label=\"Cerrar\">×</button></header><div class=\"gpt5sa-body\"><div id=\"gpt5sa-messages\"></div><div id=\"gpt5sa-products\"><h4>Productos recomendados</h4><div id=\"gpt5sa-search\"><input type=\"search\" placeholder=\"Buscar productos\" aria-label=\"Buscar productos\"/><button type=\"button\">Buscar</button></div><div id=\"gpt5sa-slider\"></div></div><div id=\"gpt5sa-input\"><textarea placeholder=\"¿En qué podemos ayudarte?\"></textarea><button type=\"button\">Enviar</button></div></div>';
const launcher=document.createElement('button');launcher.id='gpt5sa-launcher';launcher.type='button';launcher.innerHTML='<span>Asistente</span>';
root.appendChild(launcher);root.appendChild(panel);
const closeBtn=panel.querySelector('header button');const textarea=panel.querySelector('textarea');const sendBtn=panel.querySelector('#gpt5sa-input button');const searchInput=panel.querySelector('#gpt5sa-search input');const searchBtn=panel.querySelector('#gpt5sa-search button');const slider=panel.querySelector('#gpt5sa-slider');const messages=panel.querySelector('#gpt5sa-messages');
function toggle(){panel.style.display=panel.style.display==='block'?'none':'block';if(panel.style.display==='block'){textarea.focus();if(!slider.dataset.loaded){loadProducts('');}}}
launcher.addEventListener('click',toggle);closeBtn.addEventListener('click',toggle);
function escapeHTML(str){return str.replace(/[&<>\"]/g,m=>({'&':'&amp;','<':'&lt;','>':'&gt;','\"':'&quot;'}[m]));}
function addMessage(text,who){const wrap=document.createElement('div');wrap.className='msg '+who;wrap.innerHTML='<span>'+escapeHTML(text)+'</span>';messages.appendChild(wrap);messages.scrollTop=messages.scrollHeight;}
async function send(){const content=textarea.value.trim();if(!content)return;textarea.value='';addMessage(content,'user');try{const res=await fetch(cfg.rest+'chat',{method:'POST',headers:{'Content-Type':'application/json','X-GPT5SA-Nonce':cfg.nonce},credentials:'include',body:JSON.stringify({message:content})});const data=await res.json();if(!res.ok)throw new Error(data.message||'Error');addMessage(data.message||'', 'bot');if(Array.isArray(data.products)){renderProducts(data.products);} }catch(e){addMessage(e.message||'Error','bot');}}
sendBtn.addEventListener('click',send);textarea.addEventListener('keydown',e=>{if(e.key==='Enter'&&!e.shiftKey){e.preventDefault();send();}});
async function loadProducts(q){slider.dataset.loaded='1';slider.innerHTML='<div class=\"gpt5sa-card\">Cargando…</div>';
try{const url=new URL(cfg.rest+'wc-search');if(q)url.searchParams.set('q',q);const res=await fetch(url.toString(),{headers:{'X-GPT5SA-Nonce':cfg.nonce},credentials:'include'});const data=await res.json();if(!res.ok)throw new Error(data.message||'Error');renderProducts(data.items||[]);}catch(e){slider.innerHTML='<div class=\"gpt5sa-card\">'+escapeHTML(e.message||'Error')+'</div>';}}
searchBtn.addEventListener('click',()=>loadProducts(searchInput.value.trim()));searchInput.addEventListener('keydown',e=>{if(e.key==='Enter'){e.preventDefault();loadProducts(searchInput.value.trim());}});
function renderProducts(items){if(!items.length){slider.innerHTML='<div class=\"gpt5sa-card\">Sin productos</div>';return;}slider.innerHTML='';items.forEach(item=>{const card=document.createElement('div');card.className='gpt5sa-card';card.innerHTML=(item.image?'<img src=\"'+item.image+'\" alt=\"\"/>':'')+'<strong>'+escapeHTML(item.name||'Producto')+'</strong><span class=\"price\">'+escapeHTML(item.price||'')+'</span><div class=\"muted\">'+escapeHTML(item.brand||'')+'</div><div class=\"actions\"><a href=\"'+(item.permalink||'#')+'\" target=\"_blank\" rel=\"noopener\">Ver</a>'+(item.add_to_cart?'':'')+'</div>';
const actions=card.querySelector('.actions');
if(item.add_to_cart){const btn=document.createElement('button');btn.type='button';btn.textContent='Añadir';btn.addEventListener('click',()=>addToCart(btn,item));actions.appendChild(btn);}else{const span=document.createElement('span');span.className='muted';span.textContent='Agotado';actions.appendChild(span);}slider.appendChild(card);});}
async function addToCart(btn,item){btn.disabled=true;btn.textContent='…';try{const res=await fetch(cfg.rest+'wc-add-to-cart',{method:'POST',headers:{'Content-Type':'application/json','X-GPT5SA-Nonce':cfg.nonce},credentials:'include',body:JSON.stringify({product_id:item.id})});const data=await res.json();if(!res.ok)throw new Error(data.message||'Error');btn.textContent='Agregado';setTimeout(()=>{btn.textContent='Añadir';btn.disabled=false;},1500);}catch(e){btn.textContent='Error';setTimeout(()=>{btn.textContent='Añadir';btn.disabled=false;},2000);}}
})();";
    }

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
    }

    public function route_chat(WP_REST_Request $request) {
        $security = $this->verify_request();
        if (is_wp_error($security)) {
            return $security;
        }

        $message = trim((string) $request->get_param('message'));
        if ($message === '') {
            return new WP_Error('empty_message', __('Escribe un mensaje para continuar.', 'gpt5-sa'), ['status' => 400]);
        }

        $keywords = $this->extract_keywords($message);
        [$product_context, $product_cards] = $this->collect_product_context($keywords, 6);
        $post_context = $this->collect_post_context($keywords, 4);
        $sitemap_context = $this->collect_sitemap_context($keywords, 4);

        $context_lines = array_merge($product_context, $post_context, $sitemap_context);
        $context_text = implode("\n", $context_lines);

        $reply = $this->ask_openai($message, $context_text);
        if (is_wp_error($reply)) {
            return $reply;
        }

        return rest_ensure_response([
            'message' => $reply,
            'products' => $product_cards,
        ]);
    }

    public function route_wc_search(WP_REST_Request $request) {
        $security = $this->verify_request();
        if (is_wp_error($security)) {
            return $security;
        }

        $query = sanitize_text_field((string) $request->get_param('q'));
        $keywords = $this->extract_keywords($query);
        [, $products] = $this->collect_product_context($keywords, 8);

        return rest_ensure_response([
            'items' => $products,
        ]);
    }

    public function route_wc_add_to_cart(WP_REST_Request $request) {
        $security = $this->verify_request();
        if (is_wp_error($security)) {
            return $security;
        }

        if (!class_exists('WooCommerce')) {
            return new WP_Error('no_woocommerce', __('WooCommerce no está disponible.', 'gpt5-sa'), ['status' => 400]);
        }

        $product_id = intval($request->get_param('product_id'));
        if ($product_id <= 0) {
            return new WP_Error('invalid_product', __('Producto inválido.', 'gpt5-sa'), ['status' => 400]);
        }

        if (function_exists('wc_load_cart')) {
            wc_load_cart();
        }

        $added = WC()->cart ? WC()->cart->add_to_cart($product_id, 1) : false;
        if (!$added) {
            return new WP_Error('add_failed', __('No se pudo agregar al carrito.', 'gpt5-sa'), ['status' => 400]);
        }

        return rest_ensure_response([
            'success' => true,
            'message' => __('Producto añadido al carrito.', 'gpt5-sa'),
        ]);
    }

    private function verify_request() {
        $opts = $this->get_settings();
        $this->send_cors_headers($opts);

        $nonce = isset($_SERVER['HTTP_X_GPT5SA_NONCE']) ? sanitize_text_field(wp_unslash($_SERVER['HTTP_X_GPT5SA_NONCE'])) : '';
        if (!wp_verify_nonce($nonce, self::NONCE_ACTION)) {
            return new WP_Error('forbidden', __('Solicitud no autorizada.', 'gpt5-sa'), ['status' => 403]);
        }

        return true;
    }

    private function send_cors_headers($opts) {
        $origins = array_filter(array_map('trim', explode(',', (string) ($opts['allowed_origins'] ?? ''))));
        if (empty($origins)) {
            return;
        }

        if (in_array('*', $origins, true)) {
            header('Access-Control-Allow-Origin: *');
            header('Access-Control-Allow-Headers: Content-Type, X-GPT5SA-Nonce');
            header('Access-Control-Allow-Credentials: true');
            return;
        }

        $origin = isset($_SERVER['HTTP_ORIGIN']) ? $this->normalize_origin($_SERVER['HTTP_ORIGIN']) : '';
        if ($origin && in_array($origin, $origins, true)) {
            header('Access-Control-Allow-Origin: ' . esc_url_raw($origin));
            header('Access-Control-Allow-Headers: Content-Type, X-GPT5SA-Nonce');
            header('Access-Control-Allow-Credentials: true');
            header('Vary: Origin');
        }
    }

    private function normalize_origin($origin) {
        $origin = trim((string) $origin);
        if ($origin === '') {
            return '';
        }
        $parts = wp_parse_url($origin);
        if (!$parts || empty($parts['scheme']) || empty($parts['host'])) {
            return '';
        }
        $scheme = strtolower($parts['scheme']);
        $host = strtolower($parts['host']);
        $port = '';
        if (!empty($parts['port'])) {
            $default = $scheme === 'https' ? 443 : ($scheme === 'http' ? 80 : 0);
            if ((int) $parts['port'] !== $default) {
                $port = ':' . intval($parts['port']);
            }
        }
        return $scheme . '://' . $host . $port;
    }

    private function extract_keywords($text) {
        $text = strtolower(wp_strip_all_tags($text));
        $parts = preg_split('/[^a-z0-9áéíóúñ]+/u', $text, -1, PREG_SPLIT_NO_EMPTY);
        $parts = array_values(array_unique(array_filter($parts, function ($word) {
            return strlen($word) > 2;
        })));
        return array_slice($parts, 0, 6);
    }

    private function collect_product_context($keywords, $limit = 5) {
        if (!function_exists('wc_get_products')) {
            return [[], []];
        }

        $args = [
            'status' => 'publish',
            'limit' => $limit,
            'orderby' => 'popularity',
        ];
        if (!empty($keywords)) {
            $args['search'] = implode(' ', $keywords);
        }

        $products = wc_get_products($args);
        $opts = $this->get_settings();
        $brand_attr = !empty($opts['brand_attribute']) ? $opts['brand_attribute'] : 'pa_brand';

        $context = [];
        $cards = [];

        foreach ($products as $product) {
            if (!($product instanceof WC_Product)) {
                continue;
            }
            $price = $product->get_price();
            $price_text = $price === '' ? __('Consultar precio', 'gpt5-sa') : strip_tags(wc_price($price));
            $permalink = get_permalink($product->get_id());
            $brand = '';
            if ($brand_attr && function_exists('wc_get_product_terms')) {
                $terms = wc_get_product_terms($product->get_id(), $brand_attr, ['fields' => 'names']);
                if (!is_wp_error($terms) && !empty($terms)) {
                    $brand = implode(', ', $terms);
                }
            }

            $context[] = sprintf(
                __('Producto: %1$s | Precio: %2$s | Marca: %3$s | Enlace: %4$s', 'gpt5-sa'),
                $product->get_name(),
                $price_text,
                $brand ?: __('Sin marca', 'gpt5-sa'),
                $permalink
            );

            $cards[] = [
                'id' => $product->get_id(),
                'name' => $product->get_name(),
                'price' => $price_text,
                'permalink' => $permalink,
                'image' => $product->get_image_id() ? wp_get_attachment_image_url($product->get_image_id(), 'medium') : '',
                'brand' => $brand,
                'add_to_cart' => $product->is_in_stock(),
            ];
        }

        return [$context, $cards];
    }

    private function collect_post_context($keywords, $limit = 4) {
        $args = [
            'post_type' => ['page', 'post'],
            'post_status' => 'publish',
            'posts_per_page' => $limit,
        ];
        if (!empty($keywords)) {
            $args['s'] = implode(' ', $keywords);
        }
        $posts = get_posts($args);
        $context = [];
        foreach ($posts as $post) {
            $excerpt = $post->post_excerpt ?: wp_trim_words(wp_strip_all_tags($post->post_content), 35, '…');
            $context[] = sprintf(
                __('Contenido: %1$s - %2$s', 'gpt5-sa'),
                get_permalink($post),
                $excerpt
            );
        }
        wp_reset_postdata();
        return $context;
    }

    private function collect_sitemap_context($keywords, $limit = 4) {
        $entries = $this->fetch_sitemap_entries();
        if (empty($entries)) {
            return [];
        }

        $matched = [];
        foreach ($entries as $entry) {
            foreach ($keywords as $keyword) {
                if (stripos($entry['loc'], $keyword) !== false) {
                    $matched[] = $entry;
                    break;
                }
            }
            if (count($matched) >= $limit) {
                break;
            }
        }

        if (empty($matched)) {
            $matched = array_slice($entries, 0, $limit);
        }

        $context = [];
        foreach ($matched as $entry) {
            $context[] = sprintf(
                __('Sitemap: %1$s (Última actualización: %2$s)', 'gpt5-sa'),
                $entry['loc'],
                $entry['lastmod'] ?: __('N/D', 'gpt5-sa')
            );
        }

        return $context;
    }

    private function fetch_sitemap_entries() {
        $candidates = [
            home_url('/sitemap_index.xml'),
            home_url('/sitemap.xml'),
        ];
        $urls = [];

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
                    if (!$loc) {
                        continue;
                    }
                    $child = wp_remote_get($loc, ['timeout' => 10]);
                    if (is_wp_error($child)) {
                        continue;
                    }
                    $child_body = wp_remote_retrieve_body($child);
                    $child_xml = @simplexml_load_string($child_body);
                    if (!$child_xml || !isset($child_xml->url)) {
                        continue;
                    }
                    foreach ($child_xml->url as $url_node) {
                        $loc_url = (string) $url_node->loc;
                        if (!$loc_url) {
                            continue;
                        }
                        $urls[] = [
                            'loc' => $loc_url,
                            'lastmod' => isset($url_node->lastmod) ? (string) $url_node->lastmod : '',
                        ];
                        if (count($urls) >= 40) {
                            break 3;
                        }
                    }
                }
            } elseif (isset($xml->url)) {
                foreach ($xml->url as $url_node) {
                    $loc = (string) $url_node->loc;
                    if (!$loc) {
                        continue;
                    }
                    $urls[] = [
                        'loc' => $loc,
                        'lastmod' => isset($url_node->lastmod) ? (string) $url_node->lastmod : '',
                    ];
                    if (count($urls) >= 40) {
                        break;
                    }
                }
            }

            if (!empty($urls)) {
                break;
            }
        }

        return $urls;
    }

    private function ask_openai($message, $context) {
        $opts = $this->get_settings();
        if (empty($opts['api_key'])) {
            return new WP_Error('missing_key', __('Configura la API Key de OpenAI en los ajustes.', 'gpt5-sa'), ['status' => 500]);
        }

        $payload = [
            'model' => $opts['model'],
            'temperature' => (float) $opts['temperature'],
            'max_tokens' => (int) $opts['max_tokens'],
            'messages' => [
                [
                    'role' => 'system',
                    'content' => __('Eres un asistente de ventas de la tienda GROUI. Usa el contexto para recomendar productos y responder dudas de forma breve y amable. Si no sabes algo, indícalo.', 'gpt5-sa'),
                ],
                [
                    'role' => 'system',
                    'content' => __('Contexto del sitio:', 'gpt5-sa') . "\n" . $context,
                ],
                [
                    'role' => 'user',
                    'content' => $message,
                ],
            ],
        ];

        $response = wp_remote_post(trailingslashit($opts['api_base']) . 'chat/completions', [
            'headers' => [
                'Authorization' => 'Bearer ' . $opts['api_key'],
                'Content-Type' => 'application/json',
            ],
            'body' => wp_json_encode($payload),
            'timeout' => 20,
        ]);

        if (is_wp_error($response)) {
            return new WP_Error('openai_error', $response->get_error_message(), ['status' => 500]);
        }

        $code = wp_remote_retrieve_response_code($response);
        $body = json_decode(wp_remote_retrieve_body($response), true);

        if ($code < 200 || $code >= 300) {
            $message = isset($body['error']['message']) ? $body['error']['message'] : __('No se obtuvo respuesta de OpenAI.', 'gpt5-sa');
            return new WP_Error('openai_http_error', $message, ['status' => 500]);
        }

        $content = isset($body['choices'][0]['message']['content']) ? trim($body['choices'][0]['message']['content']) : '';
        if ($content === '') {
            $content = __('No tengo una respuesta en este momento.', 'gpt5-sa');
        }

        return $content;
    }
}

new GPT5_Shop_Assistant_Onefile();
