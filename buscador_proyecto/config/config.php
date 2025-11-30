<?php
require __DIR__ . '/../vendor/autoload.php'; 

$config = [
    'endpoint' => [
        'localhost' => [
            'host' => '127.0.0.1',
            'port' => 8983,
            'path' => '/',
            'core' => 'buscador_proyecto',
        ]
    ]
];

function getSolrClient($config) {
    return new \Solarium\Client(
        new \Solarium\Core\Client\Adapter\Curl(), 
        new \Symfony\Component\EventDispatcher\EventDispatcher(), 
        $config
    );
}