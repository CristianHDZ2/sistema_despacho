<?php
// despacho_ruta.php
require_once 'config/database.php';
require_once 'includes/functions.php';

verificarLogin();

$db = getDB();
$mensaje = '';
$tipoMensaje = '';

// Parámetros de entrada
$despacho_id = $_GET['id'] ?? null;
$ruta_id = $_GET['ruta_id'] ?? null;
$fecha = $_GET['fecha'] ?? date('Y-m-d');
$solo_ver = $_GET['ver'] ?? false;

// Validaciones iniciales
if (!$despacho_id && !$ruta_id) {
    header('Location: despachos.php');
    exit();
}

// Obtener información del despacho o crear nuevo
$despacho = null;
$ruta = null;

if ($despacho_id) {
    // Obtener despacho existente
    try {
        $stmt = $db->prepare("
            SELECT dr.*, r.nombre as ruta_nombre, r.tipo_ruta, r.direccion
            FROM despachos_ruta dr
            JOIN rutas r ON dr.ruta_id = r.id
            WHERE dr.id = ?
        ");
        $stmt->execute([$despacho_id]);
        $despacho = $stmt->fetch();
        
        if (!$despacho) {
            $mensaje = 'Despacho no encontrado';
            $tipoMensaje = 'error';
            header('Location: despachos.php');
            exit();
        }
        
        $ruta_id = $despacho['ruta_id'];
        $fecha = $despacho['fecha_despacho'];
    } catch (PDOException $e) {
        $mensaje = 'Error al obtener despacho: ' . $e->getMessage();
        $tipoMensaje = 'error';
    }
} else {
    // Verificar que no exista ya un despacho para esta ruta y fecha
    try {
        $stmt = $db->prepare("
            SELECT id FROM despachos_ruta 
            WHERE ruta_id = ? AND fecha_despacho = ?
        ");
        $stmt->execute([$ruta_id, $fecha]);
        $existente = $stmt->fetch();
        
        if ($existente) {
            header('Location: despacho_ruta.php?id=' . $existente['id']);
            exit();
        }
        
        // Obtener información de la ruta
        $stmt = $db->prepare("SELECT * FROM rutas WHERE id = ? AND estado = 'activo'");
        $stmt->execute([$ruta_id]);
        $ruta = $stmt->fetch();
        
        if (!$ruta) {
            $mensaje = 'Ruta no encontrada o inactiva';
            $tipoMensaje = 'error';
            header('Location: despachos.php');
            exit();
        }
    } catch (PDOException $e) {
        $mensaje = 'Error al verificar ruta: ' . $e->getMessage();
        $tipoMensaje = 'error';
    }
}

// Validaciones de fecha y permisos
$puedeEditar = !$solo_ver && 
               ($despacho ? $despacho['estado'] !== 'completado' : true) && 
               !esFechaAnterior($fecha) &&
               (!esFechaPosterior($fecha) || !$despacho);

// Obtener productos disponibles para la ruta
$productos = [];
try {
    $whereCategoria = '';
    if (($despacho['tipo_ruta'] ?? $ruta['tipo_ruta']) === 'grupo_aje') {
        $whereCategoria = " AND categoria = 'grupo_aje'";
    }
    
    $stmt = $db->query("
        SELECT * FROM productos 
        WHERE estado = 'activo' $whereCategoria
        ORDER BY categoria, nombre
    ");
    $productos = $stmt->fetchAll();
} catch (PDOException $e) {
    $productos = [];
}

// Obtener detalles del despacho
$detalles = [];
if ($despacho) {
    try {
        $stmt = $db->prepare("
            SELECT drd.*, p.nombre as producto_nombre, p.categoria, p.precio_unitario, p.usa_formula, p.stock_actual
            FROM despacho_ruta_detalle drd
            JOIN productos p ON drd.producto_id = p.id
            WHERE drd.despacho_ruta_id = ?
            ORDER BY p.nombre
        ");
        $stmt->execute([$despacho['id']]);
        $detalles = $stmt->fetchAll();
    } catch (PDOException $e) {
        $detalles = [];
    }
}

// Procesar formulario
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $puedeEditar) {
    $accion = $_POST['accion'] ?? '';
    
    if ($accion === 'crear_despacho') {
        // Crear nuevo despacho
        try {
            $stmt = $db->prepare("
                INSERT INTO despachos_ruta (fecha_despacho, ruta_id, usuario_id, estado) 
                VALUES (?, ?, ?, 'salida')
            ");
            
            if ($stmt->execute([$fecha, $ruta_id, $_SESSION['usuario_id']])) {
                $despacho_id = $db->lastInsertId();
                
                // Registrar en historial
                $stmt = $db->prepare("
                    INSERT INTO despachos_historial (despacho_ruta_id, accion, usuario_id, detalles)
                    VALUES (?, 'crear', ?, 'Despacho creado')
                ");
                $stmt->execute([$despacho_id, $_SESSION['usuario_id']]);
                
                header('Location: despacho_ruta.php?id=' . $despacho_id);
                exit();
            }
        } catch (PDOException $e) {
            $mensaje = 'Error al crear despacho: ' . $e->getMessage();
            $tipoMensaje = 'error';
        }
    }
    
    elseif ($accion === 'guardar_productos') {
        $evento = $_POST['evento'];
        $productos_data = $_POST['productos'] ?? [];
        
        try {
            $db->beginTransaction();
            
            $productosGuardados = 0;
            
            foreach ($productos_data as $producto_id => $cantidad) {
                $cantidad = (float)$cantidad;
                
                if ($cantidad > 0) {
                    // Verificar si ya existe el registro
                    $stmt = $db->prepare("
                        SELECT id FROM despacho_ruta_detalle 
                        WHERE despacho_ruta_id = ? AND producto_id = ?
                    ");
                    $stmt->execute([$despacho['id'], $producto_id]);
                    $detalle_existente = $stmt->fetch();
                    
                    if ($detalle_existente) {
                        // Actualizar registro existente
                        $stmt = $db->prepare("
                            UPDATE despacho_ruta_detalle 
                            SET $evento = ? 
                            WHERE id = ?
                        ");
                        $stmt->execute([$cantidad, $detalle_existente['id']]);
                    } else {
                        // Crear nuevo registro
                        $stmt = $db->prepare("
                            INSERT INTO despacho_ruta_detalle (despacho_ruta_id, producto_id, $evento) 
                            VALUES (?, ?, ?)
                        ");
                        $stmt->execute([$despacho['id'], $producto_id, $cantidad]);
                    }
                    
                    $productosGuardados++;
                }
            }
            
            if ($productosGuardados > 0) {
                // Actualizar estado del despacho
                $estados = [
                    'salida' => 'recarga', 
                    'recarga' => 'segunda_recarga', 
                    'segunda_recarga' => 'retorno'
                ];
                
                if (isset($estados[$evento])) {
                    $stmt = $db->prepare("UPDATE despachos_ruta SET estado = ? WHERE id = ?");
                    $stmt->execute([$estados[$evento], $despacho['id']]);
                } elseif ($evento === 'retorno') {
                    // Calcular ventas y preparar para liquidación
                    $stmt = $db->prepare("
                        UPDATE despacho_ruta_detalle 
                        SET ventas_calculadas = (salida + recarga + segunda_recarga - retorno)
                        WHERE despacho_ruta_id = ?
                    ");
                    $stmt->execute([$despacho['id']]);
                    
                    $stmt = $db->prepare("UPDATE despachos_ruta SET estado = 'retorno' WHERE id = ?");
                    $stmt->execute([$despacho['id']]);
                }
                
                // Registrar en historial
                $stmt = $db->prepare("
                    INSERT INTO despachos_historial (despacho_ruta_id, accion, usuario_id, detalles)
                    VALUES (?, 'editar', ?, ?)
                ");
                $stmt->execute([$despacho['id'], $_SESSION['usuario_id'], "Registrado $evento con $productosGuardados productos"]);
                
                $db->commit();
                $mensaje = ucfirst($evento) . ' registrado exitosamente (' . $productosGuardados . ' productos)';
                $tipoMensaje = 'success';
                
                // Recargar despacho
                $stmt = $db->prepare("
                    SELECT dr.*, r.nombre as ruta_nombre, r.tipo_ruta, r.direccion
                    FROM despachos_ruta dr
                    JOIN rutas r ON dr.ruta_id = r.id
                    WHERE dr.id = ?
                ");
                $stmt->execute([$despacho['id']]);
                $despacho = $stmt->fetch();
                
                // Recargar detalles
                $stmt = $db->prepare("
                    SELECT drd.*, p.nombre as producto_nombre, p.categoria, p.precio_unitario, p.usa_formula, p.stock_actual
                    FROM despacho_ruta_detalle drd
                    JOIN productos p ON drd.producto_id = p.id
                    WHERE drd.despacho_ruta_id = ?
                    ORDER BY p.nombre
                ");
                $stmt->execute([$despacho['id']]);
                $detalles = $stmt->fetchAll();
                
            } else {
                $db->rollBack();
                $mensaje = 'Debe ingresar al menos un producto con cantidad mayor a 0';
                $tipoMensaje = 'error';
            }
            
        } catch (PDOException $e) {
            $db->rollBack();
            $mensaje = 'Error al guardar productos: ' . $e->getMessage();
            $tipoMensaje = 'error';
        }
    }
    elseif ($accion === 'liquidar_despacho') {
        // Procesar liquidación con precios especiales
        $precios_especiales = $_POST['precios_especiales'] ?? [];
        
        try {
            $db->beginTransaction();
            
            // Limpiar precios especiales anteriores
            $stmt = $db->prepare("
                DELETE vpe FROM ventas_precios_especiales vpe
                JOIN despacho_ruta_detalle drd ON vpe.despacho_ruta_detalle_id = drd.id
                WHERE drd.despacho_ruta_id = ?
            ");
            $stmt->execute([$despacho['id']]);
            
            // Procesar cada detalle
            foreach ($detalles as $detalle) {
                $ventas = $detalle['ventas_calculadas'];
                $total_dinero = 0;
                
                // Verificar si hay precios especiales para este producto
                $precio_especial_key = $detalle['producto_id'];
                if (isset($precios_especiales[$precio_especial_key]) && !empty($precios_especiales[$precio_especial_key])) {
                    $precios_data = $precios_especiales[$precio_especial_key];
                    $cantidad_especial_total = 0;
                    
                    // Guardar precios especiales
                    foreach ($precios_data['cantidad'] as $index => $cantidad_esp) {
                        $cantidad_esp = (float)$cantidad_esp;
                        $precio_esp = (float)$precios_data['precio'][$index];
                        
                        if ($cantidad_esp > 0 && $precio_esp > 0) {
                            $subtotal_esp = $cantidad_esp * $precio_esp;
                            
                            $stmt = $db->prepare("
                                INSERT INTO ventas_precios_especiales (despacho_ruta_detalle_id, cantidad, precio_venta, subtotal)
                                VALUES (?, ?, ?, ?)
                            ");
                            $stmt->execute([$detalle['id'], $cantidad_esp, $precio_esp, $subtotal_esp]);
                            
                            $total_dinero += $subtotal_esp;
                            $cantidad_especial_total += $cantidad_esp;
                        }
                    }
                    
                    // Calcular resto con precio normal
                    $cantidad_normal = $ventas - $cantidad_especial_total;
                    if ($cantidad_normal > 0) {
                        if ($detalle['usa_formula']) {
                            $total_dinero += calcularVentaFormula($cantidad_normal);
                        } else {
                            $total_dinero += calcularVentaNormal($cantidad_normal, $detalle['precio_unitario']);
                        }
                    }
                } else {
                    // Sin precios especiales, usar precio normal
                    if ($detalle['usa_formula']) {
                        $total_dinero = calcularVentaFormula($ventas);
                    } else {
                        $total_dinero = calcularVentaNormal($ventas, $detalle['precio_unitario']);
                    }
                }
                
                // Actualizar total de dinero
                $stmt = $db->prepare("
                    UPDATE despacho_ruta_detalle 
                    SET total_dinero = ? 
                    WHERE id = ?
                ");
                $stmt->execute([$total_dinero, $detalle['id']]);
            }
            
            // Marcar como completado
            $stmt = $db->prepare("UPDATE despachos_ruta SET estado = 'completado' WHERE id = ?");
            $stmt->execute([$despacho['id']]);
            
            // Registrar en historial
            $stmt = $db->prepare("
                INSERT INTO despachos_historial (despacho_ruta_id, accion, usuario_id, detalles)
                VALUES (?, 'completar', ?, 'Despacho liquidado y completado')
            ");
            $stmt->execute([$despacho['id'], $_SESSION['usuario_id']]);
            
            $db->commit();
            $mensaje = 'Despacho liquidado y completado exitosamente';
            $tipoMensaje = 'success';
            
            // Recargar datos
            $stmt = $db->prepare("
                SELECT dr.*, r.nombre as ruta_nombre, r.tipo_ruta, r.direccion
                FROM despachos_ruta dr
                JOIN rutas r ON dr.ruta_id = r.id
                WHERE dr.id = ?
            ");
            $stmt->execute([$despacho['id']]);
            $despacho = $stmt->fetch();
            
        } catch (PDOException $e) {
            $db->rollBack();
            $mensaje = 'Error en la liquidación: ' . $e->getMessage();
            $tipoMensaje = 'error';
        }
    }
}

// Determinar evento actual
$eventoActual = '';
$tituloEvento = '';
if ($despacho) {
    switch ($despacho['estado']) {
        case 'salida':
            $eventoActual = 'recarga';
            $tituloEvento = 'Registrar Recarga';
            break;
        case 'recarga':
            $eventoActual = 'segunda_recarga';
            $tituloEvento = 'Registrar Segunda Recarga';
            break;
        case 'segunda_recarga':
            $eventoActual = 'retorno';
            $tituloEvento = 'Registrar Retorno';
            break;
        case 'retorno':
            $eventoActual = 'liquidacion';
            $tituloEvento = 'Completar Liquidación';
            break;
        case 'completado':
            $eventoActual = 'completado';
            $tituloEvento = 'Despacho Completado';
            break;
    }
} else {
    $eventoActual = 'salida';
    $tituloEvento = 'Registrar Salida de la Mañana';
}
?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $tituloEvento; ?> - Sistema de Despacho</title>
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
        
        .progress-steps {
            display: flex;
            justify-content: space-between;
            margin-bottom: 30px;
            position: relative;
        }
        .progress-step {
            flex: 1;
            text-align: center;
            position: relative;
        }
        .progress-step::after {
            content: '';
            position: absolute;
            top: 20px;
            right: -50%;
            width: 100%;
            height: 3px;
            background: #dee2e6;
            z-index: 1;
        }
        .progress-step:last-child::after {
            display: none;
        }
        .progress-step.completed::after {
            background: #28a745;
        }
        .step-circle {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            background: #dee2e6;
            color: #6c757d;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 10px;
            font-weight: bold;
            position: relative;
            z-index: 2;
        }
        .progress-step.completed .step-circle {
            background: #28a745;
            color: white;
        }
        .progress-step.active .step-circle {
            background: #007bff;
            color: white;
        }
        
        .producto-card {
            border: 1px solid #dee2e6;
            border-radius: 10px;
            padding: 15px;
            margin-bottom: 15px;
            transition: all 0.3s ease;
        }
        .producto-card:hover {
            box-shadow: 0 5px 15px rgba(0,0,0,0.1);
        }
        .producto-grupo-aje { border-left: 4px solid #28a745; }
        .producto-proveedores-varios { border-left: 4px solid #007bff; }
        
        .stock-info {
            font-size: 0.85rem;
            color: #6c757d;
        }
        .stock-bajo {
            color: #dc3545;
            font-weight: bold;
        }
        
        .filtros-productos {
            background: #f8f9fa;
            border-radius: 10px;
            padding: 20px;
            margin-bottom: 20px;
        }
        
        .precio-especial-row {
            background: #fff3cd;
            border-radius: 5px;
            padding: 10px;
            margin-bottom: 10px;
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
            
            .progress-steps {
                flex-direction: column;
                gap: 10px;
            }
            .progress-step::after {
                display: none;
            }
            .step-circle {
                width: 35px;
                height: 35px;
                font-size: 14px;
            }
            
            .producto-card {
                padding: 12px;
            }
            .filtros-productos {
                padding: 15px;
            }
        }
        
        /* Tablet */
        @media (min-width: 768px) and (max-width: 991.98px) {
            .sidebar {
                width: 250px;
            }
            .content-area {
                margin-left: 250px;
            }
        }
        
        /* Móvil - Ajustes adicionales */
        @media (max-width: 576px) {
            .p-4 {
                padding: 1rem !important;
            }
            .navbar {
                padding: 0.5rem 1rem;
            }
            .navbar-brand {
                font-size: 1rem;
            }
            .card-body {
                padding: 1rem;
            }
            .btn {
                font-size: 0.9rem;
                padding: 6px 12px;
            }
            .form-control {
                font-size: 16px; /* Evita zoom en iOS */
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
                <?php if ($_SESSION['tipo_usuario'] === 'admin'): ?>
                <a class="nav-link" href="usuarios.php">
                    <i class="fas fa-users me-2"></i>Usuarios
                </a>
                <a class="nav-link" href="productos.php">
                    <i class="fas fa-box me-2"></i>Productos
                </a>
                <a class="nav-link" href="rutas.php">
                    <i class="fas fa-route me-2"></i>Rutas
                </a>
                <?php endif; ?>
                <a class="nav-link active" href="despachos.php">
                    <i class="fas fa-shipping-fast me-2"></i>Despachos
                </a>
                <?php if ($_SESSION['tipo_usuario'] === 'admin'): ?>
                <a class="nav-link" href="reportes.php">
                    <i class="fas fa-chart-bar me-2"></i>Reportes
                </a>
                <?php endif; ?>
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
                <h5 class="navbar-brand mb-0">
                    <?php echo $despacho ? htmlspecialchars($despacho['ruta_nombre']) : htmlspecialchars($ruta['nombre']); ?>
                    - <?php echo date('d/m/Y', strtotime($fecha)); ?>
                </h5>
                <div class="navbar-nav ms-auto">
                    <a href="despachos.php?fecha=<?php echo $fecha; ?>" class="btn btn-outline-primary btn-sm">
                        <i class="fas fa-arrow-left me-1"></i>Volver
                    </a>
                </div>
            </div>
        </nav>
        
        <!-- Main Content -->
        <div class="container-fluid p-4">
            <?php if (!empty($mensaje)): ?>
                <?php mostrarAlerta($tipoMensaje, $mensaje); ?>
            <?php endif; ?>
            
            <!-- Información de la ruta -->
            <div class="card mb-4">
                <div class="card-header bg-<?php echo ($despacho['tipo_ruta'] ?? $ruta['tipo_ruta']) === 'grupo_aje' ? 'success' : 'primary'; ?> text-white">
                    <div class="row align-items-center">
                        <div class="col">
                            <h5 class="mb-0">
                                <i class="fas fa-route me-2"></i>
                                <?php echo htmlspecialchars($despacho['ruta_nombre'] ?? $ruta['nombre']); ?>
                            </h5>
                            <small><?php echo htmlspecialchars($despacho['direccion'] ?? $ruta['direccion']); ?></small>
                        </div>
                        <div class="col-auto">
                            <span class="badge bg-light text-dark">
                                <?php echo ($despacho['tipo_ruta'] ?? $ruta['tipo_ruta']) === 'grupo_aje' ? 'Solo Grupo AJE' : 'Todos los productos'; ?>
                            </span>
                        </div>
                    </div>
                </div>
            </div>
            
            <?php if ($despacho): ?>
                <!-- Progress Steps -->
                <div class="progress-steps">
                    <div class="progress-step completed">
                        <div class="step-circle">1</div>
                        <small>Salida</small>
                    </div>
                    <div class="progress-step <?php echo in_array($despacho['estado'], ['recarga', 'segunda_recarga', 'retorno', 'completado']) ? 'completed' : 'active'; ?>">
                        <div class="step-circle">2</div>
                        <small>Recarga</small>
                    </div>
                    <div class="progress-step <?php echo in_array($despacho['estado'], ['segunda_recarga', 'retorno', 'completado']) ? 'completed' : ($despacho['estado'] === 'recarga' ? 'active' : ''); ?>">
                        <div class="step-circle">3</div>
                        <small>2da Recarga</small>
                    </div>
                    <div class="progress-step <?php echo in_array($despacho['estado'], ['retorno', 'completado']) ? 'completed' : ($despacho['estado'] === 'segunda_recarga' ? 'active' : ''); ?>">
                        <div class="step-circle">4</div>
                        <small>Retorno</small>
                    </div>
                    <div class="progress-step <?php echo $despacho['estado'] === 'completado' ? 'completed' : ($despacho['estado'] === 'retorno' ? 'active' : ''); ?>">
                        <div class="step-circle">5</div>
                        <small>Liquidación</small>
                    </div>
                </div>
                
                <!-- Contenido principal según el estado -->
                <?php if ($eventoActual === 'completado'): ?>
                    <!-- Despacho completado - mostrar resumen -->
                    <div class="card">
                        <div class="card-header bg-success text-white">
                            <h5 class="mb-0">
                                <i class="fas fa-check-circle me-2"></i>Despacho Completado
                            </h5>
                        </div>
                        <div class="card-body">
                            <div class="alert alert-success">
                                <i class="fas fa-check-circle me-2"></i>
                                Este despacho ha sido completado y liquidado exitosamente.
                            </div>
                            
                            <?php if (!empty($detalles)): ?>
                                <div class="table-responsive">
                                    <table class="table table-striped">
                                        <thead>
                                            <tr>
                                                <th>Producto</th>
                                                <th>Salida</th>
                                                <th>Recarga</th>
                                                <th>2da Recarga</th>
                                                <th>Retorno</th>
                                                <th>Vendido</th>
                                                <th>Total $</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php 
                                            $totalVendido = 0;
                                            $totalDinero = 0;
                                            foreach ($detalles as $detalle): 
                                                $totalVendido += $detalle['ventas_calculadas'];
                                                $totalDinero += $detalle['total_dinero'];
                                            ?>
                                                <tr class="producto-<?php echo $detalle['categoria']; ?>">
                                                    <td>
                                                        <strong><?php echo htmlspecialchars($detalle['producto_nombre']); ?></strong>
                                                        <br><small class="text-muted"><?php echo $detalle['categoria'] === 'grupo_aje' ? 'Grupo AJE' : 'Proveedores Varios'; ?></small>
                                                    </td>
                                                    <td><?php echo formatearNumero($detalle['salida']); ?></td>
                                                    <td><?php echo formatearNumero($detalle['recarga']); ?></td>
                                                    <td><?php echo formatearNumero($detalle['segunda_recarga']); ?></td>
                                                    <td><?php echo formatearNumero($detalle['retorno']); ?></td>
                                                    <td><strong><?php echo formatearNumero($detalle['ventas_calculadas']); ?></strong></td>
                                                    <td><strong><?php echo formatearDinero($detalle['total_dinero']); ?></strong></td>
                                                </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                        <tfoot>
                                            <tr class="table-success">
                                                <th>TOTALES</th>
                                                <th>-</th>
                                                <th>-</th>
                                                <th>-</th>
                                                <th>-</th>
                                                <th><strong><?php echo formatearNumero($totalVendido); ?></strong></th>
                                                <th><strong><?php echo formatearDinero($totalDinero); ?></strong></th>
                                            </tr>
                                        </tfoot>
                                    </table>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                    
                <?php elseif ($eventoActual === 'liquidacion'): ?>
                    <!-- Formulario de liquidación -->
                    <div class="card">
                        <div class="card-header bg-warning text-dark">
                            <h5 class="mb-0">
                                <i class="fas fa-calculator me-2"></i>Liquidación del Despacho
                            </h5>
                        </div>
                        <div class="card-body">
                            <div class="alert alert-info">
                                <i class="fas fa-info-circle me-2"></i>
                                Revise las ventas calculadas y ajuste precios especiales si es necesario antes de completar la liquidación.
                            </div>
                            
                            <form method="POST" action="">
                                <input type="hidden" name="accion" value="liquidar_despacho">
                                
                                <?php if (!empty($detalles)): ?>
                                    <div class="table-responsive">
                                        <table class="table table-striped">
                                            <thead>
                                                <tr>
                                                    <th>Producto</th>
                                                    <th>Enviado</th>
                                                    <th>Retorno</th>
                                                    <th>Vendido</th>
                                                    <th>Precio Especial</th>
                                                    <th>Total Estimado</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                <?php foreach ($detalles as $detalle): ?>
                                                    <tr class="producto-<?php echo $detalle['categoria']; ?>">
                                                        <td>
                                                            <strong><?php echo htmlspecialchars($detalle['producto_nombre']); ?></strong>
                                                            <br><small class="text-muted">
                                                                <?php echo $detalle['categoria'] === 'grupo_aje' ? 'Grupo AJE' : 'Proveedores Varios'; ?>
                                                                | Precio: <?php echo formatearDinero($detalle['precio_unitario']); ?>
                                                                <?php if ($detalle['usa_formula']): ?>
                                                                    <span class="badge bg-info">Fórmula</span>
                                                                <?php endif; ?>
                                                            </small>
                                                        </td>
                                                        <td><?php echo formatearNumero($detalle['salida'] + $detalle['recarga'] + $detalle['segunda_recarga']); ?></td>
                                                        <td><?php echo formatearNumero($detalle['retorno']); ?></td>
                                                        <td><strong><?php echo formatearNumero($detalle['ventas_calculadas']); ?></strong></td>
                                                        <td>
                                                            <div id="precios_especiales_<?php echo $detalle['producto_id']; ?>">
                                                                <button type="button" class="btn btn-sm btn-outline-secondary" 
                                                                        onclick="agregarPrecioEspecial(<?php echo $detalle['producto_id']; ?>)">
                                                                    <i class="fas fa-plus me-1"></i>Precio Especial
                                                                </button>
                                                            </div>
                                                        </td>
                                                        <td>
                                                            <strong>
                                                                <?php 
                                                                if ($detalle['usa_formula']) {
                                                                    echo formatearDinero(calcularVentaFormula($detalle['ventas_calculadas']));
                                                                } else {
                                                                    echo formatearDinero(calcularVentaNormal($detalle['ventas_calculadas'], $detalle['precio_unitario']));
                                                                }
                                                                ?>
                                                            </strong>
                                                        </td>
                                                    </tr>
                                                <?php endforeach; ?>
                                            </tbody>
                                        </table>
                                    </div>
                                    
                                    <div class="row mt-4">
                                        <div class="col-md-6">
                                            <button type="submit" class="btn btn-success" 
                                                    onclick="return confirm('¿Está seguro de completar la liquidación? Esta acción no se puede deshacer.')">
                                                <i class="fas fa-check me-2"></i>Completar Liquidación
                                            </button>
                                        </div>
                                    </div>
                                <?php endif; ?>
                            </form>
                        </div>
                    </div>
                    
                <?php else: ?>
                    <!-- Formulario para registrar productos -->
                    <div class="card">
                        <div class="card-header bg-primary text-white">
                            <h5 class="mb-0">
                                <i class="fas fa-clipboard-list me-2"></i><?php echo $tituloEvento; ?>
                            </h5>
                        </div>
                        <div class="card-body">
                            <?php if ($puedeEditar): ?>
                                <form method="POST" action="">
                                    <input type="hidden" name="accion" value="guardar_productos">
                                    <input type="hidden" name="evento" value="<?php echo $eventoActual; ?>">
                                    
                                    <div class="alert alert-info">
                                        <i class="fas fa-info-circle me-2"></i>
                                        Ingrese las cantidades de productos para <strong><?php echo strtolower($tituloEvento); ?></strong>.
                                    </div>
                                    
                                    <!-- Filtros de productos -->
                                    <div class="filtros-productos">
                                        <div class="row">
                                            <div class="col-md-6">
                                                <input type="text" class="form-control" id="buscarProducto" 
                                                       placeholder="Buscar producto...">
                                            </div>
                                            <div class="col-md-6">
                                                <select class="form-select" id="filtroCategoria">
                                                    <option value="">Todas las categorías</option>
                                                    <option value="grupo_aje">Grupo AJE</option>
                                                    <option value="proveedores_varios">Proveedores Varios</option>
                                                </select>
                                            </div>
                                        </div>
                                    </div>
                                    
                                    <!-- Lista de productos -->
                                    <div class="row" id="productosContainer">
                                        <?php foreach ($productos as $producto): ?>
                                            <div class="col-md-6 col-lg-4 producto-item" 
                                                 data-categoria="<?php echo $producto['categoria']; ?>"
                                                 data-nombre="<?php echo strtolower($producto['nombre']); ?>">
                                                <div class="producto-card producto-<?php echo $producto['categoria']; ?>">
                                                    <div class="d-flex justify-content-between align-items-start mb-2">
                                                        <h6 class="mb-0"><?php echo htmlspecialchars($producto['nombre']); ?></h6>
                                                        <span class="badge bg-<?php echo $producto['categoria'] === 'grupo_aje' ? 'success' : 'primary'; ?>">
                                                            <?php echo $producto['categoria'] === 'grupo_aje' ? 'AJE' : 'Varios'; ?>
                                                        </span>
                                                    </div>
                                                    
                                                    <div class="stock-info mb-3">
                                                        <small>
                                                            <strong>Stock:</strong> 
                                                            <span class="<?php echo $producto['stock_actual'] <= $producto['stock_minimo'] ? 'stock-bajo' : ''; ?>">
                                                                <?php echo formatearNumero($producto['stock_actual']); ?>
                                                            </span>
                                                            | <strong>Precio:</strong> <?php echo formatearDinero($producto['precio_unitario']); ?>
                                                            <?php if ($producto['usa_formula']): ?>
                                                                <span class="badge bg-info">Fórmula</span>
                                                            <?php endif; ?>
                                                        </small>
                                                    </div>
                                                    
                                                    <div class="input-group">
                                                        <span class="input-group-text">Cantidad</span>
                                                        <input type="number" class="form-control" 
                                                               name="productos[<?php echo $producto['id']; ?>]"
                                                               step="0.5" min="0" 
                                                               placeholder="0"
                                                               value="<?php 
                                                               // Mostrar cantidad existente si existe
                                                               foreach ($detalles as $detalle) {
                                                                   if ($detalle['producto_id'] == $producto['id']) {
                                                                       echo $detalle[$eventoActual] ?? '0';
                                                                       break;
                                                                   }
                                                               }
                                                               ?>">
                                                    </div>
                                                </div>
                                            </div>
                                        <?php endforeach; ?>
                                    </div>
                                    
                                    <div class="row mt-4">
                                        <div class="col-12">
                                            <button type="submit" class="btn btn-primary">
                                                <i class="fas fa-save me-2"></i>Guardar <?php echo $tituloEvento; ?>
                                            </button>
                                        </div>
                                    </div>
                                </form>
                            <?php else: ?>
                                <div class="alert alert-warning">
                                    <i class="fas fa-exclamation-triangle me-2"></i>
                                    No se puede editar este despacho. 
                                    <?php if ($despacho['estado'] === 'completado'): ?>
                                        El despacho ya está completado.
                                    <?php elseif (esFechaAnterior($fecha)): ?>
                                        No se pueden editar despachos de fechas anteriores.
                                    <?php else: ?>
                                        Solo se puede ver la información.
                                    <?php endif; ?>
                                </div>
                                
                                <!-- Mostrar información actual si existe -->
                                <?php if (!empty($detalles)): ?>
                                    <div class="table-responsive">
                                        <table class="table table-striped">
                                            <thead>
                                                <tr>
                                                    <th>Producto</th>
                                                    <th>Salida</th>
                                                    <th>Recarga</th>
                                                    <th>2da Recarga</th>
                                                    <th>Retorno</th>
                                                    <th>Vendido</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                <?php foreach ($detalles as $detalle): ?>
                                                    <tr class="producto-<?php echo $detalle['categoria']; ?>">
                                                        <td>
                                                            <strong><?php echo htmlspecialchars($detalle['producto_nombre']); ?></strong>
                                                            <br><small class="text-muted"><?php echo $detalle['categoria'] === 'grupo_aje' ? 'Grupo AJE' : 'Proveedores Varios'; ?></small>
                                                        </td>
                                                        <td><?php echo formatearNumero($detalle['salida']); ?></td>
                                                        <td><?php echo formatearNumero($detalle['recarga']); ?></td>
                                                        <td><?php echo formatearNumero($detalle['segunda_recarga']); ?></td>
                                                        <td><?php echo formatearNumero($detalle['retorno']); ?></td>
                                                        <td><strong><?php echo formatearNumero($detalle['ventas_calculadas']); ?></strong></td>
                                                    </tr>
                                                <?php endforeach; ?>
                                            </tbody>
                                        </table>
                                    </div>
                                <?php endif; ?>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endif; ?>
                
            <?php else: ?>
                <!-- Crear nuevo despacho -->
                <div class="card">
                    <div class="card-header bg-success text-white">
                        <h5 class="mb-0">
                            <i class="fas fa-plus-circle me-2"></i>Crear Nuevo Despacho
                        </h5>
                    </div>
                    <div class="card-body">
                        <div class="alert alert-info">
                            <i class="fas fa-info-circle me-2"></i>
                            Se creará un nuevo despacho para la ruta <strong><?php echo htmlspecialchars($ruta['nombre']); ?></strong> 
                            en la fecha <strong><?php echo date('d/m/Y', strtotime($fecha)); ?></strong>.
                        </div>
                        
                        <form method="POST" action="">
                            <input type="hidden" name="accion" value="crear_despacho">
                            <button type="submit" class="btn btn-success">
                                <i class="fas fa-plus me-2"></i>Crear Despacho
                            </button>
                        </form>
                    </div>
                </div>
            <?php endif; ?>
        </div>
    </div>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
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
        });
        
        // Filtros de productos
        document.addEventListener('DOMContentLoaded', function() {
            const buscarInput = document.getElementById('buscarProducto');
            const filtroCategoria = document.getElementById('filtroCategoria');
            const productosContainer = document.getElementById('productosContainer');
            
            if (buscarInput && filtroCategoria && productosContainer) {
                function filtrarProductos() {
                    const textoBusqueda = buscarInput.value.toLowerCase();
                    const categoriaSeleccionada = filtroCategoria.value;
                    const productos = productosContainer.querySelectorAll('.producto-item');
                    
                    productos.forEach(producto => {
                        const nombre = producto.dataset.nombre;
                        const categoria = producto.dataset.categoria;
                        
                        const coincideTexto = nombre.includes(textoBusqueda);
                        const coincideCategoria = !categoriaSeleccionada || categoria === categoriaSeleccionada;
                        
                        if (coincideTexto && coincideCategoria) {
                            producto.style.display = 'block';
                        } else {
                            producto.style.display = 'none';
                        }
                    });
                }
                
                buscarInput.addEventListener('input', filtrarProductos);
                filtroCategoria.addEventListener('change', filtrarProductos);
            }
        });
        
        // Función para agregar precios especiales
        function agregarPrecioEspecial(productoId) {
            const container = document.getElementById('precios_especiales_' + productoId);
            const index = container.querySelectorAll('.precio-especial-row').length;
            
            const row = document.createElement('div');
            row.className = 'precio-especial-row';
            row.innerHTML = `
                <div class="row">
                    <div class="col-4">
                        <input type="number" class="form-control form-control-sm" 
                               name="precios_especiales[${productoId}][cantidad][]" 
                               placeholder="Cantidad" step="0.5" min="0">
                    </div>
                    <div class="col-4">
                        <input type="number" class="form-control form-control-sm" 
                               name="precios_especiales[${productoId}][precio][]" 
                               placeholder="Precio" step="0.01" min="0">
                    </div>
                    <div class="col-4">
                        <button type="button" class="btn btn-danger btn-sm" 
                                onclick="this.closest('.precio-especial-row').remove()">
                            <i class="fas fa-trash"></i>
                        </button>
                    </div>
                </div>
            `;
            
            container.appendChild(row);
        }
        
        // Validación del formulario
        document.addEventListener('DOMContentLoaded', function() {
            const forms = document.querySelectorAll('form');
            forms.forEach(form => {
                form.addEventListener('submit', function(e) {
                    const inputs = form.querySelectorAll('input[name^="productos"]');
                    let hasValue = false;
                    
                    inputs.forEach(input => {
                        if (parseFloat(input.value) > 0) {
                            hasValue = true;
                        }
                    });
                    
                    if (form.querySelector('input[name="accion"][value="guardar_productos"]') && !hasValue) {
                        e.preventDefault();
                        alert('Debe ingresar al menos un producto con cantidad mayor a 0');
                        return false;
                    }
                });
            });
        });
        
        // Auto-guardar borrador (opcional)
        let autoSaveTimeout;
        document.addEventListener('DOMContentLoaded', function() {
            const inputs = document.querySelectorAll('input[name^="productos"]');
            inputs.forEach(input => {
                input.addEventListener('input', function() {
                    clearTimeout(autoSaveTimeout);
                    autoSaveTimeout = setTimeout(() => {
                        // Aquí podrías implementar auto-guardar en localStorage si fuera necesario
                        console.log('Auto-guardado de borrador...');
                    }, 2000);
                });
            });
        });
        
        // Confirmar acciones importantes
        function confirmarAccion(mensaje) {
            return confirm(mensaje);
        }
        
        // Resaltar productos con stock bajo
        document.addEventListener('DOMContentLoaded', function() {
            const productosStockBajo = document.querySelectorAll('.stock-bajo');
            productosStockBajo.forEach(elemento => {
                const card = elemento.closest('.producto-card');
                if (card) {
                    card.style.borderLeftColor = '#dc3545';
                    card.style.borderLeftWidth = '4px';
                }
            });
        });
        
        // Navegación con teclado para inputs numéricos
        document.addEventListener('DOMContentLoaded', function() {
            const inputs = document.querySelectorAll('input[type="number"]');
            inputs.forEach((input, index) => {
                input.addEventListener('keydown', function(e) {
                    if (e.key === 'Enter') {
                        e.preventDefault();
                        const nextInput = inputs[index + 1];
                        if (nextInput) {
                            nextInput.focus();
                        }
                    }
                });
            });
        });
        
        // Calcular totales en tiempo real (para liquidación)
        document.addEventListener('DOMContentLoaded', function() {
            const preciosEspeciales = document.querySelectorAll('input[name^="precios_especiales"]');
            preciosEspeciales.forEach(input => {
                input.addEventListener('input', function() {
                    // Aquí podrías calcular totales en tiempo real
                    console.log('Recalculando totales...');
                });
            });
        });
    </script>
</body>
</html>