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