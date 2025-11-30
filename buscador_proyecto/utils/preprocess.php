<?php
// utils/preprocess.php

class Preprocessor {
    // Lista de stop words genérica (ejemplo para español)
    private static $stopwords = [
        'el', 'la', 'los', 'las', 'un', 'una', 'unos', 'unas', 
        'y', 'o', 'a', 'de', 'en', 'es', 'son', 'que', 'no', 
        'se', 'su', 'sus', 'con', 'para'
    ];

    /**
     * Aplica todas las etapas de preprocesamiento.
     * @param string $html Contenido HTML de la página.
     * @return array Tokens limpios y singularizados.
     */
    public static function process($html) {
        // 1. Eliminar código script y style
        $text = self::removeScriptsAndStyles($html);
        
        // 2. Extraer texto del HTML
        $text = strip_tags($text);

        // 3. Convertir a minúsculas
        $text = mb_strtolower($text, 'UTF-8');

        // 4. Eliminar signos de puntuación y reemplazar por espacio
        $text = preg_replace('/[[:punct:]\s]+/', ' ', $text);

        // 5. Tokenizar y filtrar stop-words
        $tokens = self::tokenizeAndFilter($text);

        // 6. Singularizar (Simplificación: en un sistema real se usaría un algoritmo stemmer/lemmatizer)
        $tokens = self::simpleSingularize($tokens);
        
        return $tokens;
    }

    private static function removeScriptsAndStyles($html) {
        $html = preg_replace('/<script\b[^>]*>(.*?)<\/script>/is', '', $html);
        $html = preg_replace('/<style\b[^>]*>(.*?)<\/style>/is', '', $html);
        return $html;
    }

    private static function tokenizeAndFilter($text) {
        $tokens = array_filter(explode(' ', $text));
        return array_diff($tokens, self::$stopwords);
    }
    
    // Simplificación: eliminar 's' o 'es' al final para una singularización básica en español
    private static function simpleSingularize($tokens) {
        $singularized = [];
        foreach ($tokens as $token) {
            if (strlen($token) > 2) {
                if (substr($token, -2) === 'es') {
                    $token = substr($token, 0, -2);
                } elseif (substr($token, -1) === 's') {
                    $token = substr($token, 0, -1);
                }
            }
            $singularized[] = $token;
        }
        return $singularized;
    }
}