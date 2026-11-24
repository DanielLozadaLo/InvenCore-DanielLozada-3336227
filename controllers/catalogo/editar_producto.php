<?php
/**
 * InvenCore - Controlador: editar producto (UPDATE)
 * Ruta: controllers/catalogo/editar_producto.php
 *
 * Recibe por POST los datos del modal en modo "Editar Producto" y actualiza:
 * nombre, descripcion, precio, stock_actual, stock_minimo y ubicacion_sga.
 * (El SKU, la categoría y el proveedor no se modifican aquí.)
 *
 * Flujo:
 *   catalogo.html (modal en modo edición) --POST--> editar_producto.php
 *        éxito: catalogo.html?producto=editado
 *        error: catalogo.html?error=codigo
 */

declare(strict_types=1);

require_once __DIR__ . '/../../config/sesion.php';   // iniciarSesionSegura()
require_once __DIR__ . '/../../config/zonas_sga.php';   // ZONAS_SGA (lista oficial de ubicaciones)

/* =====================================================================
 * 1. CONSTANTES
 * ===================================================================== */
const URL_CATALOGO = '../../views/catalogo/catalogo.html';
const URL_LOGIN    = '../../views/seguridad/login.html';

/* =====================================================================
 * 2. FUNCIONES AUXILIARES
 * ===================================================================== */

/** Redirige a una URL (con parámetros opcionales en la query) y detiene el script. */
function redirigir(string $url, array $parametros = []): void
{
    if (!empty($parametros)) {
        $url .= '?' . http_build_query($parametros);
    }
    header('Location: ' . $url);
    exit;
}

/** Lee un campo de $_POST como texto sin espacios sobrantes. Si falta o no es texto, devuelve ''. */
function obtenerTexto(string $campo): string
{
    $valor = $_POST[$campo] ?? '';
    return is_string($valor) ? trim($valor) : '';
}

/**
 * Valida y normaliza los datos de la edición.
 * Devuelve [$datos, $error]: si hay error, $error es un código y $datos queda vacío.
 */
function validarEdicion(array $entrada): array
{
    // a) Identificador del producto
    $idProducto = filter_var($entrada['id_producto'], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    if ($idProducto === false) {
        return [[], 'id_invalido'];
    }

    // b) Campos obligatorios (se compara con '' porque el stock "0" es un valor válido)
    foreach (['nombre', 'precio', 'stock_actual', 'stock_minimo', 'ubicacion_sga'] as $campo) {
        if ($entrada[$campo] === '') {
            return [[], 'campos_vacios'];
        }
    }

    // c) Longitudes máximas de las columnas VARCHAR (nombre y descripción)
    if (mb_strlen($entrada['nombre'], 'UTF-8') > 100) {
        return [[], 'nombre_invalido'];
    }
    if (mb_strlen($entrada['descripcion'], 'UTF-8') > 255) {
        return [[], 'descripcion_larga'];
    }

    // d) Ubicación SGA: solo una de las zonas oficiales (config/zonas_sga.php), no texto libre
    if (!array_key_exists($entrada['ubicacion_sga'], ZONAS_SGA)) {
        return [[], 'ubicacion_invalida'];
    }

    // e) Precio: número positivo con hasta 2 decimales (DECIMAL(10,2)), validado como texto
    if (!preg_match('/^\d{1,8}(\.\d{1,2})?$/', $entrada['precio'])) {
        return [[], 'precio_invalido'];
    }

    // f) Stock: enteros de 0 en adelante
    $opcionesStock = ['options' => ['min_range' => 0, 'max_range' => 2147483647]];
    $stockActual = filter_var($entrada['stock_actual'], FILTER_VALIDATE_INT, $opcionesStock);
    $stockMinimo = filter_var($entrada['stock_minimo'], FILTER_VALIDATE_INT, $opcionesStock);
    if ($stockActual === false || $stockMinimo === false) {
        return [[], 'stock_invalido'];
    }

    return [[
        'id_producto'   => (int) $idProducto,
        'nombre'        => $entrada['nombre'],
        'descripcion'   => $entrada['descripcion'],
        'precio'        => $entrada['precio'],
        'stock_actual'  => (int) $stockActual,
        'stock_minimo'  => (int) $stockMinimo,
        'ubicacion_sga' => $entrada['ubicacion_sga'],
    ], null];
}

/* =====================================================================
 * 3. SOLO SE ACEPTA POST
 * ===================================================================== */
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    redirigir(URL_CATALOGO);
}

/* =====================================================================
 * 4. SOLO USUARIOS CON SESIÓN ACTIVA
 * ===================================================================== */
iniciarSesionSegura();
if (!isset($_SESSION['id_usuario'])) {
    redirigir(URL_LOGIN);
}
session_write_close();

/* =====================================================================
 * 5. RECEPCIÓN Y VALIDACIÓN
 *    (los nombres coinciden con el atributo name del modal)
 * ===================================================================== */
$entrada = [
    'id_producto'   => obtenerTexto('id_producto'),
    'nombre'        => obtenerTexto('nombre'),
    'descripcion'   => obtenerTexto('descripcion'),
    'precio'        => obtenerTexto('precio'),
    'stock_actual'  => obtenerTexto('stock_actual'),
    'stock_minimo'  => obtenerTexto('stock_minimo'),
    'ubicacion_sga' => obtenerTexto('ubicacion_sga'),
];

[$datos, $error] = validarEdicion($entrada);
if ($error !== null) {
    redirigir(URL_CATALOGO, ['error' => $error]);
}

/* =====================================================================
 * 6. ACTUALIZACIÓN EN LA BASE DE DATOS (PDO + consultas preparadas)
 * ===================================================================== */
try {
    // Reutiliza la conexión existente: config/database.php define $conexion (PDO)
    require_once __DIR__ . '/../../config/database.php';

    if (!isset($conexion) || !($conexion instanceof PDO)) {
        throw new RuntimeException('La variable $conexion no está disponible.');
    }
    $conexion->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    // 6.1 ¿Existe el producto? (rowCount() del UPDATE vale 0 también cuando no cambió ningún dato)
    $consulta = $conexion->prepare('SELECT id_producto FROM productos WHERE id_producto = :id_producto LIMIT 1');
    $consulta->bindValue(':id_producto', $datos['id_producto'], PDO::PARAM_INT);
    $consulta->execute();

    if ($consulta->fetch() === false) {
        redirigir(URL_CATALOGO, ['error' => 'no_encontrado']);
    }

    // 6.2 Actualizar (los valores viajan separados del SQL: sin inyección)
    $actualizar = $conexion->prepare(
        'UPDATE productos
            SET nombre        = :nombre,
                descripcion   = :descripcion,
                precio        = :precio,
                stock_actual  = :stock_actual,
                stock_minimo  = :stock_minimo,
                ubicacion_sga = :ubicacion_sga
          WHERE id_producto   = :id_producto'
    );
    $actualizar->bindValue(':nombre',        $datos['nombre'],        PDO::PARAM_STR);
    $actualizar->bindValue(':descripcion',   $datos['descripcion'],   PDO::PARAM_STR);
    $actualizar->bindValue(':precio',        $datos['precio'],        PDO::PARAM_STR); // DECIMAL: se envía como texto
    $actualizar->bindValue(':stock_actual',  $datos['stock_actual'],  PDO::PARAM_INT);
    $actualizar->bindValue(':stock_minimo',  $datos['stock_minimo'],  PDO::PARAM_INT);
    $actualizar->bindValue(':ubicacion_sga', $datos['ubicacion_sga'], PDO::PARAM_STR);
    $actualizar->bindValue(':id_producto',   $datos['id_producto'],   PDO::PARAM_INT);
    $actualizar->execute();

    // 6.3 Éxito: volver al catálogo
    redirigir(URL_CATALOGO, ['producto' => 'editado']);

} catch (PDOException $e) {
    // El detalle técnico va al log del servidor, NUNCA al usuario
    error_log('[InvenCore][editar_producto] PDOException: ' . $e->getMessage());
    redirigir(URL_CATALOGO, ['error' => 'error_servidor']);

} catch (Throwable $e) {
    error_log('[InvenCore][editar_producto] Error: ' . $e->getMessage());
    redirigir(URL_CATALOGO, ['error' => 'error_servidor']);
}