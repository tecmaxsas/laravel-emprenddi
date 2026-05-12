<?php

namespace App\Http\Controllers;

use App\Models\Restaurant\Menu;
use Illuminate\View\View;

class PublicMenuController extends Controller
{
    /**
     * Carta publica del restaurante. Accesible via /menu/{slug} sin auth.
     * Usa withoutGlobalScopes() porque no hay user logueado (no aplica el
     * CompanyScope).
     */
    public function show(string $slug): View
    {
        $menu = Menu::query()
            ->withoutGlobalScopes()
            ->where('slug', $slug)
            ->where('active', true)
            ->with([
                'sections' => fn ($q) => $q->where('active', true),
                'sections.items' => fn ($q) => $q->where('active', true),
                'company:id,name,phone,email,address',
            ])
            ->first();

        abort_if(! $menu, 404);

        return view('public.menu', [
            'menu' => $menu,
            'theme' => array_merge(Menu::DEFAULT_THEME, $menu->theme ?? []),
        ]);
    }
}
