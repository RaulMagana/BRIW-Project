<?php
// ver_modelos.php
header('Content-Type: text/plain');

// -----------------------------------------------------------
// PON TU API KEY AQUÍ
$apiKey = 'AIzaSyB8yv4yT17DNe1FAHf9t2xlYuncsELR1vA'; // <--- ¡TU LLAVE REAL AQUÍ!
// -----------------------------------------------------------

$url = "https://generativelanguage.googleapis.com/v1beta/models?key=" . $apiKey;

$ch = curl_init($url);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);

$response = curl_exec($ch);
curl_close($ch);

$json = json_decode($response, true);

if (isset($json['models'])) {
    echo "✅ CONEXIÓN EXITOSA. Tienes acceso a estos modelos:\n\n";
    foreach ($json['models'] as $model) {
        // Filtramos solo los que sirven para generar texto (generateContent)
        if (in_array("generateContent", $model['supportedGenerationMethods'])) {
            echo "NOMBRE: " . $model['name'] . "\n";
        }
    }
} else {
    echo "❌ ERROR: \n" . $response;
}
?>