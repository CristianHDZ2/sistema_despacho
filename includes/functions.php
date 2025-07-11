<?php
// includes/functions.php
session_start();

// Función para verificar si el usuario está logueado
function verificarLogin() {
    if (!isset($_SESSION['usuario_id'])) {
        header('Location: login.php');
        exit();
    }
}

// Función para verificar si el usuario es administrador
function verificarAdmin() {
    verificarLogin();
    if ($_SESSION['tipo_usuario'] !== 'admin') {
        header('Location: dashboard.php');
        exit();
    }
}

// Función para formatear DUI
function formatearDUI($dui) {
    if (strlen($dui) === 9) {
        return substr($dui, 0, 8) . '-' . substr($dui, 8);
    }
    return $dui;
}

// Función para validar DUI
function validarDUI($dui) {
    // Remover guiones y espacios
    $dui = str_replace(['-', ' '], '', $dui);
    
    // Verificar que tenga 9 dígitos
    if (strlen($dui) !== 9 || !ctype_digit($dui)) {
        return false;
    }
    
    return true;
}

// Función para mostrar mensajes de alerta
function mostrarAlerta($tipo, $mensaje) {
    $iconos = [
        'success' => 'check-circle',
        'error' => 'exclamation-triangle',
        'warning' => 'exclamation-triangle',
        'info' => 'info-circle'
    ];
    
    $colores = [
        'success' => 'success',
        'error' => 'danger',
        'warning' => 'warning',
        'info' => 'info'
    ];
    
    echo '<div class="alert alert-' . $colores[$tipo] . ' alert-dismissible fade show" role="alert">';
    echo '<i class="fas fa-' . $iconos[$tipo] . ' me-2"></i>';
    echo $mensaje;
    echo '<button type="button" class="btn-close" data-bs-dismiss="alert"></button>';
    echo '</div>';
}

// Función para formatear números
function formatearNumero($numero, $decimales = 2) {
    return number_format($numero, $decimales, '.', ',');
}

// Función para formatear dinero
function formatearDinero($cantidad) {
    return '$' . number_format($cantidad, 2, '.', ',');
}

// Función para calcular ventas con fórmula especial
function calcularVentaFormula($cantidad) {
    return (2.50 / 3) * $cantidad;
}

// Función para calcular ventas normales
function calcularVentaNormal($cantidad, $precio) {
    return $cantidad * $precio;
}

// Función para obtener el nombre del mes
function obtenerNombreMes($numero) {
    $meses = [
        1 => 'Enero', 2 => 'Febrero', 3 => 'Marzo', 4 => 'Abril',
        5 => 'Mayo', 6 => 'Junio', 7 => 'Julio', 8 => 'Agosto',
        9 => 'Septiembre', 10 => 'Octubre', 11 => 'Noviembre', 12 => 'Diciembre'
    ];
    return $meses[$numero] ?? '';
}

// Función para limpiar input
function limpiarInput($data) {
    $data = trim($data);
    $data = stripslashes($data);
    $data = htmlspecialchars($data);
    return $data;
}

// Función para obtener la fecha actual
function obtenerFechaActual() {
    return date('Y-m-d');
}

// Función para verificar si una fecha es válida
function validarFecha($fecha) {
    $d = DateTime::createFromFormat('Y-m-d', $fecha);
    return $d && $d->format('Y-m-d') === $fecha;
}

// Función para verificar si la fecha es anterior a hoy
function esFechaAnterior($fecha) {
    return strtotime($fecha) < strtotime(date('Y-m-d'));
}

// Función para verificar si la fecha es posterior a hoy
function esFechaPosterior($fecha) {
    return strtotime($fecha) > strtotime(date('Y-m-d'));
}
?>