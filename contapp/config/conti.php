<?php

/*
|--------------------------------------------------------------------------
| Conti, el asistente (CLAUDE.md secc. 32)
|--------------------------------------------------------------------------
|
| El consumo se mide en créditos: 1 crédito = credit_usd dólares de lo que
| cobra OpenAI por el modelo. Así un límite de «100 créditos por día» es un
| tope de gasto conocido (US$1,00 con el valor por defecto), sin importar si
| la gente hace preguntas simples o pesadas.
|
*/

return [

    'credit_usd' => (float) env('CONTI_CREDIT_USD', 0.01),

    /*
    | Precio por millón de tokens, en dólares: entrada, entrada en caché
    | (OpenAI la cobra más barata cuando el inicio del mensaje se repite) y
    | salida. Son los de la lista pública de OpenAI al escribir esto:
    | verificalos en https://openai.com/api/pricing antes de cobrar por
    | consumo, y actualizalos acá si cambian. El modelo de una respuesta
    | («gpt-4.1-mini-2025-04-14») se busca por el prefijo más largo.
    */
    'pricing' => [
        'gpt-4.1-nano' => ['input' => 0.10, 'cached_input' => 0.025, 'output' => 0.40],
        'gpt-4.1-mini' => ['input' => 0.40, 'cached_input' => 0.10, 'output' => 1.60],
        'gpt-4.1' => ['input' => 2.00, 'cached_input' => 0.50, 'output' => 8.00],
        'gpt-4o-mini' => ['input' => 0.15, 'cached_input' => 0.075, 'output' => 0.60],
        'gpt-4o' => ['input' => 2.50, 'cached_input' => 1.25, 'output' => 10.00],
        'gpt-5-nano' => ['input' => 0.05, 'cached_input' => 0.005, 'output' => 0.40],
        'gpt-5-mini' => ['input' => 0.25, 'cached_input' => 0.025, 'output' => 2.00],
        'gpt-5' => ['input' => 1.25, 'cached_input' => 0.125, 'output' => 10.00],
    ],

    /*
    | Los modelos que cada persona puede elegir en el chat («Modelo y
    | consumo»). Solo los que tienen precio arriba: con otro, el consumo
    | saldría mal. Además, el chat muestra solo los que la key de OpenAI puede
    | usar. Para ofrecer uno nuevo: su precio en «pricing» y una entrada acá.
    |
    | «costo» es lo que ve la persona (bajo, medio, alto); «razona», si es de
    | los que piensan antes de responder (la familia GPT-5): se le pide un
    | razonamiento corto y se le deja escribir más, porque lo que piensa
    | cuenta como salida.
    */
    'models' => [
        'gpt-4.1-nano' => ['name' => 'GPT-4.1 nano', 'cost' => 'bajo', 'description' => 'El más rápido y económico. Para preguntas simples y consultas cortas.'],
        'gpt-4.1-mini' => ['name' => 'GPT-4.1 mini', 'cost' => 'medio', 'description' => 'Equilibrado entre calidad, velocidad y costo. El recomendado para el día a día.'],
        'gpt-4.1' => ['name' => 'GPT-4.1', 'cost' => 'alto', 'description' => 'Más preciso para análisis y preguntas difíciles. Gasta unas cinco veces más que el mini.'],
        'gpt-5-mini' => ['name' => 'GPT-5 mini', 'cost' => 'medio', 'description' => 'Razona antes de responder: mejor para analizar números y comparar. Tarda un poco más.', 'reasoning' => true],
        'gpt-5' => ['name' => 'GPT-5', 'cost' => 'alto', 'description' => 'El que mejor razona, para los análisis más complejos. Tarda más y gasta más.', 'reasoning' => true],
    ],

    // Para los modelos que razonan: cuánto piensan y lo máximo que escriben
    // en cada vuelta (lo que piensan cuenta).
    'reasoning_effort' => env('CONTI_REASONING_EFFORT', 'low'),
    'max_output_tokens_reasoning' => (int) env('CONTI_MAX_OUTPUT_TOKENS_REASONING', 4000),

    // Cuánto se recuerda la lista de modelos que la key puede usar (minutos).
    'models_cache_minutes' => 1440,

    // Para un modelo que no está en la lista: el más caro de arriba, para no
    // quedarse corto. Se puede fijar con estas variables.
    'fallback_pricing' => [
        'input' => (float) env('CONTI_PRICE_INPUT', 2.50),
        'cached_input' => (float) env('CONTI_PRICE_CACHED_INPUT', 1.25),
        'output' => (float) env('CONTI_PRICE_OUTPUT', 10.00),
    ],

    // Cuántas vueltas puede dar el agente (usar herramientas) antes de
    // responder, y lo máximo que puede escribir en cada una.
    'max_iterations' => (int) env('CONTI_MAX_ITERATIONS', 6),
    'max_output_tokens' => (int) env('CONTI_MAX_OUTPUT_TOKENS', 1500),

    // Lo que espera cada llamada al modelo, en segundos.
    'request_timeout' => (int) env('CONTI_REQUEST_TIMEOUT', 60),

    // Lo que devuelve una herramienta, como mucho, en caracteres: más que eso
    // se recorta (y se le avisa al modelo que filtre).
    'max_tool_result_chars' => 24000,

    // La conversación que se le recuerda al modelo: últimos mensajes, y
    // cuánto dura guardada (en caché, nunca en la base).
    'history_messages' => 16,
    'history_minutes' => 360,

    // Los días y las semanas de los límites (la semana va de lunes a domingo).
    'timezone' => env('CONTI_TIMEZONE', 'America/Costa_Rica'),

    // Desde qué porcentaje de un límite se avisa en el chat.
    'warning_percent' => 80,

];
