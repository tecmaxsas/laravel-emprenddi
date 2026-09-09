<?php

namespace App\Services\Ai;

use App\Models\AiConversation;
use App\Models\AiMessage;
use App\Models\Company;
use App\Models\User;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * El orquestador: recibe una pregunta y devuelve la respuesta de Claude.
 *
 * Hace tres cosas que no se pueden separar sin duplicar lógica: arma el
 * contexto, corre el ciclo de herramientas contra la base y deja escrito lo que
 * pasó —mensajes, consultas usadas, tokens y cobro—.
 */
class AiAssistant
{
    public function __construct(
        private readonly AiCredits $credits,
    ) {}

    /**
     * Responde una pregunta dentro de una conversación.
     *
     * Devuelve el mensaje del asistente ya guardado. Si algo falla, también:
     * una conversación con un error visible se puede retomar; una con un hueco
     * deja al usuario sin saber si preguntó o no.
     */
    public function responder(AiConversation $conversacion, string $pregunta, User $usuario): AiMessage
    {
        $company = $conversacion->company ?? Company::findOrFail($conversacion->company_id);
        $ajustes = AiSettings::para($company);

        if ($motivo = $ajustes->motivoParaNoUsar()) {
            throw new RuntimeException($motivo);
        }

        $this->guardarPregunta($conversacion, $pregunta);

        $tools = new BusinessTools($company);
        $cliente = new AnthropicClient($ajustes->apiKey());
        $modelo = $conversacion->model ?: $ajustes->modelo();

        $mensajes = $this->historialParaElModelo($conversacion);
        $usadas = [];
        $tokensEntrada = 0;
        $tokensSalida = 0;
        $texto = '';
        $error = null;

        for ($vuelta = 0; $vuelta < config('ai.max_tool_rounds'); $vuelta++) {
            $respuesta = $cliente->mensajes(
                $modelo,
                $this->instrucciones($company, $usuario),
                $mensajes,
                $tools->definiciones(),
            );

            if (! $respuesta['ok']) {
                $error = $respuesta['error'];
                break;
            }

            $datos = $respuesta['data'];
            $tokensEntrada += (int) data_get($datos, 'usage.input_tokens', 0);
            $tokensSalida += (int) data_get($datos, 'usage.output_tokens', 0);

            $bloques = $datos['content'] ?? [];
            $texto = trim($texto."\n\n".$this->textoDe($bloques));

            $pedidos = array_values(array_filter($bloques, fn ($b) => ($b['type'] ?? '') === 'tool_use'));

            if ($pedidos === []) {
                break;
            }

            // Claude pidió datos: se ejecutan las consultas y se le devuelven
            // para que arme la respuesta con cifras reales.
            $mensajes[] = ['role' => 'assistant', 'content' => $bloques];
            $resultados = [];

            foreach ($pedidos as $pedido) {
                $nombre = $pedido['name'] ?? '';
                $argumentos = (array) ($pedido['input'] ?? []);
                $salida = $tools->ejecutar($nombre, $argumentos);

                $usadas[] = ['nombre' => $nombre, 'argumentos' => $argumentos];

                $resultados[] = [
                    'type' => 'tool_result',
                    'tool_use_id' => $pedido['id'] ?? '',
                    'content' => json_encode($salida, JSON_UNESCAPED_UNICODE) ?: '{}',
                ];
            }

            $mensajes[] = ['role' => 'user', 'content' => $resultados];

            if ($vuelta === config('ai.max_tool_rounds') - 1) {
                $error = 'La consulta resultó demasiado larga. Prueba con una pregunta más concreta.';
            }
        }

        $texto = trim($texto);

        if ($texto === '' && ! $error) {
            $error = 'Claude no devolvió una respuesta. Inténtalo de nuevo.';
        }

        $costo = $ajustes->usaCuentaPropia()
            ? 0.0
            : $this->costoEnPesos($modelo, $tokensEntrada, $tokensSalida);

        $mensaje = AiMessage::create([
            'ai_conversation_id' => $conversacion->id,
            'role' => AiMessage::ROLE_ASSISTANT,
            'content' => $texto ?: null,
            'tools' => $usadas ?: null,
            'input_tokens' => $tokensEntrada,
            'output_tokens' => $tokensSalida,
            'cost_cop' => $costo,
            'error' => $error,
            'created_at' => now(),
        ]);

        $conversacion->update([
            'model' => $modelo,
            'last_message_at' => now(),
        ]);

        if ($costo > 0) {
            $this->credits->cobrar($company, $costo, $conversacion);
        }

        return $mensaje;
    }

    /**
     * Lo que Claude sabe antes de que el usuario escriba.
     *
     * Se le dice qué es el sistema, con qué empresa está hablando y cómo debe
     * comportarse. Lo importante es la última parte: sin ella, un modelo
     * responde de memoria en vez de consultar, y una cifra inventada en un
     * reporte de ventas es peor que no tener la funcionalidad.
     */
    private function instrucciones(Company $company, User $usuario): string
    {
        return implode("\n", [
            'Eres el asistente de datos de Emprenddi, un sistema POS y contable colombiano.',
            '',
            'Estás atendiendo a '.$usuario->name.', de la empresa «'.$company->name.'»'
                .($company->nit ? ' (NIT '.$company->nit.')' : '').'.',
            'La fecha de hoy es '.now()->translatedFormat('l j \d\e F \d\e Y').'.',
            '',
            'REGLAS:',
            '- Responde SIEMPRE con datos obtenidos de las herramientas. Nunca inventes ni estimes',
            '  una cifra: si no tienes la consulta para responder algo, dilo con claridad.',
            '- Los valores están en pesos colombianos. Formatéalos como $1.234.567.',
            '- Cuando el usuario diga "este mes", "la semana pasada" o "ayer", calcula las fechas',
            '  concretas a partir de la fecha de hoy y pásalas a las herramientas.',
            '- Sé breve y directo. Un comerciante quiere la cifra y qué significa, no un informe.',
            '- Usa tablas de markdown cuando compares varias filas.',
            '- Si un resultado viene vacío, dilo: "no hay ventas registradas en ese período" es una',
            '  respuesta válida y útil.',
            '- Solo ves datos de esta empresa. Si te piden información de otra, explica que no puedes.',
            '- No puedes crear, modificar ni borrar nada: solo consultar.',
        ]);
    }

    /**
     * El historial en el formato de la API.
     *
     * Se manda solo la cola de la conversación. La entera se conserva en la
     * base, pero enviarla completa haría que cada pregunta costara más que la
     * anterior hasta volverse impagable.
     */
    private function historialParaElModelo(AiConversation $conversacion): array
    {
        return $conversacion->messages()
            ->whereNotNull('content')
            ->orderByDesc('id')
            ->limit(config('ai.context_messages'))
            ->get()
            ->reverse()
            ->values()
            ->map(fn (AiMessage $m) => [
                'role' => $m->role,
                'content' => $m->content,
            ])
            ->all();
    }

    private function guardarPregunta(AiConversation $conversacion, string $pregunta): AiMessage
    {
        $mensaje = AiMessage::create([
            'ai_conversation_id' => $conversacion->id,
            'role' => AiMessage::ROLE_USER,
            'content' => trim($pregunta),
            'created_at' => now(),
        ]);

        $conversacion->update(['last_message_at' => now()]);

        return $mensaje;
    }

    private function textoDe(array $bloques): string
    {
        return trim(collect($bloques)
            ->where('type', 'text')
            ->pluck('text')
            ->implode("\n"));
    }

    /**
     * Lo que se le cobra a la empresa: el costo de Anthropic pasado a pesos,
     * por el margen de Tecmax. Las tarifas viven en config/ai.php.
     */
    private function costoEnPesos(string $modelo, int $entrada, int $salida): float
    {
        $tarifas = config("ai.models.{$modelo}");

        if (! $tarifas) {
            Log::warning("[Claude] Modelo sin tarifa configurada: {$modelo}. No se cobra.");

            return 0.0;
        }

        $usd = ($entrada / 1_000_000) * (float) $tarifas['input_usd_per_mtok']
            + ($salida / 1_000_000) * (float) $tarifas['output_usd_per_mtok'];

        return round($usd * (float) config('ai.usd_to_cop') * (float) config('ai.margin'), 2);
    }
}
