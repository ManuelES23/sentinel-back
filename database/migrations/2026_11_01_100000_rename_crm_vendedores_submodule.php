<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * CRM · Equipo comercial (spec 2026-11-01 §10). El submódulo
 * `catalogos.vendedores` pasa a llamarse «Equipo comercial» en todas las
 * empresas con CRM. Solo cambia el nombre: el slug, los permisos y los accesos
 * siguen igual, y las empresas nuevas ya lo reciben así de CrmPermisosSeeder.
 *
 * Se busca por slugs (aplicación `crm` → módulo `catalogos` → submódulo
 * `vendedores`), así que un submódulo `vendedores` de otra aplicación no se
 * toca, y solo se renombra si el nombre actual es exactamente «Vendedores»
 * (un nombre personalizado se respeta). Repetible. `down()` lo devuelve solo
 * donde el nombre es «Equipo comercial».
 */
return new class extends Migration
{
    private const ANTES = 'Vendedores';
    private const DESPUES = 'Equipo comercial';

    public function up(): void
    {
        $this->renombrar(self::ANTES, self::DESPUES);
    }

    public function down(): void
    {
        $this->renombrar(self::DESPUES, self::ANTES);
    }

    private function renombrar(string $de, string $a): void
    {
        $ids = DB::table('submodules as s')
            ->join('modules as m', 'm.id', '=', 's.module_id')
            ->join('applications as ap', 'ap.id', '=', 'm.application_id')
            ->where('ap.slug', 'crm')
            ->where('m.slug', 'catalogos')
            ->where('s.slug', 'vendedores')
            ->where('s.name', $de)
            ->pluck('s.id');

        if ($ids->isEmpty()) {
            return;
        }

        DB::table('submodules')->whereIn('id', $ids)->update(['name' => $a, 'updated_at' => now()]);
    }
};
