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
        $api_key  = isset( $settings['openai_api_key'] ) ? trim( $settings['openai_api_key'] ) : '';
        $model    = isset( $settings['model'] ) ? $settings['model'] : '';
        $model    = $this->normalize_model( $model );

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

        $meta     = isset( $context['_meta'] ) && is_array( $context['_meta'] ) ? $context['_meta'] : array();
        $warnings = isset( $meta['warnings'] ) && is_array( $meta['warnings'] ) ? $meta['warnings'] : array();
        $errors   = isset( $meta['errors'] ) && is_array( $meta['errors'] ) ? $meta['errors'] : array();

        if ( ! empty( $warnings ) ) {
            do_action( 'groui_smart_assistant_context_warnings', $warnings, $context );
        }

        if ( ! empty( $errors ) ) {
            do_action( 'groui_smart_assistant_context_errors', $errors, $context );
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

        $timeout_setting = isset( $settings['openai_timeout'] ) ? absint( $settings['openai_timeout'] ) : 30;
        if ( $timeout_setting < 5 ) {
            $timeout_setting = 5;
        }
        if ( $timeout_setting > 60 ) {
            $timeout_setting = 60;
        }

        $timeout = apply_filters( 'groui_smart_assistant_openai_timeout', $timeout_setting, $context, $message );

        $response = wp_remote_post(
            'https://api.openai.com/v1/chat/completions',
            array(
                'headers' => array(
                    'Content-Type'  => 'application/json',
                    'Authorization' => 'Bearer ' . $api_key,
                ),
                'timeout' => $timeout,
                'body'    => wp_json_encode( $body ),
            )
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
            return new WP_Error( 'openai_error', $message, array(
                'response' => $data,
                'meta'     => $meta,
            ) );
        }

        // Ensure a message was returned.
        if ( empty( $data['choices'][0]['message']['content'] ) ) {
            return new WP_Error( 'empty_response', __( 'OpenAI no devolvió contenido.', 'groui-smart-assistant' ), array(
                'response' => $data,
                'meta'     => $meta,
            ) );
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
            'meta'    => $meta,
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
        $instructions = __( 'Eres GROUI Smart Assistant, una IA entrenada con toda la información de este sitio. Usa los datos proporcionados para responder preguntas, guiar procesos de compra y recomendar productos de WooCommerce. Devuelve siempre una respuesta JSON con las claves "answer" (HTML amigable) y "products" (arreglo opcional de IDs de productos de WooCommerce que quieras resaltar). Cuando no tengas información suficiente, sé honesto.', 'groui-smart-assistant' );

        $summary = array(
            'site'       => isset( $context['site'] ) ? $context['site'] : '',
            'tagline'    => isset( $context['tagline'] ) ? $context['tagline'] : '',
            'pages'      => isset( $context['pages'] ) ? $context['pages'] : array(),
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
