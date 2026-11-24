<?php
/**
 * InvenCore - Controlador: registrar producto
 * Ruta: controllers/catalogo/registrar_producto.php
 *
 * Recibe por POST los datos del modal "+ Registrar Nuevo Producto"
 * (views/catalogo/catalogo.html) y los inserta en la tabla `productos`.
 *
 * Flujo:
 *   catalogo.html (modal) --POST--> registrar_producto.php
 *        --valida--> --consulta preparada (PDO)--> MySQL
 *        éxito: catalogo.html?producto=ok
 *        error: catalogo.html?error=codigo
 *
 * El formulario debe llevar:
 *   <form id="formNuevoProducto" action="../../controllers/catalogo/registrar_producto.php" method="post" novalidate>
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
 * Valida y normaliza los datos del producto.
 * Devuelve [$datos, $error]: si hay error, $error es un código y $datos queda vacío.
 */
function validarProducto(array $entrada): array
{
    // a) Campos obligatorios (se compara con '' porque el stock "0" es un valor válido)
    foreach (['sku', 'nombre', 'precio', 'stock_actual', 'stock_minimo'] as $campo) {
        if ($entrada[$campo] === '') {
            return [[], 'campos_vacios'];
        }
    }

    // b) SKU: letras, números y guiones, máximo 20 (igual que el formulario). Se guarda en mayúsculas
    $sku = strtoupper($entrada['sku']);
    if (!preg_match('/^[A-Z0-9\-]{1,20}$/', $sku)) {
        return [[], 'sku_invalido'];
    }

    // c) Nombre y descripción: longitudes máximas de las columnas VARCHAR
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

    // e) Precio: número positivo con hasta 2 decimales (DECIMAL(10,2)). Se valida como texto para evitar errores de redondeo
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

    // g) Categoría (llave foránea): entero positivo (que exista lo comprueba la propia base de datos)
    $opcionesId = ['options' => ['min_range' => 1]];
    $idCategoria = filter_var($entrada['id_categoria'], FILTER_VALIDATE_INT, $opcionesId);
    if ($idCategoria === false) {
        return [[], 'categoria_invalida'];
    }

    return [[
        'sku'           => $sku,
        'nombre'        => $entrada['nombre'],
        'descripcion'   => $entrada['descripcion'],
        'ubicacion_sga' => $entrada['ubicacion_sga'],
        'precio'        => $entrada['precio'],
        'stock_actual'  => (int) $stockActual,
        'stock_minimo'  => (int) $stockMinimo,
        'id_categoria'  => (int) $idCategoria,
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
 *    (el frontend se puede saltar: el servidor siempre verifica)
 * ===================================================================== */
iniciarSesionSegura();
if (!isset($_SESSION['id_usuario'])) {
    redirigir(URL_LOGIN);
}
session_write_close(); // Ya no se necesita escribir en la sesión: se libera

/* =====================================================================
 * 5. RECEPCIÓN Y VALIDACIÓN
 *    (los nombres coinciden con el atributo name del modal)
 * ===================================================================== */
$entrada = [
    'sku'           => obtenerTexto('sku'),
    'nombre'        => obtenerTexto('nombre'),
    'descripcion'   => obtenerTexto('descripcion'),
    'ubicacion_sga' => obtenerTexto('ubicacion_sga'),
    'precio'        => obtenerTexto('precio'),
    'stock_actual'  => obtenerTexto('stock_actual'),
    'stock_minimo'  => obtenerTexto('stock_minimo'),
    'id_categoria'  => obtenerTexto('id_categoria'),
];

[$datos, $error] = validarProducto($entrada);
if ($error !== null) {
    redirigir(URL_CATALOGO, ['error' => $error]);
}

/* =====================================================================
 * 6. INSERCIÓN EN LA BASE DE DATOS (PDO + consultas preparadas)
 * ===================================================================== */
try {
    // Reutiliza la conexión existente: config/database.php define $conexion (PDO)
    require_once __DIR__ . '/../../config/database.php';

    if (!isset($conexion) || !($conexion instanceof PDO)) {
        throw new RuntimeException('La variable $conexion no está disponible.');
    }

    // Garantiza que los errores de MySQL se lancen como excepciones (para el catch)
    $conexion->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    // 6.1 ¿Ya existe un producto con ese SKU?
    $consulta = $conexion->prepare('SELECT id_producto FROM productos WHERE sku = :sku LIMIT 1');
    $consulta->execute([':sku' => $datos['sku']]);

    if ($consulta->fetch() !== false) {
        redirigir(URL_CATALOGO, ['error' => 'sku_existente']);
    }

    // 6.2 Insertar el producto (los valores viajan separados del SQL: sin inyección)
    $insertar = $conexion->prepare(
        'INSERT INTO productos
            (sku, nombre, descripcion, ubicacion_sga, precio, stock_actual, stock_minimo, id_categoria)
         VALUES
            (:sku, :nombre, :descripcion, :ubicacion_sga, :precio, :stock_actual, :stock_minimo, :id_categoria)'
    );
    $insertar->bindValue(':sku',           $datos['sku'],           PDO::PARAM_STR);
    $insertar->bindValue(':nombre',        $datos['nombre'],        PDO::PARAM_STR);
    $insertar->bindValue(':descripcion',   $datos['descripcion'],   PDO::PARAM_STR);
    $insertar->bindValue(':ubicacion_sga', $datos['ubicacion_sga'], PDO::PARAM_STR);
    $insertar->bindValue(':precio',        $datos['precio'],        PDO::PARAM_STR); // DECIMAL: se envía como texto
    $insertar->bindValue(':stock_actual',  $datos['stock_actual'],  PDO::PARAM_INT);
    $insertar->bindValue(':stock_minimo',  $datos['stock_minimo'],  PDO::PARAM_INT);
    $insertar->bindValue(':id_categoria',  $datos['id_categoria'],  PDO::PARAM_INT);
    $insertar->execute();

    // Confirma que realmente se insertó una fila (si no, se trata como error)
    if ($insertar->rowCount() !== 1) {
        throw new RuntimeException('El INSERT no afectó ninguna fila.');
    }

    // DIAGNÓSTICO TEMPORAL (bórralo cuando todo funcione): deja en el log de Apache
    // el id insertado y en QUÉ base de datos se guardó.
    error_log('[InvenCore][registrar_producto] Insertado id=' . $conexion->lastInsertId()
        . ' sku=' . $datos['sku']
        . ' base=' . $conexion->query('SELECT DATABASE()')->fetchColumn());

    // 6.3 Éxito: volver al catálogo
    redirigir(URL_CATALOGO, ['producto' => 'ok']);

} catch (PDOException $e) {
    // El detalle técnico va al log del servidor, NUNCA al usuario
    error_log('[InvenCore][registrar_producto] PDOException: ' . $e->getMessage());

    $codigoMySql = (int) ($e->errorInfo[1] ?? 0);
    if ($codigoMySql === 1062) {            // Clave duplicada: el UNIQUE de sku lo rechazó
        redirigir(URL_CATALOGO, ['error' => 'sku_existente']);
    }
    if ($codigoMySql === 1452) {            // Llave foránea inexistente (la categoría no existe)
        redirigir(URL_CATALOGO, ['error' => 'relacion_invalida']);
    }
    redirigir(URL_CATALOGO, ['error' => 'error_servidor']);

} catch (Throwable $e) {
    error_log('[InvenCore][registrar_producto] Error: ' . $e->getMessage());
    redirigir(URL_CATALOGO, ['error' => 'error_servidor']);
}