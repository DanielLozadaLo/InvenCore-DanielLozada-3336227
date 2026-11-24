<?php
/**
 * InvenCore - Controlador: listar productos (READ)
 * Ruta: controllers/catalogo/listar_productos.php
 *
 * Devuelve en JSON todos los productos con el nombre de su categoría y su proveedor más reciente.
 * (La tabla productos no tiene proveedor: se toma el del último movimiento de ENTRADA de ese producto.)
 * catalogo.js lo consulta con fetch() al cargar la página.
 *
 * Respuestas:
 *   200 {"ok": true,  "productos": [ {...}, {...} ]}
 *   401 {"ok": false, "error": "sin_sesion"}
 *   405 {"ok": false, "error": "metodo_no_permitido"}
 *   500 {"ok": false, "error": "error_servidor"}
 */

declare(strict_types=1);

require_once __DIR__ . '/../../config/sesion.php';   // iniciarSesionSegura()

// Ponla en true SOLO mientras depuras en local: la respuesta incluirá el mensaje técnico del error.
// Déjala en false siempre que el sistema se use de verdad (no se debe exponer SQL al usuario).
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

/* 3. Consulta */
try {
    // Reutiliza la conexión existente: config/database.php define $conexion (PDO)
    require_once __DIR__ . '/../../config/database.php';

    if (!isset($conexion) || !($conexion instanceof PDO)) {
        throw new RuntimeException('La variable $conexion no está disponible.');
    }
    $conexion->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    // LEFT JOIN: el producto aparece aunque no tenga categoría asignada.
    // El proveedor sale de una subconsulta: la empresa de la última ENTRADA de ese producto.
    $consulta = $conexion->prepare(
        "SELECT p.id_producto, p.sku, p.nombre, p.descripcion, p.ubicacion_sga,
                p.precio, p.stock_actual, p.stock_minimo,
                p.id_categoria, c.nombre AS categoria,
                (SELECT pr.nombre_empresa
                   FROM movimientos m
                   JOIN proveedores pr ON pr.id_proveedor = m.id_proveedor
                  WHERE m.id_producto = p.id_producto
                    AND m.tipo_movimiento = 'ENTRADA'
                  ORDER BY m.fecha DESC, m.id_movimiento DESC
                  LIMIT 1) AS proveedor
           FROM productos p
           LEFT JOIN categorias c ON c.id_categoria = p.id_categoria
          ORDER BY p.sku ASC"
    );
    $consulta->execute();
    $filas = $consulta->fetchAll(PDO::FETCH_ASSOC);

    // PDO entrega todo como texto: se convierte cada valor a su tipo real
    $productos = array_map(static function (array $fila): array {
        return [
            'id_producto'   => (int) $fila['id_producto'],
            'sku'           => (string) $fila['sku'],
            'nombre'        => (string) $fila['nombre'],
            'descripcion'   => (string) ($fila['descripcion'] ?? ''),
            'ubicacion_sga' => (string) ($fila['ubicacion_sga'] ?? ''),
            'precio'        => (string) $fila['precio'],        // DECIMAL como texto: no pierde exactitud
            'stock_actual'  => (int) $fila['stock_actual'],
            'stock_minimo'  => (int) $fila['stock_minimo'],
            'id_categoria'  => $fila['id_categoria'] !== null ? (int) $fila['id_categoria'] : null,
            'categoria'     => (string) ($fila['categoria'] ?? 'Sin categoría'),
            'proveedor'     => (string) ($fila['proveedor'] ?? 'Sin proveedor'),
        ];
    }, $filas);

    responderJson(['ok' => true, 'productos' => $productos]);

} catch (Throwable $e) {
    // El detalle técnico va al log del servidor, NUNCA al usuario
    error_log('[InvenCore][listar_productos] ' . get_class($e) . ': ' . $e->getMessage());

    $respuesta = ['ok' => false, 'error' => 'error_servidor'];
    if (MODO_DEPURACION) {
        $respuesta['detalle'] = $e->getMessage();   // Solo en depuración
    }
    responderJson($respuesta, 500);
}