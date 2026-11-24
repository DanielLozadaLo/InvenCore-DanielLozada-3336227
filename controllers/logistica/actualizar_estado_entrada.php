<?php
/**
 * InvenCore - Controlador: actualizar el estado de una entrada (UPDATE)
 * Ruta: controllers/logistica/actualizar_estado_entrada.php
 *
 * Avanza una entrada por el flujo de aprobación. entradas.js lo llama con fetch().
 *
 *   accion = "inspeccionar":  'Pendiente'     → 'En Inspección'
 *   accion = "aprobar":       'En Inspección' → 'Recepcionado'  (y SUMA la cantidad al stock del producto)
 *
 * Campos POST: id_movimiento, accion
 *
 * El servidor comprueba que la entrada esté en el estado que corresponde a la acción:
 * así un doble clic, o una pantalla desactualizada, no puede saltarse una etapa
 * ni sumar el stock dos veces.
 *
 * Todo ocurre en UNA transacción: el cambio de estado y la suma de stock se confirman juntos o no se hace nada.
 *
 * Respuestas (JSON):
 *   200 {"ok": true, "estado": "Recepcionado", "nro_guia": "OC-2026-0417", "stock_actual": 43}
 *       (stock_actual solo viene al aprobar)
 *   400 {"ok": false, "error": "id_invalido" | "accion_invalida"}
 *   401 {"ok": false, "error": "sin_sesion"}
 *   404 {"ok": false, "error": "no_encontrado"}
 *   405 {"ok": false, "error": "metodo_no_permitido"}
 *   409 {"ok": false, "error": "estado_desactualizado"}
 *   422 {"ok": false, "error": "stock_excedido"}
 *   500 {"ok": false, "error": "error_servidor"}
 */

declare(strict_types=1);

require_once __DIR__ . '/../../config/sesion.php';   // iniciarSesionSegura()

/* =====================================================================
 * 1. CONSTANTES
 * ===================================================================== */
const MODO_DEPURACION = false;                       // true SOLO mientras depuras en local
const STOCK_MAXIMO    = 2147483647;                  // Límite de una columna INT

/**
 * Acciones permitidas:
 *   desde      → estado actual requerido (normalizado: minúsculas y sin tilde)
 *   hasta      → estado nuevo que se guarda en movimientos.estado
 *   suma_stock → si al llegar a ese estado se suma la cantidad al stock del producto
 */
const ACCIONES = [
    'inspeccionar' => ['desde' => 'pendiente',     'hasta' => 'En Inspección', 'suma_stock' => false],
    'aprobar'      => ['desde' => 'en inspeccion', 'hasta' => 'Recepcionado',  'suma_stock' => true],
];

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

/* =====================================================================
 * 2. FUNCIONES AUXILIARES
 * ===================================================================== */

/** Envía una respuesta JSON con su código HTTP y detiene el script. */
function responderJson(array $datos, int $codigoHttp = 200): void
{
    http_response_code($codigoHttp);
    echo json_encode($datos, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}

/** Normaliza un estado para compararlo: "En Inspección" → "en inspeccion". */
function claveEstado(string $estado): string
{
    return str_replace(['ó', 'Ó'], 'o', mb_strtolower(trim($estado), 'UTF-8'));
}

/* =====================================================================
 * 3. SOLO POST
 * ===================================================================== */
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    header('Allow: POST');
    responderJson(['ok' => false, 'error' => 'metodo_no_permitido'], 405);
}

/* =====================================================================
 * 4. SOLO USUARIOS CON SESIÓN ACTIVA
 * ===================================================================== */
iniciarSesionSegura();
if (!isset($_SESSION['id_usuario'])) {
    responderJson(['ok' => false, 'error' => 'sin_sesion'], 401);
}
session_write_close();

/* =====================================================================
 * 5. VALIDACIÓN DE LO RECIBIDO
 * ===================================================================== */
$idRecibido = $_POST['id_movimiento'] ?? '';
$idMovimiento = is_string($idRecibido)
    ? filter_var(trim($idRecibido), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]])
    : false;
if ($idMovimiento === false) {
    responderJson(['ok' => false, 'error' => 'id_invalido'], 400);
}

$accionRecibida = $_POST['accion'] ?? '';
$accion = is_string($accionRecibida) ? trim($accionRecibida) : '';
if (!array_key_exists($accion, ACCIONES)) {
    responderJson(['ok' => false, 'error' => 'accion_invalida'], 400);
}
$reglas = ACCIONES[$accion];

/* =====================================================================
 * 6. TRANSACCIÓN: cambiar el estado (+ sumar el stock al aprobar)
 * ===================================================================== */
try {
    // Reutiliza la conexión existente: config/database.php define $conexion (PDO)
    require_once __DIR__ . '/../../config/database.php';

    if (!isset($conexion) || !($conexion instanceof PDO)) {
        throw new RuntimeException('La variable $conexion no está disponible.');
    }
    $conexion->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    $conexion->beginTransaction();

    // 6.1 La entrada debe existir. FOR UPDATE bloquea su fila: dos clics simultáneos no pueden aplicarse a la vez
    $consulta = $conexion->prepare(
        'SELECT nro_guia, estado, cantidad, id_producto
           FROM movimientos
          WHERE id_movimiento = :id_movimiento AND tipo_movimiento = :tipo
          FOR UPDATE'
    );
    $consulta->bindValue(':id_movimiento', $idMovimiento, PDO::PARAM_INT);
    $consulta->bindValue(':tipo', 'ENTRADA', PDO::PARAM_STR);
    $consulta->execute();
    $entrada = $consulta->fetch(PDO::FETCH_ASSOC);
    $consulta->closeCursor();

    if ($entrada === false) {
        $conexion->rollBack();
        responderJson(['ok' => false, 'error' => 'no_encontrado'], 404);
    }

    // 6.2 La entrada debe estar en el estado que esa acción exige
    if (claveEstado((string) $entrada['estado']) !== $reglas['desde']) {
        $conexion->rollBack();
        responderJson(['ok' => false, 'error' => 'estado_desactualizado'], 409);
    }

    // 6.3 Al aprobar: sumar la cantidad recibida al stock del producto
    $nuevoStock = null;
    if ($reglas['suma_stock']) {
        $producto = $conexion->prepare('SELECT stock_actual FROM productos WHERE id_producto = :id_producto FOR UPDATE');
        $producto->bindValue(':id_producto', (int) $entrada['id_producto'], PDO::PARAM_INT);
        $producto->execute();
        $filaProducto = $producto->fetch(PDO::FETCH_ASSOC);
        $producto->closeCursor();

        if ($filaProducto === false) {
            $conexion->rollBack();
            responderJson(['ok' => false, 'error' => 'no_encontrado'], 404);
        }

        $nuevoStock = (int) $filaProducto['stock_actual'] + (int) $entrada['cantidad'];
        if ($nuevoStock > STOCK_MAXIMO) {
            $conexion->rollBack();
            responderJson(['ok' => false, 'error' => 'stock_excedido'], 422);
        }

        $sumar = $conexion->prepare(
            'UPDATE productos SET stock_actual = stock_actual + :cantidad WHERE id_producto = :id_producto'
        );
        $sumar->bindValue(':cantidad',    (int) $entrada['cantidad'],    PDO::PARAM_INT);
        $sumar->bindValue(':id_producto', (int) $entrada['id_producto'], PDO::PARAM_INT);
        $sumar->execute();
    }

    // 6.4 Guardar el nuevo estado
    $actualizar = $conexion->prepare('UPDATE movimientos SET estado = :estado WHERE id_movimiento = :id_movimiento');
    $actualizar->bindValue(':estado',        $reglas['hasta'], PDO::PARAM_STR);
    $actualizar->bindValue(':id_movimiento', $idMovimiento,    PDO::PARAM_INT);
    $actualizar->execute();

    // 6.5 Todo salió bien: se confirman AMBOS cambios a la vez
    $conexion->commit();

    $respuesta = [
        'ok'       => true,
        'estado'   => $reglas['hasta'],
        'nro_guia' => (string) $entrada['nro_guia'],
    ];
    if ($nuevoStock !== null) {
        $respuesta['stock_actual'] = $nuevoStock;
    }
    responderJson($respuesta);

} catch (Throwable $e) {
    // Si algo falló a mitad de la transacción, se deshace TODO
    if (isset($conexion) && $conexion instanceof PDO && $conexion->inTransaction()) {
        $conexion->rollBack();
    }

    // El detalle técnico va al log del servidor, NUNCA al usuario
    error_log('[InvenCore][actualizar_estado_entrada] ' . get_class($e) . ': ' . $e->getMessage());

    $respuesta = ['ok' => false, 'error' => 'error_servidor'];
    if (MODO_DEPURACION) {
        $respuesta['detalle'] = $e->getMessage();   // Solo en depuración
    }
    responderJson($respuesta, 500);
}