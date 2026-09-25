<?php
/**
 * reddit_rip.php — backend del botón "descargar perfil/subreddit entero"
 * de Reddit Explorer.
 *
 * Reemplaza el esquema anterior (fetch + JSZip en el navegador, con proxies
 * públicos para esquivar CORS) por descargas hechas directamente por el
 * servidor:
 *   - PHP no tiene restricción CORS, así que no hace falta ningún proxy.
 *   - Cada archivo se guarda directo en disco (carpeta temporal), nunca en
 *     RAM del navegador ni del propio PHP más de lo que tarda un curl_exec.
 *
 * Acciones (todas vía POST, ?action=... + body JSON):
 *   start  -> crea una carpeta temporal nueva, devuelve rip_id
 *   fetch  -> descarga UNA url y la guarda en esa carpeta
 *   cancel -> borra la carpeta temporal (limpieza)
 *
 * La compresión final y el streaming de descarga del zip están en
 * rip_finalize.php (por GET, así el navegador lo maneja como un link normal).
 */

declare(strict_types=1);
header('Content-Type: application/json; charset=utf-8');

// Carpeta base para todas las carpetas temporales de rips. Cambiá esta ruta
// si preferís usar un disco/partición específico en vez de /tmp.
const TMP_ROOT = '/tmp/reddit_rip';

if (!is_dir(TMP_ROOT)) {
    mkdir(TMP_ROOT, 0700, true);
}

function json_out(array $data, int $code = 200): never {
    http_response_code($code);
    echo json_encode($data, JSON_UNESCAPED_SLASHES);
    exit;
}

function safe_rip_id(?string $id): ?string {
    // Los rip_id los genera este mismo script (bin2hex de 8 bytes = 16 hex),
    // así que un id que no matchee ese formato es inválido/sospechoso.
    if ($id !== null && preg_match('/^[a-f0-9]{16}$/', $id) === 1) {
        return $id;
    }
    return null;
}

function rip_dir(string $id): string {
    return TMP_ROOT . '/' . $id;
}

function rrmdir(string $dir): void {
    if (!is_dir($dir)) return;
    foreach (scandir($dir) ?: [] as $f) {
        if ($f === '.' || $f === '..') continue;
        $p = "$dir/$f";
        is_dir($p) ? rrmdir($p) : @unlink($p);
    }
    @rmdir($dir);
}

$action = $_GET['action'] ?? '';
$body = json_decode(file_get_contents('php://input') ?: '', true) ?: [];

switch ($action) {

    case 'start': {
        $id = bin2hex(random_bytes(8));
        if (!mkdir(rip_dir($id), 0700, true)) {
            json_out(['ok' => false, 'error' => 'no se pudo crear la carpeta temporal'], 500);
        }
        json_out(['ok' => true, 'rip_id' => $id]);
    }

    case 'fetch': {
        $id  = safe_rip_id($body['rip_id'] ?? null);
        $url = $body['url'] ?? null;
        $mediaType = $body['media_type'] ?? '';

        if (!$id || !is_dir(rip_dir($id))) {
            json_out(['ok' => false, 'error' => 'rip_id inválido o expirado'], 400);
        }
        if (!$url || !preg_match('#^https?://#i', $url)) {
            json_out(['ok' => false, 'error' => 'url inválida'], 400);
        }

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS      => 5,
            CURLOPT_TIMEOUT        => 30,
            CURLOPT_CONNECTTIMEOUT => 10,
            // Reddit/imgur devuelven 403 sin un User-Agent "de navegador".
            CURLOPT_USERAGENT      => 'Mozilla/5.0 (X11; Linux x86_64) RedditExplorer/1.0',
            CURLOPT_SSL_VERIFYPEER => true,
        ]);
        $data = curl_exec($ch);
        $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $contentType = (string) curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
        $curlErr = curl_error($ch);
        curl_close($ch);

        if ($data === false) {
            json_out(['ok' => false, 'error' => $curlErr ?: 'fallo de red']);
        }
        if ($httpCode >= 400) {
            json_out(['ok' => false, 'error' => "HTTP $httpCode"]);
        }

        // Nombre de archivo: preferimos el basename de la URL si tiene
        // extensión razonable; si no, lo inferimos del Content-Type.
        $urlPath = parse_url(explode('?', $url)[0], PHP_URL_PATH) ?: '';
        $base = basename($urlPath);
        if ($base === '' || !preg_match('/\.[a-z0-9]{2,5}$/i', $base)) {
            $ext = '.jpg';
            if ($mediaType === 'gifs') {
                $ext = '.gif';
            } elseif ($contentType !== '') {
                if (str_contains($contentType, 'gif'))       $ext = '.gif';
                elseif (str_contains($contentType, 'png'))   $ext = '.png';
                elseif (str_contains($contentType, 'webp'))  $ext = '.webp';
                elseif (str_contains($contentType, 'mp4'))   $ext = '.mp4';
                elseif (str_contains($contentType, 'jpeg'))  $ext = '.jpg';
            }
            $base = 'file_' . substr(bin2hex(random_bytes(4)), 0, 7) . $ext;
        }
        // Sanitizar: solo caracteres seguros para nombre de archivo.
        $base = preg_replace('/[^A-Za-z0-9._-]/', '_', $base) ?? $base;

        $dir = rip_dir($id);
        $finalName = $base;
        $n = 1;
        while (file_exists("$dir/$finalName")) {
            $dot = strrpos($base, '.');
            $finalName = $dot !== false
                ? substr($base, 0, $dot) . "_{$n}" . substr($base, $dot)
                : "{$base}_{$n}";
            $n++;
        }

        if (file_put_contents("$dir/$finalName", $data) === false) {
            json_out(['ok' => false, 'error' => 'no se pudo escribir en disco']);
        }

        json_out(['ok' => true, 'filename' => $finalName]);
    }

    case 'cancel': {
        $id = safe_rip_id($body['rip_id'] ?? null);
        if ($id) {
            rrmdir(rip_dir($id));
        }
        json_out(['ok' => true]);
    }

    default:
        json_out(['ok' => false, 'error' => 'acción desconocida'], 400);
}
