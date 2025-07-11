<?php
// exportar_excel.php
require_once 'config/database.php';
require_once 'includes/functions.php';

verificarAdmin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: reportes.php');
    exit();
}

$tipoReporte = $_POST['tipo_reporte'] ?? 'general';
$fechas = $_POST['fechas'] ?? 'Sin fechas';

// Configurar headers para descarga de Excel
header('Content-Type: application/vnd.ms-excel');
header('Content-Disposition: attachment;filename="reporte_' . $tipoReporte . '_' . date('Y-m-d_H-i-s') . '.xls"');
header('Cache-Control: max-age=0');

// Obtener datos del reporte
$fechaInicio = $_GET['fecha_inicio'] ?? date('Y-m-01');
$fechaFin = $_GET['fecha_fin'] ?? date('Y-m-d');
$rutaSeleccionada = $_GET['ruta_id'] ?? '';

$db = getDB();

// Funciones de obtención de datos (reutilizadas)
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

// Obtener datos según el tipo de reporte
$datosReporte = [];
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

// Generar el archivo Excel
?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>Reporte <?php echo ucfirst($tipoReporte); ?></title>
    <style>
        table { border-collapse: collapse; width: 100%; }
        th, td { border: 1px solid #000; padding: 8px; text-align: left; }
        th { background-color: #4472C4; color: white; font-weight: bold; }
        .total-row { background-color: #D9E1F2; font-weight: bold; }
        .header { font-size: 18px; font-weight: bold; margin-bottom: 10px; }
        .info { font-size: 12px; margin-bottom: 20px; }
    </style>
</head>
<body>
    <div class="header">
        Sistema de Despacho - Reporte de <?php echo ucfirst($tipoReporte); ?>
    </div>
    <div class="info">
        <strong>Período:</strong> <?php echo $fechas; ?><br>
        <strong>Generado:</strong> <?php echo date('d/m/Y H:i:s'); ?><br>
        <strong>Usuario:</strong> <?php echo $_SESSION['nombre_completo']; ?>
    </div>
    
    <?php if (!empty($datosReporte)): ?>
        <?php if ($tipoReporte === 'ventas' || $tipoReporte === 'general'): ?>
            <!-- Tabla de Ventas -->
            <table>
                <thead>
                    <tr>
                        <th>Fecha</th>
                        <th>Ruta</th>
                        <th>Tipo Ruta</th>
                        <th>Producto</th>
                        <th>Categoría</th>
                        <th>Cantidad Vendida</th>
                        <th>Total Dinero</th>
                        <th>Despachos</th>
                    </tr>
                </thead>
                <tbody>
                    <?php 
                    $totalVendido = 0;
                    $totalDinero = 0;
                    foreach ($datosReporte as $item): 
                        $totalVendido += $item['total_vendido'];
                        $totalDinero += $item['total_dinero'];
                    ?>
                        <tr>
                            <td><?php echo date('d/m/Y', strtotime($item['fecha'])); ?></td>
                            <td><?php echo $item['ruta_nombre']; ?></td>
                            <td><?php echo $item['tipo_ruta'] === 'grupo_aje' ? 'Grupo AJE' : 'Proveedores Varios'; ?></td>
                            <td><?php echo $item['producto_nombre']; ?></td>
                            <td><?php echo $item['categoria'] === 'grupo_aje' ? 'Grupo AJE' : 'Proveedores Varios'; ?></td>
                            <td><?php echo number_format($item['total_vendido'], 2); ?></td>
                            <td><?php echo number_format($item['total_dinero'], 2); ?></td>
                            <td><?php echo $item['total_despachos']; ?></td>
                        </tr>
                    <?php endforeach; ?>
                    <tr class="total-row">
                        <td colspan="5">TOTALES</td>
                        <td><?php echo number_format($totalVendido, 2); ?></td>
                        <td><?php echo number_format($totalDinero, 2); ?></td>
                        <td>-</td>
                    </tr>
                </tbody>
            </table>
        
        <?php elseif ($tipoReporte === 'retornos'): ?>
            <!-- Tabla de Retornos -->
            <table>
                <thead>
                    <tr>
                        <th>Fecha</th>
                        <th>Ruta</th>
                        <th>Tipo Ruta</th>
                        <th>Producto</th>
                        <th>Categoría</th>
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
                    foreach ($datosReporte as $item): 
                        $totalEnviado += $item['total_enviado'];
                        $totalVendido += $item['total_vendido'];
                        $totalRetorno += $item['total_retorno'];
                    ?>
                        <tr>
                            <td><?php echo date('d/m/Y', strtotime($item['fecha'])); ?></td>
                            <td><?php echo $item['ruta_nombre']; ?></td>
                            <td><?php echo $item['tipo_ruta'] === 'grupo_aje' ? 'Grupo AJE' : 'Proveedores Varios'; ?></td>
                            <td><?php echo $item['producto_nombre']; ?></td>
                            <td><?php echo $item['categoria'] === 'grupo_aje' ? 'Grupo AJE' : 'Proveedores Varios'; ?></td>
                            <td><?php echo number_format($item['total_enviado'], 2); ?></td>
                            <td><?php echo number_format($item['total_vendido'], 2); ?></td>
                            <td><?php echo number_format($item['total_retorno'], 2); ?></td>
                            <td><?php echo number_format($item['porcentaje_venta'] ?? 0, 1); ?>%</td>
                        </tr>
                    <?php endforeach; ?>
                    <tr class="total-row">
                        <td colspan="5">TOTALES</td>
                        <td><?php echo number_format($totalEnviado, 2); ?></td>
                        <td><?php echo number_format($totalVendido, 2); ?></td>
                        <td><?php echo number_format($totalRetorno, 2); ?></td>
                        <td><?php echo $totalEnviado > 0 ? number_format(($totalVendido / $totalEnviado) * 100, 1) : 0; ?>%</td>
                    </tr>
                </tbody>
            </table>
        
        <?php elseif ($tipoReporte === 'rutas'): ?>
            <!-- Tabla por Rutas -->
            <table>
                <thead>
                    <tr>
                        <th>Ruta</th>
                        <th>Tipo</th>
                        <th>Despachos</th>
                        <th>Completados</th>
                        <th>% Completados</th>
                        <th>Unidades Vendidas</th>
                        <th>Total Dinero</th>
                        <th>Total Retorno</th>
                        <th>% Venta Promedio</th>
                    </tr>
                </thead>
                <tbody>
                    <?php 
                    $totalDespachos = 0;
                    $totalCompletados = 0;
                    $totalVendido = 0;
                    $totalDinero = 0;
                    $totalRetorno = 0;
                    foreach ($datosReporte as $item): 
                        $totalDespachos += $item['total_despachos'];
                        $totalCompletados += $item['despachos_completados'];
                        $totalVendido += $item['total_vendido'];
                        $totalDinero += $item['total_dinero'];
                        $totalRetorno += $item['total_retorno'];
                        $porcentajeCompletados = $item['total_despachos'] > 0 ? ($item['despachos_completados'] / $item['total_despachos']) * 100 : 0;
                    ?>
                        <tr>
                            <td><?php echo $item['ruta_nombre']; ?></td>
                            <td><?php echo $item['tipo_ruta'] === 'grupo_aje' ? 'Grupo AJE' : 'Proveedores Varios'; ?></td>
                            <td><?php echo $item['total_despachos']; ?></td>
                            <td><?php echo $item['despachos_completados']; ?></td>
                            <td><?php echo number_format($porcentajeCompletados, 1); ?>%</td>
                            <td><?php echo number_format($item['total_vendido'], 2); ?></td>
                            <td><?php echo number_format($item['total_dinero'], 2); ?></td>
                            <td><?php echo number_format($item['total_retorno'], 2); ?></td>
                            <td><?php echo number_format($item['porcentaje_venta_promedio'] ?? 0, 1); ?>%</td>
                        </tr>
                    <?php endforeach; ?>
                    <tr class="total-row">
                        <td colspan="2">TOTALES</td>
                        <td><?php echo $totalDespachos; ?></td>
                        <td><?php echo $totalCompletados; ?></td>
                        <td><?php echo $totalDespachos > 0 ? number_format(($totalCompletados / $totalDespachos) * 100, 1) : 0; ?>%</td>
                        <td><?php echo number_format($totalVendido, 2); ?></td>
                        <td><?php echo number_format($totalDinero, 2); ?></td>
                        <td><?php echo number_format($totalRetorno, 2); ?></td>
                        <td>-</td>
                    </tr>
                </tbody>
            </table>
        
        <?php elseif ($tipoReporte === 'productos'): ?>
            <!-- Tabla de Productos Más Vendidos -->
            <table>
                <thead>
                    <tr>
                        <th>#</th>
                        <th>Producto</th>
                        <th>Categoría</th>
                        <th>Precio Unitario</th>
                        <th>Usa Fórmula</th>
                        <th>Cantidad Vendida</th>
                        <th>Total Dinero</th>
                        <th>Rutas</th>
                        <th>Días</th>
                    </tr>
                </thead>
                <tbody>
                    <?php 
                    $contador = 1;
                    $totalVendido = 0;
                    $totalDinero = 0;
                    foreach ($datosReporte as $item): 
                        $totalVendido += $item['total_vendido'];
                        $totalDinero += $item['total_dinero'];
                    ?>
                        <tr>
                            <td><?php echo $contador++; ?></td>
                            <td><?php echo $item['producto_nombre']; ?></td>
                            <td><?php echo $item['categoria'] === 'grupo_aje' ? 'Grupo AJE' : 'Proveedores Varios'; ?></td>
                            <td><?php echo number_format($item['precio_unitario'], 2); ?></td>
                            <td><?php echo $item['usa_formula'] ? 'Sí' : 'No'; ?></td>
                            <td><?php echo number_format($item['total_vendido'], 2); ?></td>
                            <td><?php echo number_format($item['total_dinero'], 2); ?></td>
                            <td><?php echo $item['rutas_vendido']; ?></td>
                            <td><?php echo $item['dias_vendido']; ?></td>
                        </tr>
                    <?php endforeach; ?>
                    <tr class="total-row">
                        <td colspan="5">TOTALES</td>
                        <td><?php echo number_format($totalVendido, 2); ?></td>
                        <td><?php echo number_format($totalDinero, 2); ?></td>
                        <td colspan="2">-</td>
                    </tr>
                </tbody>
            </table>
        <?php endif; ?>
        
    <?php else: ?>
        <p>No se encontraron datos para el reporte solicitado.</p>
    <?php endif; ?>
    
    <br><br>
    <div class="info">
        <strong>Nota:</strong> Este reporte fue generado automáticamente por el Sistema de Despacho.
    </div>
</body>
</html> {
        $params[':ruta_id'] = $rutaId;
    }
    
    $stmt->execute($params);
    return $stmt->fetchAll();
}

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
    
    if ($rutaId)