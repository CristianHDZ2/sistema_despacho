<?php
// rutas.php
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
        $direccion = limpiarInput($_POST['direccion']);
        $tipo_ruta = limpiarInput($_POST['tipo_ruta']);
        
        // Validaciones
        $errores = [];
        
        if (empty($nombre)) {
            $errores[] = 'El nombre de la ruta es requerido';
        }
        
        if (empty($direccion)) {
            $errores[] = 'La dirección es requerida';
        }
        
        if (empty($tipo_ruta)) {
            $errores[] = 'Debe seleccionar el tipo de ruta';
        }
        
        // Verificar si ya existe una ruta con el mismo nombre
        try {
            $stmt = $db->prepare("SELECT id FROM rutas WHERE nombre = ? AND estado = 'activo'");
            $stmt->execute([$nombre]);
            if ($stmt->fetch()) {
                $errores[] = 'Ya existe una ruta con ese nombre';
            }
        } catch (PDOException $e) {
            $errores[] = 'Error al verificar ruta existente';
        }
        
        if (empty($errores)) {
            try {
                $stmt = $db->prepare("
                    INSERT INTO rutas (nombre, direccion, tipo_ruta) 
                    VALUES (?, ?, ?)
                ");
                
                if ($stmt->execute([$nombre, $direccion, $tipo_ruta])) {
                    $mensaje = 'Ruta creada exitosamente';
                    $tipoMensaje = 'success';
                } else {
                    $mensaje = 'Error al crear la ruta';
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
        $direccion = limpiarInput($_POST['direccion']);
        $tipo_ruta = limpiarInput($_POST['tipo_ruta']);
        
        // Validaciones
        $errores = [];
        
        if (empty($nombre)) {
            $errores[] = 'El nombre de la ruta es requerido';
        }
        
        if (empty($direccion)) {
            $errores[] = 'La dirección es requerida';
        }
        
        // Verificar si ya existe otra ruta con el mismo nombre
        try {
            $stmt = $db->prepare("SELECT id FROM rutas WHERE nombre = ? AND id != ? AND estado = 'activo'");
            $stmt->execute([$nombre, $id]);
            if ($stmt->fetch()) {
                $errores[] = 'Ya existe otra ruta con ese nombre';
            }
        } catch (PDOException $e) {
            $errores[] = 'Error al verificar ruta existente';
        }
        
        if (empty($errores)) {
            try {
                $stmt = $db->prepare("
                    UPDATE rutas 
                    SET nombre = ?, direccion = ?, tipo_ruta = ?
                    WHERE id = ?
                ");
                
                if ($stmt->execute([$nombre, $direccion, $tipo_ruta, $id])) {
                    $mensaje = 'Ruta actualizada exitosamente';
                    $tipoMensaje = 'success';
                } else {
                    $mensaje = 'Error al actualizar la ruta';
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
            $stmt = $db->prepare("UPDATE rutas SET estado = ? WHERE id = ?");
            if ($stmt->execute([$nuevo_estado, $id])) {
                $mensaje = 'Estado de la ruta actualizado exitosamente';
                $tipoMensaje = 'success';
            } else {
                $mensaje = 'Error al actualizar el estado de la ruta';
                $tipoMensaje = 'error';
            }
        } catch (PDOException $e) {
            $mensaje = 'Error en la base de datos: ' . $e->getMessage();
            $tipoMensaje = 'error';
        }
    }
}

// Obtener lista de rutas
try {
    $stmt = $db->query("
        SELECT id, nombre, direccion, tipo_ruta, estado, fecha_creacion
        FROM rutas 
        ORDER BY tipo_ruta, nombre
    ");
    $rutas = $stmt->fetchAll();
} catch (PDOException $e) {
    $mensaje = 'Error al obtener rutas: ' . $e->getMessage();
    $tipoMensaje = 'error';
    $rutas = [];
}

// Obtener ruta para editar si se está editando
$rutaEditar = null;
if (isset($_GET['editar'])) {
    $idEditar = (int)$_GET['editar'];
    try {
        $stmt = $db->prepare("SELECT * FROM rutas WHERE id = ?");
        $stmt->execute([$idEditar]);
        $rutaEditar = $stmt->fetch();
    } catch (PDOException $e) {
        $mensaje = 'Error al obtener datos de la ruta';
        $tipoMensaje = 'error';
    }
}
?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Gestión de Rutas - Sistema de Despacho</title>
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
        .ruta-grupo-aje {
            border-left: 4px solid #28a745;
        }
        .ruta-proveedores-varios {
            border-left: 4px solid #007bff;
        }
        
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
            
            /* Tabla móvil stack */
            .table-mobile-stack thead {
                display: none;
            }
            .table-mobile-stack,
            .table-mobile-stack tbody,
            .table-mobile-stack tr,
            .table-mobile-stack td {
                display: block;
                width: 100%;
            }
            .table-mobile-stack tr {
                border: 1px solid #dee2e6;
                margin-bottom: 10px;
                border-radius: 8px;
                padding: 15px;
                background: white;
                box-shadow: 0 2px 4px rgba(0,0,0,0.1);
            }
            .table-mobile-stack td {
                border: none;
                position: relative;
                padding: 8px 0;
                text-align: left;
            }
            .table-mobile-stack td:before {
                content: attr(data-label) ": ";
                font-weight: bold;
                color: #667eea;
                display: inline-block;
                width: 80px;
            }
            .table-mobile-stack .btn-action {
                display: inline-block;
                margin: 2px;
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
                        <a class="nav-link" href="productos.php">
                            <i class="fas fa-box me-2"></i>Productos
                        </a>
                        <a class="nav-link active" href="rutas.php">
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
                        <h5 class="navbar-brand mb-0">Gestión de Rutas</h5>
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
                    
                    <!-- Formulario para crear/editar ruta -->
                    <div class="card mb-4">
                        <div class="card-header bg-<?php echo $rutaEditar ? 'warning' : 'primary'; ?> text-white">
                            <div class="d-flex justify-content-between align-items-center">
                                <h5 class="mb-0">
                                    <i class="fas fa-<?php echo $rutaEditar ? 'edit' : 'plus'; ?> me-2"></i>
                                    <?php echo $rutaEditar ? 'Editar Ruta' : 'Crear Nueva Ruta'; ?>
                                </h5>
                                <?php if ($rutaEditar): ?>
                                    <a href="rutas.php" class="btn btn-light btn-sm">
                                        <i class="fas fa-times me-1"></i>Cancelar
                                    </a>
                                <?php endif; ?>
                            </div>
                        </div>
                        <div class="card-body">
                            <form method="POST" action="">
                                <input type="hidden" name="accion" value="<?php echo $rutaEditar ? 'editar' : 'crear'; ?>">
                                <?php if ($rutaEditar): ?>
                                    <input type="hidden" name="id" value="<?php echo $rutaEditar['id']; ?>">
                                <?php endif; ?>
                                
                                <div class="row">
                                    <div class="col-md-6 mb-3">
                                        <label for="nombre" class="form-label">Nombre de la Ruta *</label>
                                        <input type="text" class="form-control" id="nombre" name="nombre" 
                                               value="<?php echo $rutaEditar ? htmlspecialchars($rutaEditar['nombre']) : ''; ?>" 
                                               placeholder="Ej: Ruta 1 - Centro" required>
                                    </div>
                                    <div class="col-md-6 mb-3">
                                        <label for="tipo_ruta" class="form-label">Tipo de Ruta *</label>
                                        <select class="form-select" id="tipo_ruta" name="tipo_ruta" required>
                                            <option value="">Seleccionar...</option>
                                            <option value="grupo_aje" <?php echo ($rutaEditar && $rutaEditar['tipo_ruta'] === 'grupo_aje') ? 'selected' : ''; ?>>
                                                Grupo AJE (Solo productos AJE)
                                            </option>
                                            <option value="proveedores_varios" <?php echo ($rutaEditar && $rutaEditar['tipo_ruta'] === 'proveedores_varios') ? 'selected' : ''; ?>>
                                                Proveedores Varios (Todos los productos)
                                            </option>
                                        </select>
                                    </div>
                                </div>
                                <div class="row">
                                    <div class="col-12 mb-3">
                                        <label for="direccion" class="form-label">Dirección/Zona *</label>
                                        <textarea class="form-control" id="direccion" name="direccion" rows="3" 
                                                  placeholder="Descripción de la zona o dirección que cubre esta ruta" required><?php echo $rutaEditar ? htmlspecialchars($rutaEditar['direccion']) : ''; ?></textarea>
                                    </div>
                                </div>
                                <button type="submit" class="btn btn-<?php echo $rutaEditar ? 'warning' : 'primary'; ?>">
                                    <i class="fas fa-save me-2"></i><?php echo $rutaEditar ? 'Actualizar Ruta' : 'Crear Ruta'; ?>
                                </button>
                            </form>
                        </div>
                    </div>
                    
                    <!-- Lista de rutas -->
                    <div class="card">
                        <div class="card-header bg-info text-white">
                            <div class="d-flex justify-content-between align-items-center">
                                <h5 class="mb-0">
                                    <i class="fas fa-list me-2"></i>Lista de Rutas
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
                            <?php if (!empty($rutas)): ?>
                                <div class="table-responsive">
                                    <table class="table table-striped table-hover d-none d-md-table">
                                        <thead>
                                            <tr>
                                                <th>Nombre</th>
                                                <th>Tipo</th>
                                                <th>Dirección/Zona</th>
                                                <th>Estado</th>
                                                <th>Fecha Creación</th>
                                                <th>Acciones</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach ($rutas as $ruta): ?>
                                                <tr class="<?php echo $ruta['tipo_ruta'] === 'grupo_aje' ? 'ruta-grupo-aje' : 'ruta-proveedores-varios'; ?>">
                                                    <td>
                                                        <strong><?php echo htmlspecialchars($ruta['nombre']); ?></strong>
                                                    </td>
                                                    <td>
                                                        <span class="badge bg-<?php echo $ruta['tipo_ruta'] === 'grupo_aje' ? 'success' : 'primary'; ?>">
                                                            <?php echo $ruta['tipo_ruta'] === 'grupo_aje' ? 'Grupo AJE' : 'Proveedores Varios'; ?>
                                                        </span>
                                                    </td>
                                                    <td>
                                                        <small><?php echo htmlspecialchars($ruta['direccion']); ?></small>
                                                    </td>
                                                    <td>
                                                        <span class="badge bg-<?php echo $ruta['estado'] === 'activo' ? 'success' : 'danger'; ?>">
                                                            <?php echo ucfirst($ruta['estado']); ?>
                                                        </span>
                                                    </td>
                                                    <td><?php echo date('d/m/Y', strtotime($ruta['fecha_creacion'])); ?></td>
                                                    <td>
                                                        <a href="rutas.php?editar=<?php echo $ruta['id']; ?>" 
                                                           class="btn btn-warning btn-action">
                                                            <i class="fas fa-edit"></i> Editar
                                                        </a>
                                                        <form method="POST" action="" style="display: inline;">
                                                            <input type="hidden" name="accion" value="cambiar_estado">
                                                            <input type="hidden" name="id" value="<?php echo $ruta['id']; ?>">
                                                            <input type="hidden" name="estado" value="<?php echo $ruta['estado']; ?>">
                                                            <button type="submit" 
                                                                    class="btn btn-<?php echo $ruta['estado'] === 'activo' ? 'danger' : 'success'; ?> btn-action"
                                                                    onclick="return confirm('¿Está seguro de cambiar el estado de esta ruta?')">
                                                                <i class="fas fa-<?php echo $ruta['estado'] === 'activo' ? 'ban' : 'check'; ?>"></i>
                                                                <?php echo $ruta['estado'] === 'activo' ? 'Desactivar' : 'Activar'; ?>
                                                            </button>
                                                        </form>
                                                    </td>
                                                </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                    
                                    <!-- Tabla móvil -->
                                    <div class="d-md-none">
                                        <?php foreach ($rutas as $ruta): ?>
                                            <div class="card mb-3 <?php echo $ruta['tipo_ruta'] === 'grupo_aje' ? 'ruta-grupo-aje' : 'ruta-proveedores-varios'; ?>">
                                                <div class="card-body">
                                                    <h6 class="card-title"><?php echo htmlspecialchars($ruta['nombre']); ?></h6>
                                                    <p class="card-text">
                                                        <span class="badge bg-<?php echo $ruta['tipo_ruta'] === 'grupo_aje' ? 'success' : 'primary'; ?> mb-2">
                                                            <?php echo $ruta['tipo_ruta'] === 'grupo_aje' ? 'Grupo AJE' : 'Proveedores Varios'; ?>
                                                        </span><br>
                                                        <small class="text-muted"><?php echo htmlspecialchars($ruta['direccion']); ?></small><br>
                                                        <span class="badge bg-<?php echo $ruta['estado'] === 'activo' ? 'success' : 'danger'; ?> mt-1">
                                                            <?php echo ucfirst($ruta['estado']); ?>
                                                        </span>
                                                    </p>
                                                    <div class="btn-group w-100" role="group">
                                                        <a href="rutas.php?editar=<?php echo $ruta['id']; ?>" 
                                                           class="btn btn-warning btn-sm">
                                                            <i class="fas fa-edit"></i> Editar
                                                        </a>
                                                        <form method="POST" action="" class="d-inline">
                                                            <input type="hidden" name="accion" value="cambiar_estado">
                                                            <input type="hidden" name="id" value="<?php echo $ruta['id']; ?>">
                                                            <input type="hidden" name="estado" value="<?php echo $ruta['estado']; ?>">
                                                            <button type="submit" 
                                                                    class="btn btn-<?php echo $ruta['estado'] === 'activo' ? 'danger' : 'success'; ?> btn-sm"
                                                                    onclick="return confirm('¿Está seguro de cambiar el estado de esta ruta?')">
                                                                <i class="fas fa-<?php echo $ruta['estado'] === 'activo' ? 'ban' : 'check'; ?>"></i>
                                                                <?php echo $ruta['estado'] === 'activo' ? 'Desactivar' : 'Activar'; ?>
                                                            </button>
                                                        </form>
                                                    </div>
                                                </div>
                                            </div>
                                        <?php endforeach; ?>
                                    </div>
                                </div>
                            <?php else: ?>
                                <div class="text-center py-4">
                                    <i class="fas fa-route fa-3x text-muted mb-3"></i>
                                    <p class="text-muted">No hay rutas registradas</p>
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