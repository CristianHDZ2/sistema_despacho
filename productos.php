<?php
// productos.php
require_once 'config/database.php';
require_once 'includes/functions.php';

verificarAdmin();

$db = getDB();
$mensaje = '';
$tipoMensaje = '';

// Procesar formulario
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $accion = $_POST['accion'] ?? '';
    
    if ($accion === 'crear') {
        $nombre = limpiarInput($_POST['nombre']);
        $categoria = limpiarInput($_POST['categoria']);
        $precio_unitario = (float)$_POST['precio_unitario'];
        $usa_formula = isset($_POST['usa_formula']) ? 1 : 0;
        $stock_actual = (float)$_POST['stock_actual'];
        $stock_minimo = (float)$_POST['stock_minimo'];
        
        // Validaciones
        $errores = [];
        
        if (empty($nombre)) {
            $errores[] = 'El nombre del producto es requerido';
        }
        
        if (empty($categoria)) {
            $errores[] = 'Debe seleccionar una categoría';
        }
        
        if ($precio_unitario <= 0) {
            $errores[] = 'El precio debe ser mayor a 0';
        }
        
        if ($stock_actual < 0) {
            $errores[] = 'El stock actual no puede ser negativo';
        }
        
        if ($stock_minimo < 0) {
            $errores[] = 'El stock mínimo no puede ser negativo';
        }
        
        // Verificar si ya existe un producto con el mismo nombre
        try {
            $stmt = $db->prepare("SELECT id FROM productos WHERE nombre = ? AND estado = 'activo'");
            $stmt->execute([$nombre]);
            if ($stmt->fetch()) {
                $errores[] = 'Ya existe un producto con ese nombre';
            }
        } catch (PDOException $e) {
            $errores[] = 'Error al verificar producto existente';
        }
        
        if (empty($errores)) {
            try {
                $stmt = $db->prepare("
                    INSERT INTO productos (nombre, categoria, precio_unitario, usa_formula, stock_actual, stock_minimo) 
                    VALUES (?, ?, ?, ?, ?, ?)
                ");
                
                if ($stmt->execute([$nombre, $categoria, $precio_unitario, $usa_formula, $stock_actual, $stock_minimo])) {
                    $mensaje = 'Producto creado exitosamente';
                    $tipoMensaje = 'success';
                } else {
                    $mensaje = 'Error al crear el producto';
                    $tipoMensaje = 'error';
                }
            } catch (PDOException $e) {
                $mensaje = 'Error en la base de datos: ' . $e->getMessage();
                $tipoMensaje = 'error';
            }
        } else {
            $mensaje = implode('<br>', $errores);
            $tipoMensaje = 'error';
        }
    }
    
    elseif ($accion === 'editar') {
        $id = (int)$_POST['id'];
        $nombre = limpiarInput($_POST['nombre']);
        $categoria = limpiarInput($_POST['categoria']);
        $precio_unitario = (float)$_POST['precio_unitario'];
        $usa_formula = isset($_POST['usa_formula']) ? 1 : 0;
        $stock_actual = (float)$_POST['stock_actual'];
        $stock_minimo = (float)$_POST['stock_minimo'];
        
        // Validaciones
        $errores = [];
        
        if (empty($nombre)) {
            $errores[] = 'El nombre del producto es requerido';
        }
        
        if ($precio_unitario <= 0) {
            $errores[] = 'El precio debe ser mayor a 0';
        }
        
        if ($stock_actual < 0) {
            $errores[] = 'El stock actual no puede ser negativo';
        }
        
        if ($stock_minimo < 0) {
            $errores[] = 'El stock mínimo no puede ser negativo';
        }
        
        // Verificar si ya existe otro producto con el mismo nombre
        try {
            $stmt = $db->prepare("SELECT id FROM productos WHERE nombre = ? AND id != ? AND estado = 'activo'");
            $stmt->execute([$nombre, $id]);
            if ($stmt->fetch()) {
                $errores[] = 'Ya existe otro producto con ese nombre';
            }
        } catch (PDOException $e) {
            $errores[] = 'Error al verificar producto existente';
        }
        
        if (empty($errores)) {
            try {
                $stmt = $db->prepare("
                    UPDATE productos 
                    SET nombre = ?, categoria = ?, precio_unitario = ?, usa_formula = ?, 
                        stock_actual = ?, stock_minimo = ?
                    WHERE id = ?
                ");
                
                if ($stmt->execute([$nombre, $categoria, $precio_unitario, $usa_formula, $stock_actual, $stock_minimo, $id])) {
                    $mensaje = 'Producto actualizado exitosamente';
                    $tipoMensaje = 'success';
                } else {
                    $mensaje = 'Error al actualizar el producto';
                    $tipoMensaje = 'error';
                }
            } catch (PDOException $e) {
                $mensaje = 'Error en la base de datos: ' . $e->getMessage();
                $tipoMensaje = 'error';
            }
        } else {
            $mensaje = implode('<br>', $errores);
            $tipoMensaje = 'error';
        }
    }
    
    elseif ($accion === 'cambiar_estado') {
        $id = (int)$_POST['id'];
        $nuevo_estado = $_POST['estado'] === 'activo' ? 'inactivo' : 'activo';
        
        try {
            $stmt = $db->prepare("UPDATE productos SET estado = ? WHERE id = ?");
            if ($stmt->execute([$nuevo_estado, $id])) {
                $mensaje = 'Estado del producto actualizado exitosamente';
                $tipoMensaje = 'success';
            } else {
                $mensaje = 'Error al actualizar el estado del producto';
                $tipoMensaje = 'error';
            }
        } catch (PDOException $e) {
            $mensaje = 'Error en la base de datos: ' . $e->getMessage();
            $tipoMensaje = 'error';
        }
    }
}

// Filtros
$filtro = $_GET['filtro'] ?? '';
$whereClause = "WHERE 1=1";
$params = [];

if ($filtro === 'stock_bajo') {
    $whereClause .= " AND stock_actual <= stock_minimo AND estado = 'activo'";
} elseif ($filtro === 'grupo_aje') {
    $whereClause .= " AND categoria = 'grupo_aje' AND estado = 'activo'";
} elseif ($filtro === 'proveedores_varios') {
    $whereClause .= " AND categoria = 'proveedores_varios' AND estado = 'activo'";
}

// Obtener lista de productos
try {
    $stmt = $db->query("
        SELECT id, nombre, categoria, precio_unitario, usa_formula, stock_actual, stock_minimo, estado, fecha_creacion
        FROM productos 
        $whereClause
        ORDER BY categoria, nombre
    ");
    $productos = $stmt->fetchAll();
} catch (PDOException $e) {
    $mensaje = 'Error al obtener productos: ' . $e->getMessage();
    $tipoMensaje = 'error';
    $productos = [];
}

// Obtener producto para editar si se está editando
$productoEditar = null;
if (isset($_GET['editar'])) {
    $idEditar = (int)$_GET['editar'];
    try {
        $stmt = $db->prepare("SELECT * FROM productos WHERE id = ?");
        $stmt->execute([$idEditar]);
        $productoEditar = $stmt->fetch();
    } catch (PDOException $e) {
        $mensaje = 'Error al obtener datos del producto';
        $tipoMensaje = 'error';
    }
}

// Obtener estadísticas
try {
    $stmt = $db->query("SELECT COUNT(*) as total FROM productos WHERE estado = 'activo'");
    $totalProductos = $stmt->fetch()['total'];
    
    $stmt = $db->query("SELECT COUNT(*) as total FROM productos WHERE stock_actual <= stock_minimo AND estado = 'activo'");
    $stockBajo = $stmt->fetch()['total'];
    
    $stmt = $db->query("SELECT COUNT(*) as total FROM productos WHERE categoria = 'grupo_aje' AND estado = 'activo'");
    $grupoAje = $stmt->fetch()['total'];
    
    $stmt = $db->query("SELECT COUNT(*) as total FROM productos WHERE categoria = 'proveedores_varios' AND estado = 'activo'");
    $proveedoresVarios = $stmt->fetch()['total'];
} catch (PDOException $e) {
    $totalProductos = $stockBajo = $grupoAje = $proveedoresVarios = 0;
}
?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Gestión de Productos - Sistema de Despacho</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <style>
        .sidebar {
            min-height: 100vh;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            position: sticky;
            top: 0;
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
        }
        .navbar {
            background: white;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
            position: sticky;
            top: 0;
            z-index: 1020;
        }
        .card {
            border: none;
            border-radius: 15px;
            box-shadow: 0 5px 15px rgba(0,0,0,0.1);
        }
        .table th {
            background: #667eea;
            color: white;
            border: none;
            white-space: nowrap;
        }
        .btn-action {
            padding: 5px 10px;
            font-size: 12px;
            border-radius: 8px;
            white-space: nowrap;
            margin: 2px;
        }
        .producto-grupo-aje {
            border-left: 4px solid #28a745;
        }
        .producto-proveedores-varios {
            border-left: 4px solid #007bff;
        }
        .stock-bajo {
            background-color: #fff3cd;
        }
        .filter-buttons {
            margin-bottom: 20px;
        }
        .stats-cards {
            margin-bottom: 20px;
        }
        .stat-card {
            border-radius: 10px;
            padding: 15px;
            color: white;
            text-decoration: none;
            transition: transform 0.2s;
        }
        .stat-card:hover {
            transform: translateY(-2px);
            color: white;
            text-decoration: none;
        }
        .stat-card-total { background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); }
        .stat-card-bajo { background: linear-gradient(135deg, #ff6b6b 0%, #ee5a24 100%); }
        .stat-card-aje { background: linear-gradient(135deg, #26de81 0%, #20bf6b 100%); }
        .stat-card-varios { background: linear-gradient(135deg, #3742fa 0%, #2f3542 100%); }
        
        /* Sidebar responsivo */
        @media (max-width: 767.98px) {
            .sidebar {
                position: fixed;
                top: 0;
                left: -100%;
                width: 280px;
                height: 100vh;
                z-index: 1050;
                transition: left 0.3s ease;
                overflow-y: auto;
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
                z-index: 1040;
                display: none;
            }
            .sidebar-backdrop.show {
                display: block;
            }
            .content-area {
                margin-left: 0 !important;
                width: 100% !important;
            }
            .navbar .navbar-toggler {
                border: none;
                padding: 4px 8px;
            }
            
            /* Cards estadísticas en móvil */
            .stats-cards .col-md-3 {
                margin-bottom: 10px;
            }
            .stat-card {
                padding: 12px;
                font-size: 0.9rem;
            }
            
            /* Tabla responsiva en móvil */
            .table-responsive {
                font-size: 0.8rem;
            }
            .table th,
            .table td {
                padding: 8px 4px;
                vertical-align: middle;
            }
            .btn-action {
                padding: 3px 6px;
                font-size: 10px;
                margin: 1px;
            }
            .badge {
                font-size: 0.6rem;
                padding: 2px 6px;
            }
            
            /* Filtros en móvil */
            .filter-buttons .btn {
                font-size: 0.8rem;
                padding: 6px 10px;
                margin: 2px;
            }
        }
        
        /* Tablet */
        @media (min-width: 768px) and (max-width: 991.98px) {
            .sidebar {
                width: 250px;
            }
            .table-responsive {
                font-size: 0.9rem;
            }
            .table th,
            .table td {
                padding: 10px 6px;
            }
        }
        
        /* Móvil - Ajustes adicionales */
        @media (max-width: 576px) {
            .container-fluid {
                padding: 0;
            }
            .p-4 {
                padding: 1rem !important;
            }
            .navbar {
                padding: 0.5rem 1rem;
            }
            .navbar-brand {
                font-size: 1.1rem;
            }
            .card-body {
                padding: 1rem;
            }
            .card-header h5 {
                font-size: 1rem;
            }
            .form-control,
            .form-select {
                font-size: 16px; /* Evita zoom en iOS */
            }
            .btn {
                font-size: 0.9rem;
                padding: 8px 12px;
            }
            
            /* Formulario en móvil */
            .row .col-md-6 {
                margin-bottom: 1rem;
            }
            
            /* Tabla móvil card style */
            .product-card-mobile {
                border: 1px solid #dee2e6;
                border-radius: 8px;
                margin-bottom: 15px;
                padding: 15px;
                background: white;
                box-shadow: 0 2px 4px rgba(0,0,0,0.1);
            }
            
            .product-card-mobile h6 {
                margin-bottom: 10px;
                color: #333;
            }
            
            .product-info {
                margin-bottom: 10px;
            }
            
            .product-info span {
                display: inline-block;
                margin-right: 10px;
                margin-bottom: 5px;
            }
        }
        
        /* Desktop grande */
        @media (min-width: 1200px) {
            .sidebar {
                width: 280px;
            }
        }
    </style>
</head>
<body>
    <div class="container-fluid">
        <div class="row">
            <!-- Sidebar -->
            <div class="col-md-3 col-lg-2 p-0 sidebar">
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
                        <a class="nav-link active" href="productos.php">
                            <i class="fas fa-box me-2"></i>Productos
                        </a>
                        <a class="nav-link" href="rutas.php">
                            <i class="fas fa-route me-2"></i>Rutas
                        </a>
                        <a class="nav-link" href="despachos.php">
                            <i class="fas fa-shipping-fast me-2"></i>Despachos
                        </a>
                        <a class="nav-link" href="reportes.php">
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
            <div class="col-md-9 col-lg-10 p-0 content-area">
                <!-- Sidebar Backdrop para móvil -->
                <div class="sidebar-backdrop" id="sidebarBackdrop"></div>
                
                <!-- Top Navbar -->
                <nav class="navbar navbar-expand-lg navbar-light">
                    <div class="container-fluid">
                        <button class="navbar-toggler d-md-none" type="button" id="sidebarToggle">
                            <i class="fas fa-bars"></i>
                        </button>
                        <h5 class="navbar-brand mb-0">Gestión de Productos</h5>
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
                    
                    <!-- Estadísticas -->
                    <div class="row stats-cards">
                        <div class="col-md-3 col-6">
                            <a href="productos.php" class="stat-card stat-card-total d-block">
                                <div class="d-flex align-items-center">
                                    <i class="fas fa-box fa-2x me-3"></i>
                                    <div>
                                        <h4 class="mb-0"><?php echo $totalProductos; ?></h4>
                                        <small>Total Productos</small>
                                    </div>
                                </div>
                            </a>
                        </div>
                        <div class="col-md-3 col-6">
                            <a href="productos.php?filtro=stock_bajo" class="stat-card stat-card-bajo d-block">
                                <div class="d-flex align-items-center">
                                    <i class="fas fa-exclamation-triangle fa-2x me-3"></i>
                                    <div>
                                        <h4 class="mb-0"><?php echo $stockBajo; ?></h4>
                                        <small>Stock Bajo</small>
                                    </div>
                                </div>
                            </a>
                        </div>
                        <div class="col-md-3 col-6">
                            <a href="productos.php?filtro=grupo_aje" class="stat-card stat-card-aje d-block">
                                <div class="d-flex align-items-center">
                                    <i class="fas fa-star fa-2x me-3"></i>
                                    <div>
                                        <h4 class="mb-0"><?php echo $grupoAje; ?></h4>
                                        <small>Grupo AJE</small>
                                    </div>
                                </div>
                            </a>
                        </div>
                        <div class="col-md-3 col-6">
                            <a href="productos.php?filtro=proveedores_varios" class="stat-card stat-card-varios d-block">
                                <div class="d-flex align-items-center">
                                    <i class="fas fa-boxes fa-2x me-3"></i>
                                    <div>
                                        <h4 class="mb-0"><?php echo $proveedoresVarios; ?></h4>
                                        <small>Prov. Varios</small>
                                    </div>
                                </div>
                            </a>
                        </div>
                    </div>
                    
                    <!-- Botones de filtro -->
                    <div class="filter-buttons">
                        <a href="productos.php" class="btn btn-outline-primary <?php echo empty($filtro) ? 'active' : ''; ?>">
                            <i class="fas fa-list me-1"></i>Todos
                        </a>
                        <a href="productos.php?filtro=stock_bajo" class="btn btn-outline-warning <?php echo $filtro === 'stock_bajo' ? 'active' : ''; ?>">
                            <i class="fas fa-exclamation-triangle me-1"></i>Stock Bajo
                        </a>
                        <a href="productos.php?filtro=grupo_aje" class="btn btn-outline-success <?php echo $filtro === 'grupo_aje' ? 'active' : ''; ?>">
                            <i class="fas fa-star me-1"></i>Grupo AJE
                        </a>
                        <a href="productos.php?filtro=proveedores_varios" class="btn btn-outline-info <?php echo $filtro === 'proveedores_varios' ? 'active' : ''; ?>">
                            <i class="fas fa-boxes me-1"></i>Proveedores Varios
                        </a>
                    </div>
                    
                    <!-- Formulario para crear/editar producto -->
                    <div class="card mb-4">
                        <div class="card-header bg-<?php echo $productoEditar ? 'warning' : 'primary'; ?> text-white">
                            <div class="d-flex justify-content-between align-items-center">
                                <h5 class="mb-0">
                                    <i class="fas fa-<?php echo $productoEditar ? 'edit' : 'plus'; ?> me-2"></i>
                                    <?php echo $productoEditar ? 'Editar Producto' : 'Crear Nuevo Producto'; ?>
                                </h5>
                                <?php if ($productoEditar): ?>
                                    <a href="productos.php" class="btn btn-light btn-sm">
                                        <i class="fas fa-times me-1"></i>Cancelar
                                    </a>
                                <?php endif; ?>
                            </div>
                        </div>
                        <div class="card-body">
                            <form method="POST" action="">
                                <input type="hidden" name="accion" value="<?php echo $productoEditar ? 'editar' : 'crear'; ?>">
                                <?php if ($productoEditar): ?>
                                    <input type="hidden" name="id" value="<?php echo $productoEditar['id']; ?>">
                                <?php endif; ?>
                                
                                <div class="row">
                                    <div class="col-md-6 mb-3">
                                        <label for="nombre" class="form-label">Nombre del Producto *</label>
                                        <input type="text" class="form-control" id="nombre" name="nombre" 
                                               value="<?php echo $productoEditar ? htmlspecialchars($productoEditar['nombre']) : ''; ?>" 
                                               placeholder="Ej: Big Cola 3L" required>
                                    </div>
                                    <div class="col-md-6 mb-3">
                                        <label for="categoria" class="form-label">Categoría *</label>
                                        <select class="form-select" id="categoria" name="categoria" required>
                                            <option value="">Seleccionar...</option>
                                            <option value="grupo_aje" <?php echo ($productoEditar && $productoEditar['categoria'] === 'grupo_aje') ? 'selected' : ''; ?>>
                                                Grupo AJE
                                            </option>
                                            <option value="proveedores_varios" <?php echo ($productoEditar && $productoEditar['categoria'] === 'proveedores_varios') ? 'selected' : ''; ?>>
                                                Proveedores Varios
                                            </option>
                                        </select>
                                    </div>
                                </div>
                                <div class="row">
                                    <div class="col-md-6 mb-3">
                                        <label for="precio_unitario" class="form-label">Precio Unitario *</label>
                                        <div class="input-group">
                                            <span class="input-group-text">$</span>
                                            <input type="number" class="form-control" id="precio_unitario" name="precio_unitario" 
                                                   step="0.01" min="0.01"
                                                   value="<?php echo $productoEditar ? $productoEditar['precio_unitario'] : ''; ?>" 
                                                   placeholder="0.00" required>
                                        </div>
                                    </div>
                                    <div class="col-md-6 mb-3">
                                        <div class="form-check mt-4 pt-2">
                                            <input class="form-check-input" type="checkbox" id="usa_formula" name="usa_formula" 
                                                   <?php echo ($productoEditar && $productoEditar['usa_formula']) ? 'checked' : ''; ?>>
                                            <label class="form-check-label" for="usa_formula">
                                                <strong>Usa Fórmula Especial</strong>
                                                <br><small class="text-muted">Fórmula: (2.50 ÷ 3) × cantidad vendida</small>
                                            </label>
                                        </div>
                                    </div>
                                </div>
                                <div class="row">
                                    <div class="col-md-6 mb-3">
                                        <label for="stock_actual" class="form-label">Stock Actual</label>
                                        <input type="number" class="form-control" id="stock_actual" name="stock_actual" 
                                               step="0.5" min="0"
                                               value="<?php echo $productoEditar ? $productoEditar['stock_actual'] : '0'; ?>" 
                                               placeholder="0">
                                        <small class="text-muted">Acepta medios paquetes (0.5)</small>
                                    </div>
                                    <div class="col-md-6 mb-3">
                                        <label for="stock_minimo" class="form-label">Stock Mínimo</label>
                                        <input type="number" class="form-control" id="stock_minimo" name="stock_minimo" 
                                               step="0.5" min="0"
                                               value="<?php echo $productoEditar ? $productoEditar['stock_minimo'] : '0'; ?>" 
                                               placeholder="0">
                                        <small class="text-muted">Cantidad mínima para alertas</small>
                                    </div>
                                </div>
                                <button type="submit" class="btn btn-<?php echo $productoEditar ? 'warning' : 'primary'; ?>">
                                    <i class="fas fa-save me-2"></i><?php echo $productoEditar ? 'Actualizar Producto' : 'Crear Producto'; ?>
                                </button>
                            </form>
                        </div>
                    </div>
                    
                    <!-- Lista de productos -->
                    <div class="card">
                        <div class="card-header bg-info text-white">
                            <div class="d-flex justify-content-between align-items-center">
                                <h5 class="mb-0">
                                    <i class="fas fa-list me-2"></i>Lista de Productos
                                    <?php if ($filtro): ?>
                                        <small class="ms-2">
                                            (<?php 
                                            switch($filtro) {
                                                case 'stock_bajo': echo 'Stock Bajo'; break;
                                                case 'grupo_aje': echo 'Grupo AJE'; break;
                                                case 'proveedores_varios': echo 'Proveedores Varios'; break;
                                            }
                                            ?>)
                                        </small>
                                    <?php endif; ?>
                                </h5>
                                <div class="d-none d-md-block">
                                    <small>
                                        <i class="fas fa-square text-success me-1"></i>Grupo AJE &nbsp;
                                        <i class="fas fa-square text-primary me-1"></i>Proveedores Varios
                                    </small>
                                </div>
                            </div>
                        </div>
                        <div class="card-body">
                            <?php if (!empty($productos)): ?>
                                <!-- Tabla Desktop -->
                                <div class="table-responsive d-none d-md-block">
                                    <table class="table table-striped table-hover">
                                        <thead>
                                            <tr>
                                                <th>Producto</th>
                                                <th>Categoría</th>
                                                <th>Precio</th>
                                                <th>Fórmula</th>
                                                <th>Stock Actual</th>
                                                <th>Stock Mínimo</th>
                                                <th>Estado</th>
                                                <th>Acciones</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach ($productos as $producto): ?>
                                                <tr class="<?php echo $producto['categoria'] === 'grupo_aje' ? 'producto-grupo-aje' : 'producto-proveedores-varios'; ?> 
                                                           <?php echo ($producto['stock_actual'] <= $producto['stock_minimo']) ? 'stock-bajo' : ''; ?>">
                                                    <td>
                                                        <strong><?php echo htmlspecialchars($producto['nombre']); ?></strong>
                                                        <?php if ($producto['stock_actual'] <= $producto['stock_minimo']): ?>
                                                            <i class="fas fa-exclamation-triangle text-warning ms-2" title="Stock bajo"></i>
                                                        <?php endif; ?>
                                                    </td>
                                                    <td>
                                                        <span class="badge bg-<?php echo $producto['categoria'] === 'grupo_aje' ? 'success' : 'primary'; ?>">
                                                            <?php echo $producto['categoria'] === 'grupo_aje' ? 'Grupo AJE' : 'Proveedores Varios'; ?>
                                                        </span>
                                                    </td>
                                                    <td><?php echo formatearDinero($producto['precio_unitario']); ?></td>
                                                    <td>
                                                        <?php if ($producto['usa_formula']): ?>
                                                            <span class="badge bg-info">Sí</span>
                                                        <?php else: ?>
                                                            <span class="badge bg-secondary">No</span>
                                                        <?php endif; ?>
                                                    </td>
                                                    <td>
                                                        <span class="<?php echo ($producto['stock_actual'] <= $producto['stock_minimo']) ? 'text-danger fw-bold' : ''; ?>">
                                                            <?php echo formatearNumero($producto['stock_actual']); ?>
                                                        </span>
                                                    </td>
                                                    <td><?php echo formatearNumero($producto['stock_minimo']); ?></td>
                                                    <td>
                                                        <span class="badge bg-<?php echo $producto['estado'] === 'activo' ? 'success' : 'danger'; ?>">
                                                            <?php echo ucfirst($producto['estado']); ?>
                                                        </span>
                                                    </td>
                                                    <td>
                                                        <a href="productos.php?editar=<?php echo $producto['id']; ?>" 
                                                           class="btn btn-warning btn-action">
                                                            <i class="fas fa-edit"></i> Editar
                                                        </a>
                                                        <form method="POST" action="" style="display: inline;">
                                                            <input type="hidden" name="accion" value="cambiar_estado">
                                                            <input type="hidden" name="id" value="<?php echo $producto['id']; ?>">
                                                            <input type="hidden" name="estado" value="<?php echo $producto['estado']; ?>">
                                                            <button type="submit" 
                                                                    class="btn btn-<?php echo $producto['estado'] === 'activo' ? 'danger' : 'success'; ?> btn-action"
                                                                    onclick="return confirm('¿Está seguro de cambiar el estado de este producto?')">
                                                                <i class="fas fa-<?php echo $producto['estado'] === 'activo' ? 'ban' : 'check'; ?>"></i>
                                                                <?php echo $producto['estado'] === 'activo' ? 'Desactivar' : 'Activar'; ?>
                                                            </button>
                                                        </form>
                                                    </td>
                                                </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>
                                
                                <!-- Cards Móviles -->
                                <div class="d-md-none">
                                    <?php foreach ($productos as $producto): ?>
                                        <div class="product-card-mobile <?php echo $producto['categoria'] === 'grupo_aje' ? 'producto-grupo-aje' : 'producto-proveedores-varios'; ?> 
                                                    <?php echo ($producto['stock_actual'] <= $producto['stock_minimo']) ? 'stock-bajo' : ''; ?>">
                                            <h6 class="d-flex justify-content-between align-items-start">
                                                <?php echo htmlspecialchars($producto['nombre']); ?>
                                                <?php if ($producto['stock_actual'] <= $producto['stock_minimo']): ?>
                                                    <i class="fas fa-exclamation-triangle text-warning" title="Stock bajo"></i>
                                                <?php endif; ?>
                                            </h6>
                                            
                                            <div class="product-info">
                                                <span class="badge bg-<?php echo $producto['categoria'] === 'grupo_aje' ? 'success' : 'primary'; ?>">
                                                    <?php echo $producto['categoria'] === 'grupo_aje' ? 'Grupo AJE' : 'Proveedores Varios'; ?>
                                                </span>
                                                <span class="badge bg-<?php echo $producto['estado'] === 'activo' ? 'success' : 'danger'; ?>">
                                                    <?php echo ucfirst($producto['estado']); ?>
                                                </span>
                                                <?php if ($producto['usa_formula']): ?>
                                                    <span class="badge bg-info">Usa Fórmula</span>
                                                <?php endif; ?>
                                            </div>
                                            
                                            <div class="product-info">
                                                <strong>Precio:</strong> <?php echo formatearDinero($producto['precio_unitario']); ?><br>
                                                <strong>Stock:</strong> 
                                                <span class="<?php echo ($producto['stock_actual'] <= $producto['stock_minimo']) ? 'text-danger fw-bold' : ''; ?>">
                                                    <?php echo formatearNumero($producto['stock_actual']); ?>
                                                </span> 
                                                / Mín: <?php echo formatearNumero($producto['stock_minimo']); ?>
                                            </div>
                                            
                                            <div class="btn-group w-100 mt-2" role="group">
                                                <a href="productos.php?editar=<?php echo $producto['id']; ?>" 
                                                   class="btn btn-warning btn-sm">
                                                    <i class="fas fa-edit"></i> Editar
                                                </a>
                                                <form method="POST" action="" class="d-inline flex-grow-1">
                                                    <input type="hidden" name="accion" value="cambiar_estado">
                                                    <input type="hidden" name="id" value="<?php echo $producto['id']; ?>">
                                                    <input type="hidden" name="estado" value="<?php echo $producto['estado']; ?>">
                                                    <button type="submit" 
                                                            class="btn btn-<?php echo $producto['estado'] === 'activo' ? 'danger' : 'success'; ?> btn-sm w-100"
                                                            onclick="return confirm('¿Está seguro de cambiar el estado de este producto?')">
                                                        <i class="fas fa-<?php echo $producto['estado'] === 'activo' ? 'ban' : 'check'; ?>"></i>
                                                        <?php echo $producto['estado'] === 'activo' ? 'Desactivar' : 'Activar'; ?>
                                                    </button>
                                                </form>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            <?php else: ?>
                                <div class="text-center py-4">
                                    <i class="fas fa-box fa-3x text-muted mb-3"></i>
                                    <p class="text-muted">
                                        <?php 
                                        if ($filtro) {
                                            echo "No hay productos que coincidan con el filtro seleccionado";
                                        } else {
                                            echo "No hay productos registrados";
                                        }
                                        ?>
                                    </p>
                                    <?php if ($filtro): ?>
                                        <a href="productos.php" class="btn btn-primary">Ver todos los productos</a>
                                    <?php endif; ?>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Funcionalidad del menú móvil
        document.addEventListener('DOMContentLoaded', function() {
            const sidebarToggle = document.getElementById('sidebarToggle');
            const sidebar = document.querySelector('.sidebar');
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
    </script>
</body>
</html>