<?php

namespace Database\Seeders;

use App\Models\Enterprise;
use App\Models\SfPosition;
use App\Models\SfPositionGroup;
use Illuminate\Database\Seeder;

class SfPersonalCatalogSeeder extends Seeder
{
    public function run(): void
    {
        $enterprise = Enterprise::where('slug', 'splendidfarms')->first();

        if (! $enterprise) {
            $this->command?->warn('No existe enterprise splendidfarms. Se omite SfPersonalCatalogSeeder.');
            return;
        }

        $groups = [
            ['name' => 'GRUPO A', 'salary' => 350],
            ['name' => 'GRUPO B', 'salary' => 355],
            ['name' => 'GRUPO C', 'salary' => 360],
            ['name' => 'GRUPO D', 'salary' => 365],
            ['name' => 'GRUPO E', 'salary' => 370],
            ['name' => 'GRUPO EMPACADORES', 'salary' => 0],
            ['name' => 'GRUPO F', 'salary' => 380],
            ['name' => 'GRUPO G', 'salary' => 400],
            ['name' => 'GRUPO H', 'salary' => 470],
            ['name' => 'GRUPO I', 'salary' => 430],
            ['name' => 'GRUPO J', 'salary' => 430],
        ];

        $groupIdsByCode = [];

        foreach ($groups as $groupData) {
            $groupName = strtoupper(trim($groupData['name']));
            $code = str_replace('GRUPO ', '', $groupName);

            $group = SfPositionGroup::withTrashed()
                ->where('enterprise_id', $enterprise->id)
                ->where('code', $code)
                ->first();

            if ($group) {
                if ($group->trashed()) {
                    $group->restore();
                }
                $group->update([
                    'name' => $groupName,
                    'salary' => $groupData['salary'],
                    'is_active' => true,
                    'notes' => 'Carga inicial desde listado de empaque',
                ]);
            } else {
                $group = SfPositionGroup::create([
                    'enterprise_id' => $enterprise->id,
                    'code' => $code,
                    'name' => $groupName,
                    'salary' => $groupData['salary'],
                    'is_active' => true,
                    'notes' => 'Carga inicial desde listado de empaque',
                ]);
            }

            $groupIdsByCode[$code] = $group->id;
        }

        $positions = [
            ['name' => 'AUXILIAR ADMINISTRATIVO', 'group' => 'GRUPO G'],
            ['name' => 'AUXILIAR DE CALIDAD', 'group' => 'GRUPO G'],
            ['name' => 'AUXILIAR DE CONTENEDOR DE HIELO', 'group' => 'GRUPO E'],
            ['name' => 'AUXILIAR DE INOCUIDAD', 'group' => 'GRUPO E'],
            ['name' => 'AUXILIAR DE MANTENIMIENTO', 'group' => 'GRUPO G'],
            ['name' => 'AUXILIAR DE MAQUINA DE HIELO', 'group' => 'GRUPO D'],
            ['name' => 'AUXILIAR TI', 'group' => 'GRUPO D'],
            ['name' => 'AYUDANTE ALBAÑIL', 'group' => 'GRUPO H'],
            ['name' => 'BAÑOS', 'group' => 'GRUPO A'],
            ['name' => 'BOLETERA', 'group' => 'GRUPO C'],
            ['name' => 'CARGADOR DE BATANGAS', 'group' => 'GRUPO C'],
            ['name' => 'CARTONERO', 'group' => 'GRUPO C'],
            ['name' => 'CASILLEROS', 'group' => 'GRUPO A'],
            ['name' => 'CUARTO FRIO', 'group' => 'GRUPO D'],
            ['name' => 'CUARTO FRIO SM', 'group' => 'GRUPO D'],
            ['name' => 'DESCARGADOR EN RECEPCION', 'group' => 'GRUPO D'],
            ['name' => 'DIARIO', 'group' => 'GRUPO A'],
            ['name' => 'EMBARQUES', 'group' => 'GRUPO E'],
            ['name' => 'EMPACADOR DIARIO', 'group' => 'GRUPO A'],
            ['name' => 'EMPACADORES', 'group' => 'GRUPO EMPACADORES'],
            ['name' => 'ENCARGADO CARTON', 'group' => 'GRUPO E'],
            ['name' => 'ENCARGADO DE CALIDAD', 'group' => 'GRUPO I'],
            ['name' => 'ENCARGADO DE MAQUINA DE HIELO', 'group' => 'GRUPO F'],
            ['name' => 'ENMALLADORES', 'group' => 'GRUPO B'],
            ['name' => 'ESQUINEROS', 'group' => 'GRUPO A'],
            ['name' => 'ESTIBADOR', 'group' => 'GRUPO B'],
            ['name' => 'FLEJADOR', 'group' => 'GRUPO D'],
            ['name' => 'JEFE DE MANTENIMIENTO', 'group' => 'GRUPO H'],
            ['name' => 'LAVADORES DE CAJAS', 'group' => 'GRUPO D'],
            ['name' => 'LIMPIEZA', 'group' => 'GRUPO A'],
            ['name' => 'MONTACARGUISTA', 'group' => 'GRUPO E'],
            ['name' => 'OPERADOR', 'group' => 'GRUPO F'],
            ['name' => 'OPERADOR TRACTOR', 'group' => 'GRUPO F'],
            ['name' => 'PAPELETAS', 'group' => 'GRUPO D'],
            ['name' => 'PATIN ELECTRICO (PALLET JET)', 'group' => 'GRUPO E'],
            ['name' => 'PESADORA', 'group' => 'GRUPO C'],
            ['name' => 'PORTERO', 'group' => 'GRUPO C'],
            ['name' => 'REZAGADOR', 'group' => 'GRUPO A'],
            ['name' => 'SELECCIONADOR', 'group' => 'GRUPO B'],
            ['name' => 'SELLOS/ETIQUETAS', 'group' => 'GRUPO A'],
            ['name' => 'SOPORTE TI', 'group' => 'GRUPO F'],
            ['name' => 'VACIADOR', 'group' => 'GRUPO D'],
            ['name' => 'VELADOR', 'group' => 'GRUPO C'],
        ];

        foreach ($positions as $positionData) {
            $groupCode = str_replace('GRUPO ', '', strtoupper(trim($positionData['group'])));
            $groupId = $groupIdsByCode[$groupCode] ?? null;

            if (! $groupId) {
                continue;
            }

            $position = SfPosition::withTrashed()
                ->where('enterprise_id', $enterprise->id)
                ->where('name', $positionData['name'])
                ->first();

            if ($position) {
                if ($position->trashed()) {
                    $position->restore();
                }
                $position->update([
                    'sf_position_group_id' => $groupId,
                    'is_active' => true,
                    'notes' => 'Carga inicial desde listado de empaque',
                ]);
            } else {
                SfPosition::create([
                    'enterprise_id' => $enterprise->id,
                    'code' => SfPosition::generateCode(),
                    'name' => $positionData['name'],
                    'sf_position_group_id' => $groupId,
                    'department' => null,
                    'is_active' => true,
                    'notes' => 'Carga inicial desde listado de empaque',
                ]);
            }
        }

        $this->command?->info('  ✓ Catálogo SF Personal: grupos y puestos cargados');
    }
}
