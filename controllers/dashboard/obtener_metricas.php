<?php
/**
 * InvenCore - Controlador: métricas del Dashboard
 * Ruta: controllers/dashboard/obtener_metricas.php
 *
 * Calcula con consultas preparadas (PDO) los datos del Dashboard General y los
 * devuelve en UN solo objeto JSON. dashboard.js lo consulta con fetch().
 *
 * Respuestas:
 *   200 {
 *         "ok": true,
 *         "metricas": {
 *           "total_productos": 5,        COUNT(*) de productos
 *           "total_unidades": 79,        SUM(stock_actual) de productos
 *           "total_entradas": 3,         movimientos de tipo 'ENTRADA'
 *           "total_salidas": 3,          movimientos de tipo 'SALIDA'
 *           "stock_critico_total": 2,    cuántos productos tienen stock_actual <= stock_minimo
 *           "stock_critico": [ {...} ]   los más urgentes (máximo LIMITE_ALERTAS)
 *         }
 *       }
 *   401 {"ok": false, "error": "sin_sesion"}
 *   405 {"ok": false, "error": "metodo_no_permitido"}
 *   500 {"ok": false, "error": "error_servidor"}
 */

declare(strict_types=1);

require_once __DIR__ . '/../../config/sesion.php';   // iniciarSesionSegura()

// Ponla en true SOLO mientras depuras en local: la respuesta incluirá el mensaje técnico del error.
const MODO_DEPURACION = false;
const LIMITE_ALERTAS  = 20;   // Máximo de filas en la tabla de stock crítico

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

    // 3.1 Total de productos (SKUs) y unidades en existencia.
    //     COALESCE evita NULL cuando la tabla está vacía (SUM de nada es NULL, no 0)
    $consulta = $conexion->prepare(
        'SELECT COUNT(*) AS total_productos,
                COALESCE(SUM(stock_actual), 0) AS total_unidades
           FROM productos'
    );
    $consulta->execute();
    $resumen = $consulta->fetch(PDO::FETCH_ASSOC);

    // 3.2 y 3.3 Movimientos por tipo: se prepara UNA vez y se ejecuta dos veces
    $contarMovimientos = $conexion->prepare('SELECT COUNT(*) FROM movimientos WHERE tipo_movimiento = :tipo');

    $contarMovimientos->execute([':tipo' => 'ENTRADA']);
    $totalEntradas = (int) $contarMovimientos->fetchColumn();
    $contarMovimientos->closeCursor();

    $contarMovimientos->execute([':tipo' => 'SALIDA']);
    $totalSalidas = (int) $contarMovimientos->fetchColumn();
    $contarMovimientos->closeCursor();

    // 3.4 Stock crítico: existencias en el punto de reorden o por debajo del mínimo
    $consulta = $conexion->prepare('SELECT COUNT(*) FROM productos WHERE stock_actual <= stock_minimo');
    $consulta->execute();
    $totalCriticos = (int) $consulta->fetchColumn();
    $consulta->closeCursor();

    // Los más urgentes primero: mayor déficit (stock_actual - stock_minimo más negativo)
    $consulta = $conexion->prepare(
        'SELECT id_producto, sku, nombre, ubicacion_sga, stock_actual, stock_minimo
           FROM productos
          WHERE stock_actual <= stock_minimo
          ORDER BY (stock_actual - stock_minimo) ASC, nombre ASC
          LIMIT :limite'
    );
    $consulta->bindValue(':limite', LIMITE_ALERTAS, PDO::PARAM_INT);
    $consulta->execute();
    $filas = $consulta->fetchAll(PDO::FETCH_ASSOC);

    // PDO entrega todo como texto: se convierte cada valor a su tipo real
    $stockCritico = array_map(static function (array $fila): array {
        return [
            'id_producto'   => (int) $fila['id_producto'],
            'sku'           => (string) $fila['sku'],
            'nombre'        => (string) $fila['nombre'],
            'ubicacion_sga' => (string) ($fila['ubicacion_sga'] ?? ''),
            'stock_actual'  => (int) $fila['stock_actual'],
            'stock_minimo'  => (int) $fila['stock_minimo'],
        ];
    }, $filas);

    responderJson([
        'ok'       => true,
        'metricas' => [
            'total_productos'     => (int) $resumen['total_productos'],
            'total_unidades'      => (int) $resumen['total_unidades'],
            'total_entradas'      => $totalEntradas,
            'total_salidas'       => $totalSalidas,
            'stock_critico_total' => $totalCriticos,
            'stock_critico'       => $stockCritico,
        ],
    ]);

} catch (Throwable $e) {
    // El detalle técnico va al log del servidor, NUNCA al usuario
    error_log('[InvenCore][obtener_metricas] ' . get_class($e) . ': ' . $e->getMessage());

    $respuesta = ['ok' => false, 'error' => 'error_servidor'];
    if (MODO_DEPURACION) {
        $respuesta['detalle'] = $e->getMessage();   // Solo en depuración
    }
    responderJson($respuesta, 500);
}