<?php

/**
 * Integración con Claude.
 *
 * Dos formas de conectarse conviven:
 *
 *   - «tecmax»: la llave la pone Tecmax y la empresa consume de un saldo que
 *     recarga con el equipo comercial. Cada conversación descuenta.
 *   - «propia»: la empresa pone su propia llave de Anthropic y le factura
 *     directamente Anthropic. No consume saldo.
 *
 * Los precios están en dólares por millón de tokens, tal como los publica
 * Anthropic, y se convierten a pesos con la tasa de abajo. Van en configuración
 * y no en código porque cambian sin avisar.
 */
return [

    // Llave de Tecmax. Solo se usa en modo «tecmax»; si falta, ese modo queda
    // deshabilitado y la pantalla lo dice.
    'api_key' => env('ANTHROPIC_API_KEY'),

    'api_url' => env('ANTHROPIC_API_URL', 'https://api.anthropic.com/v1/messages'),

    'version' => env('ANTHROPIC_VERSION', '2023-06-01'),

    // WhatsApp del equipo comercial para pedir recarga de saldo.
    'sales_whatsapp' => env('TECMAX_SALES_WHATSAPP', env('SUPPORT_WHATSAPP', '573246415947')),

    'default_model' => env('ANTHROPIC_MODEL', 'claude-sonnet-5'),

    /**
     * Modelos ofrecidos. La etiqueta es lo que ve el usuario: no le sirve el id
     * técnico, le sirve saber cuál es más listo y cuál más barato.
     */
    'models' => [
        'claude-sonnet-5' => [
            'label' => 'Claude Sonnet 5 — el más capaz (recomendado)',
            'input_usd_per_mtok' => 3.0,
            'output_usd_per_mtok' => 15.0,
        ],
        'claude-haiku-4-5-20251001' => [
            'label' => 'Claude Haiku 4.5 — más rápido y económico',
            'input_usd_per_mtok' => 1.0,
            'output_usd_per_mtok' => 5.0,
        ],
    ],

    /**
     * Tasa del dólar, SOLO para mostrar un equivalente aproximado en pesos.
     *
     * El monedero se lleva en dólares —es lo que factura Anthropic y lo que
     * vende Tecmax— así que cambiar este número no altera ningún cobro ni el
     * saldo de nadie. Antes sí lo hacía, y subirlo le reducía el poder de compra
     * a un saldo ya recargado.
     */
    'usd_to_cop' => (float) env('AI_USD_TO_COP', 4200),

    /**
     * Cuanto se le descuenta al cliente por cada peso que cuesta Anthropic.
     *
     * Es un multiplicador, no un porcentaje, y el valor sale del modelo de
     * negocio de Tecmax: el cliente recarga 50 USD, ve 50 USD en su monedero, y
     * por dentro esos 50 le alcanzan para 37,5 USD de consumo real. Tecmax se
     * queda con 12,5 = el 25 % de lo facturado.
     *
     *     multiplicador = 50 / 37.5 = 1 / (1 - 0.25) = 1.3333
     *
     * Ojo con la confusion: 1.25 NO es una ganancia del 25 %, es del 20 % sobre
     * la venta. Para una ganancia del X % sobre lo facturado, el multiplicador
     * es 1/(1-X).
     */
    'margin' => (float) env('AI_MARGIN', 1.3333),

    // Cuántas vueltas de herramientas se permiten en una respuesta. Sin tope,
    // un modelo confundido podría encadenar consultas sin parar.
    'max_tool_rounds' => (int) env('AI_MAX_TOOL_ROUNDS', 8),

    // Cuántos mensajes anteriores se le mandan al modelo. La conversación
    // completa se conserva en la base; esto solo limita lo que viaja.
    'context_messages' => (int) env('AI_CONTEXT_MESSAGES', 20),

    'timeout' => (int) env('AI_TIMEOUT', 120),
];
