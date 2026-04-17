<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\Enterprise;
use App\Models\Consignatario;

class EmpresaProfileSeeder extends Seeder
{
    public function run(): void
    {
        // ── Splendid Farms ──
        $sf = Enterprise::where('slug', 'splendidfarms')->first();
        if ($sf) {
            $sf->update([
                'razon_social' => 'SPLENDID FARMS S.P.R. DE R.L.',
                'rfc'          => 'SFA1909258A6',
                'direccion'    => 'BLVD. ADOLFO LOPEZ MATEOS 1460 NTE. FRACC. LAS FUENTES, CP 81223.',
                'ciudad'       => 'LOS MOCHIS, SINALOA',
                'pais'         => 'MEXICO',
                'agente_aduana_mx' => 'INTERENLACE',
            ]);

            // Consignatarios frecuentes
            Consignatario::firstOrCreate(
                ['enterprise_id' => $sf->id, 'nombre' => 'SPLENDID BY PORVENIR'],
                [
                    'rfc_tax_id'    => '383986295',
                    'direccion'     => '450 W GOLD HILL RD. SUITE 4 NOGALES, AZ 85621',
                    'ciudad'        => 'NOGALES, AZ',
                    'pais'          => 'EUA',
                    'agente_aduana' => 'GODINEZ',
                    'bodega'        => null,
                    'is_active'     => true,
                ],
            );

            Consignatario::firstOrCreate(
                ['enterprise_id' => $sf->id, 'nombre' => 'SPLENDID FARMS USA'],
                [
                    'rfc_tax_id'    => null,
                    'direccion'     => '450 W GOLD HILL RD. SUITE 4 NOGALES, AZ 85621',
                    'ciudad'        => 'NOGALES, AZ',
                    'pais'          => 'EUA',
                    'agente_aduana' => 'GODINEZ',
                    'bodega'        => null,
                    'is_active'     => true,
                ],
            );
        }

        // ── Splendid By Porvenir ──
        $sbp = Enterprise::where('slug', 'splendidbyporvenir')->first();
        if ($sbp) {
            $sbp->update([
                'razon_social' => 'SPLENDID BY PORVENIR LLC',
                'rfc'          => '383986295',
                'direccion'    => '450 W GOLD HILL RD. SUITE 4 NOGALES, AZ 85621',
                'ciudad'       => 'NOGALES, AZ',
                'pais'         => 'EUA',
            ]);
        }
    }
}
