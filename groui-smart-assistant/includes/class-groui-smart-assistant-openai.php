<?php
/**
 * OpenAI client wrapper.
 *
 * @package GROUI_Smart_Assistant
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class GROUI_Smart_Assistant_OpenAI {

    /**
     * Send a chat completion request to OpenAI.
     *
     * @param string $message User message.
     * @param array  $context Site context array.
     *
     * @return array|WP_Error
     */
    public function query( $message, $context ) {
        $settings = get_option( GROUI_Smart_Assistant::OPTION_KEY, array() );
        $api_key  = isset( $settings['openai_api_key'] ) ? trim( $settings['openai_api_key'] ) : '';
        $model    = ! empty( $settings['model'] ) ? $settings['model'] : 'gpt-5.1';

        if ( empty( $api_key ) ) {
            return new WP_Error( 'missing_api_key', __( 'Falta la API key de OpenAI.', 'groui-smart-assistant' ) );
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
            'temperature' => 0.3,
        );

        $body = apply_filters( 'groui_smart_assistant_openai_body', $body, $message, $context );

        $response = wp_remote_post(
            'https://api.openai.com/v1/chat/completions',
            array(
                'headers' => array(
                    'Content-Type'  => 'application/json',
                    'Authorization' => 'Bearer ' . $api_key,
                ),
                'timeout' => 30,
                'body'    => wp_json_encode( $body ),
            )
        );

        if ( is_wp_error( $response ) ) {
            return $response;
        }

        $code = wp_remote_retrieve_response_code( $response );
        $data = json_decode( wp_remote_retrieve_body( $response ), true );

        if ( $code >= 400 ) {
            $message = isset( $data['error']['message'] ) ? $data['error']['message'] : __( 'Error desconocido en OpenAI.', 'groui-smart-assistant' );
            return new WP_Error( 'openai_error', $message, $data );
        }

        if ( empty( $data['choices'][0]['message']['content'] ) ) {
            return new WP_Error( 'empty_response', __( 'OpenAI no devolvió contenido.', 'groui-smart-assistant' ), $data );
        }

        $content = $data['choices'][0]['message']['content'];
        $parsed  = json_decode( $content, true );

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
     * Compose system prompt with context data.
     *
     * @param array $context Context array.
     *
     * @return string
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

        return $instructions . '\n\nContexto:\n' . $summary_text;
    }
}
