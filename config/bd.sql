-- --------------------------------------------------------
-- Host:                         127.0.0.1
-- Versión del servidor:         8.0.30 - MySQL Community Server - GPL
-- SO del servidor:              Win64
-- HeidiSQL Versión:             12.1.0.6537
-- --------------------------------------------------------

/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET NAMES utf8 */;
/*!50503 SET NAMES utf8mb4 */;
/*!40103 SET @OLD_TIME_ZONE=@@TIME_ZONE */;
/*!40103 SET TIME_ZONE='+00:00' */;
/*!40014 SET @OLD_FOREIGN_KEY_CHECKS=@@FOREIGN_KEY_CHECKS, FOREIGN_KEY_CHECKS=0 */;
/*!40101 SET @OLD_SQL_MODE=@@SQL_MODE, SQL_MODE='NO_AUTO_VALUE_ON_ZERO' */;
/*!40111 SET @OLD_SQL_NOTES=@@SQL_NOTES, SQL_NOTES=0 */;


-- Volcando estructura de base de datos para sistema_despacho
CREATE DATABASE IF NOT EXISTS `sistema_despacho` /*!40100 DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci */ /*!80016 DEFAULT ENCRYPTION='N' */;
USE `sistema_despacho`;

-- Volcando estructura para tabla sistema_despacho.despachos_historial
CREATE TABLE IF NOT EXISTS `despachos_historial` (
  `id` int NOT NULL AUTO_INCREMENT,
  `despacho_ruta_id` int NOT NULL,
  `accion` enum('crear','editar','eliminar','completar') NOT NULL,
  `estado_anterior` varchar(50) DEFAULT NULL,
  `estado_nuevo` varchar(50) DEFAULT NULL,
  `usuario_id` int NOT NULL,
  `detalles` text,
  `fecha_accion` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `despacho_ruta_id` (`despacho_ruta_id`),
  KEY `usuario_id` (`usuario_id`),
  CONSTRAINT `despachos_historial_ibfk_1` FOREIGN KEY (`despacho_ruta_id`) REFERENCES `despachos_ruta` (`id`),
  CONSTRAINT `despachos_historial_ibfk_2` FOREIGN KEY (`usuario_id`) REFERENCES `usuarios` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- Volcando datos para la tabla sistema_despacho.despachos_historial: ~0 rows (aproximadamente)

-- Volcando estructura para tabla sistema_despacho.despachos_ruta
CREATE TABLE IF NOT EXISTS `despachos_ruta` (
  `id` int NOT NULL AUTO_INCREMENT,
  `fecha_despacho` date NOT NULL,
  `ruta_id` int NOT NULL,
  `usuario_id` int NOT NULL,
  `estado` enum('salida','recarga','segunda_recarga','retorno','completado') DEFAULT 'salida',
  `observaciones` text,
  `fecha_creacion` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `fecha_actualizacion` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_despacho_ruta_fecha` (`fecha_despacho`,`ruta_id`),
  KEY `ruta_id` (`ruta_id`),
  KEY `usuario_id` (`usuario_id`),
  CONSTRAINT `despachos_ruta_ibfk_1` FOREIGN KEY (`ruta_id`) REFERENCES `rutas` (`id`),
  CONSTRAINT `despachos_ruta_ibfk_2` FOREIGN KEY (`usuario_id`) REFERENCES `usuarios` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- Volcando datos para la tabla sistema_despacho.despachos_ruta: ~0 rows (aproximadamente)

-- Volcando estructura para tabla sistema_despacho.despacho_ruta_detalle
CREATE TABLE IF NOT EXISTS `despacho_ruta_detalle` (
  `id` int NOT NULL AUTO_INCREMENT,
  `despacho_ruta_id` int NOT NULL,
  `producto_id` int NOT NULL,
  `salida` decimal(10,2) DEFAULT '0.00',
  `recarga` decimal(10,2) DEFAULT '0.00',
  `segunda_recarga` decimal(10,2) DEFAULT '0.00',
  `retorno` decimal(10,2) DEFAULT '0.00',
  `ventas_calculadas` decimal(10,2) DEFAULT '0.00',
  `total_dinero` decimal(10,2) DEFAULT '0.00',
  `fecha_creacion` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `fecha_actualizacion` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_despacho_ruta_producto` (`despacho_ruta_id`,`producto_id`),
  KEY `producto_id` (`producto_id`),
  CONSTRAINT `despacho_ruta_detalle_ibfk_1` FOREIGN KEY (`despacho_ruta_id`) REFERENCES `despachos_ruta` (`id`) ON DELETE CASCADE,
  CONSTRAINT `despacho_ruta_detalle_ibfk_2` FOREIGN KEY (`producto_id`) REFERENCES `productos` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- Volcando datos para la tabla sistema_despacho.despacho_ruta_detalle: ~0 rows (aproximadamente)

-- Volcando estructura para tabla sistema_despacho.productos
CREATE TABLE IF NOT EXISTS `productos` (
  `id` int NOT NULL AUTO_INCREMENT,
  `nombre` varchar(100) NOT NULL,
  `categoria` enum('grupo_aje','proveedores_varios') NOT NULL,
  `precio_unitario` decimal(10,2) NOT NULL,
  `usa_formula` tinyint(1) DEFAULT '0',
  `stock_actual` decimal(10,2) DEFAULT '0.00',
  `stock_minimo` decimal(10,2) DEFAULT '0.00',
  `estado` enum('activo','inactivo') DEFAULT 'activo',
  `fecha_creacion` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=10 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- Volcando datos para la tabla sistema_despacho.productos: ~9 rows (aproximadamente)
INSERT INTO `productos` (`id`, `nombre`, `categoria`, `precio_unitario`, `usa_formula`, `stock_actual`, `stock_minimo`, `estado`, `fecha_creacion`) VALUES
	(1, 'Big Cola 3L', 'grupo_aje', 2.50, 0, 100.00, 10.00, 'activo', '2025-07-11 17:59:43'),
	(2, 'Big Cola 2.5L', 'grupo_aje', 2.00, 0, 150.00, 15.00, 'activo', '2025-07-11 17:59:43'),
	(3, 'Cifrut Naranja', 'grupo_aje', 0.83, 1, 200.00, 20.00, 'activo', '2025-07-11 17:59:43'),
	(4, 'Volt Energizante', 'grupo_aje', 1.50, 0, 80.00, 8.00, 'activo', '2025-07-11 17:59:43'),
	(5, 'Coca Cola 350ml', 'proveedores_varios', 1.25, 0, 300.00, 30.00, 'activo', '2025-07-11 17:59:43'),
	(6, 'Pepsi 350ml', 'proveedores_varios', 1.20, 0, 250.00, 25.00, 'activo', '2025-07-11 17:59:43'),
	(7, 'Agua Cristal 500ml', 'proveedores_varios', 0.50, 0, 400.00, 40.00, 'activo', '2025-07-11 17:59:43'),
	(8, 'Cerveza Pilsener', 'proveedores_varios', 2.75, 0, 120.00, 12.00, 'activo', '2025-07-11 17:59:43'),
	(9, 'Jugo Del Valle', 'proveedores_varios', 1.80, 0, 180.00, 18.00, 'activo', '2025-07-11 17:59:43');

-- Volcando estructura para tabla sistema_despacho.rutas
CREATE TABLE IF NOT EXISTS `rutas` (
  `id` int NOT NULL AUTO_INCREMENT,
  `nombre` varchar(100) NOT NULL,
  `direccion` varchar(200) NOT NULL,
  `tipo_ruta` enum('grupo_aje','proveedores_varios') NOT NULL,
  `estado` enum('activo','inactivo') DEFAULT 'activo',
  `fecha_creacion` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=6 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- Volcando datos para la tabla sistema_despacho.rutas: ~5 rows (aproximadamente)
INSERT INTO `rutas` (`id`, `nombre`, `direccion`, `tipo_ruta`, `estado`, `fecha_creacion`) VALUES
	(1, 'Ruta 1 - Grupo AJE', 'Zona Centro - Exclusiva AJE', 'grupo_aje', 'activo', '2025-07-11 17:59:43'),
	(2, 'Ruta 2 - Proveedores Varios', 'Zona Norte', 'proveedores_varios', 'activo', '2025-07-11 17:59:43'),
	(3, 'Ruta 3 - Proveedores Varios', 'Zona Sur', 'proveedores_varios', 'activo', '2025-07-11 17:59:43'),
	(4, 'Ruta 4 - Proveedores Varios', 'Zona Este', 'proveedores_varios', 'activo', '2025-07-11 17:59:43'),
	(5, 'Ruta 5 - Proveedores Varios', 'Zona Oeste', 'proveedores_varios', 'activo', '2025-07-11 17:59:43');

-- Volcando estructura para tabla sistema_despacho.usuarios
CREATE TABLE IF NOT EXISTS `usuarios` (
  `id` int NOT NULL AUTO_INCREMENT,
  `usuario` varchar(50) NOT NULL,
  `password` varchar(255) NOT NULL,
  `tipo_usuario` enum('admin','despachador') NOT NULL,
  `nombre_completo` varchar(100) NOT NULL,
  `dui` varchar(10) DEFAULT NULL,
  `estado` enum('activo','inactivo') DEFAULT 'activo',
  `fecha_creacion` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `fecha_actualizacion` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `usuario` (`usuario`)
) ENGINE=InnoDB AUTO_INCREMENT=3 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- Volcando datos para la tabla sistema_despacho.usuarios: ~2 rows (aproximadamente)
INSERT INTO `usuarios` (`id`, `usuario`, `password`, `tipo_usuario`, `nombre_completo`, `dui`, `estado`, `fecha_creacion`, `fecha_actualizacion`) VALUES
	(1, 'admin', '$2y$10$KFd.TsJqDUqX3PnrbTrAUOm6udKxfcSHVRucdNxikCpPw26YuBh5y', 'admin', 'Administrador del Sistema', NULL, 'activo', '2025-07-11 17:59:43', '2025-07-11 18:06:45'),
	(2, '123456789', '$2y$10$KFd.TsJqDUqX3PnrbTrAUOm6udKxfcSHVRucdNxikCpPw26YuBh5y', 'despachador', 'Despachador Principal', '123456789', 'activo', '2025-07-11 17:59:43', '2025-07-11 18:06:45');

-- Volcando estructura para tabla sistema_despacho.ventas_precios_especiales
CREATE TABLE IF NOT EXISTS `ventas_precios_especiales` (
  `id` int NOT NULL AUTO_INCREMENT,
  `despacho_ruta_detalle_id` int NOT NULL,
  `cantidad` decimal(10,2) NOT NULL,
  `precio_venta` decimal(10,2) NOT NULL,
  `subtotal` decimal(10,2) NOT NULL,
  `fecha_creacion` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `despacho_ruta_detalle_id` (`despacho_ruta_detalle_id`),
  CONSTRAINT `ventas_precios_especiales_ibfk_1` FOREIGN KEY (`despacho_ruta_detalle_id`) REFERENCES `despacho_ruta_detalle` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- Volcando datos para la tabla sistema_despacho.ventas_precios_especiales: ~0 rows (aproximadamente)

/*!40103 SET TIME_ZONE=IFNULL(@OLD_TIME_ZONE, 'system') */;
/*!40101 SET SQL_MODE=IFNULL(@OLD_SQL_MODE, '') */;
/*!40014 SET FOREIGN_KEY_CHECKS=IFNULL(@OLD_FOREIGN_KEY_CHECKS, 1) */;
/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40111 SET SQL_NOTES=IFNULL(@OLD_SQL_NOTES, 1) */;
