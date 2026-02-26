<?php
require __DIR__.'/vendor/autoload.php';
$app = require __DIR__.'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$paljet = app(App\Services\PaljetService::class);
$arts = $paljet->getArticulos(['size' => 500, 'publica_web' => 'true', 'include' => 'listas']);

$conPrecio = collect($arts['content'] ?? [])
    ->filter(fn($a) => !empty($a['pr_final']) && (float)$a['pr_final'] > 0)
    ->sortBy('pr_final')
    ->take(10)
    ->values();

if ($conPrecio->isEmpty()) {
    echo "No hay artículos con pr_final. Mostrando primeros 5 sin filtro:\n";
    collect($arts['content'] ?? [])->take(5)->each(function($a) {
        echo "art_id: {$a['id']} | pr_final: " . ($a['pr_final'] ?? 'null') . " | {$a['descripcion']}\n";
    });
} else {
    echo "Los 10 artículos más baratos:\n";
    $conPrecio->each(fn($a) => printf(
        "art_id: %d | precio: \$%.2f | %s\n",
        $a['id'], $a['pr_final'], $a['descripcion']
    ));
}
