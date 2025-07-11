<?php
// fix_passwords.php - Ejecutar este archivo UNA VEZ para arreglar las contraseñas
require_once 'config/database.php';

try {
    $db = getDB();
    
    // Generar hash correcto para "Password"
    $passwordHash = password_hash('Password', PASSWORD_DEFAULT);
    
    echo "<h3>Generando contraseñas correctas...</h3>";
    echo "<p><strong>Hash generado:</strong> " . $passwordHash . "</p>";
    
    // Actualizar contraseña del admin
    $stmt = $db->prepare("UPDATE usuarios SET password = ? WHERE usuario = 'admin'");
    $result1 = $stmt->execute([$passwordHash]);
    
    // Actualizar contraseña del despachador
    $stmt = $db->prepare("UPDATE usuarios SET password = ? WHERE usuario = '123456789'");
    $result2 = $stmt->execute([$passwordHash]);
    
    if ($result1 && $result2) {
        echo "<div style='color: green; padding: 10px; border: 1px solid green; margin: 10px 0;'>";
        echo "<strong>✅ ¡Contraseñas actualizadas correctamente!</strong><br>";
        echo "Ahora puedes usar:<br>";
        echo "• Admin: admin / Password<br>";
        echo "• Despachador: 123456789 / Password";
        echo "</div>";
        
        // Verificar que las contraseñas funcionan
        echo "<h4>Verificando contraseñas...</h4>";
        
        $stmt = $db->prepare("SELECT usuario, password FROM usuarios WHERE usuario IN ('admin', '123456789')");
        $stmt->execute();
        $usuarios = $stmt->fetchAll();
        
        foreach ($usuarios as $user) {
            $verify = password_verify('Password', $user['password']);
            echo "<p>Usuario <strong>" . $user['usuario'] . "</strong>: " . 
                 ($verify ? "✅ Contraseña válida" : "❌ Error en contraseña") . "</p>";
        }
        
    } else {
        echo "<div style='color: red; padding: 10px; border: 1px solid red; margin: 10px 0;'>";
        echo "❌ Error al actualizar las contraseñas";
        echo "</div>";
    }
    
    echo "<hr>";
    echo "<p><strong>Importante:</strong> Elimina este archivo (fix_passwords.php) después de ejecutarlo por seguridad.</p>";
    
} catch (Exception $e) {
    echo "<div style='color: red; padding: 10px; border: 1px solid red; margin: 10px 0;'>";
    echo "❌ Error: " . $e->getMessage();
    echo "</div>";
}
?>