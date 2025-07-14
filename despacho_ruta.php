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
                VALUES (?, ?, ?, 'pendiente_salida')
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
        $nuevos_productos = $_POST['nuevos_productos'] ?? [];
        
        try {
            $db->beginTransaction();
            
            $productosGuardados = 0;
            
            // Procesar productos existentes
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
            
            // Procesar nuevos productos (solo en recargas)
            if (($evento === 'recarga' || $evento === 'segunda_recarga') && !empty($nuevos_productos)) {
                foreach ($nuevos_productos as $producto_id => $cantidad) {
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
                                SET $evento = $evento + ? 
                                WHERE id = ?
                            ");
                            $stmt->execute([$cantidad, $detalle_existente['id']]);
                        } else {
                            // Crear nuevo registro (solo con la recarga)
                            $stmt = $db->prepare("
                                INSERT INTO despacho_ruta_detalle (despacho_ruta_id, producto_id, $evento) 
                                VALUES (?, ?, ?)
                            ");
                            $stmt->execute([$despacho['id'], $producto_id, $cantidad]);
                        }
                        
                        $productosGuardados++;
                    }
                }
            }
            
            // Validar que se haya registrado al menos un producto para SALIDA
            if ($evento === 'salida' && $productosGuardados === 0) {
                $db->rollBack();
                $mensaje = 'Debe registrar al menos un producto en la SALIDA';
                $tipoMensaje = 'error';
            } else {
                // Actualizar estado del despacho según el evento
                $nuevoEstado = '';
                switch ($evento) {
                    case 'salida':
                        $nuevoEstado = 'pendiente_recarga';
                        break;
                    case 'recarga':
                        $nuevoEstado = 'pendiente_segunda_recarga';
                        break;
                    case 'segunda_recarga':
                        $nuevoEstado = 'pendiente_retorno';
                        break;
                    case 'retorno':
                        // Calcular ventas y preparar para liquidación
                        $stmt = $db->prepare("
                            UPDATE despacho_ruta_detalle 
                            SET ventas_calculadas = (COALESCE(salida, 0) + COALESCE(recarga, 0) + COALESCE(segunda_recarga, 0) - COALESCE(retorno, 0))
                            WHERE despacho_ruta_id = ?
                        ");
                        $stmt->execute([$despacho['id']]);
                        
                        $nuevoEstado = 'pendiente_liquidacion';
                        break;
                }
                
                if ($nuevoEstado) {
                    $stmt = $db->prepare("UPDATE despachos_ruta SET estado = ? WHERE id = ?");
                    $stmt->execute([$nuevoEstado, $despacho['id']]);
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
                
                // Recargar datos
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
            }
            
        } catch (PDOException $e) {
            $db->rollBack();
            $mensaje = 'Error al guardar productos: ' . $e->getMessage();
            $tipoMensaje = 'error';
        }
    }
    elseif ($accion === 'omitir_recarga') {
        // Omitir recarga (solo para rutas que no son Grupo AJE)
        $evento = $_POST['evento'];
        
        try {
            $nuevoEstado = '';
            switch ($evento) {
                case 'recarga':
                    $nuevoEstado = 'pendiente_segunda_recarga';
                    break;
                case 'segunda_recarga':
                    $nuevoEstado = 'pendiente_retorno';
                    break;
            }
            
            if ($nuevoEstado) {
                $stmt = $db->prepare("UPDATE despachos_ruta SET estado = ? WHERE id = ?");
                $stmt->execute([$nuevoEstado, $despacho['id']]);
                
                // Registrar en historial
                $stmt = $db->prepare("
                    INSERT INTO despachos_historial (despacho_ruta_id, accion, usuario_id, detalles)
                    VALUES (?, 'omitir', ?, ?)
                ");
                $stmt->execute([$despacho['id'], $_SESSION['usuario_id'], "Omitido $evento"]);
                
                $mensaje = ucfirst($evento) . ' omitido exitosamente';
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
            }
            
        } catch (PDOException $e) {
            $mensaje = 'Error al omitir recarga: ' . $e->getMessage();
            $tipoMensaje = 'error';
        }
    }
    
    elseif ($accion === 'preview_liquidacion') {
        // Vista previa de liquidación con precios especiales
        $precios_especiales = $_POST['precios_especiales'] ?? [];
        $preview_data = [];
        
        foreach ($detalles as $detalle) {
            $ventas = $detalle['ventas_calculadas'];
            $total_dinero = 0;
            $precio_normal = $detalle['precio_unitario'];
            $detalles_precio = [];
            
            // Verificar si hay precios especiales para este producto
            $precio_especial_key = $detalle['producto_id'];
            if (isset($precios_especiales[$precio_especial_key]) && !empty($precios_especiales[$precio_especial_key])) {
                $precios_data = $precios_especiales[$precio_especial_key];
                $cantidad_especial_total = 0;
                
                // Calcular precios especiales
                foreach ($precios_data['cantidad'] as $index => $cantidad_esp) {
                    $cantidad_esp = (float)$cantidad_esp;
                    $precio_esp = (float)$precios_data['precio'][$index];
                    
                    if ($cantidad_esp > 0 && $precio_esp > 0) {
                        $subtotal_esp = $cantidad_esp * $precio_esp;
                        $total_dinero += $subtotal_esp;
                        $cantidad_especial_total += $cantidad_esp;
                        
                        $detalles_precio[] = [
                            'cantidad' => $cantidad_esp,
                            'precio' => $precio_esp,
                            'subtotal' => $subtotal_esp,
                            'tipo' => 'especial'
                        ];
                    }
                }
                
                // Calcular resto con precio normal
                $cantidad_normal = $ventas - $cantidad_especial_total;
                if ($cantidad_normal > 0) {
                    if ($detalle['usa_formula']) {
                        $subtotal_normal = calcularVentaFormula($cantidad_normal);
                    } else {
                        $subtotal_normal = calcularVentaNormal($cantidad_normal, $detalle['precio_unitario']);
                    }
                    $total_dinero += $subtotal_normal;
                    
                    $detalles_precio[] = [
                        'cantidad' => $cantidad_normal,
                        'precio' => $precio_normal,
                        'subtotal' => $subtotal_normal,
                        'tipo' => 'normal'
                    ];
                }
            } else {
                // Sin precios especiales, usar precio normal
                if ($detalle['usa_formula']) {
                    $total_dinero = calcularVentaFormula($ventas);
                } else {
                    $total_dinero = calcularVentaNormal($ventas, $detalle['precio_unitario']);
                }
                
                $detalles_precio[] = [
                    'cantidad' => $ventas,
                    'precio' => $precio_normal,
                    'subtotal' => $total_dinero,
                    'tipo' => 'normal'
                ];
            }
            
            $preview_data[] = [
                'producto_id' => $detalle['producto_id'],
                'producto_nombre' => $detalle['producto_nombre'],
                'categoria' => $detalle['categoria'],
                'ventas_calculadas' => $ventas,
                'total_dinero' => $total_dinero,
                'detalles_precio' => $detalles_precio
            ];
        }
        
        // Mostrar vista previa en lugar de guardar
        $mostrar_preview = true;
        $preview_precios_especiales = $precios_especiales;
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
            
        } catch (PDOException $e) {
            $db->rollBack();
            $mensaje = 'Error en la liquidación: ' . $e->getMessage();
            $tipoMensaje = 'error';
        }
    }
    
    elseif ($accion === 'eliminar_despacho' && $_SESSION['tipo_usuario'] === 'admin') {
        // Eliminar despacho completamente (solo admin)
        try {
            $db->beginTransaction();
            
            // Eliminar precios especiales
            $stmt = $db->prepare("
                DELETE vpe FROM ventas_precios_especiales vpe
                JOIN despacho_ruta_detalle drd ON vpe.despacho_ruta_detalle_id = drd.id
                WHERE drd.despacho_ruta_id = ?
            ");
            $stmt->execute([$despacho['id']]);
            
            // Eliminar detalles
            $stmt = $db->prepare("DELETE FROM despacho_ruta_detalle WHERE despacho_ruta_id = ?");
            $stmt->execute([$despacho['id']]);
            
            // Eliminar historial
            $stmt = $db->prepare("DELETE FROM despachos_historial WHERE despacho_ruta_id = ?");
            $stmt->execute([$despacho['id']]);
            
            // Eliminar despacho
            $stmt = $db->prepare("DELETE FROM despachos_ruta WHERE id = ?");
            $stmt->execute([$despacho['id']]);
            
            $db->commit();
            
            header('Location: despachos.php?fecha=' . $fecha);
            exit();
            
        } catch (PDOException $e) {
            $db->rollBack();
            $mensaje = 'Error al eliminar despacho: ' . $e->getMessage();
            $tipoMensaje = 'error';
        }
    }
}

// Determinar evento actual y título
$eventoActual = '';
$tituloEvento = '';
$esObligatorio = true;
$puedeOmitir = false;

if ($despacho) {
    switch ($despacho['estado']) {
        case 'pendiente_salida':
            $eventoActual = 'salida';
            $tituloEvento = 'Registrar Salida de la Mañana';
            $esObligatorio = true;
            break;
        case 'pendiente_recarga':
            $eventoActual = 'recarga';
            $tituloEvento = 'Registrar Primera Recarga';
            $esObligatorio = ($despacho['tipo_ruta'] === 'grupo_aje');
            $puedeOmitir = ($despacho['tipo_ruta'] !== 'grupo_aje');
            break;
        case 'pendiente_segunda_recarga':
            $eventoActual = 'segunda_recarga';
            $tituloEvento = 'Registrar Segunda Recarga';
            $esObligatorio = false;
            $puedeOmitir = true;
            break;
        case 'pendiente_retorno':
            $eventoActual = 'retorno';
            $tituloEvento = 'Registrar Retorno';
            $esObligatorio = true;
            break;
        case 'pendiente_liquidacion':
            $eventoActual = 'liquidacion';
            $tituloEvento = 'Completar Liquidación';
            $esObligatorio = true;
            break;
        case 'completado':
            $eventoActual = 'completado';
            $tituloEvento = 'Despacho Completado';
            break;
    }
} else {
    $eventoActual = 'crear';
    $tituloEvento = 'Crear Nuevo Despacho';
}

// Obtener productos que tienen salida registrada (para mostrar en recargas y retorno)
$productosConSalida = [];
if ($despacho && in_array($eventoActual, ['recarga', 'segunda_recarga', 'retorno'])) {
    foreach ($detalles as $detalle) {
        if ($detalle['salida'] > 0) {
            $productosConSalida[] = $detalle['producto_id'];
        }
    }
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
        
        .search-box {
            background: #f8f9fa;
            border-radius: 10px;
            padding: 20px;
            margin-bottom: 20px;
        }
        
        .preview-modal {
            background: rgba(0,0,0,0.5);
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            z-index: 2000;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        
        .preview-content {
            background: white;
            border-radius: 15px;
            padding: 30px;
            max-width: 90%;
            max-height: 90%;
            overflow-y: auto;
        }
        
        .nuevo-producto-section {
            background: #fff3cd;
            border-radius: 10px;
            padding: 20px;
            margin-top: 20px;
            border-left: 4px solid #ffc107;
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
            .search-box {
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
                    <a href="despachos.php?fecha=<?php echo $fecha; ?>" class="btn btn-outline-primary btn-sm me-2">
                        <i class="fas fa-arrow-left me-1"></i>Volver
                    </a>
                    <?php if ($_SESSION['tipo_usuario'] === 'admin' && $despacho): ?>
                        <button class="btn btn-outline-danger btn-sm" onclick="eliminarDespacho()">
                            <i class="fas fa-trash me-1"></i>Eliminar
                        </button>
                    <?php endif; ?>
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
                    <div class="progress-step <?php echo in_array($despacho['estado'], ['pendiente_recarga', 'pendiente_segunda_recarga', 'pendiente_retorno', 'pendiente_liquidacion', 'completado']) ? 'completed' : 'active'; ?>">
                        <div class="step-circle">1</div>
                        <small>Salida</small>
                    </div>
                    <div class="progress-step <?php echo in_array($despacho['estado'], ['pendiente_segunda_recarga', 'pendiente_retorno', 'pendiente_liquidacion', 'completado']) ? 'completed' : ($despacho['estado'] === 'pendiente_recarga' ? 'active' : ''); ?>">
                        <div class="step-circle">2</div>
                        <small>1ra Recarga</small>
                    </div>
                    <div class="progress-step <?php echo in_array($despacho['estado'], ['pendiente_retorno', 'pendiente_liquidacion', 'completado']) ? 'completed' : ($despacho['estado'] === 'pendiente_segunda_recarga' ? 'active' : ''); ?>">
                        <div class="step-circle">3</div>
                        <small>2da Recarga</small>
                    </div>
                    <div class="progress-step <?php echo in_array($despacho['estado'], ['pendiente_liquidacion', 'completado']) ? 'completed' : ($despacho['estado'] === 'pendiente_retorno' ? 'active' : ''); ?>">
                        <div class="step-circle">4</div>
                        <small>Retorno</small>
                    </div>
                    <div class="progress-step <?php echo $despacho['estado'] === 'completado' ? 'completed' : ($despacho['estado'] === 'pendiente_liquidacion' ? 'active' : ''); ?>">
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
                                                <th>1ra Recarga</th>
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
                                Revise las ventas calculadas y ajuste precios especiales si es necesario.
                            </div>
                            
                            <!-- Vista previa si existe -->
                            <?php if (isset($mostrar_preview) && $mostrar_preview): ?>
                                <div class="alert alert-warning">
                                    <h6><i class="fas fa-eye me-2"></i>Vista Previa de Liquidación</h6>
                                    <div class="table-responsive">
                                        <table class="table table-sm">
                                            <thead>
                                                <tr>
                                                    <th>Producto</th>
                                                    <th>Vendido</th>
                                                    <th>Detalles de Precio</th>
                                                    <th>Total</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                <?php 
                                                $totalPreview = 0;
                                                foreach ($preview_data as $item): 
                                                    $totalPreview += $item['total_dinero'];
                                                ?>
                                                    <tr>
                                                        <td><?php echo htmlspecialchars($item['producto_nombre']); ?></td>
                                                        <td><?php echo formatearNumero($item['ventas_calculadas']); ?></td>
                                                        <td>
                                                            <?php foreach ($item['detalles_precio'] as $detalle_precio): ?>
                                                                <small class="d-block">
                                                                    <?php echo formatearNumero($detalle_precio['cantidad']); ?> × 
                                                                    <?php echo formatearDinero($detalle_precio['precio']); ?> 
                                                                    (<?php echo $detalle_precio['tipo'] === 'especial' ? 'Especial' : 'Normal'; ?>) = 
                                                                    <?php echo formatearDinero($detalle_precio['subtotal']); ?>
                                                                </small>
                                                            <?php endforeach; ?>
                                                        </td>
                                                        <td><strong><?php echo formatearDinero($item['total_dinero']); ?></strong></td>
                                                    </tr>
                                                <?php endforeach; ?>
                                            </tbody>
                                            <tfoot>
                                                <tr class="table-info">
                                                    <th colspan="3">TOTAL GENERAL</th>
                                                    <th><?php echo formatearDinero($totalPreview); ?></th>
                                                </tr>
                                            </tfoot>
                                        </table>
                                    </div>
                                    
                                    <form method="POST" action="" class="mt-3">
                                        <input type="hidden" name="accion" value="liquidar_despacho">
                                        <?php foreach ($preview_precios_especiales as $producto_id => $precios_data): ?>
                                            <?php foreach ($precios_data['cantidad'] as $index => $cantidad): ?>
                                                <input type="hidden" name="precios_especiales[<?php echo $producto_id; ?>][cantidad][]" value="<?php echo $cantidad; ?>">
                                                <input type="hidden" name="precios_especiales[<?php echo $producto_id; ?>][precio][]" value="<?php echo $precios_data['precio'][$index]; ?>">
                                            <?php endforeach; ?>
                                        <?php endforeach; ?>
                                        
                                        <button type="submit" class="btn btn-success me-2" 
                                                onclick="return confirm('¿Confirma la liquidación con estos valores?')">
                                            <i class="fas fa-check me-2"></i>Confirmar Liquidación
                                        </button>
                                        <button type="button" class="btn btn-secondary" onclick="location.reload()">
                                            <i class="fas fa-times me-2"></i>Cancelar
                                        </button>
                                    </form>
                                </div>
                            <?php else: ?>
                                <!-- Formulario normal de liquidación -->
                                <form method="POST" action="">
                                    <input type="hidden" name="accion" value="preview_liquidacion">
                                    
                                    <?php if (!empty($detalles)): ?>
                                        <div class="table-responsive">
                                            <table class="table table-striped">
                                                <thead>
                                                    <tr>
                                                        <th>Producto</th>
                                                        <th>Enviado Total</th>
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
                                                <button type="submit" class="btn btn-warning">
                                                    <i class="fas fa-eye me-2"></i>Vista Previa
                                                </button>
                                            </div>
                                        </div>
                                    <?php endif; ?>
                                </form>
                            <?php endif; ?>
                        </div>
                    </div>
                    
                <?php elseif (in_array($eventoActual, ['salida', 'recarga', 'segunda_recarga', 'retorno'])): ?>
                    <!-- Formulario para registrar productos -->
                    <div class="card">
                        <div class="card-header bg-<?php echo $eventoActual === 'salida' ? 'success' : ($eventoActual === 'retorno' ? 'danger' : 'primary'); ?> text-white">
                            <div class="d-flex justify-content-between align-items-center">
                                <h5 class="mb-0">
                                    <i class="fas fa-clipboard-list me-2"></i><?php echo $tituloEvento; ?>
                                    <?php if (!$esObligatorio): ?>
                                        <span class="badge bg-light text-dark ms-2">Opcional</span>
                                    <?php endif; ?>
                                </h5>
                                <?php if ($puedeOmitir && $puedeEditar): ?>
                                    <form method="POST" action="" class="d-inline">
                                        <input type="hidden" name="accion" value="omitir_recarga">
                                        <input type="hidden" name="evento" value="<?php echo $eventoActual; ?>">
                                        <button type="submit" class="btn btn-outline-light btn-sm" 
                                                onclick="return confirm('¿Está seguro de omitir esta <?php echo $eventoActual; ?>?')">
                                            <i class="fas fa-forward me-1"></i>Omitir
                                        </button>
                                    </form>
                                <?php endif; ?>
                            </div>
                        </div>
                        <div class="card-body">
                            <?php if ($puedeEditar): ?>
                                <!-- Buscador de productos -->
                                <div class="search-box">
                                    <div class="row">
                                        <div class="col-md-6">
                                            <label class="form-label">Buscar Producto</label>
                                            <input type="text" class="form-control" id="buscarProducto" 
                                                   placeholder="Buscar por nombre o medida...">
                                        </div>
                                        <div class="col-md-6">
                                            <label class="form-label">Filtrar por Categoría</label>
                                            <select class="form-select" id="filtroCategoria">
                                                <option value="">Todas las categorías</option>
                                                <option value="grupo_aje">Grupo AJE</option>
                                                <option value="proveedores_varios">Proveedores Varios</option>
                                            </select>
                                        </div>
                                    </div>
                                </div>
                                
                                <form method="POST" action="" id="formProductos">
                                    <input type="hidden" name="accion" value="guardar_productos">
                                    <input type="hidden" name="evento" value="<?php echo $eventoActual; ?>">
                                    
                                    <div class="alert alert-info">
                                        <i class="fas fa-info-circle me-2"></i>
                                        <?php if ($eventoActual === 'salida'): ?>
                                            Ingrese las cantidades de productos que <strong>SALEN</strong> en la mañana. Solo los productos con cantidad aparecerán en las recargas.
                                        <?php elseif ($eventoActual === 'retorno'): ?>
                                            Ingrese las cantidades de productos que <strong>REGRESAN</strong> sin vender.
                                            <br><strong>Fórmula:</strong> VENDIDO = (SALIDA + RECARGA + 2DA RECARGA) - RETORNO
                                        <?php else: ?>
                                            Ingrese las cantidades de productos para <strong><?php echo strtoupper($eventoActual); ?></strong>.
                                        <?php endif; ?>
                                    </div>
                                    
                                    <!-- Lista de productos -->
                                    <div class="row" id="productosContainer">
                                        <?php 
                                        // Para salida mostrar todos los productos
                                        // Para recargas y retorno, mostrar solo los que tienen salida
                                        $mostrarProductos = [];
                                        if ($eventoActual === 'salida') {
                                            $mostrarProductos = $productos;
                                        } else {
                                            foreach ($productos as $producto) {
                                        if ($eventoActual === 'salida') {
                                            $mostrarProductos = $productos;
                                        } else {
                                            foreach ($productos as $producto) {
                                                if (in_array($producto['id'], $productosConSalida)) {
                                                    $mostrarProductos[] = $producto;
                                                }
                                            }
                                        }
                                        
                                        foreach ($mostrarProductos as $producto): ?>
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
                                                    
                                                    <div class="mb-3">
                                                        <small class="text-muted">
                                                            <strong>Precio:</strong> <?php echo formatearDinero($producto['precio_unitario']); ?>
                                                            <?php if ($producto['usa_formula']): ?>
                                                                <span class="badge bg-info">Fórmula</span>
                                                            <?php endif; ?>
                                                            <br><strong>Stock:</strong> <?php echo formatearNumero($producto['stock_actual']); ?>
                                                            
                                                            <?php if ($eventoActual !== 'salida'): ?>
                                                                <?php
                                                                // Mostrar cantidades anteriores
                                                                $detalleActual = null;
                                                                foreach ($detalles as $detalle) {
                                                                    if ($detalle['producto_id'] == $producto['id']) {
                                                                        $detalleActual = $detalle;
                                                                        break;
                                                                    }
                                                                }
                                                                if ($detalleActual):
                                                                ?>
                                                                    <br><strong>Salida:</strong> <?php echo formatearNumero($detalleActual['salida']); ?>
                                                                    <?php if ($eventoActual !== 'recarga'): ?>
                                                                        <strong>Recarga:</strong> <?php echo formatearNumero($detalleActual['recarga']); ?>
                                                                    <?php endif; ?>
                                                                    <?php if ($eventoActual === 'retorno'): ?>
                                                                        <strong>2da Recarga:</strong> <?php echo formatearNumero($detalleActual['segunda_recarga']); ?>
                                                                        <br><strong>Total Enviado:</strong> <?php echo formatearNumero($detalleActual['salida'] + $detalleActual['recarga'] + $detalleActual['segunda_recarga']); ?>
                                                                    <?php endif; ?>
                                                                <?php endif; ?>
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
                                    
                                    <!-- Sección para agregar nuevos productos (solo en recargas) -->
                                    <?php if (in_array($eventoActual, ['recarga', 'segunda_recarga'])): ?>
                                        <div class="nuevo-producto-section">
                                            <h6><i class="fas fa-plus-circle me-2"></i>Agregar Productos No Llevados en Salida</h6>
                                            <p class="text-muted mb-3">Si necesita agregar productos que no se llevaron en la salida inicial.</p>
                                            
                                            <div class="row">
                                                <div class="col-md-6 mb-3">
                                                    <input type="text" class="form-control" id="buscarNuevoProducto" 
                                                           placeholder="Buscar producto para agregar...">
                                                </div>
                                                <div class="col-md-6 mb-3">
                                                    <select class="form-select" id="filtroNuevaCategoria">
                                                        <option value="">Todas las categorías</option>
                                                        <option value="grupo_aje">Grupo AJE</option>
                                                        <option value="proveedores_varios">Proveedores Varios</option>
                                                    </select>
                                                </div>
                                            </div>
                                            
                                            <div class="row" id="nuevosProductosContainer">
                                                <?php 
                                                // Mostrar productos que NO tienen salida
                                                foreach ($productos as $producto): 
                                                    if (!in_array($producto['id'], $productosConSalida)):
                                                ?>
                                                    <div class="col-md-6 col-lg-4 nuevo-producto-item" 
                                                         data-categoria="<?php echo $producto['categoria']; ?>"
                                                         data-nombre="<?php echo strtolower($producto['nombre']); ?>"
                                                         style="display: none;">
                                                        <div class="producto-card producto-<?php echo $producto['categoria']; ?>" style="opacity: 0.8;">
                                                            <div class="d-flex justify-content-between align-items-start mb-2">
                                                                <h6 class="mb-0"><?php echo htmlspecialchars($producto['nombre']); ?></h6>
                                                                <span class="badge bg-warning">Nuevo</span>
                                                            </div>
                                                            
                                                            <div class="mb-3">
                                                                <small class="text-muted">
                                                                    <strong>Precio:</strong> <?php echo formatearDinero($producto['precio_unitario']); ?>
                                                                    <br><strong>Stock:</strong> <?php echo formatearNumero($producto['stock_actual']); ?>
                                                                </small>
                                                            </div>
                                                            
                                                            <div class="input-group">
                                                                <span class="input-group-text">Cantidad</span>
                                                                <input type="number" class="form-control" 
                                                                       name="nuevos_productos[<?php echo $producto['id']; ?>]"
                                                                       step="0.5" min="0" 
                                                                       placeholder="0">
                                                            </div>
                                                        </div>
                                                    </div>
                                                <?php 
                                                    endif;
                                                endforeach; 
                                                ?>
                                            </div>
                                            
                                            <button type="button" class="btn btn-outline-warning btn-sm" onclick="mostrarNuevosProductos()">
                                                <i class="fas fa-eye me-1"></i>Mostrar Productos Disponibles
                                            </button>
                                        </div>
                                    <?php endif; ?>
                                    
                                    <div class="row mt-4">
                                        <div class="col-12">
                                            <button type="submit" class="btn btn-<?php echo $eventoActual === 'salida' ? 'success' : ($eventoActual === 'retorno' ? 'danger' : 'primary'); ?>">
                                                <i class="fas fa-save me-2"></i>Guardar <?php echo ucfirst($eventoActual); ?>
                                            </button>
                                        </div>
                                    </div>
                                </form>
                            <?php else: ?>
                                <div class="alert alert-warning">
                                    <i class="fas fa-exclamation-triangle me-2"></i>
                                    No se puede editar este despacho.
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                    
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
            
            sidebarToggle?.addEventListener('click', function() {
                sidebar.classList.toggle('show');
                sidebarBackdrop.classList.toggle('show');
            });
            
            sidebarBackdrop?.addEventListener('click', function() {
                sidebar.classList.remove('show');
                sidebarBackdrop.classList.remove('show');
            });
        });
        
        // Buscador de productos
        function setupProductSearch(searchId, categoryId, containerId, itemClass) {
            const buscarInput = document.getElementById(searchId);
            const filtroCategoria = document.getElementById(categoryId);
            const container = document.getElementById(containerId);
            
            if (buscarInput && filtroCategoria && container) {
                function filtrarProductos() {
                    const textoBusqueda = buscarInput.value.toLowerCase();
                    const categoriaSeleccionada = filtroCategoria.value;
                    const productos = container.querySelectorAll('.' + itemClass);
                    
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
        }
        
        // Configurar buscadores
        document.addEventListener('DOMContentLoaded', function() {
            setupProductSearch('buscarProducto', 'filtroCategoria', 'productosContainer', 'producto-item');
            setupProductSearch('buscarNuevoProducto', 'filtroNuevaCategoria', 'nuevosProductosContainer', 'nuevo-producto-item');
        });
        
        // Mostrar productos nuevos
        function mostrarNuevosProductos() {
            const productos = document.querySelectorAll('.nuevo-producto-item');
            productos.forEach(producto => {
                producto.style.display = 'block';
            });
        }
        
        // Función para agregar precios especiales
        function agregarPrecioEspecial(productoId) {
            const container = document.getElementById('precios_especiales_' + productoId);
            const row = document.createElement('div');
            row.className = 'row mb-2';
            row.innerHTML = `
                <div class="col-4">
                    <input type="number" class="form-control form-control-sm" 
                           name="precios_especiales[${productoId}][cantidad][]" 
                           placeholder="Cantidad" step="0.5" min="0" required>
                </div>
                <div class="col-4">
                    <input type="number" class="form-control form-control-sm" 
                           name="precios_especiales[${productoId}][precio][]" 
                           placeholder="Precio" step="0.01" min="0" required>
                </div>
                <div class="col-4">
                    <button type="button" class="btn btn-danger btn-sm" 
                            onclick="this.closest('.row').remove()">
                        <i class="fas fa-trash"></i>
                    </button>
                </div>
            `;
            container.appendChild(row);
        }
        
        // Eliminar despacho (solo admin)
        function eliminarDespacho() {
            if (confirm('¿Está TOTALMENTE SEGURO de eliminar este despacho?\n\nEsta acción eliminará:\n- Todos los productos registrados\n- El historial completo\n- Los precios especiales\n\nEsta acción NO SE PUEDE DESHACER.')) {
                const form = document.createElement('form');
                form.method = 'POST';
                form.innerHTML = '<input type="hidden" name="accion" value="eliminar_despacho">';
                document.body.appendChild(form);
                form.submit();
            }
        }
        
        // Validación del formulario
        document.addEventListener('DOMContentLoaded', function() {
            const form = document.getElementById('formProductos');
            if (form) {
                form.addEventListener('submit', function(e) {
                    const inputs = form.querySelectorAll('input[name^="productos"], input[name^="nuevos_productos"]');
                    let hasValue = false;
                    
                    inputs.forEach(input => {
                        if (parseFloat(input.value) > 0) {
                            hasValue = true;
                        }
                    });
                    
                    const evento = form.querySelector('input[name="evento"]').value;
                    if (evento === 'salida' && !hasValue) {
                        e.preventDefault();
                        alert('Debe registrar al menos un producto en la SALIDA');
                        return false;
                    }
                });
            }
        });
        
        // Navegación con teclado
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
    </script>
</body>
</html>