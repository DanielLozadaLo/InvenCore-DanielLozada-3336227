<?php
/**
 * InvenCore - Controlador: eliminar producto (DELETE)
 * Ruta: controllers/catalogo/eliminar_producto.php
 *
 * Recibe por POST el campo id_producto y borra ese producto.
 * Se acepta solo POST (no GET): una URL de borrado por GET podría dispararse
 * por error (un enlace, el precargado del navegador) o desde otro sitio.
 *
 * Respuestas (JSON):
 *   200 {"ok": true}
 *   400 {"ok": false, "error": "id_invalido"}
 *   401 {"ok": false, "error": "sin_sesion"}
 *   404 {"ok": false, "error": "no_encontrado"}
 *   405 {"ok": false, "error": "metodo_no_permitido"}
 *   409 {"ok": false, "error": "producto_con_movimientos"}   (lo referencian otros registros)
 *   500 {"ok": false, "error": "error_servidor"}
 */

declare(strict_types=1);

require_once __DIR__ . '/../../config/sesion.php';   // iniciarSesionSegura()

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

/** Envía una respuesta JSON con su código HTTP y detiene el script. */
function responderJson(array $datos, int $codigoHttp = 200): void
{
    http_response_code($codigoHttp);
    echo json_encode($datos, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}

/* 1. Solo POST */
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    header('Allow: POST');
    responderJson(['ok' => false, 'error' => 'metodo_no_permitido'], 405);
}

/* 2. Solo usuarios con sesión activa */
iniciarSesionSegura();
if (!isset($_SESSION['id_usuario'])) {
    responderJson(['ok' => false, 'error' => 'sin_sesion'], 401);
}
session_write_close();

/* 3. Validar el id recibido (entero positivo) */
$idRecibido = $_POST['id_producto'] ?? '';
$idProducto = is_string($idRecibido)
    ? filter_var(trim($idRecibido), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]])
    : false;

if ($idProducto === false) {
    responderJson(['ok' => false, 'error' => 'id_invalido'], 400);
}

/* 4. Borrado */
try {
    // Reutiliza la conexión existente: config/database.php define $conexion (PDO)
    require_once __DIR__ . '/../../config/database.php';

    if (!isset($conexion) || !($conexion instanceof PDO)) {
        throw new RuntimeException('La variable $conexion no está disponible.');
    }
    $conexion->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    $eliminar = $conexion->prepare('DELETE FROM productos WHERE id_producto = :id_producto');
    $eliminar->bindValue(':id_producto', $idProducto, PDO::PARAM_INT);
    $eliminar->execute();

    // rowCount() = filas borradas. Si es 0, ese id no existía
    if ($eliminar->rowCount() === 0) {
        responderJson(['ok' => false, 'error' => 'no_encontrado'], 404);
    }

    responderJson(['ok' => true]);

} catch (PDOException $e) {
    error_log('[InvenCore][eliminar_producto] PDOException: ' . $e->getMessage());

    // Código 1451 de MySQL: otra tabla (p. ej. movimientos) referencia este producto
    if ((int) ($e->errorInfo[1] ?? 0) === 1451) {
        responderJson(['ok' => false, 'error' => 'producto_con_movimientos'], 409);
    }
    responderJson(['ok' => false, 'error' => 'error_servidor'], 500);

} catch (Throwable $e) {
    error_log('[InvenCore][eliminar_producto] Error: ' . $e->getMessage());
    responderJson(['ok' => false, 'error' => 'error_servidor'], 500);
}