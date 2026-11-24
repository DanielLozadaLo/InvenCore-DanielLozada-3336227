<?php
/**
 * InvenCore - Controlador: registrar salida / orden de despacho (CREATE + resta de stock)
 * Ruta: controllers/logistica/registrar_salida.php
 *
 * Recibe por POST los datos del modal "Nueva Orden de Despacho" (salidas.js lo envía con fetch).
 * En UNA transacción:
 *   1) bloquea la fila del producto, comprueba que exista y que haya stock suficiente,
 *   2) genera un nro_guia único (GR-AAAA-NNNN),
 *   3) inserta el movimiento de tipo 'SALIDA' con estado 'En Picking',
 *   4) resta la cantidad retirada a productos.stock_actual.
 * Si algo falla, no queda nada a medias (rollBack).
 *
 * Campos POST: id_producto, cantidad, motivo (Cliente / Destino)
 * Los demás datos NO los decide el cliente: tipo_movimiento, nro_guia, fecha, estado e id_usuario.
 * En una salida no hay proveedor: id_proveedor queda en NULL.
 *
 * Respuestas (JSON):
 *   200 {"ok": true, "nro_guia": "GR-2026-0417", "estado": "En Picking", "stock_actual": 12}
 *   401 / 404 / 405 / 422 / 500 {"ok": false, "error": "codigo"}
 *   422 stock_insuficiente incluye además "disponible": N
 */

declare(strict_types=1);

require_once __DIR__ . '/../../config/sesion.php';   // iniciarSesionSegura()

/* =====================================================================
 * 1. CONSTANTES
 * ===================================================================== */
const MODO_DEPURACION = false;                       // true SOLO mientras depuras en local
const TIPO_MOVIMIENTO = 'SALIDA';
const ESTADO_INICIAL  = 'En Picking';                // Primera etapa: En Picking → Embalado → Despachado
const PREFIJO_GUIA    = 'GR';                        // Resultado: GR-2026-0417
const INTENTOS_GUIA   = 5;                           // Reintentos si el número aleatorio ya existe
const CANTIDAD_MAXIMA = 1000000;
const MOTIVO_MAXIMO   = 150;                         // Igual que el maxlength del formulario

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

/** Lee un campo de $_POST como texto sin espacios sobrantes. Si falta o no es texto, devuelve ''. */
function obtenerTexto(string $campo): string
{
    $valor = $_POST[$campo] ?? '';
    return is_string($valor) ? trim($valor) : '';
}

/**
 * Valida y normaliza los datos de la salida.
 * Devuelve [$datos, $error]: si hay error, $error es un código y $datos queda vacío.
 */
function validarSalida(array $entrada): array
{
    // a) Campos obligatorios
    foreach (['id_producto', 'cantidad', 'motivo'] as $campo) {
        if ($entrada[$campo] === '') {
            return [[], 'campos_vacios'];
        }
    }

    // b) Producto: entero positivo (que exista se comprueba en la base de datos)
    $idProducto = filter_var($entrada['id_producto'], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    if ($idProducto === false) {
        return [[], 'producto_invalido'];
    }

    // c) Cantidad: entero entre 1 y el máximo permitido
    $cantidad = filter_var(
        $entrada['cantidad'],
        FILTER_VALIDATE_INT,
        ['options' => ['min_range' => 1, 'max_range' => CANTIDAD_MAXIMA]]
    );
    if ($cantidad === false) {
        return [[], 'cantidad_invalida'];
    }

    // d) Cliente / Destino
    if (mb_strlen($entrada['motivo'], 'UTF-8') > MOTIVO_MAXIMO) {
        return [[], 'motivo_largo'];
    }

    return [[
        'id_producto' => (int) $idProducto,
        'cantidad'    => (int) $cantidad,
        'motivo'      => $entrada['motivo'],
    ], null];
}

/* =====================================================================
 * 3. SOLO POST
 * ===================================================================== */
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    header('Allow: POST');
    responderJson(['ok' => false, 'error' => 'metodo_no_permitido'], 405);
}

/* =====================================================================
 * 4. SOLO USUARIOS CON SESIÓN ACTIVA: el id_usuario sale de la sesión, nunca del formulario
 * ===================================================================== */
iniciarSesionSegura();
if (!isset($_SESSION['id_usuario'])) {
    responderJson(['ok' => false, 'error' => 'sin_sesion'], 401);
}
$idUsuario = (int) $_SESSION['id_usuario'];
session_write_close();

/* =====================================================================
 * 5. RECEPCIÓN Y VALIDACIÓN
 * ===================================================================== */
$entrada = [
    'id_producto' => obtenerTexto('id_producto'),
    'cantidad'    => obtenerTexto('cantidad'),
    'motivo'      => obtenerTexto('motivo'),
];

[$datos, $error] = validarSalida($entrada);
if ($error !== null) {
    responderJson(['ok' => false, 'error' => $error], 422);
}

/* =====================================================================
 * 6. TRANSACCIÓN: insertar el movimiento + restar el stock
 * ===================================================================== */
try {
    // Reutiliza la conexión existente: config/database.php define $conexion (PDO)
    require_once __DIR__ . '/../../config/database.php';

    if (!isset($conexion) || !($conexion instanceof PDO)) {
        throw new RuntimeException('La variable $conexion no está disponible.');
    }
    $conexion->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    $conexion->beginTransaction();

    // 6.1 El producto debe existir. FOR UPDATE bloquea su fila hasta el commit:
    //     dos salidas simultáneas no pueden "gastar" el mismo stock
    $consulta = $conexion->prepare('SELECT stock_actual FROM productos WHERE id_producto = :id_producto FOR UPDATE');
    $consulta->bindValue(':id_producto', $datos['id_producto'], PDO::PARAM_INT);
    $consulta->execute();
    $producto = $consulta->fetch(PDO::FETCH_ASSOC);
    $consulta->closeCursor();

    if ($producto === false) {
        $conexion->rollBack();
        responderJson(['ok' => false, 'error' => 'producto_no_encontrado'], 404);
    }

    // 6.2 Debe haber stock suficiente (nunca se permite dejar el stock en negativo)
    $stockDisponible = (int) $producto['stock_actual'];
    if ($datos['cantidad'] > $stockDisponible) {
        $conexion->rollBack();
        responderJson(['ok' => false, 'error' => 'stock_insuficiente', 'disponible' => $stockDisponible], 422);
    }

    // 6.3 Generar un nro_guia único: GR-AAAA-NNNN (NNNN aleatorio), verificando que no exista
    $buscarGuia = $conexion->prepare('SELECT 1 FROM movimientos WHERE nro_guia = :nro_guia LIMIT 1');
    $nroGuia = null;
    for ($intento = 0; $intento < INTENTOS_GUIA; $intento++) {
        $candidato = sprintf('%s-%s-%04d', PREFIJO_GUIA, date('Y'), random_int(0, 9999));
        $buscarGuia->execute([':nro_guia' => $candidato]);
        $ocupado = $buscarGuia->fetchColumn() !== false;
        $buscarGuia->closeCursor();

        if (!$ocupado) {
            $nroGuia = $candidato;
            break;
        }
    }
    if ($nroGuia === null) {
        throw new RuntimeException('No se pudo generar un nro_guia único.');
    }

    // 6.4 Insertar el movimiento (fecha = NOW() del servidor de base de datos).
    //     id_proveedor y almacen_destino no se incluyen: quedan en NULL (no aplican a una salida)
    $insertar = $conexion->prepare(
        'INSERT INTO movimientos
            (tipo_movimiento, nro_guia, cantidad, fecha, estado, motivo, id_producto, id_usuario)
         VALUES
            (:tipo_movimiento, :nro_guia, :cantidad, NOW(), :estado, :motivo, :id_producto, :id_usuario)'
    );
    $insertar->bindValue(':tipo_movimiento', TIPO_MOVIMIENTO,       PDO::PARAM_STR);
    $insertar->bindValue(':nro_guia',        $nroGuia,              PDO::PARAM_STR);
    $insertar->bindValue(':cantidad',        $datos['cantidad'],    PDO::PARAM_INT);
    $insertar->bindValue(':estado',          ESTADO_INICIAL,        PDO::PARAM_STR);
    $insertar->bindValue(':motivo',          $datos['motivo'],      PDO::PARAM_STR);
    $insertar->bindValue(':id_producto',     $datos['id_producto'], PDO::PARAM_INT);
    $insertar->bindValue(':id_usuario',      $idUsuario,            PDO::PARAM_INT);
    $insertar->execute();

    // 6.5 Restar la cantidad retirada al stock del producto
    $actualizar = $conexion->prepare(
        'UPDATE productos SET stock_actual = stock_actual - :cantidad WHERE id_producto = :id_producto'
    );
    $actualizar->bindValue(':cantidad',    $datos['cantidad'],    PDO::PARAM_INT);
    $actualizar->bindValue(':id_producto', $datos['id_producto'], PDO::PARAM_INT);
    $actualizar->execute();

    // 6.6 Todo salió bien: se confirman AMBOS cambios a la vez
    $conexion->commit();

    responderJson([
        'ok'           => true,
        'nro_guia'     => $nroGuia,
        'estado'       => ESTADO_INICIAL,
        'stock_actual' => $stockDisponible - $datos['cantidad'],
    ]);

} catch (Throwable $e) {
    // Si algo falló a mitad de la transacción, se deshace TODO
    if (isset($conexion) && $conexion instanceof PDO && $conexion->inTransaction()) {
        $conexion->rollBack();
    }

    // El detalle técnico va al log del servidor, NUNCA al usuario
    error_log('[InvenCore][registrar_salida] ' . get_class($e) . ': ' . $e->getMessage());

    $respuesta = ['ok' => false, 'error' => 'error_servidor'];
    if (MODO_DEPURACION) {
        $respuesta['detalle'] = $e->getMessage();   // Solo en depuración
    }
    responderJson($respuesta, 500);
}