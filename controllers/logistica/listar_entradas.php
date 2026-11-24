<?php
/**
 * InvenCore - Controlador: listar entradas (READ)
 * Ruta: controllers/logistica/listar_entradas.php
 *
 * Devuelve en JSON, en una sola respuesta:
 *   - entradas:    los movimientos de tipo 'ENTRADA' con producto, proveedor e inspector (para la tabla)
 *   - productos:   id, sku y nombre de cada producto (para llenar el select del modal)
 *   - proveedores: id y nombre de cada proveedor (para llenar el select del modal)
 *
 * Respuestas:
 *   200 {"ok": true, "entradas": [...], "productos": [...], "proveedores": [...]}
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

    // 3.1 Entradas: JOIN con productos (obligatorio) y LEFT JOIN con proveedores y usuarios
    //     (la entrada aparece aunque el proveedor o el usuario ya no existan)
    $consulta = $conexion->prepare(
        'SELECT m.id_movimiento, m.nro_guia, m.fecha, m.cantidad, m.estado, m.motivo, m.almacen_destino,
                p.sku, p.nombre AS producto,
                pr.nombre_empresa AS proveedor,
                u.nombre AS inspector, u.rol AS rol_inspector
           FROM movimientos m
           JOIN productos p        ON p.id_producto  = m.id_producto
           LEFT JOIN proveedores pr ON pr.id_proveedor = m.id_proveedor
           LEFT JOIN usuarios u     ON u.id_usuario    = m.id_usuario
          WHERE m.tipo_movimiento = :tipo
          ORDER BY m.fecha DESC, m.id_movimiento DESC'
    );
    $consulta->bindValue(':tipo', 'ENTRADA', PDO::PARAM_STR);
    $consulta->execute();
    $filas = $consulta->fetchAll(PDO::FETCH_ASSOC);

    // PDO entrega todo como texto: se convierte cada valor a su tipo real
    $entradas = array_map(static function (array $fila): array {
        return [
            'id_movimiento'   => (int) $fila['id_movimiento'],
            'nro_guia'        => (string) ($fila['nro_guia'] ?? ''),
            'fecha'           => (string) ($fila['fecha'] ?? ''),          // "AAAA-MM-DD HH:MM:SS"
            'cantidad'        => (int) $fila['cantidad'],
            'estado'          => (string) ($fila['estado'] ?? ''),
            'motivo'          => (string) ($fila['motivo'] ?? ''),
            'almacen_destino' => (string) ($fila['almacen_destino'] ?? ''),
            'sku'             => (string) $fila['sku'],
            'producto'        => (string) $fila['producto'],
            'proveedor'       => (string) ($fila['proveedor'] ?? 'Sin proveedor'),
            'inspector'       => $fila['inspector'] !== null ? (string) $fila['inspector'] : null,
            'rol_inspector'   => $fila['rol_inspector'] !== null ? (string) $fila['rol_inspector'] : null,
        ];
    }, $filas);

    // 3.2 Productos para el select del modal
    $consulta = $conexion->prepare('SELECT id_producto, sku, nombre, ubicacion_sga FROM productos ORDER BY nombre ASC');
    $consulta->execute();
    $productos = array_map(static function (array $fila): array {
        return [
            'id_producto' => (int) $fila['id_producto'],
            'sku'         => (string) $fila['sku'],
            'nombre'      => (string) $fila['nombre'],
            'ubicacion_sga' => (string) ($fila['ubicacion_sga'] ?? ''),
        ];
    }, $consulta->fetchAll(PDO::FETCH_ASSOC));

    // 3.3 Proveedores para el select del modal
    $consulta = $conexion->prepare('SELECT id_proveedor, nombre_empresa FROM proveedores ORDER BY nombre_empresa ASC');
    $consulta->execute();
    $proveedores = array_map(static function (array $fila): array {
        return [
            'id_proveedor'   => (int) $fila['id_proveedor'],
            'nombre_empresa' => (string) $fila['nombre_empresa'],
        ];
    }, $consulta->fetchAll(PDO::FETCH_ASSOC));

    responderJson([
        'ok'          => true,
        'entradas'    => $entradas,
        'productos'   => $productos,
        'proveedores' => $proveedores,
    ]);

} catch (Throwable $e) {
    // El detalle técnico va al log del servidor, NUNCA al usuario
    error_log('[InvenCore][listar_entradas] ' . get_class($e) . ': ' . $e->getMessage());

    $respuesta = ['ok' => false, 'error' => 'error_servidor'];
    if (MODO_DEPURACION) {
        $respuesta['detalle'] = $e->getMessage();   // Solo en depuración
    }
    responderJson($respuesta, 500);
}