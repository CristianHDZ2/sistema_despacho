<?php
// dashboard.php
require_once 'config/database.php';
require_once 'includes/functions.php';

verificarLogin();

$db = getDB();

// Obtener estadísticas generales
try {
    // Total de productos
    $stmt = $db->query("SELECT COUNT(*) as total FROM productos WHERE estado = 'activo'");
    $totalProductos = $stmt->fetch()['total'];
    
    // Total de rutas
    $stmt = $db->query("SELECT COUNT(*) as total FROM rutas WHERE estado = 'activo'");
    $totalRutas = $stmt->fetch()['total'];
    
    // Productos con stock bajo
    $stmt = $db->query("SELECT COUNT(*) as total FROM productos WHERE stock_actual <= stock_minimo AND estado = 'activo'");
    $stockBajo = $stmt->fetch()['total'];
    
    // Despachos de hoy
    $stmt = $db->prepare("SELECT COUNT(*) as total FROM despachos WHERE fecha_despacho = ?");
    $stmt->execute([date('Y-m-d')]);
    $despachosHoy = $stmt->fetch()['total'];
    
    // Productos más vendidos (últimos 30 días)
    $stmt = $db->prepare("
        SELECT p.nombre, SUM(dd.ventas_calculadas) as total_vendido
        FROM despacho_detalle dd
        JOIN productos p ON dd.producto_id = p.id
        JOIN despachos d ON dd.despacho_id = d.id
        WHERE d.fecha_despacho >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)
        GROUP BY p.id, p.nombre
        ORDER BY total_vendido DESC
        LIMIT 5
    ");
    $stmt->execute();
    $productosVendidos = $stmt->fetchAll();
    
} catch (PDOException $e) {
    $error = "Error al obtener estadísticas: " . $e->getMessage();
}
?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dashboard - Sistema de Despacho</title>
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
            transition: transform 0.3s ease;
            height: 100%;
        }
        .card:hover {
            transform: translateY(-5px);
        }
        .card-icon {
            width: 60px;
            height: 60px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 24px;
            color: white;
            flex-shrink: 0;
        }
        .stat-card-1 .card-icon { background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); }
        .stat-card-2 .card-icon { background: linear-gradient(135deg, #f093fb 0%, #f5576c 100%); }
        .stat-card-3 .card-icon { background: linear-gradient(135deg, #4facfe 0%, #00f2fe 100%); }
        .stat-card-4 .card-icon { background: linear-gradient(135deg, #43e97b 0%, #38f9d7 100%); }
        .welcome-header {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            border-radius: 15px;
            padding: 30px;
            margin-bottom: 30px;
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
            .welcome-header {
                padding: 20px;
                margin-bottom: 20px;
            }
            .welcome-header h2 {
                font-size: 1.5rem;
            }
        }
        
        /* Tablet */
        @media (min-width: 768px) and (max-width: 991.98px) {
            .sidebar {
                width: 250px;
            }
            .card-icon {
                width: 50px;
                height: 50px;
                font-size: 20px;
            }
            .welcome-header {
                padding: 25px;
            }
        }
        
        /* Móvil - Ajustes adicionales */
        @media (max-width: 576px) {
            .container-fluid {
                padding: 0;
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
            .card-icon {
                width: 45px;
                height: 45px;
                font-size: 18px;
            }
            .card-title {
                font-size: 1.2rem;
            }
            .welcome-header {
                padding: 15px;
                margin-bottom: 15px;
            }
            .welcome-header h2 {
                font-size: 1.3rem;
            }
            .welcome-header p {
                font-size: 0.9rem;
            }
            .btn {
                font-size: 0.9rem;
                padding: 8px 12px;
            }
            .badge {
                font-size: 0.7rem;
            }
        }
        
        /* Desktop grande */
        @media (min-width: 1200px) {
            .sidebar {
                width: 280px;
            }
            .card-icon {
                width: 70px;
                height: 70px;
                font-size: 28px;
            }
        }
        
        /* Animaciones suaves para cambios de tamaño */
        .card, .sidebar, .content-area {
            transition: all 0.3s ease;
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
                        <a class="nav-link active" href="dashboard.php">
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
                        <a class="nav-link" href="reportes.php">
                            <i class="fas fa-chart-bar me-2"></i>Reportes
                        </a>
                        <?php endif; ?>
                        
                        <a class="nav-link" href="despachos.php">
                            <i class="fas fa-shipping-fast me-2"></i>Despachos
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
                        <h5 class="navbar-brand mb-0">Dashboard</h5>
                        <div class="navbar-nav ms-auto">
                            <span class="navbar-text">
                                <i class="fas fa-user me-2"></i>
                                <span class="d-none d-sm-inline"><?php echo htmlspecialchars($_SESSION['nombre_completo']); ?></span>
                                <span class="badge bg-primary ms-2">
                                    <?php echo ucfirst($_SESSION['tipo_usuario']); ?>
                                </span>
                            </span>
                        </div>
                    </div>
                </nav>
                
                <!-- Main Content -->
                <div class="container-fluid p-4">
                    <!-- Welcome Header -->
                    <div class="welcome-header">
                        <h2><i class="fas fa-tachometer-alt me-2"></i>Bienvenido al Sistema de Despacho</h2>
                        <p class="mb-0">Aquí puedes ver el resumen general de las operaciones del día</p>
                    </div>
                    
                    <!-- Statistics Cards -->
                    <div class="row mb-4">
                        <div class="col-md-3 mb-3">
                            <div class="card stat-card-1">
                                <div class="card-body d-flex align-items-center">
                                    <div class="card-icon me-3">
                                        <i class="fas fa-box"></i>
                                    </div>
                                    <div>
                                        <h5 class="card-title mb-1"><?php echo $totalProductos; ?></h5>
                                        <p class="card-text text-muted mb-0">Productos Activos</p>
                                    </div>
                                </div>
                            </div>
                        </div>
                        
                        <div class="col-md-3 mb-3">
                            <div class="card stat-card-2">
                                <div class="card-body d-flex align-items-center">
                                    <div class="card-icon me-3">
                                        <i class="fas fa-route"></i>
                                    </div>
                                    <div>
                                        <h5 class="card-title mb-1"><?php echo $totalRutas; ?></h5>
                                        <p class="card-text text-muted mb-0">Rutas Activas</p>
                                    </div>
                                </div>
                            </div>
                        </div>
                        
                        <div class="col-md-3 mb-3">
                            <div class="card stat-card-3">
                                <div class="card-body d-flex align-items-center">
                                    <div class="card-icon me-3">
                                        <i class="fas fa-exclamation-triangle"></i>
                                    </div>
                                    <div>
                                        <h5 class="card-title mb-1"><?php echo $stockBajo; ?></h5>
                                        <p class="card-text text-muted mb-0">Stock Bajo</p>
                                    </div>
                                </div>
                            </div>
                        </div>
                        
                        <div class="col-md-3 mb-3">
                            <div class="card stat-card-4">
                                <div class="card-body d-flex align-items-center">
                                    <div class="card-icon me-3">
                                        <i class="fas fa-shipping-fast"></i>
                                    </div>
                                    <div>
                                        <h5 class="card-title mb-1"><?php echo $despachosHoy; ?></h5>
                                        <p class="card-text text-muted mb-0">Despachos Hoy</p>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Dashboard Content -->
                    <div class="row">
                        <!-- Productos más vendidos -->
                        <div class="col-md-6 mb-4">
                            <div class="card">
                                <div class="card-header bg-primary text-white">
                                    <h5 class="mb-0">
                                        <i class="fas fa-chart-line me-2"></i>
                                        Productos Más Vendidos (Últimos 30 días)
                                    </h5>
                                </div>
                                <div class="card-body">
                                    <?php if (!empty($productosVendidos)): ?>
                                        <?php foreach ($productosVendidos as $producto): ?>
                                            <div class="d-flex justify-content-between align-items-center mb-3">
                                                <div>
                                                    <h6 class="mb-1"><?php echo htmlspecialchars($producto['nombre']); ?></h6>
                                                </div>
                                                <div>
                                                    <span class="badge bg-success">
                                                        <?php echo formatearNumero($producto['total_vendido']); ?> unidades
                                                    </span>
                                                </div>
                                            </div>
                                            <hr class="my-2">
                                        <?php endforeach; ?>
                                    <?php else: ?>
                                        <p class="text-muted text-center">No hay datos de ventas disponibles</p>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                        
                        <!-- Accesos rápidos -->
                        <div class="col-md-6 mb-4">
                            <div class="card">
                                <div class="card-header bg-success text-white">
                                    <h5 class="mb-0">
                                        <i class="fas fa-bolt me-2"></i>
                                        Accesos Rápidos
                                    </h5>
                                </div>
                                <div class="card-body">
                                    <div class="row">
                                        <div class="col-6 mb-3">
                                            <a href="despachos.php" class="btn btn-outline-primary w-100 p-3">
                                                <i class="fas fa-shipping-fast d-block mb-2" style="font-size: 24px;"></i>
                                                Nuevo Despacho
                                            </a>
                                        </div>
                                        
                                        <?php if ($_SESSION['tipo_usuario'] === 'admin'): ?>
                                        <div class="col-6 mb-3">
                                            <a href="productos.php" class="btn btn-outline-info w-100 p-3">
                                                <i class="fas fa-box d-block mb-2" style="font-size: 24px;"></i>
                                                Gestionar Productos
                                            </a>
                                        </div>
                                        <div class="col-6 mb-3">
                                            <a href="rutas.php" class="btn btn-outline-warning w-100 p-3">
                                                <i class="fas fa-route d-block mb-2" style="font-size: 24px;"></i>
                                                Gestionar Rutas
                                            </a>
                                        </div>
                                        <div class="col-6 mb-3">
                                            <a href="reportes.php" class="btn btn-outline-success w-100 p-3">
                                                <i class="fas fa-chart-bar d-block mb-2" style="font-size: 24px;"></i>
                                                Ver Reportes
                                            </a>
                                        </div>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Stock bajo alert -->
                    <?php if ($stockBajo > 0): ?>
                    <div class="row">
                        <div class="col-12">
                            <div class="alert alert-warning alert-dismissible fade show" role="alert">
                                <i class="fas fa-exclamation-triangle me-2"></i>
                                <strong>¡Atención!</strong> Hay <?php echo $stockBajo; ?> producto(s) con stock bajo. 
                                <?php if ($_SESSION['tipo_usuario'] === 'admin'): ?>
                                    <a href="productos.php?filtro=stock_bajo" class="alert-link">Ver productos</a>
                                <?php endif; ?>
                                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                            </div>
                        </div>
                    </div>
                    <?php endif; ?>
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