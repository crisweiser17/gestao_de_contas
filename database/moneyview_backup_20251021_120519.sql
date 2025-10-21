-- MySQL dump 10.13  Distrib 9.3.0, for macos15.2 (arm64)
--
-- Host: localhost    Database: moneyview
-- ------------------------------------------------------
-- Server version	9.3.0

/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!50503 SET NAMES utf8mb4 */;
/*!40103 SET @OLD_TIME_ZONE=@@TIME_ZONE */;
/*!40103 SET TIME_ZONE='+00:00' */;
/*!40014 SET @OLD_UNIQUE_CHECKS=@@UNIQUE_CHECKS, UNIQUE_CHECKS=0 */;
/*!40014 SET @OLD_FOREIGN_KEY_CHECKS=@@FOREIGN_KEY_CHECKS, FOREIGN_KEY_CHECKS=0 */;
/*!40101 SET @OLD_SQL_MODE=@@SQL_MODE, SQL_MODE='NO_AUTO_VALUE_ON_ZERO' */;
/*!40111 SET @OLD_SQL_NOTES=@@SQL_NOTES, SQL_NOTES=0 */;

--
-- Table structure for table `accounts`
--

DROP TABLE IF EXISTS `accounts`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `accounts` (
  `id` int NOT NULL AUTO_INCREMENT,
  `user_id` int NOT NULL,
  `category_id` int NOT NULL,
  `description` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `amount` decimal(10,2) NOT NULL,
  `due_date` date NOT NULL,
  `type` enum('receita','despesa') COLLATE utf8mb4_unicode_ci NOT NULL,
  `status` enum('pendente','paga','recebida') COLLATE utf8mb4_unicode_ci DEFAULT 'pendente',
  `url` varchar(500) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `is_recurring` tinyint(1) DEFAULT '0',
  `recurring_parent_id` int DEFAULT NULL,
  `notes` text COLLATE utf8mb4_unicode_ci,
  `attachment` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `category_id` (`category_id`),
  KEY `recurring_parent_id` (`recurring_parent_id`),
  KEY `idx_user_due_date` (`user_id`,`due_date`),
  KEY `idx_user_status` (`user_id`,`status`),
  KEY `idx_user_type` (`user_id`,`type`),
  CONSTRAINT `accounts_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `accounts_ibfk_2` FOREIGN KEY (`category_id`) REFERENCES `categories` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `accounts_ibfk_3` FOREIGN KEY (`recurring_parent_id`) REFERENCES `accounts` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=14 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `accounts`
--

LOCK TABLES `accounts` WRITE;
/*!40000 ALTER TABLE `accounts` DISABLE KEYS */;
INSERT INTO `accounts` VALUES (1,1,28,'Imposto AMX',2160.00,'2025-10-10','despesa','paga','',1,NULL,'obs',NULL,'2025-10-07 12:40:23','2025-10-13 15:56:24'),(2,1,32,'Aluguel Apto Pira',2000.00,'2025-10-15','despesa','paga','',1,NULL,'',NULL,'2025-10-07 15:05:14','2025-10-14 18:05:24'),(3,1,30,'Diane Schneider',1500.00,'2025-10-14','receita','pendente','',0,NULL,'',NULL,'2025-10-07 17:59:13','2025-10-07 17:59:13'),(4,1,29,'HS Consorcios',1390.00,'2025-10-11','despesa','paga','',1,NULL,'Consorcio. 220 meses. Iniciou em setembro de 2025.','uploads/attachments/68e66e57b4b07_1759931991.pdf','2025-10-07 19:18:27','2025-10-08 13:59:51'),(5,1,31,'Salario NAVA',36000.00,'2025-10-30','receita','pendente','',1,NULL,'',NULL,'2025-10-07 19:25:30','2025-10-07 19:25:58'),(6,1,30,'Ridegood Parcela #2',2000.00,'2025-10-08','receita','recebida','',0,NULL,'',NULL,'2025-10-08 14:00:56','2025-10-14 17:18:35'),(7,1,32,'Seguro Saude Sulamerica',750.00,'2025-10-15','despesa','paga','',1,NULL,'','uploads/attachments/68e675f274ab1_1759933938.jpeg','2025-10-08 14:08:50','2025-10-08 14:32:18'),(8,1,33,'Operacao Five Tedesco',23000.00,'2025-10-10','receita','pendente','',0,NULL,'E tem mais juros da parcela de 10k atrasado. Calcular',NULL,'2025-10-10 12:41:51','2025-10-10 12:42:55'),(9,1,29,'QG Cardoso de Melo 2506',1620.00,'2025-10-15','despesa','paga','',1,NULL,'',NULL,'2025-10-13 15:32:20','2025-10-13 15:49:29'),(10,1,29,'QG Cardoso de Melo 103NR',1620.00,'2025-10-15','despesa','paga','',1,NULL,'',NULL,'2025-10-13 15:32:45','2025-10-13 15:49:37'),(11,1,32,'Condominio Conj. dos Passaros',653.57,'2025-10-12','despesa','paga','',1,NULL,'',NULL,'2025-10-13 18:41:29','2025-10-13 18:43:54'),(12,1,33,'Mentoria - parte Carlinhos',1200.00,'2025-11-01','receita','pendente','',1,NULL,'na parcela de 1/nov tem que abater que Carlinhos pagou 3500 pra abrir empresa. Eu teria que dar 1750. Entao em outubro ele nao pagou a parcela da mentoria de 1200 e ainda tem 550 pra abater da parcela de novembro. Em novembro paga $650. Depois volta a pagar $1200 ate o fim.',NULL,'2025-10-14 17:28:24','2025-10-14 17:28:24'),(13,1,29,'Parcela Terreno Piracicaba',25000.00,'2025-11-01','despesa','pendente','',1,NULL,'',NULL,'2025-10-14 17:29:10','2025-10-14 17:29:10');
/*!40000 ALTER TABLE `accounts` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `cash_flow_projections`
--

DROP TABLE IF EXISTS `cash_flow_projections`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `cash_flow_projections` (
  `id` int NOT NULL AUTO_INCREMENT,
  `user_id` int NOT NULL,
  `projection_date` date NOT NULL,
  `projected_income` decimal(12,2) DEFAULT '0.00',
  `projected_expenses` decimal(12,2) DEFAULT '0.00',
  `projected_balance` decimal(12,2) DEFAULT '0.00',
  `accumulated_balance` decimal(12,2) DEFAULT '0.00',
  `last_updated` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_user_date` (`user_id`,`projection_date`),
  KEY `idx_user_projection_date` (`user_id`,`projection_date`),
  CONSTRAINT `cash_flow_projections_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `cash_flow_projections`
--

LOCK TABLES `cash_flow_projections` WRITE;
/*!40000 ALTER TABLE `cash_flow_projections` DISABLE KEYS */;
/*!40000 ALTER TABLE `cash_flow_projections` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `categories`
--

DROP TABLE IF EXISTS `categories`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `categories` (
  `id` int NOT NULL AUTO_INCREMENT,
  `user_id` int NOT NULL,
  `name` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL,
  `type` enum('receita','despesa') COLLATE utf8mb4_unicode_ci NOT NULL,
  `color` varchar(7) COLLATE utf8mb4_unicode_ci DEFAULT '#3B82F6',
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_user_category` (`user_id`,`name`),
  CONSTRAINT `categories_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=34 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `categories`
--

LOCK TABLES `categories` WRITE;
/*!40000 ALTER TABLE `categories` DISABLE KEYS */;
INSERT INTO `categories` VALUES (28,1,'Empresas','despesa','#f7a93b','2025-10-07 14:11:34'),(29,1,'Investimentos','despesa','#3b82f6','2025-10-07 14:11:49'),(30,1,'Freelance','receita','#3b82f6','2025-10-07 17:58:14'),(31,1,'Salario','receita','#f73b3b','2025-10-07 19:25:49'),(32,1,'Moradia','despesa','#f7903b','2025-10-08 14:07:54'),(33,1,'Factoring','receita','#479b36','2025-10-10 12:42:44');
/*!40000 ALTER TABLE `categories` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `recurring_settings`
--

DROP TABLE IF EXISTS `recurring_settings`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `recurring_settings` (
  `id` int NOT NULL AUTO_INCREMENT,
  `account_id` int NOT NULL,
  `frequency_type` enum('semanal','mensal','bimestral','trimestral','semestral','anual','personalizado') COLLATE utf8mb4_unicode_ci NOT NULL,
  `frequency_interval` int DEFAULT '1',
  `end_date` date DEFAULT NULL,
  `max_occurrences` int DEFAULT NULL,
  `next_generation_date` date NOT NULL,
  `last_generated_date` date DEFAULT NULL,
  `is_active` tinyint(1) DEFAULT '1',
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_account_recurring` (`account_id`),
  CONSTRAINT `recurring_settings_ibfk_1` FOREIGN KEY (`account_id`) REFERENCES `accounts` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=11 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `recurring_settings`
--

LOCK TABLES `recurring_settings` WRITE;
/*!40000 ALTER TABLE `recurring_settings` DISABLE KEYS */;
INSERT INTO `recurring_settings` VALUES (1,1,'mensal',30,NULL,5,'2025-11-10',NULL,1,'2025-10-07 12:40:23','2025-10-07 12:40:23'),(2,2,'mensal',1,'2026-08-01',NULL,'2025-11-15',NULL,1,'2025-10-07 15:05:14','2025-10-07 15:05:14'),(3,4,'mensal',1,NULL,NULL,'2025-11-11',NULL,1,'2025-10-07 19:18:28','2025-10-07 19:18:28'),(4,5,'mensal',1,NULL,NULL,'2025-11-30',NULL,1,'2025-10-07 19:25:31','2025-10-07 19:25:31'),(5,7,'mensal',1,NULL,NULL,'2025-11-15',NULL,1,'2025-10-08 14:08:59','2025-10-08 14:08:59'),(6,9,'mensal',1,NULL,32,'2025-11-15',NULL,1,'2025-10-13 15:32:21','2025-10-13 15:34:04'),(7,10,'mensal',1,NULL,32,'2025-11-15',NULL,1,'2025-10-13 15:32:45','2025-10-13 15:32:45'),(8,11,'mensal',1,NULL,9,'2025-11-12',NULL,1,'2025-10-13 18:41:29','2025-10-13 18:41:29'),(9,12,'mensal',1,NULL,8,'2025-12-01',NULL,1,'2025-10-14 17:28:24','2025-10-14 17:28:24'),(10,13,'mensal',1,NULL,10,'2025-12-01',NULL,1,'2025-10-14 17:29:10','2025-10-14 17:29:10');
/*!40000 ALTER TABLE `recurring_settings` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `users`
--

DROP TABLE IF EXISTS `users`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `users` (
  `id` int NOT NULL AUTO_INCREMENT,
  `name` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL,
  `email` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL,
  `password` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `email` (`email`)
) ENGINE=InnoDB AUTO_INCREMENT=2 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `users`
--

LOCK TABLES `users` WRITE;
/*!40000 ALTER TABLE `users` DISABLE KEYS */;
INSERT INTO `users` VALUES (1,'Cris Weiser','hello@crisweiser.com','$2y$12$iZ8uPTFEXiTM4N8ZrtTjwuVK42CN3LS188cORySgUdoEddfYJ/Exu','2025-10-07 02:01:25','2025-10-21 14:48:04');
/*!40000 ALTER TABLE `users` ENABLE KEYS */;
UNLOCK TABLES;
/*!40103 SET TIME_ZONE=@OLD_TIME_ZONE */;

/*!40101 SET SQL_MODE=@OLD_SQL_MODE */;
/*!40014 SET FOREIGN_KEY_CHECKS=@OLD_FOREIGN_KEY_CHECKS */;
/*!40014 SET UNIQUE_CHECKS=@OLD_UNIQUE_CHECKS */;
/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
/*!40111 SET SQL_NOTES=@OLD_SQL_NOTES */;

-- Dump completed on 2025-10-21 12:05:20
