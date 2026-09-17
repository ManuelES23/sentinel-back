<?php

use App\Support\Inventario\AsignacionInicialAlmacenes;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $asignacion = new AsignacionInicialAlmacenes();
        $asignacion->registrarPermiso();
        $asignacion->asignar();
    }

    public function down(): void
    {
        // La asignación de transición no se revierte (no se distingue de la
        // hecha a mano); solo se retira el tipo de permiso.
        DB::table('submodule_permission_types')->where('slug', 'ver_todos_almacenes')->delete();
    }
};
