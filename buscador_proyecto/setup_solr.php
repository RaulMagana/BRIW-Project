<?php
// Ajustamos la ruta para encontrar tu archivo de configuración
require 'config/config.php';

echo "--- Configurando Esquema de Solr ---\n";

$client = getSolrClient($config);

// Definimos los campos que usará el buscador
$campos = [
    // type: text_general permite búsqueda aproximada, sinónimos, minúsculas, etc.
    ['name' => 'titulo_texto', 'type' => 'text_general', 'stored' => true, 'indexed' => true],
    // type: string es para búsqueda exacta (útil para facetas o URLs)
    ['name' => 'url_str',      'type' => 'string',       'stored' => true, 'indexed' => true],
    ['name' => 'desc_texto',   'type' => 'text_general', 'stored' => true, 'indexed' => true],
];

foreach ($campos as $campo) {
    $guzzle = new \GuzzleHttp\Client();
    
    try {
        // Llamamos a la API Schema de Solr
        $response = $guzzle->post('http://localhost:8983/solr/buscador_empresarial/schema', [
            'json' => [
                'add-field' => $campo
            ]
        ]);
        echo "Campo '{$campo['name']}' creado/verificado.\n";
    } catch (\Exception $e) {
        // Ignoramos error si ya existe
        echo "Nota: El campo '{$campo['name']}' ya existía o hubo un error menor.\n";
    }
}

echo "\n¡Esquema configurado correctamente!\n";