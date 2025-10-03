<?php
/**
 * OpenAI client wrapper for the GROUI Smart Assistant.
 *
 * This class encapsulates interaction with the OpenAI chat completion endpoint
 * and is responsible for constructing the system prompt, sending a request
 * and parsing the response. When the API key is missing or an error occurs,
 * a WP_Error object is returned instead of raw API data.
 *
 * @package GROUI_Smart_Assistant
 */

// Bail if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Class GROUI_Smart_Assistant_OpenAI
 */
class GROUI_Smart_Assistant_OpenAI {

    /**
     * Send a chat completion request to OpenAI.
     *
     * @param string $message User message.
     * @param array  $context Site context array built by the context builder.
     *
     * @return array|WP_Error Associative array with `raw` and `content` keys on success, or WP_Error on failure.
     */
    public function query( $message, $context ) {
        $settings = get_option( GROUI_Smart_Assistant::OPTION_KEY, array() );
        $settings = wp_parse_args(
            $settings,
            array(
                'openai_api_key'   => '',
                'model'            => '',
                'max_pages'        => 60,
                'max_products'     => 60,
                'max_posts'        => 60,
                'deep_context_mode' => true,
            )
        );

        $api_key = trim( $settings['openai_api_key'] );
        $model   = $this->normalize_model( $settings['model'] );

        $allowed_models = apply_filters(
            'groui_smart_assistant_allowed_models',
            array( 'gpt-5', 'gpt-5-mini', 'gpt-5-nano' )
        );

        if ( empty( $model ) || ( ! empty( $allowed_models ) && ! in_array( $model, $allowed_models, true ) ) ) {
            $model = 'gpt-5';
        }

        if ( empty( $api_key ) ) {
            return new WP_Error( 'missing_api_key', __( 'Falta la API key de OpenAI.', 'groui-smart-assistant' ) );
        }

        $original_context = $context;

        $tokens = $this->tokenize_text( $message );
        $tokens = apply_filters( 'groui_smart_assistant_query_tokens', $tokens, $message, $context );

        $use_full_context = ! empty( $settings['deep_context_mode'] );

        /**
         * Allow toggling full-context mode before building the prompt.
         *
         * When enabled the assistant skips relevance scoring and sends the
         * complete cached context to OpenAI, which is helpful when the site
         * owner prefiere respuestas exhaustivas aunque el prompt sea más largo.
         *
         * @param bool   $use_full_context Whether full-context mode is active.
         * @param string $message          Original user message.
         * @param array  $context          Full cached context array.
         * @param array  $settings         Plugin settings array.
         * @param array  $tokens           Tokens extracted from the user message.
         */
        $use_full_context = apply_filters( 'groui_smart_assistant_use_full_context', $use_full_context, $message, $context, $settings, $tokens );

        if ( $use_full_context ) {
            /**
             * Filter the context when full-context mode is enabled.
             *
             * @param array  $context  Context array that will be sent to OpenAI.
             * @param string $message  User message.
             * @param array  $settings Plugin settings array.
             * @param array  $tokens   Tokens extracted from the user message.
             */
            $context = apply_filters( 'groui_smart_assistant_deep_context', $context, $message, $settings, $tokens );
            $context = apply_filters( 'groui_smart_assistant_refined_context', $context, $original_context, $message, $tokens, $settings );
        } else {
            $context = $this->refine_context_for_message( $message, $context, $settings, $tokens );
        }

        $system_prompt = $this->build_system_prompt( $context );

        $body = array(
            'model'    => $model,
            'messages' => array(
                array(
                    'role'    => 'system',
                    'content' => $system_prompt,
                ),
                array(
                    'role'    => 'user',
                    'content' => $message,
                ),
            ),
        );

        /**
         * Filter the request body before sending to OpenAI.
         *
         * Allows developers to tweak the temperature, messages or other
         * parameters programmatically via the `groui_smart_assistant_openai_body` filter.
         *
         * @param array  $body    Request payload.
         * @param string $message User message.
         * @param array  $context Built site context.
         */
        $body = apply_filters( 'groui_smart_assistant_openai_body', $body, $message, $context );

        $request_args = array(
            'headers' => array(
                'Content-Type'  => 'application/json',
                'Authorization' => 'Bearer ' . $api_key,
            ),
            'timeout' => 60,
            'body'    => wp_json_encode( $body ),
        );

        /**
         * Filter the request arguments before sending to OpenAI.
         *
         * Developers can tweak headers, increase the timeout, or alter any
         * other `wp_remote_post()` parameter via this filter. The default
         * timeout is 60 seconds to accommodate longer running completions.
         *
         * @param array  $request_args HTTP request arguments passed to `wp_remote_post()`.
         * @param array  $body         Prepared request payload that will be JSON encoded.
         * @param string $message      Original user message.
         * @param array  $context      Built site context.
         */
        $request_args = apply_filters( 'groui_smart_assistant_openai_request_args', $request_args, $body, $message, $context );

        $response = wp_remote_post(
            'https://api.openai.com/v1/chat/completions',
            $request_args
        );

        if ( is_wp_error( $response ) ) {
            return $response;
        }

        $code = wp_remote_retrieve_response_code( $response );
        $data = json_decode( wp_remote_retrieve_body( $response ), true );

        // Handle HTTP errors returned by the API.
        if ( $code >= 400 ) {
            $message = isset( $data['error']['message'] ) ? $data['error']['message'] : __( 'Error desconocido en OpenAI.', 'groui-smart-assistant' );

            if ( false !== stripos( $message, 'model' ) && false !== stripos( $message, 'does not exist' ) ) {
                $hint     = __( 'Verifica que el nombre del modelo sea exactamente gpt-5, gpt-5-mini o gpt-5-nano y que tu cuenta tenga acceso activo.', 'groui-smart-assistant' );
                $message .= ' ' . $hint;
            }
            return new WP_Error( 'openai_error', $message, $data );
        }

        // Ensure a message was returned.
        if ( empty( $data['choices'][0]['message']['content'] ) ) {
            return new WP_Error( 'empty_response', __( 'OpenAI no devolvió contenido.', 'groui-smart-assistant' ), $data );
        }

        $content = $data['choices'][0]['message']['content'];
        $parsed  = json_decode( $content, true );

        // If JSON decoding fails, assume the API returned plain text.
        if ( null === $parsed ) {
            $parsed = array(
                'answer' => $content,
            );
        }

        return array(
            'raw'     => $data,
            'content' => $parsed,
        );
    }

    /**
     * Compose the system prompt with context data.
     *
     * Builds the instructions string (in Spanish) and concatenates it with
     * the JSON‑encoded summary of the site context. Newlines are inserted
     * explicitly via double quotes instead of escaped strings to improve
     * readability and avoid escaping pitfalls.
     *
     * @param array $context Context array containing site, pages, faqs, products, categories and sitemap.
     *
     * @return string Fully composed system prompt.
     */
    protected function build_system_prompt( $context ) {
        $instructions = __( 'Eres GROUI Smart Assistant, una IA entrenada con toda la información de este sitio. Usa los datos proporcionados (páginas, entradas del blog, FAQs, productos de WooCommerce, categorías y el sitemap) para responder preguntas, guiar procesos de compra y recomendar cualquier elemento relevante. Devuelve siempre una respuesta JSON con las claves "answer" (HTML amigable) y "products" (arreglo opcional de IDs de productos de WooCommerce que quieras resaltar). Cuando no tengas información suficiente, sé honesto.', 'groui-smart-assistant' );

        $summary = array(
            'site'       => isset( $context['site'] ) ? $context['site'] : '',
            'tagline'    => isset( $context['tagline'] ) ? $context['tagline'] : '',
            'pages'      => isset( $context['pages'] ) ? $context['pages'] : array(),
            'posts'      => isset( $context['posts'] ) ? $context['posts'] : array(),
            'faqs'       => isset( $context['faqs'] ) ? $context['faqs'] : array(),
            'products'   => isset( $context['products'] ) ? $context['products'] : array(),
            'categories' => isset( $context['categories'] ) ? $context['categories'] : array(),
            'sitemap'    => isset( $context['sitemap'] ) ? $context['sitemap'] : array(),
        );

        $summary_text = wp_json_encode( $summary );

        // Use real newlines within the string to avoid double escaping.
        return $instructions . "\n\nContexto:\n" . $summary_text;
    }

    /**
     * Reduce the provided context to the entries most relevant to the message.
     *
     * Applies lightweight keyword scoring so the OpenAI prompt contains the
     * pages, products, FAQs and categories that best match the user request.
     * The resulting subset keeps the original order as a tiebreaker to avoid
     * destabilising the prompt when there are no obvious matches.
     *
     * @param string $message User message.
     * @param array  $context Full site context array.
     *
     * @return array Refined context.
     */
    protected function refine_context_for_message( $message, $context, $settings = array(), $tokens = null ) {
        $settings = wp_parse_args(
            $settings,
            array(
                'max_pages'    => 60,
                'max_products' => 60,
                'max_posts'    => 60,
            )
        );

        if ( null === $tokens ) {
            $tokens = $this->tokenize_text( $message );

            /**
             * Filter the set of query tokens extracted from the user message.
             *
             * @param array  $tokens  Normalised unique tokens.
             * @param string $message Original user message.
             * @param array  $context Full site context.
             */
            $tokens = apply_filters( 'groui_smart_assistant_query_tokens', $tokens, $message, $context );
        }

        if ( empty( $tokens ) ) {
            return $context;
        }

        $refined = $context;

        if ( ! empty( $context['pages'] ) && is_array( $context['pages'] ) ) {
            $default_limit = $this->normalize_limit( $settings['max_pages'], $context['pages'] );
            $limit         = apply_filters( 'groui_smart_assistant_max_relevant_pages', $default_limit, $message, $context, $tokens, $settings );
            $refined['pages'] = $this->filter_entries_by_tokens(
                $context['pages'],
                $tokens,
                array(
                    'title'   => 3,
                    'excerpt' => 1,
                    'content' => 2,
                ),
                $limit
            );
        }

        if ( ! empty( $context['posts'] ) && is_array( $context['posts'] ) ) {
            $default_limit = $this->normalize_limit(
                isset( $settings['max_posts'] ) ? $settings['max_posts'] : count( $context['posts'] ),
                $context['posts']
            );
            $limit = apply_filters( 'groui_smart_assistant_max_relevant_posts', $default_limit, $message, $context, $tokens, $settings );
            $refined['posts'] = $this->filter_entries_by_tokens(
                $context['posts'],
                $tokens,
                array(
                    'title'      => 3,
                    'excerpt'    => 2,
                    'content'    => 2,
                    'categories' => 2,
                    'tags'       => 1,
                ),
                $limit
            );
        }

        if ( ! empty( $context['faqs'] ) && is_array( $context['faqs'] ) ) {
            $default_limit = $this->normalize_limit( count( $context['faqs'] ), $context['faqs'] );
            $limit         = apply_filters( 'groui_smart_assistant_max_relevant_faqs', $default_limit, $message, $context, $tokens, $settings );
            $refined['faqs'] = $this->filter_entries_by_tokens(
                $context['faqs'],
                $tokens,
                array(
                    'question' => 2,
                ),
                $limit
            );
        }

        if ( ! empty( $context['products'] ) && is_array( $context['products'] ) ) {
            $default_limit = $this->normalize_limit( $settings['max_products'], $context['products'] );
            $limit         = apply_filters( 'groui_smart_assistant_max_relevant_products', $default_limit, $message, $context, $tokens, $settings );
            $refined['products'] = $this->filter_entries_by_tokens(
                $context['products'],
                $tokens,
                array(
                    'name'           => 5,
                    'short_desc'     => 2,
                    'long_desc'      => 2,
                    'category_names' => 2,
                    'tags'           => 1,
                    'attributes'     => 1,
                ),
                $limit
            );
        }

        if ( ! empty( $context['categories'] ) && is_array( $context['categories'] ) ) {
            $filtered_categories = array();

            foreach ( $context['categories'] as $taxonomy => $terms ) {
                if ( empty( $terms ) || ! is_array( $terms ) ) {
                    continue;
                }

                $default_limit = $this->normalize_limit( count( $terms ), $terms );
                $limit         = apply_filters( 'groui_smart_assistant_max_relevant_terms', $default_limit, $taxonomy, $message, $context, $tokens, $settings );
                $filtered_terms = $this->filter_entries_by_tokens(
                    $terms,
                    $tokens,
                    array(
                        'name'        => 3,
                        'description' => 1,
                    ),
                    $limit
                );

                if ( ! empty( $filtered_terms ) ) {
                    $filtered_categories[ $taxonomy ] = $filtered_terms;
                }
            }

            if ( ! empty( $filtered_categories ) ) {
                $refined['categories'] = $filtered_categories;
            }
        }

        if ( ! empty( $context['sitemap'] ) && is_array( $context['sitemap'] ) ) {
            $default_limit = $this->normalize_limit( count( $context['sitemap'] ), $context['sitemap'] );
            $limit         = apply_filters( 'groui_smart_assistant_max_relevant_sitemap', $default_limit, $message, $context, $tokens, $settings );
            $refined['sitemap'] = $this->filter_entries_by_tokens(
                $context['sitemap'],
                $tokens,
                array(
                    'url'     => 2,
                    'lastmod' => 1,
                ),
                $limit
            );
        }

        /**
         * Filter the refined context before it is converted into the system prompt.
         *
         * @param array  $refined  Refined context array.
         * @param array  $context  Original full context array.
         * @param string $message  User message.
         * @param array  $tokens   Tokens extracted from the user message.
         * @param array  $settings Plugin settings array.
         */
        return apply_filters( 'groui_smart_assistant_refined_context', $refined, $context, $message, $tokens, $settings );
    }

    /**
     * Ensure the requested limit is within a sensible range for the provided entries.
     *
     * @param int   $requested Requested limit.
     * @param array $entries   Entries available for the section.
     *
     * @return int Normalised limit value.
     */
    protected function normalize_limit( $requested, $entries ) {
        $limit = max( 1, absint( $requested ) );

        if ( is_array( $entries ) ) {
            $count = count( $entries );

            if ( $count > 0 ) {
                $limit = min( $limit, $count );
            }
        }

        return $limit;
    }

    /**
     * Tokenize a string into lowercase alphanumeric terms.
     *
     * @param string $text Raw text.
     *
     * @return array Unique tokens.
     */
    protected function tokenize_text( $text ) {
        $normalized = $this->normalize_text( $text );

        if ( '' === $normalized ) {
            return array();
        }

        $parts = preg_split( '/\s+/', $normalized );
        $parts = array_filter(
            array_map( 'trim', $parts ),
            static function( $token ) {
                return strlen( $token ) > 2;
            }
        );

        return array_values( array_unique( $parts ) );
    }

    /**
     * Normalise text for token comparisons.
     *
     * @param string $text Input string.
     *
     * @return string Normalised text.
     */
    protected function normalize_text( $text ) {
        $text = wp_strip_all_tags( (string) $text );

        if ( function_exists( 'remove_accents' ) ) {
            $text = remove_accents( $text );
        }

        $text = strtolower( $text );
        $text = preg_replace( '/[^a-z0-9\s]+/u', ' ', $text );
        $text = preg_replace( '/\s+/', ' ', $text );

        return trim( $text );
    }

    /**
     * Score an array of entries using keyword overlap.
     *
     * @param array $entries    Entries to score.
     * @param array $tokens     Query tokens.
     * @param array $field_map  Field weight map.
     * @param int   $limit      Maximum number of entries to keep.
     *
     * @return array Filtered entries ordered by relevance.
     */
    protected function filter_entries_by_tokens( $entries, $tokens, $field_map, $limit ) {
        $limit = max( 1, absint( $limit ) );
        $scored = array();
        $max    = 0;

        foreach ( $entries as $index => $entry ) {
            if ( ! is_array( $entry ) ) {
                continue;
            }

            $score = 0;

            foreach ( $field_map as $field => $weight ) {
                if ( empty( $entry[ $field ] ) ) {
                    continue;
                }

                $score += (float) $weight * $this->calculate_overlap_score( $tokens, $entry[ $field ] );
            }

            $max = max( $max, $score );

            $scored[] = array(
                'entry' => $entry,
                'score' => $score,
                'index' => $index,
            );
        }

        if ( empty( $scored ) ) {
            return array();
        }

        if ( $max <= 0 ) {
            return array_slice( $entries, 0, $limit );
        }

        usort(
            $scored,
            static function( $a, $b ) {
                if ( $a['score'] === $b['score'] ) {
                    return $a['index'] <=> $b['index'];
                }

                return ( $a['score'] > $b['score'] ) ? -1 : 1;
            }
        );

        $scored = array_slice( $scored, 0, $limit );

        return array_map(
            static function( $item ) {
                return $item['entry'];
            },
            $scored
        );
    }

    /**
     * Calculate how strongly a set of tokens overlaps a text string.
     *
     * @param array  $tokens Tokens derived from the user query.
     * @param string $text   Text to score against.
     *
     * @return float Overlap score.
     */
    protected function calculate_overlap_score( $tokens, $text ) {
        if ( empty( $tokens ) ) {
            return 0;
        }

        $text = $this->flatten_text_value( $text );

        $normalized = $this->normalize_text( $text );

        if ( '' === $normalized ) {
            return 0;
        }

        $score = 0;

        foreach ( $tokens as $token ) {
            if ( '' === $token ) {
                continue;
            }

            $pattern     = '/\b' . preg_quote( $token, '/' ) . '\b/u';
            $occurrences = preg_match_all( $pattern, $normalized, $unused );

            if ( false === $occurrences ) {
                $occurrences = 0;
            }

            $score += $occurrences;
        }

        return $score;
    }

    /**
     * Flatten mixed values (arrays/objects/scalars) into a searchable string.
     *
     * @param mixed $value Raw value.
     *
     * @return string
     */
    protected function flatten_text_value( $value ) {
        if ( is_array( $value ) ) {
            $pieces = array();

            foreach ( $value as $item ) {
                $flattened = $this->flatten_text_value( $item );

                if ( '' !== $flattened ) {
                    $pieces[] = $flattened;
                }
            }

            return implode( ' ', $pieces );
        }

        if ( is_object( $value ) ) {
            if ( method_exists( $value, '__toString' ) ) {
                return (string) $value;
            }

            return wp_json_encode( $value );
        }

        if ( is_bool( $value ) ) {
            return $value ? 'true' : 'false';
        }

        if ( is_scalar( $value ) ) {
            return (string) $value;
        }

        return '';
    }

    /**
     * Normalize the configured model name.
     *
     * Ensures the value is lowercase, trimmed and uses hyphens for
     * whitespace so that values such as "GPT 5" become "gpt-5" before the
     * request is sent to OpenAI.
     *
     * @param string $model Raw model string stored in settings.
     *
     * @return string Normalized model identifier.
     */
    protected function normalize_model( $model ) {
        $model = strtolower( trim( (string) $model ) );
        $model = preg_replace( '/\s+/', '-', $model );
        $model = preg_replace( '/[^a-z0-9\-.]/', '', $model );

        return $model;
    }
}
