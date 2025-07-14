<?php
// exportar_pdf.php
require_once 'config/database.php';
require_once 'includes/functions.php';

verificarAdmin();

// Verificar que sea una solicitud GET válida
if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    header('Location: reportes.php');
    exit();
}

$tipoReporte = $_GET['tipo'] ?? 'general';
$fechaInicio = $_GET['fecha_inicio'] ?? date('Y-m-01');
$fechaFin = $_GET['fecha_fin'] ?? date('Y-m-d');
$rutaSeleccionada = $_GET['ruta_id'] ?? '';

// Validar fechas
if (strtotime($fechaInicio) > strtotime($fechaFin)) {
    $fechaTemp = $fechaInicio;
    $fechaInicio = $fechaFin;
    $fechaFin = $fechaTemp;
}

$db = getDB();

// Función para obtener datos de ventas generales
function obtenerVentasGenerales($db, $fechaInicio, $fechaFin, $rutaId = null) {
    $whereRuta = $rutaId ? "AND dr.ruta_id = :ruta_id" : "";
    
    $sql = "
        SELECT 
            DATE(dr.fecha_despacho) as fecha,
            r.nombre as ruta_nombre,
            r.tipo_ruta,
            p.nombre as producto_nombre,
            p.categoria,
            SUM(drd.ventas_calculadas) as total_vendido,
            SUM(drd.total_dinero) as total_dinero,
            COUNT(DISTINCT dr.id) as total_despachos
        FROM despachos_ruta dr
        JOIN rutas r ON dr.ruta_id = r.id
        JOIN despacho_ruta_detalle drd ON dr.id = drd.despacho_ruta_id
        JOIN productos p ON drd.producto_id = p.id
        WHERE dr.fecha_despacho BETWEEN :fecha_inicio AND :fecha_fin
        AND dr.estado = 'completado'
        $whereRuta
        GROUP BY DATE(dr.fecha_despacho), dr.ruta_id, drd.producto_id
        ORDER BY dr.fecha_despacho DESC, r.nombre, p.nombre
    ";
    
    $stmt = $db->prepare($sql);
    $params = [
        ':fecha_inicio' => $fechaInicio,
        ':fecha_fin' => $fechaFin
    ];
    
    if ($rutaId) {
        $params[':ruta_id'] = $rutaId;
    }
    
    $stmt->execute($params);
    return $stmt->fetchAll();
}

// Función para obtener resumen de retornos
function obtenerRetornos($db, $fechaInicio, $fechaFin, $rutaId = null) {
    $whereRuta = $rutaId ? "AND dr.ruta_id = :ruta_id" : "";
    
    $sql = "
        SELECT 
            DATE(dr.fecha_despacho) as fecha,
            r.nombre as ruta_nombre,
            r.tipo_ruta,
            p.nombre as producto_nombre,
            p.categoria,
            SUM(drd.retorno) as total_retorno,
            SUM(drd.salida + drd.recarga + drd.segunda_recarga) as total_enviado,
            SUM(drd.ventas_calculadas) as total_vendido,
            ROUND(AVG((drd.ventas_calculadas / NULLIF(drd.salida + drd.recarga + drd.segunda_recarga, 0)) * 100), 2) as porcentaje_venta
        FROM despachos_ruta dr
        JOIN rutas r ON dr.ruta_id = r.id
        JOIN despacho_ruta_detalle drd ON dr.id = drd.despacho_ruta_id
        JOIN productos p ON drd.producto_id = p.id
        WHERE dr.fecha_despacho BETWEEN :fecha_inicio AND :fecha_fin
        AND dr.estado IN ('retorno', 'completado')
        $whereRuta
        GROUP BY DATE(dr.fecha_despacho), dr.ruta_id, drd.producto_id
        ORDER BY dr.fecha_despacho DESC, r.nombre, p.nombre
    ";
    
    $stmt = $db->prepare($sql);
    $params = [
        ':fecha_inicio' => $fechaInicio,
        ':fecha_fin' => $fechaFin
    ];
    
    if ($rutaId) {
        $params[':ruta_id'] = $rutaId;
    }
    
    $stmt->execute($params);
    return $stmt->fetchAll();
}

// Función para obtener resumen por rutas
function obtenerResumenPorRutas($db, $fechaInicio, $fechaFin) {
    $sql = "
        SELECT 
            r.id,
            r.nombre as ruta_nombre,
            r.tipo_ruta,
            COUNT(DISTINCT dr.id) as total_despachos,
            COUNT(DISTINCT CASE WHEN dr.estado = 'completado' THEN dr.id END) as despachos_completados,
            SUM(drd.ventas_calculadas) as total_vendido,
            SUM(drd.total_dinero) as total_dinero,
            SUM(drd.retorno) as total_retorno,
            ROUND(AVG((drd.ventas_calculadas / NULLIF(drd.salida + drd.recarga + drd.segunda_recarga, 0)) * 100), 2) as porcentaje_venta_promedio
        FROM rutas r
        LEFT JOIN despachos_ruta dr ON r.id = dr.ruta_id 
            AND dr.fecha_despacho BETWEEN :fecha_inicio AND :fecha_fin
        LEFT JOIN despacho_ruta_detalle drd ON dr.id = drd.despacho_ruta_id
        WHERE r.estado = 'activo'
        GROUP BY r.id, r.nombre, r.tipo_ruta
        ORDER BY total_dinero DESC, r.nombre
    ";
    
    $stmt = $db->prepare($sql);
    $stmt->execute([
        ':fecha_inicio' => $fechaInicio,
        ':fecha_fin' => $fechaFin
    ]);
    return $stmt->fetchAll();
}

// Función para obtener productos más vendidos
function obtenerProductosMasVendidos($db, $fechaInicio, $fechaFin, $rutaId = null) {
    $whereRuta = $rutaId ? "AND dr.ruta_id = :ruta_id" : "";
    
    $sql = "
        SELECT 
            p.nombre as producto_nombre,
            p.categoria,
            p.precio_unitario,
            p.usa_formula,
            SUM(drd.ventas_calculadas) as total_vendido,
            SUM(drd.total_dinero) as total_dinero,
            COUNT(DISTINCT dr.ruta_id) as rutas_vendido,
            COUNT(DISTINCT DATE(dr.fecha_despacho)) as dias_vendido
        FROM despacho_ruta_detalle drd
        JOIN despachos_ruta dr ON drd.despacho_ruta_id = dr.id
        JOIN productos p ON drd.producto_id = p.id
        WHERE dr.fecha_despacho BETWEEN :fecha_inicio AND :fecha_fin
        AND dr.estado = 'completado'
        AND drd.ventas_calculadas > 0
        $whereRuta
        GROUP BY p.id, p.nombre, p.categoria, p.precio_unitario, p.usa_formula
        ORDER BY total_vendido DESC
        LIMIT 20
    ";
    
    $stmt = $db->prepare($sql);
    $params = [
        ':fecha_inicio' => $fechaInicio,
        ':fecha_fin' => $fechaFin
    ];
    
    if ($rutaId) {
        $params[':ruta_id'] = $rutaId;
    }
    
    $stmt->execute($params);
    return $stmt->fetchAll();
}

// Función para obtener estadísticas generales del período
function obtenerEstadisticasGenerales($db, $fechaInicio, $fechaFin) {
    $sql = "
        SELECT 
            COUNT(DISTINCT dr.id) as total_despachos,
            COUNT(DISTINCT CASE WHEN dr.estado = 'completado' THEN dr.id END) as despachos_completados,
            COUNT(DISTINCT dr.ruta_id) as rutas_activas,
            COUNT(DISTINCT DATE(dr.fecha_despacho)) as dias_operacion,
            COALESCE(SUM(drd.ventas_calculadas), 0) as total_unidades_vendidas,
            COALESCE(SUM(drd.total_dinero), 0) as total_dinero_vendido,
            COALESCE(SUM(drd.retorno), 0) as total_retornos,
            COALESCE(SUM(drd.salida + drd.recarga + drd.segunda_recarga), 0) as total_enviado
        FROM despachos_ruta dr
        LEFT JOIN despacho_ruta_detalle drd ON dr.id = drd.despacho_ruta_id
        WHERE dr.fecha_despacho BETWEEN :fecha_inicio AND :fecha_fin
    ";
    
    $stmt = $db->prepare($sql);
    $stmt->execute([
        ':fecha_inicio' => $fechaInicio,
        ':fecha_fin' => $fechaFin
    ]);
    return $stmt->fetch();
}

// Obtener nombre de la ruta si está seleccionada
$nombreRuta = 'Todas las rutas';
if ($rutaSeleccionada) {
    try {
        $stmt = $db->prepare("SELECT nombre FROM rutas WHERE id = ?");
        $stmt->execute([$rutaSeleccionada]);
        $ruta = $stmt->fetch();
        if ($ruta) {
            $nombreRuta = $ruta['nombre'];
        }
    } catch (PDOException $e) {
        // Mantener el valor por defecto
    }
}

// Obtener datos según el tipo de reporte seleccionado
$datosReporte = [];
$estadisticasGenerales = obtenerEstadisticasGenerales($db, $fechaInicio, $fechaFin);

switch ($tipoReporte) {
    case 'ventas':
        $datosReporte = obtenerVentasGenerales($db, $fechaInicio, $fechaFin, $rutaSeleccionada);
        break;
    case 'retornos':
        $datosReporte = obtenerRetornos($db, $fechaInicio, $fechaFin, $rutaSeleccionada);
        break;
    case 'rutas':
        $datosReporte = obtenerResumenPorRutas($db, $fechaInicio, $fechaFin);
        break;
    case 'productos':
        $datosReporte = obtenerProductosMasVendidos($db, $fechaInicio, $fechaFin, $rutaSeleccionada);
        break;
    default:
        $datosReporte = obtenerVentasGenerales($db, $fechaInicio, $fechaFin, $rutaSeleccionada);
        break;
}

// Títulos para cada tipo de reporte
$titulos = [
    'general' => 'Reporte General de Ventas',
    'ventas' => 'Reporte Detallado de Ventas',
    'retornos' => 'Reporte de Retornos',
    'rutas' => 'Reporte por Rutas',
    'productos' => 'Productos Más Vendidos'
];

$tituloReporte = $titulos[$tipoReporte] ?? 'Reporte General';
// Configurar headers para PDF
header('Content-Type: application/pdf');
header('Content-Disposition: attachment; filename="reporte_' . $tipoReporte . '_' . date('Y-m-d_H-i-s') . '.pdf"');
header('Cache-Control: max-age=0');

// Función para escapar texto para PDF
function escaparTextoPDF($texto) {
    return htmlspecialchars($texto, ENT_QUOTES, 'UTF-8');
}

// Función para formatear fecha
function formatearFechaPDF($fecha) {
    return date('d/m/Y', strtotime($fecha));
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $tituloReporte; ?></title>
    <style>
        @page {
            margin: 1cm;
            size: A4;
        }
        
        body {
            font-family: Arial, sans-serif;
            font-size: 10px;
            line-height: 1.3;
            color: #333;
            margin: 0;
            padding: 0;
        }
        
        .header {
            text-align: center;
            margin-bottom: 20px;
            border-bottom: 2px solid #667eea;
            padding-bottom: 15px;
        }
        
        .header h1 {
            margin: 0;
            font-size: 18px;
            color: #667eea;
            font-weight: bold;
        }
        
        .header h2 {
            margin: 5px 0;
            font-size: 14px;
            color: #666;
            font-weight: normal;
        }
        
        .info-section {
            display: flex;
            justify-content: space-between;
            margin-bottom: 20px;
            background: #f8f9fa;
            padding: 10px;
            border-radius: 5px;
        }
        
        .info-left,
        .info-right {
            flex: 1;
        }
        
        .info-item {
            margin-bottom: 3px;
        }
        
        .info-label {
            font-weight: bold;
            color: #667eea;
        }
        
        .stats-section {
            margin-bottom: 20px;
            background: #e9ecef;
            padding: 10px;
            border-radius: 5px;
        }
        
        .stats-row {
            display: flex;
            justify-content: space-between;
            margin-bottom: 5px;
        }
        
        .stats-item {
            text-align: center;
            flex: 1;
            margin: 0 5px;
        }
        
        .stats-value {
            font-size: 14px;
            font-weight: bold;
            color: #667eea;
        }
        
        .stats-label {
            font-size: 8px;
            color: #666;
            text-transform: uppercase;
        }
        
        table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 20px;
            font-size: 9px;
        }
        
        table th {
            background-color: #667eea;
            color: white;
            padding: 6px 4px;
            text-align: left;
            font-weight: bold;
            border: 1px solid #555;
            font-size: 8px;
        }
        
        table td {
            padding: 4px 4px;
            border: 1px solid #ddd;
            vertical-align: top;
        }
        
        table tr:nth-child(even) {
            background-color: #f9f9f9;
        }
        
        .total-row {
            background-color: #d4edda !important;
            font-weight: bold;
        }
        
        .total-row td {
            background-color: #d4edda;
            border-color: #c3e6cb;
        }
        
        .categoria-grupo-aje {
            border-left: 3px solid #28a745;
        }
        
        .categoria-proveedores-varios {
            border-left: 3px solid #007bff;
        }
        
        .badge {
            display: inline-block;
            padding: 2px 6px;
            border-radius: 3px;
            font-size: 7px;
            font-weight: bold;
            text-transform: uppercase;
        }
        
        .badge-success {
            background-color: #28a745;
            color: white;
        }
        
        .badge-primary {
            background-color: #007bff;
            color: white;
        }
        
        .badge-warning {
            background-color: #ffc107;
            color: #212529;
        }
        
        .badge-danger {
            background-color: #dc3545;
            color: white;
        }
        
        .badge-info {
            background-color: #17a2b8;
            color: white;
        }
        
        .badge-secondary {
            background-color: #6c757d;
            color: white;
        }
        
        .text-center {
            text-align: center;
        }
        
        .text-right {
            text-align: right;
        }
        
        .footer {
            position: fixed;
            bottom: 1cm;
            left: 1cm;
            right: 1cm;
            text-align: center;
            font-size: 8px;
            color: #666;
            border-top: 1px solid #ddd;
            padding-top: 5px;
        }
        
        .page-break {
            page-break-after: always;
        }
        
        .no-data {
            text-align: center;
            padding: 40px;
            color: #666;
            font-style: italic;
        }
        
        /* Estilos específicos para cada tipo de reporte */
        .ventas-table th:nth-child(1) { width: 10%; }
        .ventas-table th:nth-child(2) { width: 20%; }
        .ventas-table th:nth-child(3) { width: 25%; }
        .ventas-table th:nth-child(4) { width: 12%; }
        .ventas-table th:nth-child(5) { width: 12%; }
        .ventas-table th:nth-child(6) { width: 12%; }
        .ventas-table th:nth-child(7) { width: 9%; }
        
        .retornos-table th:nth-child(1) { width: 12%; }
        .retornos-table th:nth-child(2) { width: 22%; }
        .retornos-table th:nth-child(3) { width: 22%; }
        .retornos-table th:nth-child(4) { width: 11%; }
        .retornos-table th:nth-child(5) { width: 11%; }
        .retornos-table th:nth-child(6) { width: 11%; }
        .retornos-table th:nth-child(7) { width: 11%; }
        
        .rutas-table th:nth-child(1) { width: 25%; }
        .rutas-table th:nth-child(2) { width: 12%; }
        .rutas-table th:nth-child(3) { width: 10%; }
        .rutas-table th:nth-child(4) { width: 10%; }
        .rutas-table th:nth-child(5) { width: 12%; }
        .rutas-table th:nth-child(6) { width: 12%; }
        .rutas-table th:nth-child(7) { width: 12%; }
        .rutas-table th:nth-child(8) { width: 7%; }
        
        .productos-table th:nth-child(1) { width: 5%; }
        .productos-table th:nth-child(2) { width: 30%; }
        .productos-table th:nth-child(3) { width: 12%; }
        .productos-table th:nth-child(4) { width: 10%; }
        .productos-table th:nth-child(5) { width: 8%; }
        .productos-table th:nth-child(6) { width: 12%; }
        .productos-table th:nth-child(7) { width: 12%; }
        .productos-table th:nth-child(8) { width: 6%; }
        .productos-table th:nth-child(9) { width: 5%; }
        
        @media print {
            body {
                print-color-adjust: exact;
                -webkit-print-color-adjust: exact;
            }
        }
    </style>
</head>
<body>
    <!-- Header -->
    <div class="header">
        <h1>Sistema de Despacho</h1>
        <h2><?php echo $tituloReporte; ?></h2>
    </div>
    
    <!-- Información del reporte -->
    <div class="info-section">
        <div class="info-left">
            <div class="info-item">
                <span class="info-label">Período:</span>
                <?php echo formatearFechaPDF($fechaInicio) . ' - ' . formatearFechaPDF($fechaFin); ?>
            </div>
            <div class="info-item">
                <span class="info-label">Ruta:</span>
                <?php echo escaparTextoPDF($nombreRuta); ?>
            </div>
            <div class="info-item">
                <span class="info-label">Tipo de Reporte:</span>
                <?php echo ucfirst($tipoReporte); ?>
            </div>
        </div>
        <div class="info-right">
            <div class="info-item">
                <span class="info-label">Generado:</span>
                <?php echo date('d/m/Y H:i:s'); ?>
            </div>
            <div class="info-item">
                <span class="info-label">Usuario:</span>
                <?php echo escaparTextoPDF($_SESSION['nombre_completo']); ?>
            </div>
            <div class="info-item">
                <span class="info-label">Total registros:</span>
                <?php echo count($datosReporte); ?>
            </div>
        </div>
    </div>
    
    <!-- Estadísticas generales -->
    <div class="stats-section">
        <div class="stats-row">
            <div class="stats-item">
                <div class="stats-value"><?php echo number_format($estadisticasGenerales['total_despachos']); ?></div>
                <div class="stats-label">Total Despachos</div>
            </div>
            <div class="stats-item">
                <div class="stats-value"><?php echo number_format($estadisticasGenerales['despachos_completados']); ?></div>
                <div class="stats-label">Completados</div>
            </div>
            <div class="stats-item">
                <div class="stats-value"><?php echo formatearNumero($estadisticasGenerales['total_unidades_vendidas'], 1); ?></div>
                <div class="stats-label">Unidades Vendidas</div>
            </div>
            <div class="stats-item">
                <div class="stats-value"><?php echo formatearDinero($estadisticasGenerales['total_dinero_vendido']); ?></div>
                <div class="stats-label">Total Vendido</div>
            </div>
        </div>
    </div>
    <!-- Contenido del reporte -->
    <?php if (!empty($datosReporte)): ?>
        
        <?php if ($tipoReporte === 'ventas' || $tipoReporte === 'general'): ?>
            <!-- Reporte de Ventas -->
            <table class="ventas-table">
                <thead>
                    <tr>
                        <th>Fecha</th>
                        <th>Ruta</th>
                        <th>Producto</th>
                        <th>Categoría</th>
                        <th>Cantidad</th>
                        <th>Total $</th>
                        <th>Desp.</th>
                    </tr>
                </thead>
                <tbody>
                    <?php 
                    $totalVendido = 0;
                    $totalDinero = 0;
                    $contador = 0;
                    foreach ($datosReporte as $item): 
                        $totalVendido += $item['total_vendido'];
                        $totalDinero += $item['total_dinero'];
                        $contador++;
                        
                        // Salto de página cada 45 filas
                        if ($contador > 0 && $contador % 45 == 0): ?>
                            </tbody>
            </table>
            <div class="page-break"></div>
            <table class="ventas-table">
                <thead>
                    <tr>
                        <th>Fecha</th>
                        <th>Ruta</th>
                        <th>Producto</th>
                        <th>Categoría</th>
                        <th>Cantidad</th>
                        <th>Total $</th>
                        <th>Desp.</th>
                    </tr>
                </thead>
                <tbody>
                        <?php endif; ?>
                        
                        <tr class="categoria-<?php echo $item['categoria']; ?>">
                            <td><?php echo formatearFechaPDF($item['fecha']); ?></td>
                            <td>
                                <?php echo escaparTextoPDF($item['ruta_nombre']); ?>
                                <br><small style="color: #666;">
                                    <?php echo $item['tipo_ruta'] === 'grupo_aje' ? 'Grupo AJE' : 'Prov. Varios'; ?>
                                </small>
                            </td>
                            <td><?php echo escaparTextoPDF($item['producto_nombre']); ?></td>
                            <td>
                                <span class="badge badge-<?php echo $item['categoria'] === 'grupo_aje' ? 'success' : 'primary'; ?>">
                                    <?php echo $item['categoria'] === 'grupo_aje' ? 'AJE' : 'Varios'; ?>
                                </span>
                            </td>
                            <td class="text-right"><?php echo formatearNumero($item['total_vendido']); ?></td>
                            <td class="text-right"><?php echo formatearDinero($item['total_dinero']); ?></td>
                            <td class="text-center"><?php echo $item['total_despachos']; ?></td>
                        </tr>
                    <?php endforeach; ?>
                    <tr class="total-row">
                        <td colspan="4"><strong>TOTALES</strong></td>
                        <td class="text-right"><strong><?php echo formatearNumero($totalVendido); ?></strong></td>
                        <td class="text-right"><strong><?php echo formatearDinero($totalDinero); ?></strong></td>
                        <td class="text-center"><strong>-</strong></td>
                    </tr>
                </tbody>
            </table>
        
        <?php elseif ($tipoReporte === 'retornos'): ?>
            <!-- Reporte de Retornos -->
            <table class="retornos-table">
                <thead>
                    <tr>
                        <th>Fecha</th>
                        <th>Ruta</th>
                        <th>Producto</th>
                        <th>Enviado</th>
                        <th>Vendido</th>
                        <th>Retorno</th>
                        <th>% Venta</th>
                    </tr>
                </thead>
                <tbody>
                    <?php 
                    $totalEnviado = 0;
                    $totalVendido = 0;
                    $totalRetorno = 0;
                    $contador = 0;
                    foreach ($datosReporte as $item): 
                        $totalEnviado += $item['total_enviado'];
                        $totalVendido += $item['total_vendido'];
                        $totalRetorno += $item['total_retorno'];
                        $contador++;
                        
                        // Salto de página cada 45 filas
                        if ($contador > 0 && $contador % 45 == 0): ?>
                            </tbody>
            </table>
            <div class="page-break"></div>
            <table class="retornos-table">
                <thead>
                    <tr>
                        <th>Fecha</th>
                        <th>Ruta</th>
                        <th>Producto</th>
                        <th>Enviado</th>
                        <th>Vendido</th>
                        <th>Retorno</th>
                        <th>% Venta</th>
                    </tr>
                </thead>
                <tbody>
                        <?php endif; ?>
                        
                        <tr class="categoria-<?php echo $item['categoria']; ?>">
                            <td><?php echo formatearFechaPDF($item['fecha']); ?></td>
                            <td>
                                <?php echo escaparTextoPDF($item['ruta_nombre']); ?>
                                <br><small style="color: #666;">
                                    <?php echo $item['tipo_ruta'] === 'grupo_aje' ? 'Grupo AJE' : 'Prov. Varios'; ?>
                                </small>
                            </td>
                            <td><?php echo escaparTextoPDF($item['producto_nombre']); ?></td>
                            <td class="text-right"><?php echo formatearNumero($item['total_enviado']); ?></td>
                            <td class="text-right"><?php echo formatearNumero($item['total_vendido']); ?></td>
                            <td class="text-right"><?php echo formatearNumero($item['total_retorno']); ?></td>
                            <td class="text-center">
                                <?php 
                                $porcentaje = $item['porcentaje_venta'] ?? 0;
                                $colorPorcentaje = $porcentaje >= 80 ? 'success' : ($porcentaje >= 60 ? 'warning' : 'danger');
                                ?>
                                <span class="badge badge-<?php echo $colorPorcentaje; ?>">
                                    <?php echo number_format($porcentaje, 1); ?>%
                                </span>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    <tr class="total-row">
                        <td colspan="3"><strong>TOTALES</strong></td>
                        <td class="text-right"><strong><?php echo formatearNumero($totalEnviado); ?></strong></td>
                        <td class="text-right"><strong><?php echo formatearNumero($totalVendido); ?></strong></td>
                        <td class="text-right"><strong><?php echo formatearNumero($totalRetorno); ?></strong></td>
                        <td class="text-center">
                            <strong>
                                <?php 
                                $porcentajeTotal = $totalEnviado > 0 ? ($totalVendido / $totalEnviado) * 100 : 0;
                                echo number_format($porcentajeTotal, 1) . '%';
                                ?>
                            </strong>
                        </td>
                    </tr>
                </tbody>
            </table>
        
        <?php elseif ($tipoReporte === 'rutas'): ?>
            <!-- Reporte por Rutas -->
            <table class="rutas-table">
                <thead>
                    <tr>
                        <th>Ruta</th>
                        <th>Tipo</th>
                        <th>Desp.</th>
                        <th>Compl.</th>
                        <th>Unidades</th>
                        <th>Total $</th>
                        <th>Retorno</th>
                        <th>% Venta</th>
                    </tr>
                </thead>
                <tbody>
                    <?php 
                    $totalDespachos = 0;
                    $totalCompletados = 0;
                    $totalVendido = 0;
                    $totalDinero = 0;
                    $totalRetorno = 0;
                    $contador = 0;
                    foreach ($datosReporte as $item): 
                        $totalDespachos += $item['total_despachos'];
                        $totalCompletados += $item['despachos_completados'];
                        $totalVendido += $item['total_vendido'];
                        $totalDinero += $item['total_dinero'];
                        $totalRetorno += $item['total_retorno'];
                        $contador++;
                        
                        // Salto de página cada 45 filas
                        if ($contador > 0 && $contador % 45 == 0): ?>
                            </tbody>
            </table>
            <div class="page-break"></div>
            <table class="rutas-table">
                <thead>
                    <tr>
                        <th>Ruta</th>
                        <th>Tipo</th>
                        <th>Desp.</th>
                        <th>Compl.</th>
                        <th>Unidades</th>
                        <th>Total $</th>
                        <th>Retorno</th>
                        <th>% Venta</th>
                    </tr>
                </thead>
                <tbody>
                        <?php endif; ?>
                        
                        <tr>
                            <td><strong><?php echo escaparTextoPDF($item['ruta_nombre']); ?></strong></td>
                            <td>
                                <span class="badge badge-<?php echo $item['tipo_ruta'] === 'grupo_aje' ? 'success' : 'primary'; ?>">
                                    <?php echo $item['tipo_ruta'] === 'grupo_aje' ? 'AJE' : 'Varios'; ?>
                                </span>
                            </td>
                            <td class="text-center"><?php echo $item['total_despachos']; ?></td>
                            <td class="text-center">
                                <?php echo $item['despachos_completados']; ?>
                                <?php if ($item['total_despachos'] > 0): ?>
                                    <br><small style="color: #666;">
                                        (<?php echo number_format(($item['despachos_completados'] / $item['total_despachos']) * 100, 1); ?>%)
                                    </small>
                                <?php endif; ?>
                            </td>
                            <td class="text-right"><?php echo formatearNumero($item['total_vendido']); ?></td>
                            <td class="text-right"><?php echo formatearDinero($item['total_dinero']); ?></td>
                            <td class="text-right"><?php echo formatearNumero($item['total_retorno']); ?></td>
                            <td class="text-center">
                                <?php 
                                $porcentaje = $item['porcentaje_venta_promedio'] ?? 0;
                                $colorPorcentaje = $porcentaje >= 80 ? 'success' : ($porcentaje >= 60 ? 'warning' : 'danger');
                                ?>
                                <span class="badge badge-<?php echo $colorPorcentaje; ?>">
                                    <?php echo number_format($porcentaje, 1); ?>%
                                </span>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    <tr class="total-row">
                        <td colspan="2"><strong>TOTALES</strong></td>
                        <td class="text-center"><strong><?php echo $totalDespachos; ?></strong></td>
                        <td class="text-center"><strong><?php echo $totalCompletados; ?></strong></td>
                        <td class="text-right"><strong><?php echo formatearNumero($totalVendido); ?></strong></td>
                        <td class="text-right"><strong><?php echo formatearDinero($totalDinero); ?></strong></td>
                        <td class="text-right"><strong><?php echo formatearNumero($totalRetorno); ?></strong></td>
                        <td class="text-center"><strong>-</strong></td>
                    </tr>
                </tbody>
            </table>
        
        <?php elseif ($tipoReporte === 'productos'): ?>
            <!-- Reporte de Productos Más Vendidos -->
            <table class="productos-table">
                <thead>
                    <tr>
                        <th>#</th>
                        <th>Producto</th>
                        <th>Categoría</th>
                        <th>Precio</th>
                        <th>Fórm.</th>
                        <th>Cantidad</th>
                        <th>Total $</th>
                        <th>Rutas</th>
                        <th>Días</th>
                    </tr>
                </thead>
                <tbody>
                    <?php 
                    $contadorProducto = 1;
                    $totalVendido = 0;
                    $totalDinero = 0;
                    $contador = 0;
                    foreach ($datosReporte as $item): 
                        $totalVendido += $item['total_vendido'];
                        $totalDinero += $item['total_dinero'];
                        $contador++;
                        
                        // Salto de página cada 40 filas (menos porque tiene más columnas)
                        if ($contador > 0 && $contador % 40 == 0): ?>
                            </tbody>
            </table>
            <div class="page-break"></div>
            <table class="productos-table">
                <thead>
                    <tr>
                        <th>#</th>
                        <th>Producto</th>
                        <th>Categoría</th>
                        <th>Precio</th>
                        <th>Fórm.</th>
                        <th>Cantidad</th>
                        <th>Total $</th>
                        <th>Rutas</th>
                        <th>Días</th>
                    </tr>
                </thead>
                <tbody>
                        <?php endif; ?>
                        
                        <tr class="categoria-<?php echo $item['categoria']; ?>">
                            <td class="text-center">
                                <span class="badge badge-primary"><?php echo $contadorProducto++; ?></span>
                            </td>
                            <td><strong><?php echo escaparTextoPDF($item['producto_nombre']); ?></strong></td>
                            <td>
                                <span class="badge badge-<?php echo $item['categoria'] === 'grupo_aje' ? 'success' : 'primary'; ?>">
                                    <?php echo $item['categoria'] === 'grupo_aje' ? 'AJE' : 'Varios'; ?>
                                </span>
                            </td>
                            <td class="text-right"><?php echo formatearDinero($item['precio_unitario']); ?></td>
                            <td class="text-center">
                                <?php if ($item['usa_formula']): ?>
                                    <span class="badge badge-info">Sí</span>
                                <?php else: ?>
                                    <span class="badge badge-secondary">No</span>
                                <?php endif; ?>
                            </td>
                            <td class="text-right"><?php echo formatearNumero($item['total_vendido']); ?></td>
                            <td class="text-right"><?php echo formatearDinero($item['total_dinero']); ?></td>
                            <td class="text-center"><?php echo $item['rutas_vendido']; ?></td>
                            <td class="text-center"><?php echo $item['dias_vendido']; ?></td>
                        </tr>
                    <?php endforeach; ?>
                    <tr class="total-row">
                        <td colspan="5"><strong>TOTALES</strong></td>
                        <td class="text-right"><strong><?php echo formatearNumero($totalVendido); ?></strong></td>
                        <td class="text-right"><strong><?php echo formatearDinero($totalDinero); ?></strong></td>
                        <td colspan="2" class="text-center"><strong>-</strong></td>
                    </tr>
                </tbody>
            </table>
        <?php endif; ?>
        
    <?php else: ?>
        <div class="no-data">
            <h3>No hay datos disponibles</h3>
            <p>No se encontraron registros para los filtros seleccionados en el período especificado.</p>
            <p>Período: <?php echo formatearFechaPDF($fechaInicio) . ' - ' . formatearFechaPDF($fechaFin); ?></p>
            <p>Ruta: <?php echo escaparTextoPDF($nombreRuta); ?></p>
        </div>
    <?php endif; ?>
    
    <!-- Información adicional del reporte -->
    <div style="margin-top: 30px; padding: 15px; background: #f8f9fa; border-radius: 5px; border: 1px solid #dee2e6;">
        <h4 style="margin: 0 0 10px 0; color: #667eea; font-size: 12px;">Información del Sistema</h4>
        <div style="display: flex; justify-content: space-between; font-size: 9px; color: #666;">
            <div>
                <strong>Sistema de Despacho v1.0</strong><br>
                Reporte generado automáticamente<br>
                Datos actualizados al: <?php echo date('d/m/Y H:i:s'); ?>
            </div>
            <div style="text-align: right;">
                <strong>Usuario:</strong> <?php echo escaparTextoPDF($_SESSION['nombre_completo']); ?><br>
                <strong>Tipo:</strong> <?php echo ucfirst($_SESSION['tipo_usuario']); ?><br>
                <strong>Registros:</strong> <?php echo count($datosReporte); ?> filas
            </div>
        </div>
    </div>
    
    <!-- Leyenda de categorías -->
    <?php if (!empty($datosReporte) && ($tipoReporte === 'ventas' || $tipoReporte === 'general' || $tipoReporte === 'retornos' || $tipoReporte === 'productos')): ?>
        <div style="margin-top: 15px; padding: 10px; background: #fff; border: 1px solid #dee2e6; border-radius: 5px;">
            <h4 style="margin: 0 0 8px 0; color: #667eea; font-size: 10px;">Leyenda de Categorías</h4>
            <div style="display: flex; gap: 20px; font-size: 8px;">
                <div style="display: flex; align-items: center;">
                    <div style="width: 12px; height: 12px; background: #28a745; margin-right: 5px; border-radius: 2px;"></div>
                    <span>Grupo AJE - Productos exclusivos del Grupo AJE</span>
                </div>
                <div style="display: flex; align-items: center;">
                    <div style="width: 12px; height: 12px; background: #007bff; margin-right: 5px; border-radius: 2px;"></div>
                    <span>Proveedores Varios - Todos los demás productos</span>
                </div>
            </div>
        </div>
    <?php endif; ?>
    
    <!-- Footer -->
    <div class="footer">
        <p>
            <strong>Sistema de Despacho</strong> | 
            Reporte generado el <?php echo date('d/m/Y H:i:s'); ?> por <?php echo escaparTextoPDF($_SESSION['nombre_completo']); ?>
        </p>
        <p>
            <em>Este reporte contiene información confidencial de la empresa. Distribución restringida.</em>
        </p>
    </div>
    
    <script>
        // Auto-imprimir el PDF cuando se carga la página
        window.onload = function() {
            // Pequeño delay para asegurar que todo esté cargado
            setTimeout(function() {
                window.print();
            }, 500);
        };
        
        // Manejar el evento después de imprimir
        window.onafterprint = function() {
            // Opcional: cerrar la ventana después de imprimir
            // window.close();
        };
    </script>
</body>
</html>