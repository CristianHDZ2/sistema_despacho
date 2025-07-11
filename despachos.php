<?php
// despachos.php
require_once 'config/database.php';
require_once 'includes/functions.php';

verificarLogin();

$db = getDB();
$mensaje = '';
$tipoMensaje = '';

// Obtener fecha seleccionada o usar fecha actual
$fechaSeleccionada = $_GET['fecha'] ?? date('Y-m-d');

// Validar que no sea fecha anterior a hoy para nuevos despachos
$puedeCrearNuevo = !esFechaAnterior($fechaSeleccionada);
$esFechaFutura = esFechaPosterior($fechaSeleccionada);

// Obtener rutas activas
try {
    $stmt = $db->query("SELECT * FROM rutas WHERE estado = 'activo' ORDER BY tipo_ruta, nombre");
    $rutas = $stmt->fetchAll();
} catch (PDOException $e) {
    $rutas = [];
}

// Obtener despachos existentes para la fecha seleccionada
$despachosExistentes = [];
try {
    $stmt = $db->prepare("
        SELECT dr.*, r.nombre as ruta_nombre, r.tipo_ruta
        FROM despachos_ruta dr
        JOIN rutas r ON dr.ruta_id = r.id
        WHERE dr.fecha_despacho = ?
        ORDER BY r.nombre
    ");
    $stmt->execute([$fechaSeleccionada]);
    $despachosExistentes = $stmt->fetchAll();
} catch (PDOException $e) {
    $mensaje = 'Error al obtener despachos: ' . $e->getMessage();
    $tipoMensaje = 'error';
}

// Procesar formulario
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $accion = $_POST['accion'] ?? '';
    
    if ($accion === 'eliminar_despacho' && $_SESSION['tipo_usuario'] === 'admin') {
        $despacho_id = (int)$_POST['despacho_id'];
        
        try {
            $db->beginTransaction();
            
            // Registrar en historial
            $stmt = $db->prepare("
                INSERT INTO despachos_historial (despacho_ruta_id, accion, usuario_id, detalles)
                VALUES (?, 'eliminar', ?, 'Despacho eliminado por administrador')
            ");
            $stmt->execute([$despacho_id, $_SESSION['usuario_id']]);
            
            // Eliminar despacho (cascade eliminará detalles)
            $stmt = $db->prepare("DELETE FROM despachos_ruta WHERE id = ?");
            $stmt->execute([$despacho_id]);
            
            $db->commit();
            $mensaje = 'Despacho eliminado exitosamente';
            $tipoMensaje = 'success';
            
            // Recargar despachos
            $stmt = $db->prepare("
                SELECT dr.*, r.nombre as ruta_nombre, r.tipo_ruta
                FROM despachos_ruta dr
                JOIN rutas r ON dr.ruta_id = r.id
                WHERE dr.fecha_despacho = ?
                ORDER BY r.nombre
            ");
            $stmt->execute([$fechaSeleccionada]);
            $despachosExistentes = $stmt->fetchAll();
            
        } catch (PDOException $e) {
            $db->rollBack();
            $mensaje = 'Error al eliminar despacho: ' . $e->getMessage();
            $tipoMensaje = 'error';
        }
    }
}
?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Sistema de Despachos - Sistema de Despacho</title>
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
        
        .despacho-card {
            border-left: 5px solid;
            transition: transform 0.2s ease;
        }
        .despacho-card:hover {
            transform: translateY(-2px);
        }
        .estado-salida { border-left-color: #28a745; }
        .estado-recarga { border-left-color: #007bff; }
        .estado-segunda-recarga { border-left-color: #ffc107; }
        .estado-retorno { border-left-color: #dc3545; }
        .estado-completado { border-left-color: #6c757d; }
        
        .ruta-grupo-aje { background: linear-gradient(135deg, #d4edda 0%, #c3e6cb 100%); }
        .ruta-proveedores-varios { background: linear-gradient(135deg, #cce7ff 0%, #b3d9ff 100%); }
        
        .date-selector {
            background: white;
            padding: 20px;
            border-radius: 10px;
            margin-bottom: 20px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
        }
        
        .progress-mini {
            height: 6px;
            margin-top: 10px;
        }
        
        .btn-ruta {
            border-radius: 25px;
            padding: 8px 20px;
            font-weight: 500;
            transition: all 0.3s ease;
        }
        
        .empty-state {
            text-align: center;
            padding: 60px 20px;
            color: #6c757d;
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
            .date-selector {
                padding: 15px;
                margin-bottom: 15px;
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
                font-size: 1.1rem;
            }
            .card-body {
                padding: 1rem;
            }
            .card-header h6 {
                font-size: 0.95rem;
            }
            .btn {
                font-size: 0.9rem;
                padding: 6px 12px;
            }
            .btn-ruta {
                padding: 6px 15px;
                font-size: 0.85rem;
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
                <h5 class="navbar-brand mb-0">Sistema de Despachos por Rutas</h5>
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
            
            <!-- Selector de fecha -->
            <div class="date-selector">
                <div class="row align-items-center">
                    <div class="col-md-6">
                        <h5 class="mb-2 mb-md-0">
                            <i class="fas fa-calendar me-2"></i>Despachos del día
                        </h5>
                    </div>
                    <div class="col-md-6">
                        <form method="GET" action="" class="d-flex gap-2">
                            <input type="date" class="form-control" name="fecha" 
                                   value="<?php echo $fechaSeleccionada; ?>" 
                                   onchange="this.form.submit()">
                            <button type="submit" class="btn btn-primary">
                                <i class="fas fa-search"></i>
                            </button>
                        </form>
                    </div>
                </div>
                
                <?php if ($esFechaFutura): ?>
                    <div class="alert alert-info mt-3 mb-0">
                        <i class="fas fa-info-circle me-2"></i>
                        <strong>Fecha futura:</strong> Solo se puede registrar la salida de la mañana.
                    </div>
                <?php elseif (esFechaAnterior($fechaSeleccionada)): ?>
                    <div class="alert alert-warning mt-3 mb-0">
                        <i class="fas fa-exclamation-triangle me-2"></i>
                        <strong>Fecha anterior:</strong> Solo se puede consultar información.
                    </div>
                <?php endif; ?>
            </div>
            
            <!-- Lista de rutas y sus despachos -->
            <div class="row">
                <?php if (!empty($rutas)): ?>
                    <?php foreach ($rutas as $ruta): ?>
                        <?php
                        // Buscar despacho existente para esta ruta
                        $despachoRuta = null;
                        foreach ($despachosExistentes as $despacho) {
                            if ($despacho['ruta_id'] == $ruta['id']) {
                                $despachoRuta = $despacho;
                                break;
                            }
                        }
                        
                        // Determinar progreso
                        $progreso = 0;
                        if ($despachoRuta) {
                            switch ($despachoRuta['estado']) {
                                case 'salida': $progreso = 25; break;
                                case 'recarga': $progreso = 50; break;
                                case 'segunda_recarga': $progreso = 75; break;
                                case 'retorno': case 'completado': $progreso = 100; break;
                            }
                        }
                        ?>
                        
                        <div class="col-lg-6 col-xl-4 mb-4">
                            <div class="card despacho-card <?php echo $despachoRuta ? 'estado-' . $despachoRuta['estado'] : ''; ?> h-100">
                                <div class="card-header <?php echo $ruta['tipo_ruta'] === 'grupo_aje' ? 'ruta-grupo-aje' : 'ruta-proveedores-varios'; ?>">
                                    <div class="d-flex justify-content-between align-items-center">
                                        <h6 class="mb-0">
                                            <i class="fas fa-route me-2"></i>
                                            <?php echo htmlspecialchars($ruta['nombre']); ?>
                                        </h6>
                                        <span class="badge bg-<?php echo $ruta['tipo_ruta'] === 'grupo_aje' ? 'success' : 'primary'; ?>">
                                            <?php echo $ruta['tipo_ruta'] === 'grupo_aje' ? 'AJE' : 'Varios'; ?>
                                        </span>
                                    </div>
                                    
                                    <?php if ($despachoRuta): ?>
                                        <div class="progress progress-mini">
                                            <div class="progress-bar bg-<?php 
                                                echo $progreso === 100 ? 'success' : 'primary'; 
                                            ?>" style="width: <?php echo $progreso; ?>%"></div>
                                        </div>
                                        <small class="text-muted"><?php echo $progreso; ?>% completado</small>
                                    <?php endif; ?>
                                </div>
                                
                                <div class="card-body">
                                    <?php if ($despachoRuta): ?>
                                        <!-- Despacho existente -->
                                        <div class="mb-3">
                                            <strong>Estado actual:</strong>
                                            <span class="badge bg-<?php 
                                                $colores = [
                                                    'salida' => 'success',
                                                    'recarga' => 'primary',
                                                    'segunda_recarga' => 'warning',
                                                    'retorno' => 'danger',
                                                    'completado' => 'secondary'
                                                ];
                                                echo $colores[$despachoRuta['estado']] ?? 'secondary';
                                            ?>">
                                                <?php echo ucfirst(str_replace('_', ' ', $despachoRuta['estado'])); ?>
                                            </span>
                                        </div>
                                        
                                        <div class="mb-3">
                                            <small class="text-muted">
                                                <i class="fas fa-clock me-1"></i>
                                                Creado: <?php echo date('d/m/Y H:i', strtotime($despachoRuta['fecha_creacion'])); ?>
                                            </small>
                                        </div>
                                        
                                        <div class="d-grid gap-2">
                                            <?php if ($despachoRuta['estado'] !== 'completado' && !esFechaAnterior($fechaSeleccionada)): ?>
                                                <a href="despacho_ruta.php?id=<?php echo $despachoRuta['id']; ?>" 
                                                   class="btn btn-primary btn-ruta">
                                                    <i class="fas fa-edit me-2"></i>
                                                    <?php 
                                                    switch ($despachoRuta['estado']) {
                                                        case 'salida': echo 'Registrar Recarga'; break;
                                                        case 'recarga': echo 'Registrar 2da Recarga'; break;
                                                        case 'segunda_recarga': echo 'Registrar Retorno'; break;
                                                        case 'retorno': echo 'Completar Liquidación'; break;
                                                        default: echo 'Editar Despacho';
                                                    }
                                                    ?>
                                                </a>
                                            <?php else: ?>
                                                <a href="despacho_ruta.php?id=<?php echo $despachoRuta['id']; ?>&ver=1" 
                                                   class="btn btn-outline-info btn-ruta">
                                                    <i class="fas fa-eye me-2"></i>Ver Despacho
                                                </a>
                                            <?php endif; ?>
                                            
                                            <?php if ($_SESSION['tipo_usuario'] === 'admin' && $despachoRuta['estado'] !== 'completado'): ?>
                                                <form method="POST" action="" class="d-inline">
                                                    <input type="hidden" name="accion" value="eliminar_despacho">
                                                    <input type="hidden" name="despacho_id" value="<?php echo $despachoRuta['id']; ?>">
                                                    <button type="submit" class="btn btn-outline-danger btn-ruta w-100"
                                                            onclick="return confirm('¿Está seguro de eliminar este despacho? Esta acción no se puede deshacer.')">
                                                        <i class="fas fa-trash me-2"></i>Eliminar Despacho
                                                    </button>
                                                </form>
                                            <?php endif; ?>
                                        </div>
                                    <?php else: ?>
                                        <!-- Sin despacho -->
                                        <div class="text-center py-3">
                                            <i class="fas fa-plus-circle fa-3x text-muted mb-3"></i>
                                            <p class="text-muted mb-3">No hay despacho registrado</p>
                                            
                                            <?php if ($puedeCrearNuevo || ($esFechaFutura && !esFechaAnterior($fechaSeleccionada))): ?>
                                                <a href="despacho_ruta.php?ruta_id=<?php echo $ruta['id']; ?>&fecha=<?php echo $fechaSeleccionada; ?>" 
                                                   class="btn btn-success btn-ruta">
                                                    <i class="fas fa-plus me-2"></i>Crear Despacho
                                                </a>
                                            <?php else: ?>
                                                <small class="text-muted">No se puede crear despacho para fecha anterior</small>
                                            <?php endif; ?>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php else: ?>
                    <div class="col-12">
                        <div class="empty-state">
                            <i class="fas fa-route fa-4x mb-3"></i>
                            <h4>No hay rutas registradas</h4>
                            <p>Para comenzar a usar el sistema de despachos, primero debe crear las rutas.</p>
                            <?php if ($_SESSION['tipo_usuario'] === 'admin'): ?>
                                <a href="rutas.php" class="btn btn-primary">
                                    <i class="fas fa-plus me-2"></i>Crear Rutas
                                </a>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endif; ?>
            </div>
            
            <!-- Resumen general del día -->
            <?php if (!empty($despachosExistentes)): ?>
                <div class="card mt-4">
                    <div class="card-header bg-info text-white">
                        <h5 class="mb-0">
                            <i class="fas fa-chart-pie me-2"></i>
                            Resumen del día - <?php echo date('d/m/Y', strtotime($fechaSeleccionada)); ?>
                        </h5>
                    </div>
                    <div class="card-body">
                        <div class="row text-center">
                            <div class="col-md-3 col-6 mb-3">
                                <h4 class="text-primary"><?php echo count($despachosExistentes); ?></h4>
                                <small class="text-muted">Rutas con Despacho</small>
                            </div>
                            <div class="col-md-3 col-6 mb-3">
                                <h4 class="text-success">
                                    <?php echo count(array_filter($despachosExistentes, function($d) { return $d['estado'] === 'completado'; })); ?>
                                </h4>
                                <small class="text-muted">Rutas Completadas</small>
                            </div>
                            <div class="col-md-3 col-6 mb-3">
                                <h4 class="text-warning">
                                    <?php echo count(array_filter($despachosExistentes, function($d) { return in_array($d['estado'], ['salida', 'recarga', 'segunda_recarga']); })); ?>
                                </h4>
                                <small class="text-muted">En Proceso</small>
                            </div>
                            <div class="col-md-3 col-6 mb-3">
                                <h4 class="text-danger">
                                    <?php echo count(array_filter($despachosExistentes, function($d) { return $d['estado'] === 'retorno'; })); ?>
                                </h4>
                                <small class="text-muted">Pendiente Liquidación</small>
                            </div>
                        </div>
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
    </script>
</body>
</html>