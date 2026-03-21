/*!40103 SET @OLD_TIME_ZONE=@@TIME_ZONE */;
/*!40103 SET TIME_ZONE='+00:00' */;
/*!40014 SET @OLD_UNIQUE_CHECKS=@@UNIQUE_CHECKS, UNIQUE_CHECKS=0 */;
/*!40014 SET @OLD_FOREIGN_KEY_CHECKS=@@FOREIGN_KEY_CHECKS, FOREIGN_KEY_CHECKS=0 */;
/*!40101 SET @OLD_SQL_MODE=@@SQL_MODE, SQL_MODE='NO_AUTO_VALUE_ON_ZERO' */;
/*!40111 SET @OLD_SQL_NOTES=@@SQL_NOTES, SQL_NOTES=0 */;
DROP TABLE IF EXISTS `categorias`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `categorias` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `nombre` varchar(120) COLLATE utf8mb4_unicode_ci NOT NULL,
  `slug` varchar(140) COLLATE utf8mb4_unicode_ci NOT NULL,
  `categoria_padre_id` bigint unsigned DEFAULT NULL,
  `activo` tinyint(1) NOT NULL DEFAULT '1',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `categorias_slug_unique` (`slug`),
  KEY `categorias_categoria_padre_id_index` (`categoria_padre_id`),
  CONSTRAINT `categorias_categoria_padre_id_foreign` FOREIGN KEY (`categoria_padre_id`) REFERENCES `categorias` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `clientes`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `clientes` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `user_id` bigint unsigned NOT NULL,
  `nombre` varchar(120) COLLATE utf8mb4_unicode_ci NOT NULL,
  `apellido` varchar(120) COLLATE utf8mb4_unicode_ci NOT NULL,
  `email` varchar(160) COLLATE utf8mb4_unicode_ci NOT NULL,
  `telefono` varchar(40) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `dni` varchar(20) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `cuit` varchar(20) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `condicion_iva` varchar(80) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `nombre_empresa` varchar(160) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `direccion_calle` varchar(160) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `direccion_numero` varchar(20) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `direccion_piso` varchar(20) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `direccion_depto` varchar(20) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `direccion_localidad` varchar(80) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `direccion_provincia` varchar(80) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `direccion_codigo_postal` varchar(20) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `activo` tinyint(1) NOT NULL DEFAULT '1',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `clientes_user_id_unique` (`user_id`),
  UNIQUE KEY `clientes_email_unique` (`email`),
  UNIQUE KEY `clientes_dni_unique` (`dni`),
  UNIQUE KEY `clientes_cuit_unique` (`cuit`),
  CONSTRAINT `clientes_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `configuraciones`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `configuraciones` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `clave` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL,
  `valor` text COLLATE utf8mb4_unicode_ci,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `configuraciones_clave_unique` (`clave`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `contenedor_reservas`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `contenedor_reservas` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `pedido_item_id` bigint unsigned NOT NULL,
  `pedido_id` bigint unsigned DEFAULT NULL,
  `producto_id` bigint unsigned DEFAULT NULL,
  `fecha_entrega` date NOT NULL,
  `franja_entrega` varchar(20) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `fecha_retiro` date NOT NULL,
  `franja_retiro` varchar(20) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `localidad` varchar(120) COLLATE utf8mb4_unicode_ci NOT NULL,
  `domicilio` varchar(180) COLLATE utf8mb4_unicode_ci NOT NULL,
  `codigo_postal` varchar(20) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `telefono` varchar(40) COLLATE utf8mb4_unicode_ci NOT NULL,
  `cantidad` int unsigned NOT NULL DEFAULT '1',
  `cuenta_corriente` tinyint(1) NOT NULL DEFAULT '0',
  `comprobante_path` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `estado` varchar(30) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'pendiente',
  `email_enviado_at` timestamp NULL DEFAULT NULL,
  `email_admin_enviado_at` timestamp NULL DEFAULT NULL,
  `observaciones` text COLLATE utf8mb4_unicode_ci,
  `fecha_retiro_real` date DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `devuelta_en` timestamp NULL DEFAULT NULL,
  `motivo_devolucion` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `contenedor_reservas_pedido_item_id_unique` (`pedido_item_id`),
  KEY `contenedor_reservas_fecha_entrega_estado_index` (`fecha_entrega`,`estado`),
  KEY `contenedor_reservas_fecha_retiro_index` (`fecha_retiro`),
  KEY `contenedor_reservas_pedido_id_index` (`pedido_id`),
  KEY `contenedor_reservas_producto_id_index` (`producto_id`),
  CONSTRAINT `contenedor_reservas_pedido_id_foreign` FOREIGN KEY (`pedido_id`) REFERENCES `pedidos` (`id`) ON DELETE SET NULL,
  CONSTRAINT `contenedor_reservas_pedido_item_id_foreign` FOREIGN KEY (`pedido_item_id`) REFERENCES `pedido_items` (`id`) ON DELETE CASCADE,
  CONSTRAINT `contenedor_reservas_producto_id_foreign` FOREIGN KEY (`producto_id`) REFERENCES `productos` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `direcciones`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `direcciones` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `cliente_id` bigint unsigned NOT NULL,
  `alias` varchar(80) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `nombre_recibe` varchar(160) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `telefono_recibe` varchar(40) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `calle` varchar(160) COLLATE utf8mb4_unicode_ci NOT NULL,
  `numero` varchar(20) COLLATE utf8mb4_unicode_ci NOT NULL,
  `piso` varchar(20) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `depto` varchar(20) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `ciudad` varchar(80) COLLATE utf8mb4_unicode_ci NOT NULL,
  `provincia` varchar(80) COLLATE utf8mb4_unicode_ci NOT NULL,
  `codigo_postal` varchar(20) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `referencias` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `es_principal` tinyint(1) NOT NULL DEFAULT '0',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `direcciones_cliente_id_es_principal_index` (`cliente_id`,`es_principal`),
  CONSTRAINT `direcciones_cliente_id_foreign` FOREIGN KEY (`cliente_id`) REFERENCES `clientes` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `envios`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `envios` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `pedido_id` bigint unsigned NOT NULL,
  `calle` varchar(160) COLLATE utf8mb4_unicode_ci NOT NULL,
  `numero` varchar(20) COLLATE utf8mb4_unicode_ci NOT NULL,
  `piso` varchar(20) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `depto` varchar(20) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `ciudad` varchar(80) COLLATE utf8mb4_unicode_ci NOT NULL,
  `provincia` varchar(80) COLLATE utf8mb4_unicode_ci NOT NULL,
  `codigo_postal` varchar(20) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `referencias` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `estado` enum('pendiente','en_preparacion','despachado','entregado','cancelado') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'pendiente',
  `empresa` varchar(120) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `tracking_codigo` varchar(120) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `despachado_en` timestamp NULL DEFAULT NULL,
  `entregado_en` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `envios_pedido_id_unique` (`pedido_id`),
  CONSTRAINT `envios_pedido_id_foreign` FOREIGN KEY (`pedido_id`) REFERENCES `pedidos` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `failed_jobs`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `failed_jobs` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `uuid` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `connection` text COLLATE utf8mb4_unicode_ci NOT NULL,
  `queue` text COLLATE utf8mb4_unicode_ci NOT NULL,
  `payload` longtext COLLATE utf8mb4_unicode_ci NOT NULL,
  `exception` longtext COLLATE utf8mb4_unicode_ci NOT NULL,
  `failed_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `failed_jobs_uuid_unique` (`uuid`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `marcas`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `marcas` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `nombre` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL,
  `slug` varchar(120) COLLATE utf8mb4_unicode_ci NOT NULL,
  `activo` tinyint(1) DEFAULT '1',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `slug` (`slug`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `medios_pago`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `medios_pago` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `nombre` varchar(80) COLLATE utf8mb4_unicode_ci NOT NULL,
  `codigo` varchar(60) COLLATE utf8mb4_unicode_ci NOT NULL,
  `activo` tinyint(1) NOT NULL DEFAULT '1',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `medios_pago_codigo_unique` (`codigo`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `migrations`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `migrations` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `migration` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `batch` int NOT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `pagos`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `pagos` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `pedido_id` bigint unsigned NOT NULL,
  `medio_pago_id` bigint unsigned NOT NULL,
  `estado` enum('iniciado','pendiente','aprobado','rechazado','cancelado') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'iniciado',
  `monto` decimal(12,2) NOT NULL,
  `moneda` varchar(3) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'ARS',
  `mp_preference_id` varchar(120) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `mp_payment_id` varchar(120) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `mp_idempotency_key` varchar(64) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `mp_merchant_order_id` varchar(120) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `mp_status` varchar(60) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `mp_status_detail` varchar(120) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `mp_raw_json` json DEFAULT NULL,
  `mp_refund_id` varchar(120) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `refund_monto` decimal(12,2) DEFAULT NULL,
  `refund_status` varchar(60) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `devuelto_en` timestamp NULL DEFAULT NULL,
  `aprobado_en` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `pagos_medio_pago_id_foreign` (`medio_pago_id`),
  KEY `pagos_pedido_id_estado_index` (`pedido_id`,`estado`),
  KEY `pagos_mp_preference_id_index` (`mp_preference_id`),
  KEY `pagos_mp_payment_id_index` (`mp_payment_id`),
  KEY `pagos_mp_merchant_order_id_index` (`mp_merchant_order_id`),
  CONSTRAINT `pagos_medio_pago_id_foreign` FOREIGN KEY (`medio_pago_id`) REFERENCES `medios_pago` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `pagos_pedido_id_foreign` FOREIGN KEY (`pedido_id`) REFERENCES `pedidos` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `paljet_articulos`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `paljet_articulos` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `paljet_id` bigint unsigned NOT NULL,
  `codigo` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `ean` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `descripcion` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `desc_cliente` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `familia_path` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `familia_id` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `escala_id` bigint unsigned DEFAULT NULL,
  `escala_nombre` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `escala_abrev` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `publica_web` tinyint(1) NOT NULL DEFAULT '0',
  `admin_existencia` tinyint(1) NOT NULL DEFAULT '0',
  `impuestos_json` json DEFAULT NULL,
  `raw_json` json DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `paljet_articulos_paljet_id_unique` (`paljet_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `paljet_depositos`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `paljet_depositos` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `paljet_id` int unsigned NOT NULL,
  `nombre` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `activo` tinyint(1) NOT NULL DEFAULT '1',
  `raw_json` json DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `paljet_depositos_paljet_id_unique` (`paljet_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `paljet_listas_precios`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `paljet_listas_precios` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `paljet_id` bigint unsigned NOT NULL,
  `nombre` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `activa` tinyint(1) NOT NULL DEFAULT '1',
  `raw_json` json DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `paljet_listas_precios_paljet_id_unique` (`paljet_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `paljet_ofertas`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `paljet_ofertas` (
  `paljet_art_id` bigint unsigned NOT NULL,
  `precio_oferta` decimal(10,2) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`paljet_art_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `paljet_precios`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `paljet_precios` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `lista_id` bigint unsigned NOT NULL,
  `articulo_id` bigint unsigned NOT NULL,
  `pr_vta` decimal(18,2) DEFAULT NULL,
  `pr_final` decimal(18,2) DEFAULT NULL,
  `moneda` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `raw_json` json DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `paljet_precios_lista_id_articulo_id_unique` (`lista_id`,`articulo_id`),
  KEY `paljet_precios_articulo_id_index` (`articulo_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `paljet_stock`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `paljet_stock` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `deposito_id` int unsigned NOT NULL,
  `articulo_id` bigint unsigned NOT NULL,
  `existencia` decimal(18,2) NOT NULL DEFAULT '0.00',
  `disponible` decimal(18,2) NOT NULL DEFAULT '0.00',
  `comprometido` decimal(18,2) NOT NULL DEFAULT '0.00',
  `a_recibir` decimal(18,2) NOT NULL DEFAULT '0.00',
  `stk_min` decimal(18,2) NOT NULL DEFAULT '0.00',
  `raw_json` json DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `paljet_stock_deposito_id_articulo_id_unique` (`deposito_id`,`articulo_id`),
  KEY `paljet_stock_articulo_id_index` (`articulo_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `password_reset_tokens`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `password_reset_tokens` (
  `email` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `token` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`email`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `pedido_items`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `pedido_items` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `pedido_id` bigint unsigned NOT NULL,
  `producto_id` bigint unsigned DEFAULT NULL,
  `paljet_art_id` bigint unsigned DEFAULT NULL,
  `nombre_producto` varchar(180) COLLATE utf8mb4_unicode_ci NOT NULL,
  `precio_unitario` decimal(12,2) NOT NULL,
  `cantidad` int NOT NULL,
  `subtotal` decimal(12,2) NOT NULL,
  `extras` json DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `es_contenedor` tinyint(1) NOT NULL DEFAULT '0',
  PRIMARY KEY (`id`),
  KEY `pedido_items_pedido_id_index` (`pedido_id`),
  KEY `pedido_items_producto_id_index` (`producto_id`),
  KEY `pedido_items_es_contenedor_index` (`es_contenedor`),
  CONSTRAINT `pedido_items_pedido_id_foreign` FOREIGN KEY (`pedido_id`) REFERENCES `pedidos` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `pedidos`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `pedidos` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `paljet_pedido_id` bigint unsigned DEFAULT NULL,
  `cliente_id` bigint unsigned DEFAULT NULL,
  `sucursal_id` bigint unsigned NOT NULL,
  `tipo_entrega` enum('retiro_sucursal','envio') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'retiro_sucursal',
  `nombre_contacto` varchar(160) COLLATE utf8mb4_unicode_ci NOT NULL,
  `email_contacto` varchar(160) COLLATE utf8mb4_unicode_ci NOT NULL,
  `telefono_contacto` varchar(40) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `dni_contacto` varchar(20) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `cuit_contacto` varchar(20) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `condicion_iva_contacto` varchar(80) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `estado` enum('borrador','pendiente_pago','pagado','en_preparacion','listo_para_retiro','enviado','entregado','cancelado','fallido') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'pendiente_pago',
  `estado_antes_devolucion` varchar(40) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `total_productos` decimal(12,2) NOT NULL DEFAULT '0.00',
  `costo_envio` decimal(12,2) NOT NULL DEFAULT '0.00',
  `total_final` decimal(12,2) NOT NULL DEFAULT '0.00',
  `moneda` varchar(3) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'ARS',
  `access_token` char(36) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `nota_cliente` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `nota_interna` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `motivo_devolucion` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `devolucion_solicitada_en` timestamp NULL DEFAULT NULL,
  `comprobante_path` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `mail_cliente_enviado_en` timestamp NULL DEFAULT NULL,
  `mail_admin_enviado_en` timestamp NULL DEFAULT NULL,
  `email_cliente_enviado_at` timestamp NULL DEFAULT NULL,
  `email_admin_enviado_at` timestamp NULL DEFAULT NULL,
  `mail_cliente_error_at` timestamp NULL DEFAULT NULL,
  `mail_admin_error_at` timestamp NULL DEFAULT NULL,
  `medio_pago_id` bigint unsigned DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `pedidos_access_token_unique` (`access_token`),
  KEY `pedidos_cliente_id_estado_index` (`cliente_id`,`estado`),
  KEY `pedidos_sucursal_id_tipo_entrega_index` (`sucursal_id`,`tipo_entrega`),
  KEY `pedidos_medio_pago_id_foreign` (`medio_pago_id`),
  CONSTRAINT `pedidos_cliente_id_foreign` FOREIGN KEY (`cliente_id`) REFERENCES `clientes` (`id`) ON DELETE SET NULL,
  CONSTRAINT `pedidos_medio_pago_id_foreign` FOREIGN KEY (`medio_pago_id`) REFERENCES `medios_pago` (`id`),
  CONSTRAINT `pedidos_sucursal_id_foreign` FOREIGN KEY (`sucursal_id`) REFERENCES `sucursales` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `personal_access_tokens`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `personal_access_tokens` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `tokenable_type` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `tokenable_id` bigint unsigned NOT NULL,
  `name` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `token` varchar(64) COLLATE utf8mb4_unicode_ci NOT NULL,
  `abilities` text COLLATE utf8mb4_unicode_ci,
  `last_used_at` timestamp NULL DEFAULT NULL,
  `expires_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `personal_access_tokens_token_unique` (`token`),
  KEY `personal_access_tokens_tokenable_type_tokenable_id_index` (`tokenable_type`,`tokenable_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `producto_imagenes`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `producto_imagenes` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `producto_id` bigint unsigned NOT NULL,
  `url` varchar(500) COLLATE utf8mb4_unicode_ci NOT NULL,
  `principal` tinyint(1) NOT NULL DEFAULT '0',
  `orden` int unsigned NOT NULL DEFAULT '1',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `producto_imagenes_producto_id_principal_index` (`producto_id`,`principal`),
  CONSTRAINT `producto_imagenes_producto_id_foreign` FOREIGN KEY (`producto_id`) REFERENCES `productos` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `producto_specs`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `producto_specs` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `producto_id` bigint unsigned NOT NULL,
  `clave` varchar(120) COLLATE utf8mb4_unicode_ci NOT NULL,
  `valor` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `orden` smallint unsigned NOT NULL DEFAULT '0',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `producto_specs_producto_id_orden_index` (`producto_id`,`orden`),
  CONSTRAINT `producto_specs_producto_id_foreign` FOREIGN KEY (`producto_id`) REFERENCES `productos` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `productos`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `productos` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `paljet_art_id` bigint unsigned DEFAULT NULL COMMENT 'ID del artículo equivalente en Paljet (para facturación)',
  `categoria_id` bigint unsigned DEFAULT NULL,
  `marca_id` bigint unsigned DEFAULT NULL,
  `nombre` varchar(180) COLLATE utf8mb4_unicode_ci NOT NULL,
  `slug` varchar(200) COLLATE utf8mb4_unicode_ci NOT NULL,
  `codigo` varchar(80) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `descripcion` text COLLATE utf8mb4_unicode_ci,
  `precio` decimal(12,2) NOT NULL,
  `en_oferta` tinyint(1) DEFAULT '0',
  `marca` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `unidad` varchar(40) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `activo` tinyint(1) NOT NULL DEFAULT '1',
  `destacado` tinyint(1) NOT NULL DEFAULT '0',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `es_contenedor` tinyint(1) NOT NULL DEFAULT '0',
  PRIMARY KEY (`id`),
  UNIQUE KEY `productos_slug_unique` (`slug`),
  UNIQUE KEY `productos_codigo_unique` (`codigo`),
  KEY `productos_categoria_id_activo_index` (`categoria_id`,`activo`),
  KEY `FK_productos_marcas` (`marca_id`),
  KEY `productos_es_contenedor_index` (`es_contenedor`),
  KEY `idx_en_oferta` (`en_oferta`),
  CONSTRAINT `FK_productos_marcas` FOREIGN KEY (`marca_id`) REFERENCES `marcas` (`id`),
  CONSTRAINT `productos_categoria_id_foreign` FOREIGN KEY (`categoria_id`) REFERENCES `categorias` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `reservas_stock`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `reservas_stock` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `pedido_id` bigint unsigned NOT NULL,
  `producto_id` bigint unsigned NOT NULL,
  `sucursal_id` bigint unsigned NOT NULL,
  `cantidad` int NOT NULL,
  `estado` enum('activa','confirmada','liberada','vencida') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'activa',
  `vence_en` timestamp NULL DEFAULT NULL,
  `devuelta_en` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `reservas_stock_sucursal_id_foreign` (`sucursal_id`),
  KEY `reservas_stock_producto_id_sucursal_id_estado_index` (`producto_id`,`sucursal_id`,`estado`),
  KEY `reservas_stock_pedido_id_estado_index` (`pedido_id`,`estado`),
  CONSTRAINT `reservas_stock_pedido_id_foreign` FOREIGN KEY (`pedido_id`) REFERENCES `pedidos` (`id`) ON DELETE CASCADE,
  CONSTRAINT `reservas_stock_producto_id_foreign` FOREIGN KEY (`producto_id`) REFERENCES `productos` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `reservas_stock_sucursal_id_foreign` FOREIGN KEY (`sucursal_id`) REFERENCES `sucursales` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `stock_sucursal`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `stock_sucursal` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `producto_id` bigint unsigned NOT NULL,
  `sucursal_id` bigint unsigned NOT NULL,
  `cantidad` int NOT NULL DEFAULT '0',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `stock_sucursal_producto_id_sucursal_id_unique` (`producto_id`,`sucursal_id`),
  KEY `stock_sucursal_sucursal_id_index` (`sucursal_id`),
  CONSTRAINT `stock_sucursal_producto_id_foreign` FOREIGN KEY (`producto_id`) REFERENCES `productos` (`id`) ON DELETE CASCADE,
  CONSTRAINT `stock_sucursal_sucursal_id_foreign` FOREIGN KEY (`sucursal_id`) REFERENCES `sucursales` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `sucursales`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `sucursales` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `nombre` varchar(120) COLLATE utf8mb4_unicode_ci NOT NULL,
  `ciudad` varchar(80) COLLATE utf8mb4_unicode_ci NOT NULL,
  `direccion` varchar(160) COLLATE utf8mb4_unicode_ci NOT NULL,
  `telefono` varchar(40) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `activo` tinyint(1) NOT NULL DEFAULT '1',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `sucursales_nombre_ciudad_unique` (`nombre`,`ciudad`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `users`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `users` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `email` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `rol` varchar(30) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'cliente',
  `email_otp` varchar(10) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `email_otp_expires_at` timestamp NULL DEFAULT NULL,
  `email_verified_at` timestamp NULL DEFAULT NULL,
  `password` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `remember_token` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `users_email_unique` (`email`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `webhooks`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `webhooks` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `proveedor` varchar(60) COLLATE utf8mb4_unicode_ci NOT NULL,
  `evento` varchar(120) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `external_id` varchar(180) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `payload_json` json NOT NULL,
  `procesado` tinyint(1) NOT NULL DEFAULT '0',
  `procesado_en` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `webhooks_proveedor_procesado_index` (`proveedor`,`procesado`),
  KEY `webhooks_external_id_index` (`external_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40103 SET TIME_ZONE=@OLD_TIME_ZONE */;

/*!40101 SET SQL_MODE=@OLD_SQL_MODE */;
/*!40014 SET FOREIGN_KEY_CHECKS=@OLD_FOREIGN_KEY_CHECKS */;
/*!40014 SET UNIQUE_CHECKS=@OLD_UNIQUE_CHECKS */;
/*!40111 SET SQL_NOTES=@OLD_SQL_NOTES */;

INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (1,'2014_10_12_000000_create_users_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (2,'2014_10_12_100000_create_password_reset_tokens_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (3,'2019_08_19_000000_create_failed_jobs_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (4,'2019_12_14_000001_create_personal_access_tokens_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (5,'2026_01_07_234719_create_sucursales_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (6,'2026_01_07_234720_create_categorias_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (7,'2026_01_07_234720_create_productos_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (8,'2026_01_07_234721_create_producto_imagenes_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (9,'2026_01_07_234721_create_stock_sucursal_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (10,'2026_01_07_234816_create_clientes_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (11,'2026_01_07_234817_create_direcciones_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (12,'2026_01_07_234855_create_pedidos_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (13,'2026_01_07_234913_create_pedido_items_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (14,'2026_01_07_234928_create_envios_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (15,'2026_01_07_234936_create_reservas_stock_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (16,'2026_01_07_234958_create_medios_pago_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (17,'2026_01_07_235006_create_pagos_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (18,'2026_01_07_235041_create_webhooks_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (19,'2026_01_15_073659_create_contenedor_reservas_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (20,'2026_01_16_074804_create_producto_specs_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (21,'2026_01_23_121905_create_paljet_articulos_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (22,'2026_01_23_123343_create_paljet_listas_precios_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (23,'2026_01_23_123359_create_paljet_precios_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (24,'2026_01_23_124729_create_paljet_depositos_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (25,'2026_01_23_124746_create_paljet_stock_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (26,'2026_02_02_000000_create_configuraciones_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (27,'2026_02_02_061152_add_es_contenedor_to_productos_table',2);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (28,'2026_02_02_061210_add_es_contenedor_to_pedido_items_table',2);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (29,'2026_02_02_061728_add_devolucion_fields_to_reservas_stock_table',3);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (30,'2026_02_02_061729_add_devolucion_fields_to_contenedor_reservas_table',3);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (31,'2026_02_03_120000_add_refund_fields_to_pagos_table',4);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (32,'2026_02_03_130000_add_devolucion_solicitada_fields_to_pedidos_table',4);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (33,'2026_02_09_132755_add_access_token_to_pedidos_table',5);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (35,'2026_02_24_071643_add_paljet_fields_to_pedido_items_table',6);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (36,'2026_02_24_123732_add_contacto_dni_cuit_to_pedidos_table',7);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (37,'2026_02_25_041259_add_paljet_art_id_to_productos_table',8);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (38,'2026_02_25_130000_create_paljet_ofertas_table',9);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (39,'2026_02_26_000000_add_precio_oferta_to_paljet_ofertas_table',10);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (40,'2026_02_27_193029_add_medio_pago_id_to_pedidos_table',11);
