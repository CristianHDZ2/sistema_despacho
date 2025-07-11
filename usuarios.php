<?php
// usuarios.php
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
        $usuario = limpiarInput($_POST['usuario']);
        $password = limpiarInput($_POST['password']);
        $confirm_password = limpiarInput($_POST['confirm_password']);
        $tipo_usuario = limpiarInput($_POST['tipo_usuario']);
        $nombre_completo = limpiarInput($_POST['nombre_completo']);
        $dui = limpiarInput($_POST['dui'] ?? '');
        
        // Validaciones
        $errores = [];
        
        if (empty($usuario)) {
            $errores[] = 'El usuario es requerido';
        }
        
        if (empty($password)) {
            $errores[] = 'La contraseña es requerida';
        } elseif (strlen($password) < 6) {
            $errores[] = 'La contraseña debe tener al menos 6 caracteres';
        }
        
        if ($password !== $confirm_password) {
            $errores[] = 'Las contraseñas no coinciden';
        }
        
        if (empty($nombre_completo)) {
            $errores[] = 'El nombre completo es requerido';
        }
        
        if (!empty($dui) && !validarDUI($dui)) {
            $errores[] = 'El DUI no tiene un formato válido';
        }
        
        // Verificar si el usuario ya existe
        try {
            $stmt = $db->prepare("SELECT id FROM usuarios WHERE usuario = ?");
            $stmt->execute([$usuario]);
            if ($stmt->fetch()) {
                $errores[] = 'El usuario ya existe';
            }
        } catch (PDOException $e) {
            $errores[] = 'Error al verificar usuario existente';
        }
        
        if (empty($errores)) {
            try {
                $passwordHash = password_hash($password, PASSWORD_DEFAULT);
                $duiFormateado = !empty($dui) ? str_replace(['-', ' '], '', $dui) : null;
                
                $stmt = $db->prepare("
                    INSERT INTO usuarios (usuario, password, tipo_usuario, nombre_completo, dui) 
                    VALUES (?, ?, ?, ?, ?)
                ");
                
                if ($stmt->execute([$usuario, $passwordHash, $tipo_usuario, $nombre_completo, $duiFormateado])) {
                    $mensaje = 'Usuario creado exitosamente';
                    $tipoMensaje = 'success';
                } else {
                    $mensaje = 'Error al crear el usuario';
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
            $stmt = $db->prepare("UPDATE usuarios SET estado = ? WHERE id = ? AND id != 1");
            if ($stmt->execute([$nuevo_estado, $id])) {
                $mensaje = 'Estado del usuario actualizado exitosamente';
                $tipoMensaje = 'success';
            } else {
                $mensaje = 'Error al actualizar el estado del usuario';
                $tipoMensaje = 'error';
            }
        } catch (PDOException $e) {
            $mensaje = 'Error en la base de datos: ' . $e->getMessage();
            $tipoMensaje = 'error';
        }
    }
}

// Obtener lista de usuarios
try {
    $stmt = $db->query("
        SELECT id, usuario, tipo_usuario, nombre_completo, dui, estado, fecha_creacion
        FROM usuarios 
        ORDER BY fecha_creacion DESC
    ");
    $usuarios = $stmt->fetchAll();
} catch (PDOException $e) {
    $mensaje = 'Error al obtener usuarios: ' . $e->getMessage();
    $tipoMensaje = 'error';
    $usuarios = [];
}
?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Gestión de Usuarios - Sistema de Despacho</title>
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
                        <a class="nav-link active" href="usuarios.php">
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
                        <h5 class="navbar-brand mb-0">Gestión de Usuarios</h5>
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
                    
                    <!-- Formulario para crear usuario -->
                    <div class="card mb-4">
                        <div class="card-header bg-primary text-white">
                            <h5 class="mb-0">
                                <i class="fas fa-user-plus me-2"></i>Crear Nuevo Usuario
                            </h5>
                        </div>
                        <div class="card-body">
                            <form method="POST" action="">
                                <input type="hidden" name="accion" value="crear">
                                <div class="row">
                                    <div class="col-md-6 mb-3">
                                        <label for="usuario" class="form-label">Usuario *</label>
                                        <input type="text" class="form-control" id="usuario" name="usuario" required>
                                    </div>
                                    <div class="col-md-6 mb-3">
                                        <label for="tipo_usuario" class="form-label">Tipo de Usuario *</label>
                                        <select class="form-select" id="tipo_usuario" name="tipo_usuario" required>
                                            <option value="">Seleccionar...</option>
                                            <option value="admin">Administrador</option>
                                            <option value="despachador">Despachador</option>
                                        </select>
                                    </div>
                                </div>
                                <div class="row">
                                    <div class="col-md-6 mb-3">
                                        <label for="password" class="form-label">Contraseña *</label>
                                        <input type="password" class="form-control" id="password" name="password" required>
                                        <small class="text-muted">Mínimo 6 caracteres</small>
                                    </div>
                                    <div class="col-md-6 mb-3">
                                        <label for="confirm_password" class="form-label">Confirmar Contraseña *</label>
                                        <input type="password" class="form-control" id="confirm_password" name="confirm_password" required>
                                    </div>
                                </div>
                                <div class="row">
                                    <div class="col-md-6 mb-3">
                                        <label for="nombre_completo" class="form-label">Nombre Completo *</label>
                                        <input type="text" class="form-control" id="nombre_completo" name="nombre_completo" required>
                                    </div>
                                    <div class="col-md-6 mb-3">
                                        <label for="dui" class="form-label">DUI (Opcional)</label>
                                        <input type="text" class="form-control" id="dui" name="dui" 
                                               placeholder="12345678-9" maxlength="10">
                                        <small class="text-muted">Formato: 12345678-9</small>
                                    </div>
                                </div>
                                <button type="submit" class="btn btn-primary">
                                    <i class="fas fa-save me-2"></i>Crear Usuario
                                </button>
                            </form>
                        </div>
                    </div>
                    
                    <!-- Lista de usuarios -->
                    <div class="card">
                        <div class="card-header bg-info text-white">
                            <h5 class="mb-0">
                                <i class="fas fa-list me-2"></i>Lista de Usuarios
                            </h5>
                        </div>
                        <div class="card-body">
                            <?php if (!empty($usuarios)): ?>
                                <!-- Tabla Desktop -->
                                <div class="table-responsive d-none d-md-block">
                                    <table class="table table-striped">
                                        <thead>
                                            <tr>
                                                <th>Usuario</th>
                                                <th>Nombre Completo</th>
                                                <th>Tipo</th>
                                                <th>DUI</th>
                                                <th>Estado</th>
                                                <th>Fecha Creación</th>
                                                <th>Acciones</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach ($usuarios as $user): ?>
                                                <tr>
                                                    <td><?php echo htmlspecialchars($user['usuario']); ?></td>
                                                    <td><?php echo htmlspecialchars($user['nombre_completo']); ?></td>
                                                    <td>
                                                        <span class="badge bg-<?php echo $user['tipo_usuario'] === 'admin' ? 'primary' : 'secondary'; ?>">
                                                            <?php echo ucfirst($user['tipo_usuario']); ?>
                                                        </span>
                                                    </td>
                                                    <td>
                                                        <?php echo $user['dui'] ? formatearDUI($user['dui']) : 'N/A'; ?>
                                                    </td>
                                                    <td>
                                                        <span class="badge bg-<?php echo $user['estado'] === 'activo' ? 'success' : 'danger'; ?>">
                                                            <?php echo ucfirst($user['estado']); ?>
                                                        </span>
                                                    </td>
                                                    <td><?php echo date('d/m/Y', strtotime($user['fecha_creacion'])); ?></td>
                                                    <td>
                                                        <?php if ($user['id'] != 1): // No permitir editar el usuario admin principal ?>
                                                            <form method="POST" action="" style="display: inline;">
                                                                <input type="hidden" name="accion" value="cambiar_estado">
                                                                <input type="hidden" name="id" value="<?php echo $user['id']; ?>">
                                                                <input type="hidden" name="estado" value="<?php echo $user['estado']; ?>">
                                                                <button type="submit" 
                                                                        class="btn btn-<?php echo $user['estado'] === 'activo' ? 'warning' : 'success'; ?> btn-action"
                                                                        onclick="return confirm('¿Está seguro de cambiar el estado de este usuario?')">
                                                                    <i class="fas fa-<?php echo $user['estado'] === 'activo' ? 'ban' : 'check'; ?>"></i>
                                                                    <?php echo $user['estado'] === 'activo' ? 'Desactivar' : 'Activar'; ?>
                                                                </button>
                                                            </form>
                                                        <?php else: ?>
                                                            <span class="text-muted">Usuario Principal</span>
                                                        <?php endif; ?>
                                                    </td>
                                                </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>
                                
                                <!-- Cards Móviles -->
                                <div class="d-md-none">
                                    <?php foreach ($usuarios as $user): ?>
                                        <div class="card mb-3">
                                            <div class="card-body">
                                                <h6 class="card-title"><?php echo htmlspecialchars($user['nombre_completo']); ?></h6>
                                                <p class="card-text">
                                                    <strong>Usuario:</strong> <?php echo htmlspecialchars($user['usuario']); ?><br>
                                                    <strong>Tipo:</strong> 
                                                    <span class="badge bg-<?php echo $user['tipo_usuario'] === 'admin' ? 'primary' : 'secondary'; ?>">
                                                        <?php echo ucfirst($user['tipo_usuario']); ?>
                                                    </span><br>
                                                    <strong>DUI:</strong> <?php echo $user['dui'] ? formatearDUI($user['dui']) : 'N/A'; ?><br>
                                                    <strong>Estado:</strong> 
                                                    <span class="badge bg-<?php echo $user['estado'] === 'activo' ? 'success' : 'danger'; ?>">
                                                        <?php echo ucfirst($user['estado']); ?>
                                                    </span><br>
                                                    <small class="text-muted">
                                                        Creado: <?php echo date('d/m/Y', strtotime($user['fecha_creacion'])); ?>
                                                    </small>
                                                </p>
                                                <?php if ($user['id'] != 1): ?>
                                                    <form method="POST" action="">
                                                        <input type="hidden" name="accion" value="cambiar_estado">
                                                        <input type="hidden" name="id" value="<?php echo $user['id']; ?>">
                                                        <input type="hidden" name="estado" value="<?php echo $user['estado']; ?>">
                                                        <button type="submit" 
                                                                class="btn btn-<?php echo $user['estado'] === 'activo' ? 'warning' : 'success'; ?> btn-sm"
                                                                onclick="return confirm('¿Está seguro de cambiar el estado de este usuario?')">
                                                            <i class="fas fa-<?php echo $user['estado'] === 'activo' ? 'ban' : 'check'; ?>"></i>
                                                            <?php echo $user['estado'] === 'activo' ? 'Desactivar' : 'Activar'; ?>
                                                        </button>
                                                    </form>
                                                <?php else: ?>
                                                    <span class="text-muted">Usuario Principal</span>
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            <?php else: ?>
                                <div class="text-center py-4">
                                    <i class="fas fa-users fa-3x text-muted mb-3"></i>
                                    <p class="text-center text-muted">No hay usuarios registrados</p>
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
        
        // Auto-formatear DUI
        const duiInput = document.getElementById('dui');
        if (duiInput) {
            duiInput.addEventListener('input', function(e) {
                let value = e.target.value.replace(/[^0-9]/g, '');
                if (value.length > 8) {
                    value = value.substring(0, 8) + '-' + value.substring(8, 9);
                }
                e.target.value = value;
            });
        }
        
        // Validar contraseñas
        const confirmPasswordInput = document.getElementById('confirm_password');
        if (confirmPasswordInput) {
            confirmPasswordInput.addEventListener('input', function(e) {
                const password = document.getElementById('password').value;
                const confirmPassword = e.target.value;
                
                if (password !== confirmPassword) {
                    e.target.setCustomValidity('Las contraseñas no coinciden');
                } else {
                    e.target.setCustomValidity('');
                }
            });
        }
    </script>
</body>
</html>