<?php

namespace App\Support;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * Permisos del usuario resueltos UNA vez por request.
 *
 * Por que existe: hay un juego de roles por empresa (221 el 2026-11-17) y
 * cada permiso esta ligado a casi todos. Spatie resuelve cada can()
 * cargando los roles del permiso como modelos y cruzandolos con los del
 * usuario; Filament llama can() unas 180 veces por pagina para armar el
 * menu, asi que eso se comia ~40 % de la CPU de cada request y crecia con
 * cada empresa nueva. Aqui son dos consultas por request y despues un
 * isset().
 *
 * El memo vive en un binding scoped (se descarta al terminar cada request
 * y antes de cada job) y se borra cuando Spatie olvida su cache de
 * permisos, para que un cambio de roles se vea en el mismo request.
 */
class PermissionMemo
{
    public const BINDING = 'permission-memo';

    /** ¿Existe un permiso con ese nombre en ese guard? */
    public static function exists(string $name, string $guard): bool
    {
        $memo = app(self::BINDING);

        $all = $memo["all:{$guard}"] ??= array_flip(
            DB::table(config('permission.table_names.permissions'))
                ->where('guard_name', $guard)
                ->pluck('name')
                ->all()
        );

        return isset($all[$name]);
    }

    /** ¿El modelo tiene ese permiso, directo o por alguno de sus roles? */
    public static function has(Model $model, string $name, string $guard): bool
    {
        $memo = app(self::BINDING);
        $key = 'model:'.$model->getMorphClass().':'.$model->getKey().':'.$guard;

        $names = $memo[$key] ??= array_flip(self::namesFor($model, $guard));

        return isset($names[$name]);
    }

    public static function flush(): void
    {
        app()->forgetInstance(self::BINDING);
    }

    /** @return list<string> */
    protected static function namesFor(Model $model, string $guard): array
    {
        $t = config('permission.table_names');
        $morphKey = config('permission.column_names.model_morph_key', 'model_id');

        $viaRoles = DB::table($t['permissions'].' as p')
            ->join($t['role_has_permissions'].' as rp', 'rp.permission_id', '=', 'p.id')
            ->join($t['model_has_roles'].' as mr', 'mr.role_id', '=', 'rp.role_id')
            ->where('mr.model_type', $model->getMorphClass())
            ->where("mr.{$morphKey}", $model->getKey())
            ->where('p.guard_name', $guard)
            ->select('p.name');

        $direct = DB::table($t['permissions'].' as p')
            ->join($t['model_has_permissions'].' as mp', 'mp.permission_id', '=', 'p.id')
            ->where('mp.model_type', $model->getMorphClass())
            ->where("mp.{$morphKey}", $model->getKey())
            ->where('p.guard_name', $guard)
            ->select('p.name');

        return $viaRoles->union($direct)->pluck('name')->all();
    }
}
