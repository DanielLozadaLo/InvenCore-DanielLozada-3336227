<?php 

$host = "localhost"; 
$dbname = "invencore_db"; 
$user = "root"; 
$dbPassword = ""; 

try { 
    $conexion = new PDO( 
        "mysql:host=$host;dbname=$dbname;charset=utf8mb4", 
        $user, 
        $dbPassword 
    ); 

    $conexion->setAttribute( 
        PDO::ATTR_ERRMODE, 
        PDO::ERRMODE_EXCEPTION 
    ); 

}   catch (PDOException $e) { 
        error_log($e->getMessage()); 
        exit("No fue posible conectar con la base de datos."); 
    }