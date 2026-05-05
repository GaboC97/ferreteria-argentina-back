<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class CategoriasSeeder extends Seeder
{
    public function run(): void
    {
        DB::table('catalogo_web')->update(['categoria_web_id' => null]);
        DB::statement('SET FOREIGN_KEY_CHECKS=0;');
        DB::table('categorias')->truncate();
        DB::statement('SET FOREIGN_KEY_CHECKS=1;');

        $now = now();

        // ── Padres ────────────────────────────────────────────────────────────
        $padres = [
            ['nombre' => 'Herramientas',               'slug' => 'herramientas'],
            ['nombre' => 'Pinturas y Recubrimientos',  'slug' => 'pinturas-y-recubrimientos'],
            ['nombre' => 'Electricidad e Iluminación', 'slug' => 'electricidad-e-iluminacion'],
            ['nombre' => 'Sanitarios y Plomería',      'slug' => 'sanitarios-y-plomeria'],
            ['nombre' => 'Materiales de Construcción', 'slug' => 'materiales-de-construccion'],
            ['nombre' => 'Adhesivos y Selladores',     'slug' => 'adhesivos-y-selladores'],
            ['nombre' => 'Abrasivos',                  'slug' => 'abrasivos'],
            ['nombre' => 'Seguridad y Cerrajería',     'slug' => 'seguridad-y-cerrajeria'],
            ['nombre' => 'Aberturas',                  'slug' => 'aberturas'],
            ['nombre' => 'Durlock',                    'slug' => 'durlock'],
            ['nombre' => 'Áridos',                     'slug' => 'aridos'],
            ['nombre' => 'Bombas y Riego',             'slug' => 'bombas-y-riego'],
            ['nombre' => 'Piscinas',                   'slug' => 'piscinas'],
            ['nombre' => 'Escaleras',                  'slug' => 'escaleras'],
            ['nombre' => 'Fijaciones y Bulonería',     'slug' => 'fijaciones-y-buloneria'],
            ['nombre' => 'Indumentaria y Protección',   'slug' => 'indumentaria-y-proteccion'],
            ['nombre' => 'Tanques de Agua',             'slug' => 'tanques-de-agua'],
            ['nombre' => 'Termotanques',                'slug' => 'termotanques'],
            ['nombre' => 'Cocinas',                     'slug' => 'cocinas'],
            ['nombre' => 'Estufas',                     'slug' => 'estufas'],
            ['nombre' => 'Aislantes',                   'slug' => 'aislantes'],
            ['nombre' => 'Soldaduría',                  'slug' => 'soldaduria'],
            ['nombre' => 'Calefacción',                 'slug' => 'calefaccion'],
            ['nombre' => 'Maquinaria',                  'slug' => 'maquinaria'],
            ['nombre' => 'Alambres',                    'slug' => 'alambres'],
            ['nombre' => 'Organizadores y Cajas',       'slug' => 'organizadores-y-cajas'],
            ['nombre' => 'Generadores',                 'slug' => 'generadores'],
            ['nombre' => 'Varios',                      'slug' => 'varios'],
        ];

        foreach ($padres as &$p) {
            $p['activo']             = true;
            $p['categoria_padre_id'] = null;
            $p['created_at']         = $now;
            $p['updated_at']         = $now;
        }
        unset($p);

        DB::table('categorias')->insert($padres);

        // Recuperar IDs por slug
        $ids = DB::table('categorias')->pluck('id', 'slug');

        // ── Hijos ─────────────────────────────────────────────────────────────
        $hijos = [
            // Herramientas
            ['nombre' => 'Amoladoras',                    'slug' => 'amoladoras',                    'padre' => 'herramientas'],
            ['nombre' => 'Taladros y Rotomartillos',      'slug' => 'taladros-y-rotomartillos',      'padre' => 'herramientas'],
            ['nombre' => 'Sierras',                       'slug' => 'sierras',                       'padre' => 'herramientas'],
            ['nombre' => 'Compresores',                   'slug' => 'compresores',                   'padre' => 'herramientas'],
            ['nombre' => 'Soldadoras',                    'slug' => 'soldadoras',                    'padre' => 'soldaduria'],
            ['nombre' => 'Accesorios para Soldaduría',   'slug' => 'accesorios-para-soldadura',     'padre' => 'soldaduria'],
            ['nombre' => 'Hidrolavadoras',                'slug' => 'hidrolavadoras',                'padre' => 'herramientas'],
            ['nombre' => 'Herramientas de Jardín',        'slug' => 'herramientas-de-jardin',        'padre' => 'herramientas'],
            ['nombre' => 'Llaves y Alicates',             'slug' => 'llaves-y-alicates',             'padre' => 'herramientas'],
            ['nombre' => 'Martillos',                     'slug' => 'martillos',                     'padre' => 'herramientas'],
            ['nombre' => 'Destornilladores',              'slug' => 'destornilladores',               'padre' => 'herramientas'],
            ['nombre' => 'Criques y Aparejos',            'slug' => 'criques-y-aparejos',            'padre' => 'herramientas'],
            // Generadores es categoría padre (ver $padres)
            ['nombre' => 'Medición',                      'slug' => 'medicion',                      'padre' => 'herramientas'],
            ['nombre' => 'Perforadoras',                  'slug' => 'perforadoras',                  'padre' => 'herramientas'],
            // Pinturas y Recubrimientos
            ['nombre' => 'Pinturas al Agua',              'slug' => 'pinturas-al-agua',              'padre' => 'pinturas-y-recubrimientos'],
            ['nombre' => 'Esmaltes',                      'slug' => 'esmaltes',                      'padre' => 'pinturas-y-recubrimientos'],
            ['nombre' => 'Barnices y Lasures',            'slug' => 'barnices-y-lasures',            'padre' => 'pinturas-y-recubrimientos'],
            ['nombre' => 'Impermeabilizantes',            'slug' => 'impermeabilizantes',            'padre' => 'pinturas-y-recubrimientos'],
            ['nombre' => 'Aerosoles',                     'slug' => 'aerosoles',                     'padre' => 'pinturas-y-recubrimientos'],
            ['nombre' => 'Accesorios de Pinturería',     'slug' => 'accesorios-de-pintureria',      'padre' => 'pinturas-y-recubrimientos'],
            ['nombre' => 'Rodillos',                      'slug' => 'rodillos',                      'padre' => 'pinturas-y-recubrimientos'],
            ['nombre' => 'Pinceles',                      'slug' => 'pinceles',                      'padre' => 'pinturas-y-recubrimientos'],
            ['nombre' => 'Brochas',                       'slug' => 'brochas',                       'padre' => 'pinturas-y-recubrimientos'],
            ['nombre' => 'Pinceletas',                    'slug' => 'pinceletas',                    'padre' => 'pinturas-y-recubrimientos'],
            // Electricidad e Iluminación
            ['nombre' => 'Cables',                        'slug' => 'cables',                        'padre' => 'electricidad-e-iluminacion'],
            ['nombre' => 'Iluminación',                   'slug' => 'iluminacion',                   'padre' => 'electricidad-e-iluminacion'],
            ['nombre' => 'Protecciones Eléctricas',       'slug' => 'protecciones-electricas',       'padre' => 'electricidad-e-iluminacion'],
            ['nombre' => 'Tomas e Interruptores',         'slug' => 'tomas-e-interruptores',         'padre' => 'electricidad-e-iluminacion'],
            ['nombre' => 'Caños Eléctricos',              'slug' => 'canos-electricos',               'padre' => 'electricidad-e-iluminacion'],
            // Sanitarios y Plomería
            ['nombre' => 'Tubos y Cañerías',              'slug' => 'tubos-y-canerias',              'padre' => 'sanitarios-y-plomeria'],
            ['nombre' => 'Válvulas y Canillas',           'slug' => 'valvulas-y-canillas',           'padre' => 'sanitarios-y-plomeria'],
            ['nombre' => 'Desagüe',                       'slug' => 'desague',                       'padre' => 'sanitarios-y-plomeria'],
            // Materiales de Construcción
            ['nombre' => 'Hierro y Acero',                'slug' => 'hierro-y-acero',                'padre' => 'materiales-de-construccion'],
            // Adhesivos y Selladores es categoría padre (ver $padres)
            ['nombre' => 'Cerámicos y Pisos',             'slug' => 'ceramicos-y-pisos',             'padre' => 'materiales-de-construccion'],
            ['nombre' => 'Cemento y Mortero',             'slug' => 'cemento-y-mortero',             'padre' => 'materiales-de-construccion'],
            // Abrasivos
            ['nombre' => 'Discos',                        'slug' => 'discos',                        'padre' => 'abrasivos'],
            ['nombre' => 'Brocas y Mechas',               'slug' => 'brocas-y-mechas',               'padre' => 'abrasivos'],
            ['nombre' => 'Lijas',                         'slug' => 'lijas',                         'padre' => 'abrasivos'],
            // Seguridad y Cerrajería
            ['nombre' => 'Candados',                      'slug' => 'candados',                      'padre' => 'seguridad-y-cerrajeria'],
            ['nombre' => 'Cerraduras y Herrajes',         'slug' => 'cerraduras-y-herrajes',         'padre' => 'seguridad-y-cerrajeria'],
            // Bombas y Riego
            ['nombre' => 'Bombas de Agua',                'slug' => 'bombas-de-agua',                'padre' => 'bombas-y-riego'],
            ['nombre' => 'Electrobombas',                 'slug' => 'electrobombas',                 'padre' => 'bombas-y-riego'],
            ['nombre' => 'Riego y Mangueras',             'slug' => 'riego-y-mangueras',             'padre' => 'bombas-y-riego'],
            ['nombre' => 'Caños de Riego',                'slug' => 'canos-de-riego',                'padre' => 'bombas-y-riego'],
        ];

        $rows = [];
        foreach ($hijos as $h) {
            $rows[] = [
                'nombre'             => $h['nombre'],
                'slug'               => $h['slug'],
                'categoria_padre_id' => $ids[$h['padre']],
                'activo'             => true,
                'created_at'         => $now,
                'updated_at'         => $now,
            ];
        }

        DB::table('categorias')->insert($rows);

        $total = DB::table('categorias')->count();
        $this->command->info("✓ {$total} categorías insertadas.");
    }
}
