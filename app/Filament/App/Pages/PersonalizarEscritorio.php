<?php

namespace App\Filament\App\Pages;

use App\Models\DashboardPreference;
use App\Support\DashboardSections;
use Filament\Forms;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Pages\Page;

/**
 * Permite a cada usuario elegir qué secciones del Escritorio ve y en qué
 * orden. Solo aparecen las secciones que su rol/permiso le habilita.
 */
class PersonalizarEscritorio extends Page implements HasForms
{
    use InteractsWithForms;

    protected static ?string $navigationIcon = 'heroicon-o-squares-2x2';
    protected static ?string $navigationLabel = 'Personalizar Escritorio';
    protected static ?string $title = 'Personalizar Escritorio';
    protected static ?int $navigationSort = 99;

    protected static string $view = 'filament.app.pages.personalizar-escritorio';

    public ?array $data = [];

    public static function canAccess(): bool
    {
        // Visible para cualquier usuario con al menos una sección disponible.
        return ! empty(DashboardSections::availableFor(auth()->user()));
    }

    public function mount(): void
    {
        $user = auth()->user();
        $pref = DashboardPreference::query()->where('user_id', $user->id)->first();
        $hidden = $pref?->hidden_sections ?? [];
        $order = DashboardPreference::visibleSectionsFor($user); // orden actual visible

        // Orden de presentación: visibles (en su orden) + ocultas al final
        $available = array_keys(DashboardSections::availableFor($user));
        $ordered = $order;
        foreach ($available as $key) {
            if (! in_array($key, $ordered, true)) {
                $ordered[] = $key;
            }
        }

        // Repeater de secciones: cada item lleva la key + si está visible
        $items = [];
        foreach ($ordered as $key) {
            $items[] = [
                'key' => $key,
                'label' => DashboardSections::SECTIONS[$key]['label'],
                'description' => DashboardSections::SECTIONS[$key]['description'],
                'visible' => ! in_array($key, $hidden, true),
            ];
        }

        $this->form->fill(['sections' => $items]);
    }

    public function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Section::make('Secciones del Escritorio')
                    ->description('Activa o desactiva las secciones que quieres ver al entrar. Arrastra para cambiar el orden. Solo aparecen las secciones que tu rol permite.')
                    ->schema([
                        Forms\Components\Repeater::make('sections')
                            ->label('')
                            ->reorderable()
                            ->reorderableWithButtons()
                            ->addable(false)
                            ->deletable(false)
                            ->collapsible(false)
                            ->itemLabel(fn (array $state) => $state['label'] ?? '')
                            ->schema([
                                Forms\Components\Hidden::make('key'),
                                Forms\Components\Hidden::make('label'),
                                Forms\Components\Toggle::make('visible')
                                    ->label(fn (Forms\Get $get) => $get('label'))
                                    ->inline(true),
                                Forms\Components\Placeholder::make('description')
                                    ->label('')
                                    ->content(fn (Forms\Get $get) => $get('description')),
                            ]),
                    ]),
            ])
            ->statePath('data');
    }

    public function save(): void
    {
        $user = auth()->user();
        $sections = $this->data['sections'] ?? [];

        $hidden = [];
        $order = [];
        foreach ($sections as $item) {
            $key = $item['key'] ?? null;
            if (! $key) continue;
            $order[] = $key;
            if (empty($item['visible'])) {
                $hidden[] = $key;
            }
        }

        DashboardPreference::updateOrCreate(
            ['user_id' => $user->id],
            [
                'company_id' => $user->company_id,
                'hidden_sections' => $hidden,
                'section_order' => $order,
            ],
        );

        Notification::make()
            ->title('Escritorio personalizado')
            ->body('Tus cambios se aplicarán la próxima vez que abras el Escritorio.')
            ->success()
            ->send();
    }
}
