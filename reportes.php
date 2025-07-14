<?php
// reportes.php
require_once 'config/database.php';
require_once 'includes/functions.php';

verificarAdmin();

$db = getDB();
$mensaje = '';
$tipoMensaje = '';

// Parámetros de filtros
$fechaInicio = $_GET['fecha_inicio'] ?? date('Y-m-01');
$fechaFin = $_GET['fecha_fin'] ?? date('Y-m-d');
$rutaSeleccionada = $_GET['ruta_id'] ?? '';
$tipoReporte = $_GET['tipo'] ?? 'general';

// Validar fechas
if (strtotime($fechaInicio) > strtotime($fechaFin)) {
    $fechaTemp = $fechaInicio;
    $fechaInicio = $fechaFin;
    $fechaFin = $fechaTemp;
}

// Obtener rutas para el selector
try {
    $stmt = $db->query("SELECT id, nombre FROM rutas WHERE estado = 'activo' ORDER BY nombre");
    $rutasDisponibles = $stmt->fetchAll();
} catch (PDOException $e) {
    $rutasDisponibles = [];
}

// Función para obtener datos de ventas generales - CORREGIDA
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
        GROUP BY DATE(dr.fecha_despacho), dr.ruta_id, drd.producto_id, r.nombre, r.tipo_ruta, p.nombre, p.categoria
        ORDER BY DATE(dr.fecha_despacho) DESC, r.nombre, p.nombre
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

// Función para obtener resumen de retornos - CORREGIDA
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
        GROUP BY DATE(dr.fecha_despacho), dr.ruta_id, drd.producto_id, r.nombre, r.tipo_ruta, p.nombre, p.categoria
        ORDER BY DATE(dr.fecha_despacho) DESC, r.nombre, p.nombre
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

// Función para obtener resumen por rutas - CORREGIDA
function obtenerResumenPorRutas($db, $fechaInicio, $fechaFin) {
    $sql = "
        SELECT 
            r.id,
            r.nombre as ruta_nombre,
            r.tipo_ruta,
            COUNT(DISTINCT dr.id) as total_despachos,
            COUNT(DISTINCT CASE WHEN dr.estado = 'completado' THEN dr.id END) as despachos_completados,
            COALESCE(SUM(drd.ventas_calculadas), 0) as total_vendido,
            COALESCE(SUM(drd.total_dinero), 0) as total_dinero,
            COALESCE(SUM(drd.retorno), 0) as total_retorno,
            ROUND(AVG(CASE 
                WHEN (drd.salida + drd.recarga + drd.segunda_recarga) > 0 
                THEN (drd.ventas_calculadas / (drd.salida + drd.recarga + drd.segunda_recarga)) * 100 
                ELSE 0 
            END), 2) as porcentaje_venta_promedio
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

// Función para obtener productos más vendidos - CORREGIDA
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
?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Reportes - Sistema de Despacho</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <style>
        .sidebar {
            min-height: 100vh;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            position: fixed;
            top: 0;
            left: 0;
            width: 280px;
            z-index: 1000;
            overflow-y: auto;
        }
        .sidebar .nav-link {
            color: rgba(255,255,255,0.8);
            margin: 5px 0;
            border-radius: 10px;
            padding: 12px 20px;
            transition: all 0.3s ease;
            display: flex;
            align-items: center;
        }
        .sidebar .nav-link:hover,
        .sidebar .nav-link.active {
            background: rgba(255,255,255,0.1);
            color: white;
            transform: translateX(5px);
        }
        .sidebar .nav-link i {
            width: 20px;
            text-align: center;
        }
        .content-area {
            background: #f8f9fa;
            min-height: 100vh;
            margin-left: 280px;
        }
        .navbar {
            background: white;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
            position: sticky;
            top: 0;
            z-index: 999;
        }
        .card {
            border: none;
            border-radius: 15px;
            box-shadow: 0 5px 15px rgba(0,0,0,0.1);
            margin-bottom: 20px;
        }
        
        .filters-card {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            border-radius: 15px;
            padding: 25px;
            margin-bottom: 25px;
        }
        
        .stat-card {
            border-radius: 10px;
            padding: 20px;
            color: white;
            text-align: center;
            margin-bottom: 20px;
            transition: transform 0.3s ease;
        }
        .stat-card:hover {
            transform: translateY(-5px);
        }
        
        .stat-card-1 { background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); }
        .stat-card-2 { background: linear-gradient(135deg, #f093fb 0%, #f5576c 100%); }
        .stat-card-3 { background: linear-gradient(135deg, #4facfe 0%, #00f2fe 100%); }
        .stat-card-4 { background: linear-gradient(135deg, #43e97b 0%, #38f9d7 100%); }
        
        .table th {
            background: #667eea;
            color: white;
            border: none;
            white-space: nowrap;
        }
        
        .report-tabs {
            margin-bottom: 20px;
        }
        
        .report-tabs .nav-link {
            color: #667eea;
            border-bottom: 3px solid transparent;
            font-weight: 500;
        }
        
        .report-tabs .nav-link.active {
            color: #667eea;
            border-bottom-color: #667eea;
            background: none;
        }
        
        .export-buttons {
            margin-bottom: 20px;
        }
        
        .categoria-grupo-aje { 
            border-left: 4px solid #28a745; 
            background: rgba(40, 167, 69, 0.1);
        }
        .categoria-proveedores-varios { 
            border-left: 4px solid #007bff; 
            background: rgba(0, 123, 255, 0.1);
        }
        
        /* Responsivo móvil */
        @media (max-width: 767.98px) {
            .sidebar {
                left: -280px;
                transition: left 0.3s ease;
            }
            .sidebar.show {
                left: 0;
            }
            .sidebar-backdrop {
                position: fixed;
                top: 0;
                left: 0;
                width: 100%;
                height: 100%;
                background: rgba(0,0,0,0.5);
                z-index: 999;
                display: none;
            }
            .sidebar-backdrop.show {
                display: block;
            }
            .content-area {
                margin-left: 0;
                width: 100%;
            }
            .navbar .navbar-toggler {
                border: none;
                padding: 4px 8px;
            }
            
            .filters-card {
                padding: 20px;
                margin-bottom: 20px;
            }
            
            .stat-card {
                padding: 15px;
                margin-bottom: 15px;
            }
            
            .table-responsive {
                font-size: 0.85rem;
            }
            
            .export-buttons .btn {
                margin-bottom: 10px;
                width: 100%;
            }
        }
        
        @media (max-width: 576px) {
            .p-4 {
                padding: 1rem !important;
            }
            .filters-card {
                padding: 15px;
            }
            .stat-card {
                padding: 12px;
            }
            .stat-card h4 {
                font-size: 1.2rem;
            }
            .form-control,
            .form-select {
                font-size: 16px;
            }
        }
    </style>
</head>
<body>
    <!-- Sidebar Backdrop para móvil -->
    <div class="sidebar-backdrop" id="sidebarBackdrop"></div>
    
    <!-- Sidebar -->
    <div class="sidebar" id="sidebar">
        <div class="p-3">
            <h4 class="text-center mb-4">
                <i class="fas fa-truck me-2"></i>
                Sistema Despacho
            </h4>
            <nav class="nav flex-column">
                <a class="nav-link" href="dashboard.php">
                    <i class="fas fa-home me-2"></i>Dashboard
                </a>
                <a class="nav-link" href="usuarios.php">
                    <i class="fas fa-users me-2"></i>Usuarios
                </a>
                <a class="nav-link" href="productos.php">
                    <i class="fas fa-box me-2"></i>Productos
                </a>
                <a class="nav-link" href="rutas.php">
                    <i class="fas fa-route me-2"></i>Rutas
                </a>
                <a class="nav-link" href="despachos.php">
                    <i class="fas fa-shipping-fast me-2"></i>Despachos
                </a>
                <a class="nav-link active" href="reportes.php">
                    <i class="fas fa-chart-bar me-2"></i>Reportes
                </a>
                <hr class="my-3">
                <a class="nav-link" href="logout.php">
                    <i class="fas fa-sign-out-alt me-2"></i>Cerrar Sesión
                </a>
            </nav>
        </div>
    </div>
    
    <!-- Content Area -->
    <div class="content-area">
        <!-- Top Navbar -->
        <nav class="navbar navbar-expand-lg navbar-light">
            <div class="container-fluid">
                <button class="navbar-toggler d-md-none" type="button" id="sidebarToggle">
                    <i class="fas fa-bars"></i>
                </button>
                <h5 class="navbar-brand mb-0">Reportes y Análisis</h5>
                <div class="navbar-nav ms-auto">
                    <span class="navbar-text">
                        <i class="fas fa-user me-2"></i>
                        <span class="d-none d-sm-inline"><?php echo htmlspecialchars($_SESSION['nombre_completo']); ?></span>
                    </span>
                </div>
            </div>
        </nav>
        
        <!-- Main Content -->
        <div class="container-fluid p-4">
            <?php if (!empty($mensaje)): ?>
                <?php mostrarAlerta($tipoMensaje, $mensaje); ?>
            <?php endif; ?>
            
            <!-- Filtros -->
            <div class="filters-card">
                <h5 class="mb-3">
                    <i class="fas fa-filter me-2"></i>Filtros de Reporte
                </h5>
                <form method="GET" action="">
                    <div class="row">
                        <div class="col-md-3 mb-3">
                            <label for="fecha_inicio" class="form-label">Fecha Inicio</label>
                            <input type="date" class="form-control" id="fecha_inicio" name="fecha_inicio" 
                                   value="<?php echo $fechaInicio; ?>">
                        </div>
                        <div class="col-md-3 mb-3">
                            <label for="fecha_fin" class="form-label">Fecha Fin</label>
                            <input type="date" class="form-control" id="fecha_fin" name="fecha_fin" 
                                   value="<?php echo $fechaFin; ?>">
                        </div>
                        <div class="col-md-3 mb-3">
                            <label for="tipo" class="form-label">Tipo de Reporte</label>
                            <select class="form-select" id="tipo" name="tipo">
                                <option value="general" <?php echo $tipoReporte === 'general' ? 'selected' : ''; ?>>General</option>
                                <option value="ventas" <?php echo $tipoReporte === 'ventas' ? 'selected' : ''; ?>>Ventas Detalladas</option>
                                <option value="retornos" <?php echo $tipoReporte === 'retornos' ? 'selected' : ''; ?>>Retornos</option>
                                <option value="rutas" <?php echo $tipoReporte === 'rutas' ? 'selected' : ''; ?>>Por Rutas</option>
                                <option value="productos" <?php echo $tipoReporte === 'productos' ? 'selected' : ''; ?>>Productos Más Vendidos</option>
                            </select>
                        </div>
                        <div class="col-md-3 mb-3">
                            <label for="ruta_id" class="form-label">Ruta (Opcional)</label>
                            <select class="form-select" id="ruta_id" name="ruta_id">
                                <option value="">Todas las rutas</option>
                                <?php foreach ($rutasDisponibles as $ruta): ?>
                                    <option value="<?php echo $ruta['id']; ?>" 
                                            <?php echo $rutaSeleccionada == $ruta['id'] ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($ruta['nombre']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                    <div class="row">
                        <div class="col-12">
                            <button type="submit" class="btn btn-light me-2">
                                <i class="fas fa-search me-2"></i>Generar Reporte
                            </button>
                            <button type="button" class="btn btn-outline-light" onclick="limpiarFiltros()">
                                <i class="fas fa-eraser me-2"></i>Limpiar Filtros
                            </button>
                        </div>
                    </div>
                </form>
            </div>
            <!-- Estadísticas Generales -->
            <div class="row mb-4">
                <div class="col-lg-3 col-md-6">
                    <div class="stat-card stat-card-1">
                        <i class="fas fa-shipping-fast fa-2x mb-2"></i>
                        <h4><?php echo number_format($estadisticasGenerales['total_despachos']); ?></h4>
                        <small>Total Despachos</small>
                    </div>
                </div>
                <div class="col-lg-3 col-md-6">
                    <div class="stat-card stat-card-2">
                        <i class="fas fa-check-circle fa-2x mb-2"></i>
                        <h4><?php echo number_format($estadisticasGenerales['despachos_completados']); ?></h4>
                        <small>Completados</small>
                    </div>
                </div>
                <div class="col-lg-3 col-md-6">
                    <div class="stat-card stat-card-3">
                        <i class="fas fa-boxes fa-2x mb-2"></i>
                        <h4><?php echo number_format($estadisticasGenerales['total_unidades_vendidas'], 1); ?></h4>
                        <small>Unidades Vendidas</small>
                    </div>
                </div>
                <div class="col-lg-3 col-md-6">
                    <div class="stat-card stat-card-4">
                        <i class="fas fa-dollar-sign fa-2x mb-2"></i>
                        <h4><?php echo formatearDinero($estadisticasGenerales['total_dinero_vendido']); ?></h4>
                        <small>Total Vendido</small>
                    </div>
                </div>
            </div>
            
            <!-- Botones de Exportación -->
            <div class="export-buttons d-flex flex-wrap gap-2">
                <button type="button" class="btn btn-success" onclick="exportarExcel()">
                    <i class="fas fa-file-excel me-2"></i>Exportar a Excel
                </button>
                <button type="button" class="btn btn-danger" onclick="exportarPDF()">
                    <i class="fas fa-file-pdf me-2"></i>Exportar a PDF
                </button>
                <button type="button" class="btn btn-info" onclick="imprimirReporte()">
                    <i class="fas fa-print me-2"></i>Imprimir
                </button>
            </div>
            
            <!-- Contenido del Reporte -->
            <div class="card">
                <div class="card-header bg-primary text-white">
                    <h5 class="mb-0">
                        <i class="fas fa-chart-line me-2"></i>
                        <?php 
                        $titulos = [
                            'general' => 'Reporte General de Ventas',
                            'ventas' => 'Reporte Detallado de Ventas',
                            'retornos' => 'Reporte de Retornos',
                            'rutas' => 'Reporte por Rutas',
                            'productos' => 'Productos Más Vendidos'
                        ];
                        echo $titulos[$tipoReporte];
                        ?>
                        <small class="ms-2">
                            (<?php echo date('d/m/Y', strtotime($fechaInicio)); ?> - <?php echo date('d/m/Y', strtotime($fechaFin)); ?>)
                        </small>
                    </h5>
                </div>
                <div class="card-body">
                    <?php if (!empty($datosReporte)): ?>
                        <?php if ($tipoReporte === 'ventas' || $tipoReporte === 'general'): ?>
                            <!-- Reporte de Ventas -->
                            <div class="table-responsive">
                                <table class="table table-striped table-hover" id="tablaReporte">
                                    <thead>
                                        <tr>
                                            <th>Fecha</th>
                                            <th>Ruta</th>
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
                                            <tr class="categoria-<?php echo $item['categoria']; ?>">
                                                <td><?php echo date('d/m/Y', strtotime($item['fecha'])); ?></td>
                                                <td>
                                                    <?php echo htmlspecialchars($item['ruta_nombre']); ?>
                                                    <br><small class="text-muted">
                                                        <?php echo $item['tipo_ruta'] === 'grupo_aje' ? 'Grupo AJE' : 'Proveedores Varios'; ?>
                                                    </small>
                                                </td>
                                                <td><?php echo htmlspecialchars($item['producto_nombre']); ?></td>
                                                <td>
                                                    <span class="badge bg-<?php echo $item['categoria'] === 'grupo_aje' ? 'success' : 'primary'; ?>">
                                                        <?php echo $item['categoria'] === 'grupo_aje' ? 'Grupo AJE' : 'Proveedores Varios'; ?>
                                                    </span>
                                                </td>
                                                <td><?php echo formatearNumero($item['total_vendido']); ?></td>
                                                <td><?php echo formatearDinero($item['total_dinero']); ?></td>
                                                <td><?php echo $item['total_despachos']; ?></td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                    <tfoot>
                                        <tr class="table-info">
                                            <th colspan="4">TOTALES</th>
                                            <th><?php echo formatearNumero($totalVendido); ?></th>
                                            <th><?php echo formatearDinero($totalDinero); ?></th>
                                            <th>-</th>
                                        </tr>
                                    </tfoot>
                                </table>
                            </div>
                        
                        <?php elseif ($tipoReporte === 'retornos'): ?>
                            <!-- Reporte de Retornos -->
                            <div class="table-responsive">
                                <table class="table table-striped table-hover" id="tablaReporte">
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
                                        foreach ($datosReporte as $item): 
                                            $totalEnviado += $item['total_enviado'];
                                            $totalVendido += $item['total_vendido'];
                                            $totalRetorno += $item['total_retorno'];
                                        ?>
                                            <tr class="categoria-<?php echo $item['categoria']; ?>">
                                                <td><?php echo date('d/m/Y', strtotime($item['fecha'])); ?></td>
                                                <td>
                                                    <?php echo htmlspecialchars($item['ruta_nombre']); ?>
                                                    <br><small class="text-muted">
                                                        <?php echo $item['tipo_ruta'] === 'grupo_aje' ? 'Grupo AJE' : 'Proveedores Varios'; ?>
                                                    </small>
                                                </td>
                                                <td><?php echo htmlspecialchars($item['producto_nombre']); ?></td>
                                                <td><?php echo formatearNumero($item['total_enviado']); ?></td>
                                                <td><?php echo formatearNumero($item['total_vendido']); ?></td>
                                                <td><?php echo formatearNumero($item['total_retorno']); ?></td>
                                                <td>
                                                    <?php 
                                                    $porcentaje = $item['porcentaje_venta'] ?? 0;
                                                    $colorPorcentaje = $porcentaje >= 80 ? 'success' : ($porcentaje >= 60 ? 'warning' : 'danger');
                                                    ?>
                                                    <span class="badge bg-<?php echo $colorPorcentaje; ?>">
                                                        <?php echo number_format($porcentaje, 1); ?>%
                                                    </span>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                    <tfoot>
                                        <tr class="table-info">
                                            <th colspan="3">TOTALES</th>
                                            <th><?php echo formatearNumero($totalEnviado); ?></th>
                                            <th><?php echo formatearNumero($totalVendido); ?></th>
                                            <th><?php echo formatearNumero($totalRetorno); ?></th>
                                            <th>
                                                <?php 
                                                $porcentajeTotal = $totalEnviado > 0 ? ($totalVendido / $totalEnviado) * 100 : 0;
                                                echo number_format($porcentajeTotal, 1) . '%';
                                                ?>
                                            </th>
                                        </tr>
                                    </tfoot>
                                </table>
                            </div>
                        
                        <?php elseif ($tipoReporte === 'rutas'): ?>
                            <!-- Reporte por Rutas -->
                            <div class="table-responsive">
                                <table class="table table-striped table-hover" id="tablaReporte">
                                    <thead>
                                        <tr>
                                            <th>Ruta</th>
                                            <th>Tipo</th>
                                            <th>Despachos</th>
                                            <th>Completados</th>
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
                                        ?>
                                            <tr>
                                                <td>
                                                    <strong><?php echo htmlspecialchars($item['ruta_nombre']); ?></strong>
                                                </td>
                                                <td>
                                                    <span class="badge bg-<?php echo $item['tipo_ruta'] === 'grupo_aje' ? 'success' : 'primary'; ?>">
                                                        <?php echo $item['tipo_ruta'] === 'grupo_aje' ? 'Grupo AJE' : 'Proveedores Varios'; ?>
                                                    </span>
                                                </td>
                                                <td><?php echo $item['total_despachos']; ?></td>
                                                <td>
                                                    <?php echo $item['despachos_completados']; ?>
                                                    <?php if ($item['total_despachos'] > 0): ?>
                                                        <small class="text-muted">
                                                            (<?php echo number_format(($item['despachos_completados'] / $item['total_despachos']) * 100, 1); ?>%)
                                                        </small>
                                                    <?php endif; ?>
                                                </td>
                                                <td><?php echo formatearNumero($item['total_vendido']); ?></td>
                                                <td><?php echo formatearDinero($item['total_dinero']); ?></td>
                                                <td><?php echo formatearNumero($item['total_retorno']); ?></td>
                                                <td>
                                                    <?php 
                                                    $porcentaje = $item['porcentaje_venta_promedio'] ?? 0;
                                                    $colorPorcentaje = $porcentaje >= 80 ? 'success' : ($porcentaje >= 60 ? 'warning' : 'danger');
                                                    ?>
                                                    <span class="badge bg-<?php echo $colorPorcentaje; ?>">
                                                        <?php echo number_format($porcentaje, 1); ?>%
                                                    </span>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                    <tfoot>
                                        <tr class="table-info">
                                            <th colspan="2">TOTALES</th>
                                            <th><?php echo $totalDespachos; ?></th>
                                            <th><?php echo $totalCompletados; ?></th>
                                            <th><?php echo formatearNumero($totalVendido); ?></th>
                                            <th><?php echo formatearDinero($totalDinero); ?></th>
                                            <th><?php echo formatearNumero($totalRetorno); ?></th>
                                            <th>-</th>
                                        </tr>
                                    </tfoot>
                                </table>
                            </div>
                        
                        <?php elseif ($tipoReporte === 'productos'): ?>
                            <!-- Reporte de Productos Más Vendidos -->
                            <div class="table-responsive">
                                <table class="table table-striped table-hover" id="tablaReporte">
                                    <thead>
                                        <tr>
                                            <th>#</th>
                                            <th>Producto</th>
                                            <th>Categoría</th>
                                            <th>Precio Unit.</th>
                                            <th>Fórmula</th>
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
                                            <tr class="categoria-<?php echo $item['categoria']; ?>">
                                                <td>
                                                    <span class="badge bg-primary"><?php echo $contador++; ?></span>
                                                </td>
                                                <td>
                                                    <strong><?php echo htmlspecialchars($item['producto_nombre']); ?></strong>
                                                </td>
                                                <td>
                                                    <span class="badge bg-<?php echo $item['categoria'] === 'grupo_aje' ? 'success' : 'primary'; ?>">
                                                        <?php echo $item['categoria'] === 'grupo_aje' ? 'Grupo AJE' : 'Proveedores Varios'; ?>
                                                    </span>
                                                </td>
                                                <td><?php echo formatearDinero($item['precio_unitario']); ?></td>
                                                <td>
                                                    <?php if ($item['usa_formula']): ?>
                                                        <span class="badge bg-info">Sí</span>
                                                    <?php else: ?>
                                                        <span class="badge bg-secondary">No</span>
                                                    <?php endif; ?>
                                                </td>
                                                <td><?php echo formatearNumero($item['total_vendido']); ?></td>
                                                <td><?php echo formatearDinero($item['total_dinero']); ?></td>
                                                <td><?php echo $item['rutas_vendido']; ?></td>
                                                <td><?php echo $item['dias_vendido']; ?></td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                    <tfoot>
                                        <tr class="table-info">
                                            <th colspan="5">TOTALES</th>
                                            <th><?php echo formatearNumero($totalVendido); ?></th>
                                            <th><?php echo formatearDinero($totalDinero); ?></th>
                                            <th colspan="2">-</th>
                                        </tr>
                                    </tfoot>
                                </table>
                            </div>
                        <?php endif; ?>
                        <?php else: ?>
                        <div class="text-center py-5">
                            <i class="fas fa-chart-line fa-4x text-muted mb-3"></i>
                            <h4 class="text-muted">No hay datos disponibles</h4>
                            <p class="text-muted">No se encontraron registros para los filtros seleccionados.</p>
                            <small class="text-muted">
                                Intenta cambiar el rango de fechas o seleccionar una ruta específica.
                            </small>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
            
            <!-- Gráficos y Análisis Adicionales -->
            <?php if (!empty($datosReporte) && $tipoReporte !== 'productos'): ?>
                <div class="row mt-4">
                    <div class="col-md-6">
                        <div class="card">
                            <div class="card-header bg-info text-white">
                                <h6 class="mb-0">
                                    <i class="fas fa-chart-pie me-2"></i>Distribución por Categoría
                                </h6>
                            </div>
                            <div class="card-body">
                                <canvas id="categoriasChart" width="400" height="200"></canvas>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-6">
                        <div class="card">
                            <div class="card-header bg-success text-white">
                                <h6 class="mb-0">
                                    <i class="fas fa-chart-line me-2"></i>Tendencia de Ventas
                                </h6>
                            </div>
                            <div class="card-body">
                                <canvas id="ventasChart" width="400" height="200"></canvas>
                            </div>
                        </div>
                    </div>
                </div>
            <?php endif; ?>
        </div>
    </div>
    
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <script>
        // Funcionalidad del menú móvil
        document.addEventListener('DOMContentLoaded', function() {
            const sidebarToggle = document.getElementById('sidebarToggle');
            const sidebar = document.getElementById('sidebar');
            const sidebarBackdrop = document.getElementById('sidebarBackdrop');
            
            // Abrir/cerrar sidebar en móvil
            sidebarToggle?.addEventListener('click', function() {
                sidebar.classList.toggle('show');
                sidebarBackdrop.classList.toggle('show');
            });
            
            // Cerrar sidebar al hacer clic en el backdrop
            sidebarBackdrop?.addEventListener('click', function() {
                sidebar.classList.remove('show');
                sidebarBackdrop.classList.remove('show');
            });
            
            // Cerrar sidebar al hacer clic en un enlace (móvil)
            const sidebarLinks = document.querySelectorAll('.sidebar .nav-link');
            sidebarLinks.forEach(link => {
                link.addEventListener('click', function() {
                    if (window.innerWidth < 768) {
                        sidebar.classList.remove('show');
                        sidebarBackdrop.classList.remove('show');
                    }
                });
            });
            
            // Cerrar sidebar al redimensionar a desktop
            window.addEventListener('resize', function() {
                if (window.innerWidth >= 768) {
                    sidebar.classList.remove('show');
                    sidebarBackdrop.classList.remove('show');
                }
            });
            
            // Inicializar gráficos si hay datos
            <?php if (!empty($datosReporte) && $tipoReporte !== 'productos'): ?>
                inicializarGraficos();
            <?php endif; ?>
        });
        
        // Función para limpiar filtros
        function limpiarFiltros() {
            document.getElementById('fecha_inicio').value = '<?php echo date('Y-m-01'); ?>';
            document.getElementById('fecha_fin').value = '<?php echo date('Y-m-d'); ?>';
            document.getElementById('tipo').value = 'general';
            document.getElementById('ruta_id').value = '';
        }
        
        // Funciones de exportación
        function exportarExcel() {
            const tabla = document.getElementById('tablaReporte');
            if (!tabla) {
                alert('No hay datos para exportar');
                return;
            }
            
            // Crear formulario para enviar datos
            const form = document.createElement('form');
            form.method = 'POST';
            form.action = 'exportar_excel.php';
            
            // Agregar datos del reporte
            const inputDatos = document.createElement('input');
            inputDatos.type = 'hidden';
            inputDatos.name = 'datos_reporte';
            inputDatos.value = tabla.outerHTML;
            form.appendChild(inputDatos);
            
            // Agregar tipo de reporte
            const inputTipo = document.createElement('input');
            inputTipo.type = 'hidden';
            inputTipo.name = 'tipo_reporte';
            inputTipo.value = '<?php echo $tipoReporte; ?>';
            form.appendChild(inputTipo);
            
            // Agregar fechas
            const inputFechas = document.createElement('input');
            inputFechas.type = 'hidden';
            inputFechas.name = 'fechas';
            inputFechas.value = '<?php echo $fechaInicio . ' - ' . $fechaFin; ?>';
            form.appendChild(inputFechas);
            
            document.body.appendChild(form);
            form.submit();
            document.body.removeChild(form);
        }
        
        function exportarPDF() {
            const tipoReporte = '<?php echo $tipoReporte; ?>';
            const fechaInicio = '<?php echo $fechaInicio; ?>';
            const fechaFin = '<?php echo $fechaFin; ?>';
            const rutaId = '<?php echo $rutaSeleccionada; ?>';
            
            const url = `exportar_pdf.php?tipo=${tipoReporte}&fecha_inicio=${fechaInicio}&fecha_fin=${fechaFin}&ruta_id=${rutaId}`;
            window.open(url, '_blank');
        }
        
        function imprimirReporte() {
            window.print();
        }
        
        // Inicializar gráficos
        function inicializarGraficos() {
            // Datos para los gráficos
            const datosReporte = <?php echo json_encode($datosReporte); ?>;
            
            // Gráfico de categorías
            const categoriasData = {};
            const ventasPorFecha = {};
            
            datosReporte.forEach(item => {
                // Datos por categoría
                const categoria = item.categoria === 'grupo_aje' ? 'Grupo AJE' : 'Proveedores Varios';
                if (!categoriasData[categoria]) {
                    categoriasData[categoria] = 0;
                }
                categoriasData[categoria] += parseFloat(item.total_dinero || 0);
                
                // Datos por fecha
                if (!ventasPorFecha[item.fecha]) {
                    ventasPorFecha[item.fecha] = 0;
                }
                ventasPorFecha[item.fecha] += parseFloat(item.total_dinero || 0);
            });
            
            // Gráfico de categorías (pie)
            if (document.getElementById('categoriasChart')) {
                new Chart(document.getElementById('categoriasChart'), {
                    type: 'pie',
                    data: {
                        labels: Object.keys(categoriasData),
                        datasets: [{
                            data: Object.values(categoriasData),
                            backgroundColor: ['#28a745', '#007bff'],
                            borderWidth: 2
                        }]
                    },
                    options: {
                        responsive: true,
                        plugins: {
                            legend: {
                                position: 'bottom'
                            }
                        }
                    }
                });
            }
            
            // Gráfico de tendencia (line)
            if (document.getElementById('ventasChart')) {
                const fechasOrdenadas = Object.keys(ventasPorFecha).sort();
                new Chart(document.getElementById('ventasChart'), {
                    type: 'line',
                    data: {
                        labels: fechasOrdenadas.map(fecha => {
                            const d = new Date(fecha);
                            return d.getDate() + '/' + (d.getMonth() + 1);
                        }),
                        datasets: [{
                            label: 'Ventas ($)',
                            data: fechasOrdenadas.map(fecha => ventasPorFecha[fecha]),
                            borderColor: '#28a745',
                            backgroundColor: 'rgba(40, 167, 69, 0.1)',
                            fill: true,
                            tension: 0.4
                        }]
                    },
                    options: {
                        responsive: true,
                        scales: {
                            y: {
                                beginAtZero: true,
                                ticks: {
                                    callback: function(value) {
                                        return '$' + value.toLocaleString();
                                    }
                                }
                            }
                        },
                        plugins: {
                            legend: {
                                display: false
                            }
                        }
                    }
                });
            }
        }
    </script>
</body>
</html>