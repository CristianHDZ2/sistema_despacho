-- Base de datos para Sistema de Control de Despacho y Liquidación
CREATE DATABASE IF NOT EXISTS sistema_despacho;
USE sistema_despacho;

-- Tabla de usuarios
CREATE TABLE usuarios (
    id INT AUTO_INCREMENT PRIMARY KEY,
    usuario VARCHAR(50) NOT NULL UNIQUE,
    password VARCHAR(255) NOT NULL,
    tipo_usuario ENUM('admin', 'despachador') NOT NULL,
    nombre_completo VARCHAR(100) NOT NULL,
    dui VARCHAR(10) NULL,
    estado ENUM('activo', 'inactivo') DEFAULT 'activo',
    fecha_creacion TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    fecha_actualizacion TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);

-- Tabla de rutas
CREATE TABLE rutas (
    id INT AUTO_INCREMENT PRIMARY KEY,
    nombre VARCHAR(100) NOT NULL,
    direccion VARCHAR(200) NOT NULL,
    tipo_ruta ENUM('grupo_aje', 'proveedores_varios') NOT NULL,
    estado ENUM('activo', 'inactivo') DEFAULT 'activo',
    fecha_creacion TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- Tabla de productos
CREATE TABLE productos (
    id INT AUTO_INCREMENT PRIMARY KEY,
    nombre VARCHAR(100) NOT NULL,
    categoria ENUM('grupo_aje', 'proveedores_varios') NOT NULL,
    precio_unitario DECIMAL(10,2) NOT NULL,
    usa_formula BOOLEAN DEFAULT FALSE,
    stock_actual DECIMAL(10,2) DEFAULT 0,
    stock_minimo DECIMAL(10,2) DEFAULT 0,
    estado ENUM('activo', 'inactivo') DEFAULT 'activo',
    fecha_creacion TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- Tabla de despachos (cabecera)
CREATE TABLE despachos (
    id INT AUTO_INCREMENT PRIMARY KEY,
    fecha_despacho DATE NOT NULL,
    usuario_id INT NOT NULL,
    estado ENUM('salida', 'recarga', 'segunda_recarga', 'retorno', 'completado') DEFAULT 'salida',
    observaciones TEXT,
    fecha_creacion TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    fecha_actualizacion TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (usuario_id) REFERENCES usuarios(id),
    UNIQUE KEY unique_despacho_fecha (fecha_despacho)
);

-- Tabla de detalle de despachos
CREATE TABLE despacho_detalle (
    id INT AUTO_INCREMENT PRIMARY KEY,
    despacho_id INT NOT NULL,
    ruta_id INT NOT NULL,
    producto_id INT NOT NULL,
    salida DECIMAL(10,2) DEFAULT 0,
    recarga DECIMAL(10,2) DEFAULT 0,
    segunda_recarga DECIMAL(10,2) DEFAULT 0,
    retorno DECIMAL(10,2) DEFAULT 0,
    ventas_calculadas DECIMAL(10,2) DEFAULT 0,
    total_dinero DECIMAL(10,2) DEFAULT 0,
    fecha_creacion TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    fecha_actualizacion TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (despacho_id) REFERENCES despachos(id) ON DELETE CASCADE,
    FOREIGN KEY (ruta_id) REFERENCES rutas(id),
    FOREIGN KEY (producto_id) REFERENCES productos(id),
    UNIQUE KEY unique_despacho_ruta_producto (despacho_id, ruta_id, producto_id)
);

-- Tabla de ventas con precios especiales
CREATE TABLE ventas_precios_especiales (
    id INT AUTO_INCREMENT PRIMARY KEY,
    despacho_detalle_id INT NOT NULL,
    cantidad DECIMAL(10,2) NOT NULL,
    precio_venta DECIMAL(10,2) NOT NULL,
    subtotal DECIMAL(10,2) NOT NULL,
    fecha_creacion TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (despacho_detalle_id) REFERENCES despacho_detalle(id) ON DELETE CASCADE
);

-- Insertar usuarios por defecto
INSERT INTO usuarios (usuario, password, tipo_usuario, nombre_completo, dui) VALUES
('admin', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'admin', 'Administrador del Sistema', NULL),
('123456789', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'despachador', 'Despachador Principal', '123456789');

-- Insertar rutas por defecto
INSERT INTO rutas (nombre, direccion, tipo_ruta) VALUES
('Ruta 1 - Grupo AJE', 'Zona Centro - Exclusiva AJE', 'grupo_aje'),
('Ruta 2 - Proveedores Varios', 'Zona Norte', 'proveedores_varios'),
('Ruta 3 - Proveedores Varios', 'Zona Sur', 'proveedores_varios'),
('Ruta 4 - Proveedores Varios', 'Zona Este', 'proveedores_varios'),
('Ruta 5 - Proveedores Varios', 'Zona Oeste', 'proveedores_varios');

-- Insertar productos de ejemplo
INSERT INTO productos (nombre, categoria, precio_unitario, usa_formula, stock_actual, stock_minimo) VALUES
-- Productos Grupo AJE
('Big Cola 3L', 'grupo_aje', 2.50, TRUE, 100, 10),
('Big Cola 2L', 'grupo_aje', 2.00, TRUE, 150, 15),
('Cifrut Naranja', 'grupo_aje', 0.83, TRUE, 200, 20),
('Volt Energizante', 'grupo_aje', 1.50, FALSE, 80, 8),

-- Productos Proveedores Varios
('Coca Cola 350ml', 'proveedores_varios', 1.25, FALSE, 300, 30),
('Pepsi 350ml', 'proveedores_varios', 1.20, FALSE, 250, 25),
('Agua Cristal 500ml', 'proveedores_varios', 0.50, FALSE, 400, 40),
('Cerveza Pilsener', 'proveedores_varios', 2.75, FALSE, 120, 12),
('Jugo Del Valle', 'proveedores_varios', 1.80, FALSE, 180, 18);