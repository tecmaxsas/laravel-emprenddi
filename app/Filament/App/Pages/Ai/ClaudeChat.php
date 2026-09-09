<?php

namespace App\Filament\App\Pages\Ai;

use App\Models\AiConversation;
use App\Models\AiMessage;
use App\Models\Company;
use App\Services\Ai\AiAssistant;
use App\Services\Ai\AiCredits;
use App\Services\Ai\AiSettings;
use App\Support\CurrentCompany;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\Collection;
use Throwable;

/**
 * La pantalla de conversación con Claude.
 *
 * Las conversaciones se guardan y se retoman: la lista de la izquierda es el
 * historial del usuario, no una sesión que se pierde al cerrar el navegador.
 *
 * La respuesta se pide de forma síncrona. Es lo más simple que funciona y evita
 * montar colas y websockets para algo que tarda unos segundos; a cambio, la
 * pantalla se bloquea mientras responde, y por eso el botón muestra su estado.
 */
class ClaudeChat extends Page
{
    protected static ?string $navigationIcon = 'heroicon-o-sparkles';

    protected static ?string $navigationLabel = 'Conversar';

    protected static ?string $navigationGroup = 'Claude AI';

    protected static ?int $navigationSort = 10;

    protected static ?string $title = 'Claude AI';

    protected static ?string $slug = 'claude';

    protected static string $view = 'filament.app.pages.ai.claude-chat';

    public ?int $conversationId = null;

    public string $pregunta = '';

    public static function canAccess(): bool
    {
        return (bool) auth()->user()?->can('ai.use');
    }

    public function getSubheading(): string|Htmlable|null
    {
        return 'Pregúntale a Claude sobre tus ventas, tu inventario, tu cartera o tus gastos. '
            .'Consulta los datos de esta empresa en el momento: no trabaja con copias.';
    }

    public function mount(): void
    {
        // Se abre la última conversación en la que estaba: es lo que espera
        // quien vuelve a la pantalla a seguir donde iba.
        $this->conversationId = $this->conversaciones->first()?->id;
    }

    // ------------------------------------------------------------- consultas

    public function getEmpresaProperty(): ?Company
    {
        return app(CurrentCompany::class)->get()
            ?? Company::find(auth()->user()?->company_id);
    }

    public function getAjustesProperty(): AiSettings
    {
        return AiSettings::para($this->empresa);
    }

    /** Por qué no se puede usar ahora mismo, o null si sí se puede. */
    public function getBloqueoProperty(): ?string
    {
        return $this->ajustes->motivoParaNoUsar();
    }

    public function getSaldoProperty(): ?float
    {
        if (! $this->empresa || $this->ajustes->usaCuentaPropia()) {
            return null;
        }

        return app(AiCredits::class)->saldo($this->empresa);
    }

    public function getWhatsappRecargaProperty(): string
    {
        $numero = preg_replace('/\D+/', '', (string) config('ai.sales_whatsapp'));

        $texto = rawurlencode(sprintf(
            'Hola, soy %s de %s. Quiero recargar saldo de Claude AI en Emprenddi.',
            auth()->user()?->name ?? '',
            $this->empresa?->name ?? '',
        ));

        return "https://wa.me/{$numero}?text={$texto}";
    }

    /** @return Collection<int, AiConversation> */
    public function getConversacionesProperty(): Collection
    {
        return AiConversation::query()
            ->where('user_id', auth()->id())
            ->orderByDesc('last_message_at')
            ->orderByDesc('id')
            ->limit(50)
            ->get();
    }

    public function getConversacionProperty(): ?AiConversation
    {
        if (! $this->conversationId) {
            return null;
        }

        return AiConversation::query()
            ->where('user_id', auth()->id())
            ->find($this->conversationId);
    }

    /** @return Collection<int, AiMessage> */
    public function getMensajesProperty(): Collection
    {
        return $this->conversacion?->messages()->get() ?? collect();
    }

    // -------------------------------------------------------------- acciones

    public function nuevaConversacion(): void
    {
        $this->conversationId = null;
        $this->pregunta = '';
    }

    public function abrir(int $id): void
    {
        if (AiConversation::query()->where('user_id', auth()->id())->whereKey($id)->exists()) {
            $this->conversationId = $id;
        }
    }

    public function eliminar(int $id): void
    {
        $conversacion = AiConversation::query()->where('user_id', auth()->id())->find($id);

        if (! $conversacion) {
            return;
        }

        $conversacion->delete();

        if ($this->conversationId === $id) {
            $this->conversationId = null;
        }

        Notification::make()->title('Conversación eliminada')->success()->send();
    }

    public function preguntar(): void
    {
        $texto = trim($this->pregunta);

        if ($texto === '') {
            return;
        }

        if ($motivo = $this->bloqueo) {
            Notification::make()->title($motivo)->warning()->send();

            return;
        }

        $conversacion = $this->conversacion ?? AiConversation::create([
            'company_id' => $this->empresa?->id,
            'user_id' => auth()->id(),
            'title' => AiConversation::tituloDesde($texto),
            'model' => $this->ajustes->modelo(),
            'last_message_at' => now(),
        ]);

        $this->conversationId = $conversacion->id;
        $this->pregunta = '';

        try {
            app(AiAssistant::class)->responder($conversacion, $texto, auth()->user());
        } catch (Throwable $e) {
            // La pregunta ya quedó guardada; se deja constancia del fallo en la
            // misma conversación para que se pueda reintentar viendo el motivo.
            AiMessage::create([
                'ai_conversation_id' => $conversacion->id,
                'role' => AiMessage::ROLE_ASSISTANT,
                'error' => $e->getMessage(),
                'created_at' => now(),
            ]);

            Notification::make()->title('No se pudo responder')->body($e->getMessage())->danger()->send();
        }
    }

    /** Preguntas de arranque, para que nadie se quede mirando un campo vacío. */
    public function getSugerenciasProperty(): array
    {
        return [
            '¿Cuánto vendí este mes comparado con el mes pasado?',
            '¿Cuáles son mis 10 productos más vendidos de los últimos 30 días?',
            '¿Qué clientes me deben y desde hace cuánto?',
            '¿Qué productos están agotados?',
            '¿En qué gasté más este mes?',
            '¿Hubo descuadres de caja en las últimas dos semanas?',
        ];
    }

    public function usarSugerencia(string $texto): void
    {
        $this->pregunta = $texto;
    }
}
