<?php
/**
 * rip_finalize.php — comprime la carpeta temporal de un rip (creada por
 * reddit_rip.php) y la entrega como descarga de archivo.
 *
 * Se llama por GET (navegación directa: window.location.href = ...), así el
 * navegador la trata como un link de descarga normal y hace streaming desde
 * disco sin pasar el zip entero por JS/blobs.
 *
 * Tras servir el archivo, borra el zip y la carpeta temporal del servidor.
 */

declare(strict_types=1);

const TMP_ROOT = '/tmp/reddit_rip';

function safe_rip_id(?string $id): ?string {
    if ($id !== null && preg_match('/^[a-f0-9]{16}$/', $id) === 1) {
        return $id;
    }
    return null;
}

function fail(int $code, string $msg): never {
    http_response_code($code);
    header('Content-Type: text/plain; charset=utf-8');
    echo $msg;
    exit;
}

$id = safe_rip_id($_GET['rip_id'] ?? null);
$label = preg_replace('/[^A-Za-z0-9_-]/', '_', $_GET['label'] ?? 'reddit_rip') ?? 'reddit_rip';

if (!$id) {
    fail(400, 'rip_id inválido');
}

$dir = TMP_ROOT . '/' . $id;
if (!is_dir($dir)) {
    fail(404, 'No se encontró la descarga (¿ya se generó o venció?)');
}

$files = array_values(array_diff(scandir($dir) ?: [], ['.', '..']));
if (count($files) === 0) {
    fail(404, 'No hay archivos para comprimir');
}

$zipPath = TMP_ROOT . "/{$id}.zip";
$zip = new ZipArchive();
if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
    fail(500, 'No se pudo crear el zip');
}
foreach ($files as $f) {
    $zip->addFile("$dir/$f", $f);
}
$zip->close();

$downloadName = "reddit_rip_{$label}_" . time() . '.zip';

header('Content-Type: application/zip');
header('Content-Disposition: attachment; filename="' . $downloadName . '"');
header('Content-Length: ' . filesize($zipPath));
header('X-Accel-Buffering: no');

readfile($zipPath);

// Limpieza: borramos el zip y todos los archivos originales de la carpeta
// temporal, ya no hacen falta en el servidor.
@unlink($zipPath);
foreach ($files as $f) {
    @unlink("$dir/$f");
}
@rmdir($dir);
