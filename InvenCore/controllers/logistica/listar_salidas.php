<?php
/**
 * InvenCore - Controlador: listar salidas (READ)
 * Ruta: controllers/logistica/listar_salidas.php
 *
 * Devuelve en JSON, en una sola respuesta:
 *   - salidas:   los movimientos de tipo 'SALIDA' con producto, ubicación SGA y operador (para la tabla)
 *   - productos: id, sku, nombre y stock_actual de cada producto (para llenar el select del modal)
 *
 * Respuestas:
 *   200 {"ok": true, "salidas": [...], "productos": [...]}
 *   401 {"ok": false, "error": "sin_sesion"}
 *   405 {"ok": false, "error": "metodo_no_permitido"}
 *   500 {"ok": false, "error": "error_servidor"}
 */

declare(strict_types=1);

require_once __DIR__ . '/../../config/sesion.php';   // iniciarSesionSegura()

// Ponla en true SOLO mientras depuras en local: la respuesta incluirá el mensaje técnico del error.
const MODO_DEPURACION = false;

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');                    // Siempre datos frescos

/** Envía una respuesta JSON con su código HTTP y detiene el script. */
function responderJson(array $datos, int $codigoHttp = 200): void
{
    http_response_code($codigoHttp);
    echo json_encode($datos, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}

/* 1. Solo GET */
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'GET') {
    header('Allow: GET');
    responderJson(['ok' => false, 'error' => 'metodo_no_permitido'], 405);
}

/* 2. Solo usuarios con sesión activa */
iniciarSesionSegura();
if (!isset($_SESSION['id_usuario'])) {
    responderJson(['ok' => false, 'error' => 'sin_sesion'], 401);
}
session_write_close();

/* 3. Consultas */
try {
    // Reutiliza la conexión existente: config/database.php define $conexion (PDO)
    require_once __DIR__ . '/../../config/database.php';

    if (!isset($conexion) || !($conexion instanceof PDO)) {
        throw new RuntimeException('La variable $conexion no está disponible.');
    }
    $conexion->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    // 3.1 Salidas: JOIN con productos (obligatorio) y LEFT JOIN con usuarios
    //     (la salida aparece aunque el operador ya no exista)
    $consulta = $conexion->prepare(
        'SELECT m.id_movimiento, m.nro_guia, m.fecha, m.cantidad, m.estado,
                m.motivo AS cliente,
                p.sku, p.nombre AS producto, p.ubicacion_sga,
                u.nombre AS operador
           FROM movimientos m
           JOIN productos p    ON p.id_producto = m.id_producto
           LEFT JOIN usuarios u ON u.id_usuario  = m.id_usuario
          WHERE m.tipo_movimiento = :tipo
          ORDER BY m.fecha DESC, m.id_movimiento DESC'
    );
    $consulta->bindValue(':tipo', 'SALIDA', PDO::PARAM_STR);
    $consulta->execute();
    $filas = $consulta->fetchAll(PDO::FETCH_ASSOC);

    // PDO entrega todo como texto: se convierte cada valor a su tipo real
    $salidas = array_map(static function (array $fila): array {
        return [
            'id_movimiento' => (int) $fila['id_movimiento'],
            'nro_guia'      => (string) ($fila['nro_guia'] ?? ''),
            'fecha'         => (string) ($fila['fecha'] ?? ''),          // "AAAA-MM-DD HH:MM:SS"
            'cantidad'      => (int) $fila['cantidad'],
            'estado'        => (string) ($fila['estado'] ?? ''),
            'cliente'       => (string) ($fila['cliente'] ?? ''),
            'sku'           => (string) $fila['sku'],
            'producto'      => (string) $fila['producto'],
            'ubicacion_sga' => (string) ($fila['ubicacion_sga'] ?? ''),
            'operador'      => $fila['operador'] !== null ? (string) $fila['operador'] : null,
        ];
    }, $filas);

    // 3.2 Productos para el select del modal (con su stock disponible)
    $consulta = $conexion->prepare('SELECT id_producto, sku, nombre, stock_actual FROM productos ORDER BY nombre ASC');
    $consulta->execute();
    $productos = array_map(static function (array $fila): array {
        return [
            'id_producto'  => (int) $fila['id_producto'],
            'sku'          => (string) $fila['sku'],
            'nombre'       => (string) $fila['nombre'],
            'stock_actual' => (int) $fila['stock_actual'],
        ];
    }, $consulta->fetchAll(PDO::FETCH_ASSOC));

    responderJson([
        'ok'        => true,
        'salidas'   => $salidas,
        'productos' => $productos,
    ]);

} catch (Throwable $e) {
    // El detalle técnico va al log del servidor, NUNCA al usuario
    error_log('[InvenCore][listar_salidas] ' . get_class($e) . ': ' . $e->getMessage());

    $respuesta = ['ok' => false, 'error' => 'error_servidor'];
    if (MODO_DEPURACION) {
        $respuesta['detalle'] = $e->getMessage();   // Solo en depuración
    }
    responderJson($respuesta, 500);
}