<?php

use PHPUnit\Framework\TestCase;

if (!defined('ABSPATH')) {
    define('ABSPATH', __DIR__ . '/');
}

if (!function_exists('apply_filters')) {
    function apply_filters($hook, $value)
    {
        return $value;
    }
}

if (!function_exists('wp_parse_args')) {
    function wp_parse_args($args, $defaults = array())
    {
        if (!is_array($args)) {
            $args = array();
        }

        return array_merge($defaults, $args);
    }
}

if (!function_exists('absint')) {
    function absint($maybeint)
    {
        return abs((int) $maybeint);
    }
}

if (!function_exists('wp_strip_all_tags')) {
    function wp_strip_all_tags($text)
    {
        return strip_tags($text);
    }
}

if (!function_exists('wp_trim_words')) {
    function wp_trim_words($text, $num_words, $more = '...')
    {
        $text  = trim($text);
        $words = preg_split('/\s+/', $text);

        if (!$words) {
            return '';
        }

        if (count($words) <= $num_words) {
            return implode(' ', $words);
        }

        return implode(' ', array_slice($words, 0, $num_words)) . $more;
    }
}

if (!function_exists('sanitize_text_field')) {
    function sanitize_text_field($value)
    {
        $value = (string) $value;
        $value = strip_tags($value);
        $value = preg_replace('/[\r\n\t]+/', ' ', $value);

        return trim($value);
    }
}

if (!function_exists('sanitize_key')) {
    function sanitize_key($key)
    {
        $key = strtolower((string) $key);

        return preg_replace('/[^a-z0-9_\-]/', '', $key);
    }
}

if (!function_exists('wp_get_attachment_image_url')) {
    function wp_get_attachment_image_url($attachment_id, $size = 'thumbnail')
    {
        return 'https://example.com/' . $attachment_id . '-' . $size . '.jpg';
    }
}

if (!function_exists('wp_json_encode')) {
    function wp_json_encode($data)
    {
        return json_encode($data);
    }
}

if (!function_exists('remove_accents')) {
    function remove_accents($string)
    {
        return $string;
    }
}

if (!function_exists('is_wp_error')) {
    function is_wp_error($thing)
    {
        return $thing instanceof WP_Error;
    }
}

if (!class_exists('WP_Error')) {
    class WP_Error
    {
    }
}

if (!function_exists('wc_attribute_label')) {
    function wc_attribute_label($label)
    {
        return $label;
    }
}

if (!function_exists('wc_get_product_terms')) {
    function wc_get_product_terms($product_id, $taxonomy, $args = array())
    {
        global $mock_product_terms;
        $key = $product_id . ':' . $taxonomy;

        if (isset($mock_product_terms[$key])) {
            return $mock_product_terms[$key];
        }

        return array();
    }
}

if (!class_exists('WC_Product')) {
    class WC_Product
    {
        private $data;

        public function __construct(array $data)
        {
            $this->data = $data;
        }

        public function get_id()
        {
            return $this->data['id'];
        }

        public function get_name()
        {
            return $this->data['name'];
        }

        public function get_sku()
        {
            return $this->data['sku'];
        }

        public function get_price_html()
        {
            return $this->data['price_html'];
        }

        public function get_permalink()
        {
            return $this->data['permalink'];
        }

        public function get_image_id()
        {
            return $this->data['image_id'];
        }

        public function get_short_description()
        {
            return $this->data['short_description'];
        }

        public function get_description()
        {
            return $this->data['description'];
        }

        public function get_category_ids()
        {
            return $this->data['category_ids'];
        }

        public function get_type()
        {
            return $this->data['type'];
        }

        public function get_average_rating()
        {
            return $this->data['average_rating'];
        }

        public function get_review_count()
        {
            return $this->data['review_count'];
        }

        public function get_attributes()
        {
            return array();
        }

        public function get_gallery_image_ids()
        {
            return array();
        }
    }
}

require_once __DIR__ . '/../groui-smart-assistant/includes/class-groui-smart-assistant-context.php';
require_once __DIR__ . '/../groui-smart-assistant/includes/class-groui-smart-assistant-openai.php';

class ContextBrandTest extends TestCase
{
    protected function setUp(): void
    {
        global $mock_product_terms;
        $mock_product_terms = array();
    }

    public function test_product_summary_includes_brand_names()
    {
        global $mock_product_terms;

        $mock_product_terms = array(
            '10:product_brand' => array('ACME', 'Beta & Co'),
            '10:pwb-brand'     => array('ACME'),
            '10:product_tag'   => array('Destacado'),
        );

        $product = new WC_Product(array(
            'id'                => 10,
            'name'              => 'Producto de prueba',
            'sku'               => 'SKU-10',
            'price_html'        => '<span>$10</span>',
            'permalink'         => 'https://example.com/producto-prueba',
            'image_id'          => 5,
            'short_description' => 'Descripción corta de prueba',
            'description'       => 'Descripción larga de prueba',
            'category_ids'      => array(),
            'type'              => 'simple',
            'average_rating'    => 4.5,
            'review_count'      => 12,
        ));

        $context = new class extends GROUI_Smart_Assistant_Context {
            public function build($product)
            {
                return $this->build_product_summary($product, false);
            }
        };

        $summary = $context->build($product);

        $this->assertArrayHasKey('brand_names', $summary);
        $this->assertSame(array('ACME', 'Beta & Co'), $summary['brand_names']);
    }

    public function test_refine_context_prioritizes_brand_matches()
    {
        $openai = new class extends GROUI_Smart_Assistant_OpenAI {
            public function refine($message, $context, $settings = array())
            {
                return $this->refine_context_for_message($message, $context, $settings);
            }
        };

        $context = array(
            'products' => array(
                array(
                    'id'             => 1,
                    'name'           => 'Producto ACME',
                    'brand_names'    => array('ACME'),
                    'short_desc'     => 'Ideal para fans de ACME',
                    'long_desc'      => 'Producto extenso ACME',
                    'category_names' => array('Gadgets'),
                    'tags'           => array('popular'),
                    'attributes'     => array(),
                ),
                array(
                    'id'             => 2,
                    'name'           => 'Producto Beta',
                    'brand_names'    => array('BetaBrand'),
                    'short_desc'     => 'Diseñado por BetaBrand',
                    'long_desc'      => 'Producto extenso BetaBrand',
                    'category_names' => array('Accesorios'),
                    'tags'           => array('nuevo'),
                    'attributes'     => array(),
                ),
            ),
        );

        $refined = $openai->refine('¿Tienes productos de la marca ACME?', $context, array('max_products' => 1));

        $this->assertArrayHasKey('products', $refined);
        $this->assertCount(1, $refined['products']);
        $this->assertSame(1, $refined['products'][0]['id']);
    }
}
