<?php
require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/../utils/preprocess.php';
require __DIR__ . '/../config/config.php'; // Usamos la configuración central

use GuzzleHttp\Client;
use Symfony\Component\DomCrawler\Crawler;
use Solarium\Client as SolrClient;
use Solarium\Core\Client\Adapter\Curl;
use Symfony\Component\EventDispatcher\EventDispatcher;

class SolrDeepCrawler
{
    private $solr;
    private $httpClient;
    private $visitedUrls = []; 
    private $maxDepth = 2;

    private $urlBlacklist = [
        'Especial:', 'Special:', 
        'Wikipedia:', 'Project:',
        'Portal:', 
        'Ayuda:', 'Help:',
        'Usuario:', 'User:',
        'Discusión:', 'Talk:',
        'Archivo:', 'File:', 'Image:',
        'MediaWiki:',
        'Plantilla:', 'Template:',
        'Categoría:', 'Category:',
        'action=edit', 'action=history',
        'printable=yes', 'oldid='
    ];

    public function __construct()
    {
        // Usamos el config.php central
        $this->solr = getSolrClient($config);

        $this->httpClient = new Client([
            'verify' => false,
            'timeout' => 10,
            'headers' => [
                'User-Agent' => 'Mozilla/5.0 (compatible; BuscadorProyectoBot/1.0)'
            ]
        ]);
    }

    public function run($seedUrls)
    {
        echo "--- INICIANDO CRAWLER CON FILTROS INTELIGENTES ---\n";
        foreach ($seedUrls as $url) {
            $this->processUrl($url, 0);
        }
    }

    private function processUrl($url, $currentDepth)
    {
        $url = rtrim($url, '/');

        if (in_array($url, $this->visitedUrls)) return;
        $this->visitedUrls[] = $url;

        $indent = str_repeat("    ", $currentDepth);
        $etiqueta = ($currentDepth === 0) ? "[SEMILLA]" : "[NIVEL $currentDepth]";
        echo "$indent $etiqueta Procesando: $url \n";

        try {
            $response = $this->httpClient->get($url);
            $html = (string) $response->getBody();
            $crawler = new Crawler($html);

            $this->indexToSolr($url, $crawler);

            if ($currentDepth < $this->maxDepth) {
                $currentHost = parse_url($url, PHP_URL_HOST);
                $selectorContexto = $crawler->filter('#bodyContent')->count() > 0 ? '#bodyContent a[href]' : 'body a[href]';
                
                $links = $crawler->filter($selectorContexto)->each(fn(Crawler $node) => $node->attr('href'));
                $links = array_unique($links);
                $count = 0; 

                $baseUrl = parse_url($url, PHP_URL_SCHEME) . '://' . $currentHost;

                foreach ($links as $link) {
                    $link = trim($link);

                    if (strpos($link, '/') === 0) $fullUrl = $baseUrl . $link;
                    elseif (strpos($link, 'http') === 0) $fullUrl = $link;
                    else continue;

                    if (!$this->isValidLink($fullUrl, $currentHost)) continue;

                    if ($count >= 10) break;
                    $count++;
                    $this->processUrl($fullUrl, $currentDepth + 1);
                }
            }

        } catch (Exception $e) {
            echo "$indent [ERROR] $url: " . $e->getMessage() . "\n";
        }
    }

    private function isValidLink($url, $allowedHost)
    {
        $linkHost = parse_url($url, PHP_URL_HOST);
        if ($linkHost !== $allowedHost) return false;

        if (preg_match('/\.(jpg|jpeg|png|gif|pdf|doc|docx|zip|rar|css|js)$/i', $url)) return false;

        foreach ($this->urlBlacklist as $blacklisted) {
            if (stripos($url, $blacklisted) !== false) return false;
        }

        return true;
    }

    private function indexToSolr($url, Crawler $crawler)
    {
        try {
            $update = $this->solr->createUpdate();
            $doc = $update->createDocument();

            $titulo = 'Sin titulo';
            if ($crawler->filter('h1')->count() > 0) $titulo = $crawler->filter('h1')->text();
            elseif ($crawler->filter('title')->count() > 0) $titulo = $crawler->filter('title')->text();

            $contenidoHtml = '';
            $selectorTexto = $crawler->filter('#bodyContent p')->count() > 0 ? '#bodyContent p' : 'body p';
            $crawler->filter($selectorTexto)->each(function (Crawler $node) use (&$contenidoHtml) {
                $contenidoHtml .= $node->html() . ' ';
            });
            if (strlen($contenidoHtml) < 50) $contenidoHtml = $crawler->filter('body')->html();

            $tokens = Preprocessor::process($contenidoHtml);
            $contenidoLimpio = implode(' ', $tokens);

            if (strlen($contenidoLimpio) < 100) {
                echo "    -> [SKIP] Contenido insuficiente ($titulo)\n";
                return;
            }

            $contenidoSeguro = mb_substr($contenidoLimpio, 0, 30000, "UTF-8");

            $doc->id = md5($url);
            $doc->titulo_texto = $titulo;
            $doc->desc_texto   = $contenidoSeguro;
            $doc->url_str      = $url;

            $update->addDocument($doc);
            $update->addCommit();
            $this->solr->update($update);

            echo "    -> [SOLR] OK: " . substr($titulo, 0, 30) . "\n";

        } catch (Exception $e) {
            echo "    -> [ERROR SOLR] " . $e->getMessage() . "\n";
        }
    }
}

// --- EJECUCIÓN ---
$bot = new SolrDeepCrawler();
$urls_semilla = ['https://es.wikipedia.org/wiki/Internet'];
$bot->run($urls_semilla);
