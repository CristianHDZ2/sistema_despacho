<?php
// login.php
require_once 'config/database.php';
require_once 'includes/functions.php';

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $usuario = limpiarInput($_POST['usuario']);
    $password = limpiarInput($_POST['password']);
    
    if (empty($usuario) || empty($password)) {
        $error = 'Por favor, complete todos los campos.';
    } else {
        try {
            $db = getDB();
            $stmt = $db->prepare("SELECT * FROM usuarios WHERE usuario = ? AND estado = 'activo'");
            $stmt->execute([$usuario]);
            $user = $stmt->fetch();
            
            if ($user && (password_verify($password, $user['password']) || $user['password'] === $password)) {
                // Si la contraseña está en texto plano, actualizarla a hash
                if ($user['password'] === $password) {
                    $newHash = password_hash($password, PASSWORD_DEFAULT);
                    $updateStmt = $db->prepare("UPDATE usuarios SET password = ? WHERE id = ?");
                    $updateStmt->execute([$newHash, $user['id']]);
                }
                $_SESSION['usuario_id'] = $user['id'];
                $_SESSION['usuario'] = $user['usuario'];
                $_SESSION['tipo_usuario'] = $user['tipo_usuario'];
                $_SESSION['nombre_completo'] = $user['nombre_completo'];
                
                header('Location: dashboard.php');
                exit();
            } else {
                $error = 'Usuario o contraseña incorrectos.';
            }
        } catch (PDOException $e) {
            $error = 'Error en el sistema. Intente nuevamente.';
        }
    }
}

// Si ya está logueado, redirigir al dashboard
if (isset($_SESSION['usuario_id'])) {
    header('Location: dashboard.php');
    exit();
}
?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Sistema de Despacho - Login</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <style>
        body {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 15px;
        }
        .login-container {
            background: white;
            border-radius: 15px;
            box-shadow: 0 10px 30px rgba(0,0,0,0.1);
            padding: 30px;
            width: 100%;
            max-width: 400px;
            margin: auto;
        }
        .login-header {
            text-align: center;
            margin-bottom: 30px;
        }
        .login-header h2 {
            color: #333;
            margin-bottom: 10px;
            font-size: 1.5rem;
        }
        .login-header p {
            color: #666;
            margin: 0;
            font-size: 0.95rem;
        }
        .form-control {
            border-radius: 10px;
            padding: 12px 15px;
            border: 2px solid #f1f1f1;
            font-size: 16px; /* Evita zoom en iOS */
        }
        .form-control:focus {
            border-color: #667eea;
            box-shadow: 0 0 0 0.2rem rgba(102, 126, 234, 0.25);
        }
        .btn-login {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            border: none;
            border-radius: 10px;
            padding: 12px;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 1px;
            transition: all 0.3s ease;
        }
        .btn-login:hover {
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(102, 126, 234, 0.4);
        }
        .input-group-text {
            background: #f8f9fa;
            border: 2px solid #f1f1f1;
            border-right: none;
            border-radius: 10px 0 0 10px;
        }
        .input-group .form-control {
            border-left: none;
            border-radius: 0 10px 10px 0;
        }
        .usuarios-demo {
            background: #f8f9fa;
            border-radius: 10px;
            padding: 15px;
            margin-top: 20px;
            font-size: 12px;
        }
        .usuarios-demo h6 {
            color: #495057;
            margin-bottom: 10px;
            font-size: 0.9rem;
        }
        .usuarios-demo p {
            margin: 5px 0;
            color: #6c757d;
            font-size: 0.8rem;
        }
        
        /* Responsivo para móviles */
        @media (max-width: 576px) {
            .login-container {
                padding: 20px;
                margin: 10px;
                border-radius: 10px;
            }
            .login-header h2 {
                font-size: 1.3rem;
            }
            .form-control {
                padding: 14px 15px; /* Más espacio táctil en móvil */
            }
            .btn-login {
                padding: 14px;
                font-size: 0.9rem;
            }
            .usuarios-demo {
                font-size: 11px;
                padding: 12px;
            }
        }
        
        /* Tablet */
        @media (min-width: 577px) and (max-width: 768px) {
            .login-container {
                max-width: 450px;
                padding: 35px;
            }
        }
        
        /* Desktop grande */
        @media (min-width: 1200px) {
            .login-container {
                max-width: 420px;
                padding: 40px;
            }
        }
    </style>
</head>
<body>
    <div class="login-container">
        <div class="login-header">
            <h2><i class="fas fa-truck me-2"></i>Sistema de Despacho</h2>
            <p>Iniciar Sesión</p>
        </div>
        
        <?php if (!empty($error)): ?>
            <div class="alert alert-danger alert-dismissible fade show" role="alert">
                <i class="fas fa-exclamation-triangle me-2"></i>
                <?php echo $error; ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>
        
        <form method="POST" action="">
            <div class="mb-3">
                <label for="usuario" class="form-label">Usuario</label>
                <div class="input-group">
                    <span class="input-group-text">
                        <i class="fas fa-user"></i>
                    </span>
                    <input type="text" class="form-control" id="usuario" name="usuario" 
                           placeholder="Ingrese su usuario" required value="<?php echo htmlspecialchars($usuario ?? ''); ?>">
                </div>
            </div>
            
            <div class="mb-4">
                <label for="password" class="form-label">Contraseña</label>
                <div class="input-group">
                    <span class="input-group-text">
                        <i class="fas fa-lock"></i>
                    </span>
                    <input type="password" class="form-control" id="password" name="password" 
                           placeholder="Ingrese su contraseña" required>
                </div>
            </div>
            
            <button type="submit" class="btn btn-primary btn-login w-100">
                <i class="fas fa-sign-in-alt me-2"></i>Iniciar Sesión
            </button>
        </form>
        
        <div class="usuarios-demo">
            <h6><i class="fas fa-info-circle me-2"></i>Usuarios de Prueba:</h6>
            <p><strong>Administrador:</strong> admin / Password</p>
            <p><strong>Despachador:</strong> 123456789 / Password</p>
        </div>
    </div>
    
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>