-- MySQL dump 10.13  Distrib 8.0.46, for Linux (x86_64)
--
-- Host: localhost    Database: bdcontapp
-- ------------------------------------------------------
-- Server version	8.0.46

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
-- Table structure for table `account_mask_configs`
--

DROP TABLE IF EXISTS `account_mask_configs`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `account_mask_configs` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `company_id` bigint unsigned NOT NULL,
  `segment_lengths` json NOT NULL COMMENT 'ej [1,2,2,2,3] para x-xx-xx-xx-xxx',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `account_mask_configs_company_id_unique` (`company_id`),
  CONSTRAINT `account_mask_configs_company_id_foreign` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=7 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `account_mask_configs`
--

LOCK TABLES `account_mask_configs` WRITE;
/*!40000 ALTER TABLE `account_mask_configs` DISABLE KEYS */;
INSERT INTO `account_mask_configs` VALUES (2,599,'[1, 2, 2, 2, 3]','2026-09-02 02:20:32','2026-09-02 02:20:32'),(3,600,'[1, 2, 2, 2, 3]','2026-09-02 02:34:14','2026-09-02 02:34:14'),(4,603,'[1, 2, 2, 2, 3]','2026-09-18 02:18:02','2026-09-18 02:18:02'),(5,604,'[1, 2, 2, 2, 3]','2026-09-18 02:23:21','2026-09-18 02:23:21'),(6,605,'[1, 2, 2, 2, 3]','2026-09-27 21:08:01','2026-09-27 21:08:01');
/*!40000 ALTER TABLE `account_mask_configs` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `account_reconciliation_lines`
--

DROP TABLE IF EXISTS `account_reconciliation_lines`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `account_reconciliation_lines` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `account_reconciliation_id` bigint unsigned NOT NULL,
  `journal_detail_id` bigint unsigned NOT NULL,
  `amount` decimal(18,2) NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `account_reconciliation_lines_account_reconciliation_id_foreign` (`account_reconciliation_id`),
  KEY `acct_recon_lines_detail_idx` (`journal_detail_id`),
  CONSTRAINT `account_reconciliation_lines_account_reconciliation_id_foreign` FOREIGN KEY (`account_reconciliation_id`) REFERENCES `account_reconciliations` (`id`) ON DELETE CASCADE,
  CONSTRAINT `account_reconciliation_lines_journal_detail_id_foreign` FOREIGN KEY (`journal_detail_id`) REFERENCES `journal_details` (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=11 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `account_reconciliation_lines`
--

LOCK TABLES `account_reconciliation_lines` WRITE;
/*!40000 ALTER TABLE `account_reconciliation_lines` DISABLE KEYS */;
INSERT INTO `account_reconciliation_lines` VALUES (3,2,1100,12611.37,'2026-09-06 21:57:26','2026-09-06 21:57:26'),(4,2,1136,12611.37,'2026-09-06 21:57:26','2026-09-06 21:57:26'),(5,3,1139,113000.00,'2026-09-09 02:26:30','2026-09-09 02:26:30'),(6,3,1178,113000.00,'2026-09-09 02:26:30','2026-09-09 02:26:30'),(9,5,806,31670.00,'2026-09-09 03:05:18','2026-09-09 03:05:18'),(10,5,1184,31670.00,'2026-09-09 03:05:18','2026-09-09 03:05:18');
/*!40000 ALTER TABLE `account_reconciliation_lines` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `account_reconciliations`
--

DROP TABLE IF EXISTS `account_reconciliations`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `account_reconciliations` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `account_id` bigint unsigned NOT NULL,
  `reconciled_by` bigint unsigned DEFAULT NULL,
  `reconciled_at` timestamp NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `account_reconciliations_reconciled_by_foreign` (`reconciled_by`),
  KEY `account_reconciliations_account_id_index` (`account_id`),
  CONSTRAINT `account_reconciliations_account_id_foreign` FOREIGN KEY (`account_id`) REFERENCES `chart_of_accounts` (`id`),
  CONSTRAINT `account_reconciliations_reconciled_by_foreign` FOREIGN KEY (`reconciled_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=6 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `account_reconciliations`
--

LOCK TABLES `account_reconciliations` WRITE;
/*!40000 ALTER TABLE `account_reconciliations` DISABLE KEYS */;
INSERT INTO `account_reconciliations` VALUES (2,983,357,'2026-09-06 21:57:26','2026-09-06 21:57:26','2026-09-06 21:57:26'),(3,919,357,'2026-09-09 02:26:30','2026-09-09 02:26:30','2026-09-09 02:26:30'),(5,947,357,'2026-09-09 03:05:18','2026-09-09 03:05:18','2026-09-09 03:05:18');
/*!40000 ALTER TABLE `account_reconciliations` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `audit_logs`
--

DROP TABLE IF EXISTS `audit_logs`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `audit_logs` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `company_id` bigint unsigned DEFAULT NULL,
  `user_id` bigint unsigned DEFAULT NULL,
  `propietario_id` bigint unsigned DEFAULT NULL,
  `action` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'create|update|delete|void|permission_change|period_close...',
  `auditable_type` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `auditable_id` bigint unsigned NOT NULL,
  `old_values` json DEFAULT NULL,
  `new_values` json DEFAULT NULL,
  `ip_address` varchar(45) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `audit_logs_company_id_foreign` (`company_id`),
  KEY `audit_logs_user_id_foreign` (`user_id`),
  KEY `audit_logs_auditable_type_auditable_id_index` (`auditable_type`,`auditable_id`),
  KEY `audit_logs_propietario_id_foreign` (`propietario_id`),
  CONSTRAINT `audit_logs_company_id_foreign` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`) ON DELETE SET NULL,
  CONSTRAINT `audit_logs_propietario_id_foreign` FOREIGN KEY (`propietario_id`) REFERENCES `propietarios` (`id`) ON DELETE SET NULL,
  CONSTRAINT `audit_logs_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=34 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `audit_logs`
--

LOCK TABLES `audit_logs` WRITE;
/*!40000 ALTER TABLE `audit_logs` DISABLE KEYS */;
INSERT INTO `audit_logs` VALUES (20,600,355,NULL,'company_created','App\\Domains\\Core\\Models\\Company',600,NULL,'{\"legal_name\": \"ASOCIACION SOLIDARISTA EMPLEADOS DE RESUSA, S.A.\", \"license_id\": 39}',NULL,'2026-09-02 02:34:14'),(21,600,355,NULL,'user_created','App\\Models\\User',356,NULL,'{\"role_type\": \"admin\", \"module_permissions\": []}',NULL,'2026-09-02 04:09:59'),(22,600,355,NULL,'user_created','App\\Models\\User',357,NULL,'{\"role_type\": \"user\", \"module_permissions\": []}',NULL,'2026-09-02 04:12:15'),(23,600,355,NULL,'permission_change','App\\Models\\User',357,'[]','{\"1427\": \"read_write\", \"1428\": \"read_write\", \"1429\": \"read_write\", \"1430\": \"read_write\", \"1431\": \"read_write\"}',NULL,'2026-09-02 04:33:23'),(24,600,355,NULL,'permission_change','App\\Models\\User',356,'[]','{\"1427\": \"read_write\", \"1428\": \"read_write\", \"1429\": \"read_write\", \"1430\": \"read_write\", \"1431\": \"read_write\"}',NULL,'2026-09-02 04:33:53'),(25,603,358,NULL,'user_invited','App\\Models\\User',356,NULL,'{\"role_type\": \"admin\", \"module_permissions\": {\"1427\": \"read_write\", \"1428\": \"read_write\", \"1429\": \"read_write\", \"1430\": \"read_write\", \"1431\": \"read_write\", \"1432\": \"read_write\"}}',NULL,'2026-09-18 02:21:59'),(26,604,358,NULL,'company_created','App\\Domains\\Core\\Models\\Company',604,NULL,'{\"legal_name\": \"kuvo pruebas\", \"license_id\": 40}',NULL,'2026-09-18 02:23:21'),(27,604,358,NULL,'user_invited','App\\Models\\User',356,NULL,'{\"role_type\": \"admin\", \"module_permissions\": {\"1427\": \"read_write\", \"1428\": \"read_write\", \"1429\": \"read_write\", \"1430\": \"read_write\", \"1431\": \"read_write\", \"1432\": \"read_write\"}}',NULL,'2026-09-18 02:24:33'),(28,604,358,NULL,'user_created','App\\Models\\User',359,NULL,'{\"role_type\": \"user\", \"module_permissions\": {\"1427\": \"read_write\", \"1428\": \"read_write\", \"1429\": \"read_write\", \"1430\": \"read_write\", \"1431\": \"read_write\", \"1432\": \"read_write\"}}',NULL,'2026-09-18 02:51:59'),(29,605,355,NULL,'company_created','App\\Domains\\Core\\Models\\Company',605,NULL,'{\"legal_name\": \"Transportes Senna S.A.\", \"license_id\": 39}',NULL,'2026-09-27 21:08:01'),(30,605,355,NULL,'company.theme_updated','App\\Domains\\Core\\Models\\Company',605,'{\"theme\": \"marino\"}','{\"theme\": \"borgona\"}','172.18.0.1','2026-10-01 01:06:30'),(31,605,355,NULL,'company.theme_updated','App\\Domains\\Core\\Models\\Company',605,'{\"theme\": \"borgona\"}','{\"theme\": \"onix\"}','172.18.0.1','2026-10-01 01:09:05'),(32,NULL,NULL,26,'license_code_revealed','App\\Domains\\Licensing\\Models\\License',40,NULL,NULL,'172.18.0.1','2026-10-01 02:04:15'),(33,NULL,NULL,26,'license_code_revealed','App\\Domains\\Licensing\\Models\\License',39,NULL,NULL,'172.18.0.1','2026-10-01 02:04:59');
/*!40000 ALTER TABLE `audit_logs` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `bank_accounts`
--

DROP TABLE IF EXISTS `bank_accounts`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `bank_accounts` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `company_id` bigint unsigned NOT NULL,
  `gl_account_id` bigint unsigned NOT NULL,
  `bank_name` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `account_number` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `currency_id` bigint unsigned NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `bank_accounts_gl_account_id_unique` (`gl_account_id`),
  KEY `bank_accounts_company_id_foreign` (`company_id`),
  KEY `bank_accounts_currency_id_foreign` (`currency_id`),
  CONSTRAINT `bank_accounts_company_id_foreign` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`) ON DELETE CASCADE,
  CONSTRAINT `bank_accounts_currency_id_foreign` FOREIGN KEY (`currency_id`) REFERENCES `currencies` (`id`),
  CONSTRAINT `bank_accounts_gl_account_id_foreign` FOREIGN KEY (`gl_account_id`) REFERENCES `chart_of_accounts` (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=29 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `bank_accounts`
--

LOCK TABLES `bank_accounts` WRITE;
/*!40000 ALTER TABLE `bank_accounts` DISABLE KEYS */;
INSERT INTO `bank_accounts` VALUES (26,600,921,'Banco Nacional CR506...','CR35015113410010007946',949,'2026-09-06 01:54:57','2026-09-06 01:54:57'),(27,605,1382,'Banco Nacional de Costa Rica','100-01-125-000642-9',949,'2026-09-29 03:03:29','2026-09-29 03:03:29'),(28,605,1383,'Banco Nacional de Costa Rica','100-02-125-000740-1',950,'2026-09-29 03:05:43','2026-09-29 03:05:43');
/*!40000 ALTER TABLE `bank_accounts` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `bank_reconciliation_lines`
--

DROP TABLE IF EXISTS `bank_reconciliation_lines`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `bank_reconciliation_lines` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `bank_reconciliation_id` bigint unsigned NOT NULL,
  `journal_detail_id` bigint unsigned NOT NULL,
  `matched_in_books` tinyint(1) NOT NULL DEFAULT '0' COMMENT 'columna "Cta" del legado',
  `matched_in_bank` tinyint(1) NOT NULL DEFAULT '0' COMMENT 'columna "Bco" del legado',
  `bank_statement_line_id` bigint unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `bank_reconciliation_lines_unique` (`bank_reconciliation_id`,`journal_detail_id`),
  KEY `bank_reconciliation_lines_journal_detail_id_foreign` (`journal_detail_id`),
  KEY `bank_reconciliation_lines_bank_statement_line_id_foreign` (`bank_statement_line_id`),
  CONSTRAINT `bank_reconciliation_lines_bank_reconciliation_id_foreign` FOREIGN KEY (`bank_reconciliation_id`) REFERENCES `bank_reconciliations` (`id`) ON DELETE CASCADE,
  CONSTRAINT `bank_reconciliation_lines_bank_statement_line_id_foreign` FOREIGN KEY (`bank_statement_line_id`) REFERENCES `bank_statement_lines` (`id`) ON DELETE SET NULL,
  CONSTRAINT `bank_reconciliation_lines_journal_detail_id_foreign` FOREIGN KEY (`journal_detail_id`) REFERENCES `journal_details` (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=113 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `bank_reconciliation_lines`
--

LOCK TABLES `bank_reconciliation_lines` WRITE;
/*!40000 ALTER TABLE `bank_reconciliation_lines` DISABLE KEYS */;
INSERT INTO `bank_reconciliation_lines` VALUES (78,13,551,1,1,NULL,'2026-09-06 21:33:22','2026-09-06 21:33:28'),(79,13,995,1,1,NULL,'2026-09-06 21:33:22','2026-09-06 21:33:30'),(80,13,996,1,1,NULL,'2026-09-06 21:33:22','2026-09-06 21:33:32'),(81,13,997,1,1,NULL,'2026-09-06 21:33:22','2026-09-06 21:33:33'),(82,13,998,1,1,NULL,'2026-09-06 21:33:22','2026-09-06 21:33:33'),(83,13,999,1,1,NULL,'2026-09-06 21:33:22','2026-09-06 21:33:36'),(84,13,1000,1,1,NULL,'2026-09-06 21:33:22','2026-09-06 21:33:37'),(85,13,1001,1,1,NULL,'2026-09-06 21:33:22','2026-09-06 21:33:37'),(86,13,1002,1,1,NULL,'2026-09-06 21:33:22','2026-09-06 21:33:37'),(87,13,1003,1,1,NULL,'2026-09-06 21:33:22','2026-09-06 21:33:38'),(88,13,1004,1,1,NULL,'2026-09-06 21:33:22','2026-09-06 21:33:38'),(89,13,1005,1,1,NULL,'2026-09-06 21:33:22','2026-09-06 21:33:42'),(90,13,1006,1,1,NULL,'2026-09-06 21:33:22','2026-09-06 21:33:42'),(91,13,1007,1,1,NULL,'2026-09-06 21:33:22','2026-09-06 21:33:43'),(92,13,1008,1,1,NULL,'2026-09-06 21:33:22','2026-09-06 21:33:43'),(93,13,1009,1,1,NULL,'2026-09-06 21:33:22','2026-09-06 21:33:44'),(94,13,1010,1,1,NULL,'2026-09-06 21:33:22','2026-09-06 21:33:45'),(95,13,1011,1,1,NULL,'2026-09-06 21:33:22','2026-09-06 21:33:46'),(96,13,1012,1,1,NULL,'2026-09-06 21:33:22','2026-09-06 21:33:46'),(97,13,1076,1,1,NULL,'2026-09-06 21:33:23','2026-09-06 21:33:47'),(98,13,1077,1,1,NULL,'2026-09-06 21:33:23','2026-09-06 21:33:52'),(99,13,1078,1,1,NULL,'2026-09-06 21:33:23','2026-09-06 21:33:52'),(100,13,1079,1,1,NULL,'2026-09-06 21:33:23','2026-09-06 21:33:52'),(101,13,1080,1,1,NULL,'2026-09-06 21:33:23','2026-09-06 21:33:54'),(102,13,1081,1,1,NULL,'2026-09-06 21:33:23','2026-09-06 21:33:54'),(103,13,1082,1,1,NULL,'2026-09-06 21:33:23','2026-09-06 21:33:54'),(104,13,1083,1,1,NULL,'2026-09-06 21:33:23','2026-09-06 21:33:54'),(105,13,1084,1,1,NULL,'2026-09-06 21:33:23','2026-09-06 21:33:55'),(106,13,1085,1,1,NULL,'2026-09-06 21:33:23','2026-09-06 21:33:56'),(107,13,1086,1,1,NULL,'2026-09-06 21:33:23','2026-09-06 21:34:01'),(108,13,1116,1,1,NULL,'2026-09-06 21:33:23','2026-09-06 21:34:01'),(109,13,1118,1,1,NULL,'2026-09-06 21:33:23','2026-09-06 21:33:57'),(110,13,1120,1,1,NULL,'2026-09-06 21:33:23','2026-09-06 21:34:02'),(111,13,1122,1,1,NULL,'2026-09-06 21:33:23','2026-09-06 21:34:03'),(112,13,1124,1,1,NULL,'2026-09-06 21:33:23','2026-09-06 21:34:04');
/*!40000 ALTER TABLE `bank_reconciliation_lines` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `bank_reconciliations`
--

DROP TABLE IF EXISTS `bank_reconciliations`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `bank_reconciliations` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `bank_account_id` bigint unsigned NOT NULL,
  `cutoff_date` date NOT NULL,
  `bank_balance` decimal(18,2) NOT NULL,
  `book_balance` decimal(18,2) NOT NULL,
  `unrecorded_deposits` decimal(18,2) NOT NULL DEFAULT '0.00' COMMENT 'depósitos en libros aún no acreditados por el banco',
  `unpaid_checks` decimal(18,2) NOT NULL DEFAULT '0.00' COMMENT 'cheques girados en libros aún no pagados por el banco',
  `unrecorded_bank_credits` decimal(18,2) NOT NULL DEFAULT '0.00' COMMENT 'créditos del banco aún no registrados en libros',
  `unrecorded_bank_debits` decimal(18,2) NOT NULL DEFAULT '0.00' COMMENT 'débitos del banco aún no registrados en libros',
  `status` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'in_progress' COMMENT 'in_progress|completed',
  `created_by` bigint unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `bank_reconciliations_bank_account_id_foreign` (`bank_account_id`),
  KEY `bank_reconciliations_created_by_foreign` (`created_by`),
  CONSTRAINT `bank_reconciliations_bank_account_id_foreign` FOREIGN KEY (`bank_account_id`) REFERENCES `bank_accounts` (`id`) ON DELETE CASCADE,
  CONSTRAINT `bank_reconciliations_created_by_foreign` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=14 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `bank_reconciliations`
--

LOCK TABLES `bank_reconciliations` WRITE;
/*!40000 ALTER TABLE `bank_reconciliations` DISABLE KEYS */;
INSERT INTO `bank_reconciliations` VALUES (13,26,'2026-08-31',2791045.54,2791045.54,0.00,0.00,0.00,0.00,'completed',357,'2026-09-06 21:33:22','2026-09-06 21:34:36');
/*!40000 ALTER TABLE `bank_reconciliations` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `bank_statement_lines`
--

DROP TABLE IF EXISTS `bank_statement_lines`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `bank_statement_lines` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `bank_account_id` bigint unsigned NOT NULL,
  `statement_date` date NOT NULL,
  `description` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `amount` decimal(18,2) NOT NULL COMMENT 'positivo = crédito del banco, negativo = débito del banco',
  `import_batch_id` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `matched` tinyint(1) NOT NULL DEFAULT '0',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `bank_statement_lines_bank_account_id_matched_index` (`bank_account_id`,`matched`),
  CONSTRAINT `bank_statement_lines_bank_account_id_foreign` FOREIGN KEY (`bank_account_id`) REFERENCES `bank_accounts` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=3 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `bank_statement_lines`
--

LOCK TABLES `bank_statement_lines` WRITE;
/*!40000 ALTER TABLE `bank_statement_lines` DISABLE KEYS */;
/*!40000 ALTER TABLE `bank_statement_lines` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `bill_of_material_lines`
--

DROP TABLE IF EXISTS `bill_of_material_lines`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `bill_of_material_lines` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `bill_of_material_id` bigint unsigned NOT NULL,
  `component_item_id` bigint unsigned NOT NULL,
  `quantity` decimal(18,6) NOT NULL,
  `scrap_percentage` decimal(8,4) NOT NULL DEFAULT '0.0000',
  `warehouse_id` bigint unsigned DEFAULT NULL,
  `notes` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `bom_line_unique` (`bill_of_material_id`,`component_item_id`),
  KEY `bill_of_material_lines_component_item_id_foreign` (`component_item_id`),
  KEY `bill_of_material_lines_warehouse_id_foreign` (`warehouse_id`),
  CONSTRAINT `bill_of_material_lines_bill_of_material_id_foreign` FOREIGN KEY (`bill_of_material_id`) REFERENCES `bills_of_materials` (`id`) ON DELETE CASCADE,
  CONSTRAINT `bill_of_material_lines_component_item_id_foreign` FOREIGN KEY (`component_item_id`) REFERENCES `items` (`id`),
  CONSTRAINT `bill_of_material_lines_warehouse_id_foreign` FOREIGN KEY (`warehouse_id`) REFERENCES `warehouses` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=9 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `bill_of_material_lines`
--

LOCK TABLES `bill_of_material_lines` WRITE;
/*!40000 ALTER TABLE `bill_of_material_lines` DISABLE KEYS */;
INSERT INTO `bill_of_material_lines` VALUES (1,1,10,25.000000,4.0000,NULL,'Cortes y troquelado','2026-09-23 03:54:07','2026-09-23 03:54:07'),(2,1,11,3.000000,0.0000,NULL,NULL,'2026-09-23 03:54:07','2026-09-23 03:54:07'),(3,1,12,2.000000,0.0000,NULL,NULL,'2026-09-23 03:54:07','2026-09-23 03:54:07'),(4,2,10,35.000000,4.0000,NULL,'Lámina reforzada','2026-09-23 03:54:07','2026-09-23 03:54:07'),(5,2,11,4.000000,0.0000,NULL,NULL,'2026-09-23 03:54:07','2026-09-23 03:54:07'),(6,2,12,3.000000,0.0000,NULL,NULL,'2026-09-23 03:54:07','2026-09-23 03:54:07'),(7,3,10,60.000000,3.0000,NULL,NULL,'2026-09-23 03:54:07','2026-09-23 03:54:07'),(8,3,12,4.000000,0.0000,NULL,NULL,'2026-09-23 03:54:07','2026-09-23 03:54:07');
/*!40000 ALTER TABLE `bill_of_material_lines` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `billing_payment_accounts`
--

DROP TABLE IF EXISTS `billing_payment_accounts`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `billing_payment_accounts` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `company_id` bigint unsigned NOT NULL,
  `method_code` varchar(2) COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'Nota 6: 01 efectivo, 02 tarjeta, 04 transferencia...',
  `account_id` bigint unsigned NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `billing_payment_accounts_company_id_method_code_unique` (`company_id`,`method_code`),
  KEY `billing_payment_accounts_account_id_foreign` (`account_id`),
  CONSTRAINT `billing_payment_accounts_account_id_foreign` FOREIGN KEY (`account_id`) REFERENCES `chart_of_accounts` (`id`),
  CONSTRAINT `billing_payment_accounts_company_id_foreign` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `billing_payment_accounts`
--

LOCK TABLES `billing_payment_accounts` WRITE;
/*!40000 ALTER TABLE `billing_payment_accounts` DISABLE KEYS */;
/*!40000 ALTER TABLE `billing_payment_accounts` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `billing_tax_accounts`
--

DROP TABLE IF EXISTS `billing_tax_accounts`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `billing_tax_accounts` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `company_id` bigint unsigned NOT NULL,
  `iva_rate_code` varchar(2) COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'Nota 8.1',
  `account_id` bigint unsigned NOT NULL,
  `tax_rate_id` bigint unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `billing_tax_accounts_rate_unique` (`company_id`,`iva_rate_code`),
  KEY `billing_tax_accounts_account_id_foreign` (`account_id`),
  KEY `billing_tax_accounts_tax_rate_id_foreign` (`tax_rate_id`),
  CONSTRAINT `billing_tax_accounts_account_id_foreign` FOREIGN KEY (`account_id`) REFERENCES `chart_of_accounts` (`id`),
  CONSTRAINT `billing_tax_accounts_company_id_foreign` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`) ON DELETE CASCADE,
  CONSTRAINT `billing_tax_accounts_tax_rate_id_foreign` FOREIGN KEY (`tax_rate_id`) REFERENCES `tax_rates` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `billing_tax_accounts`
--

LOCK TABLES `billing_tax_accounts` WRITE;
/*!40000 ALTER TABLE `billing_tax_accounts` DISABLE KEYS */;
/*!40000 ALTER TABLE `billing_tax_accounts` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `bills_of_materials`
--

DROP TABLE IF EXISTS `bills_of_materials`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `bills_of_materials` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `company_id` bigint unsigned NOT NULL,
  `item_id` bigint unsigned NOT NULL,
  `code` varchar(20) COLLATE utf8mb4_unicode_ci NOT NULL,
  `name` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `output_quantity` decimal(18,6) NOT NULL DEFAULT '1.000000',
  `is_default` tinyint(1) NOT NULL DEFAULT '0',
  `status` varchar(20) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'active',
  `notes` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `bills_of_materials_company_id_code_unique` (`company_id`,`code`),
  KEY `bills_of_materials_item_id_foreign` (`item_id`),
  KEY `bills_of_materials_company_id_item_id_status_index` (`company_id`,`item_id`,`status`),
  CONSTRAINT `bills_of_materials_company_id_foreign` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`) ON DELETE CASCADE,
  CONSTRAINT `bills_of_materials_item_id_foreign` FOREIGN KEY (`item_id`) REFERENCES `items` (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=4 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `bills_of_materials`
--

LOCK TABLES `bills_of_materials` WRITE;
/*!40000 ALTER TABLE `bills_of_materials` DISABLE KEYS */;
INSERT INTO `bills_of_materials` VALUES (1,604,13,'GAB-STD','Gabinete metálico — fórmula estándar',10.000000,1,'active','La que se usa normalmente.','2026-09-23 03:54:06','2026-09-23 03:54:06'),(2,604,13,'GAB-REF','Gabinete metálico — reforzado',10.000000,0,'active','Para instalación a la intemperie: 40% más de lámina.','2026-09-23 03:54:07','2026-09-23 03:54:07'),(3,604,14,'RACK-STD','Rack de servidores 42U — fórmula estándar',5.000000,1,'active',NULL,'2026-09-23 03:54:07','2026-09-23 03:54:07');
/*!40000 ALTER TABLE `bills_of_materials` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `bp_categories`
--

DROP TABLE IF EXISTS `bp_categories`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `bp_categories` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `company_id` bigint unsigned NOT NULL,
  `code` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `name` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `price_list_id` bigint unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `bp_categories_company_id_code_unique` (`company_id`,`code`),
  KEY `bp_categories_price_list_id_foreign` (`price_list_id`),
  CONSTRAINT `bp_categories_company_id_foreign` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`) ON DELETE CASCADE,
  CONSTRAINT `bp_categories_price_list_id_foreign` FOREIGN KEY (`price_list_id`) REFERENCES `price_lists` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=24 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `bp_categories`
--

LOCK TABLES `bp_categories` WRITE;
/*!40000 ALTER TABLE `bp_categories` DISABLE KEYS */;
INSERT INTO `bp_categories` VALUES (14,600,'GASTOS','PROVEEDOR DE GASTOS',NULL,'2026-09-04 03:41:34','2026-09-04 03:41:34'),(15,600,'PRINCIPAL','CLIENTES PRINCIPAL',NULL,'2026-09-05 00:31:30','2026-09-05 00:40:36'),(16,600,'INTERESES','CLIENTES INTERESES',NULL,'2026-09-05 00:33:16','2026-09-05 00:40:20'),(17,604,'A','Clientes categoría A',NULL,'2026-09-18 03:23:42','2026-09-18 03:23:42'),(18,604,'B','Clientes categoría B',NULL,'2026-09-18 03:23:42','2026-09-18 03:23:42'),(19,604,'PRV','Proveedores nacionales',NULL,'2026-09-18 03:23:42','2026-09-18 03:23:42'),(20,604,'EXT','Proveedores del exterior',NULL,'2026-09-18 03:23:42','2026-09-18 03:23:42'),(21,605,'C¢','Comercial ¢',NULL,'2026-09-29 02:15:36','2026-09-29 02:15:36'),(22,605,'C$','Comercial $',NULL,'2026-09-29 02:17:46','2026-09-29 02:17:46'),(23,605,'R$','Reintegros $',NULL,'2026-09-29 02:18:11','2026-09-29 02:18:11');
/*!40000 ALTER TABLE `bp_categories` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `bp_families`
--

DROP TABLE IF EXISTS `bp_families`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `bp_families` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `company_id` bigint unsigned NOT NULL,
  `code` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `name` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `bp_families_company_id_code_unique` (`company_id`,`code`),
  CONSTRAINT `bp_families_company_id_foreign` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=4 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `bp_families`
--

LOCK TABLES `bp_families` WRITE;
/*!40000 ALTER TABLE `bp_families` DISABLE KEYS */;
INSERT INTO `bp_families` VALUES (1,604,'MERC','Mercadería','2026-09-18 03:23:42','2026-09-18 03:23:42'),(2,604,'SERV','Servicios','2026-09-18 03:23:42','2026-09-18 03:23:42'),(3,604,'GAST','Gastos generales','2026-09-18 03:23:42','2026-09-18 03:23:42');
/*!40000 ALTER TABLE `bp_families` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `bp_open_item_reconciliation_lines`
--

DROP TABLE IF EXISTS `bp_open_item_reconciliation_lines`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `bp_open_item_reconciliation_lines` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `bp_open_item_reconciliation_id` bigint unsigned NOT NULL,
  `bp_open_item_id` bigint unsigned NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `bp_oi_recon_lines_open_item_id_unique` (`bp_open_item_id`),
  KEY `bp_oi_recon_lines_recon_id_fk` (`bp_open_item_reconciliation_id`),
  CONSTRAINT `bp_oi_recon_lines_open_item_id_fk` FOREIGN KEY (`bp_open_item_id`) REFERENCES `bp_open_items` (`id`),
  CONSTRAINT `bp_oi_recon_lines_recon_id_fk` FOREIGN KEY (`bp_open_item_reconciliation_id`) REFERENCES `bp_open_item_reconciliations` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=3 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `bp_open_item_reconciliation_lines`
--

LOCK TABLES `bp_open_item_reconciliation_lines` WRITE;
/*!40000 ALTER TABLE `bp_open_item_reconciliation_lines` DISABLE KEYS */;
INSERT INTO `bp_open_item_reconciliation_lines` VALUES (1,1,58,'2026-09-06 20:13:50','2026-09-06 20:13:50'),(2,1,65,'2026-09-06 20:13:50','2026-09-06 20:13:50');
/*!40000 ALTER TABLE `bp_open_item_reconciliation_lines` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `bp_open_item_reconciliations`
--

DROP TABLE IF EXISTS `bp_open_item_reconciliations`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `bp_open_item_reconciliations` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `business_partner_id` bigint unsigned NOT NULL,
  `reconciled_by` bigint unsigned DEFAULT NULL,
  `reconciled_at` timestamp NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `bp_open_item_reconciliations_reconciled_by_foreign` (`reconciled_by`),
  KEY `bp_open_item_reconciliations_business_partner_id_index` (`business_partner_id`),
  CONSTRAINT `bp_open_item_reconciliations_business_partner_id_foreign` FOREIGN KEY (`business_partner_id`) REFERENCES `business_partners` (`id`),
  CONSTRAINT `bp_open_item_reconciliations_reconciled_by_foreign` FOREIGN KEY (`reconciled_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=2 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `bp_open_item_reconciliations`
--

LOCK TABLES `bp_open_item_reconciliations` WRITE;
/*!40000 ALTER TABLE `bp_open_item_reconciliations` DISABLE KEYS */;
INSERT INTO `bp_open_item_reconciliations` VALUES (1,90,NULL,'2026-09-06 20:13:50','2026-09-06 20:13:50','2026-09-06 20:13:50');
/*!40000 ALTER TABLE `bp_open_item_reconciliations` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `bp_open_items`
--

DROP TABLE IF EXISTS `bp_open_items`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `bp_open_items` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `business_partner_id` bigint unsigned NOT NULL,
  `origin_journal_detail_id` bigint unsigned NOT NULL,
  `document_type_code` varchar(3) COLLATE utf8mb4_unicode_ci NOT NULL,
  `document_number` bigint unsigned NOT NULL,
  `due_date` date DEFAULT NULL,
  `original_amount` decimal(18,2) NOT NULL,
  `currency_id` bigint unsigned NOT NULL,
  `last_revaluation_rate` decimal(18,6) DEFAULT NULL COMMENT 'tipo de cambio de la última revaluación de cierre (FxRevaluationService) que incluyó esta partida — si está presente, ApplyPaymentService lo usa como base del diferencial REALIZADO en vez del tipo de cambio original de la línea, para no contar dos veces la porción ya reconocida como diferencial no realizado',
  `applied_amount` decimal(18,2) NOT NULL DEFAULT '0.00',
  `balance` decimal(18,2) NOT NULL,
  `status` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'open' COMMENT 'open|partial|closed',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `bp_open_items_origin_journal_detail_id_foreign` (`origin_journal_detail_id`),
  KEY `bp_open_items_currency_id_foreign` (`currency_id`),
  KEY `bp_open_items_business_partner_id_status_index` (`business_partner_id`,`status`),
  CONSTRAINT `bp_open_items_business_partner_id_foreign` FOREIGN KEY (`business_partner_id`) REFERENCES `business_partners` (`id`) ON DELETE CASCADE,
  CONSTRAINT `bp_open_items_currency_id_foreign` FOREIGN KEY (`currency_id`) REFERENCES `currencies` (`id`),
  CONSTRAINT `bp_open_items_origin_journal_detail_id_foreign` FOREIGN KEY (`origin_journal_detail_id`) REFERENCES `journal_details` (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=97 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `bp_open_items`
--

LOCK TABLES `bp_open_items` WRITE;
/*!40000 ALTER TABLE `bp_open_items` DISABLE KEYS */;
INSERT INTO `bp_open_items` VALUES (58,90,1027,'TRF',1,'2026-09-09',113000.00,949,NULL,113000.00,0.00,'closed','2026-09-06 17:36:16','2026-09-06 20:13:50'),(59,91,1023,'TRF',1,'2026-10-12',3509477.26,949,NULL,0.00,3509477.26,'open','2026-09-06 17:36:16','2026-09-06 17:36:16'),(60,91,1024,'TRF',1,'2026-10-19',4175524.31,949,NULL,0.00,4175524.31,'open','2026-09-06 17:36:16','2026-09-06 17:36:16'),(61,92,1025,'TRF',1,'2026-10-12',92133.95,949,NULL,0.00,92133.95,'open','2026-09-06 17:36:16','2026-09-06 17:36:16'),(62,92,1026,'TRF',1,'2026-10-19',117358.49,949,NULL,0.00,117358.49,'open','2026-09-06 17:36:16','2026-09-06 17:36:16'),(63,91,586,'APE',1,'2026-09-30',14759217.30,949,NULL,2420180.31,12339036.99,'partial','2026-09-06 19:47:24','2026-09-06 20:23:17'),(64,92,587,'APE',1,'2026-09-30',1118379.58,949,NULL,61735.12,1056644.46,'partial','2026-09-06 19:47:25','2026-09-06 20:23:17'),(65,90,592,'APE',1,'2026-08-31',113000.00,949,NULL,113000.00,0.00,'closed','2026-09-06 19:47:25','2026-09-06 20:13:50'),(66,90,1138,'FCP',1,'2026-09-25',113000.00,949,NULL,0.00,113000.00,'open','2026-09-09 01:34:19','2026-09-09 01:34:19'),(67,93,1186,'FVE',1,'2026-02-15',200.00,949,NULL,0.00,200.00,'open','2026-09-10 21:11:17','2026-09-10 21:11:17'),(82,100,1268,'FCP',1,'2026-02-19',3390000.00,949,NULL,3390000.00,0.00,'closed','2026-09-18 03:45:25','2026-09-18 03:45:26'),(83,100,1271,'FCP',2,'2026-03-12',9040000.00,949,NULL,9040000.00,0.00,'closed','2026-09-18 03:45:25','2026-09-18 04:02:41'),(84,94,1272,'FVE',1,'2026-04-04',5650000.00,949,NULL,5650000.00,0.00,'closed','2026-09-18 03:45:25','2026-09-18 03:45:25'),(85,101,1290,'FCP',3,'2026-06-14',1186500.00,949,NULL,0.00,1186500.00,'open','2026-09-18 03:45:25','2026-09-18 03:45:25'),(86,95,1291,'FVE',2,'2026-07-18',9040000.00,949,NULL,0.00,9040000.00,'open','2026-09-18 03:45:25','2026-09-18 03:45:25'),(87,98,1296,'FVE',3,'2026-09-26',12000.00,950,NULL,0.00,12000.00,'open','2026-09-18 03:45:25','2026-09-18 03:45:25'),(88,102,1300,'FCP',4,'2026-10-05',1638500.00,949,NULL,0.00,1638500.00,'open','2026-09-18 03:45:26','2026-09-18 03:45:26'),(90,100,1418,'FCP',6,'2026-03-12',9040000.00,949,NULL,0.00,9040000.00,'open','2026-09-18 04:02:42','2026-09-18 04:02:42');
/*!40000 ALTER TABLE `bp_open_items` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `bp_payment_applications`
--

DROP TABLE IF EXISTS `bp_payment_applications`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `bp_payment_applications` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `payment_journal_entry_id` bigint unsigned NOT NULL,
  `open_item_id` bigint unsigned NOT NULL,
  `applied_amount` decimal(18,2) NOT NULL,
  `applied_date` date NOT NULL,
  `exchange_rate` decimal(18,6) DEFAULT NULL,
  `realized_fx_difference` decimal(18,2) NOT NULL DEFAULT '0.00',
  `created_by` bigint unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `bp_payment_applications_payment_journal_entry_id_foreign` (`payment_journal_entry_id`),
  KEY `bp_payment_applications_open_item_id_foreign` (`open_item_id`),
  KEY `bp_payment_applications_created_by_foreign` (`created_by`),
  CONSTRAINT `bp_payment_applications_created_by_foreign` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `bp_payment_applications_open_item_id_foreign` FOREIGN KEY (`open_item_id`) REFERENCES `bp_open_items` (`id`),
  CONSTRAINT `bp_payment_applications_payment_journal_entry_id_foreign` FOREIGN KEY (`payment_journal_entry_id`) REFERENCES `journal_entries` (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=21 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `bp_payment_applications`
--

LOCK TABLES `bp_payment_applications` WRITE;
/*!40000 ALTER TABLE `bp_payment_applications` DISABLE KEYS */;
INSERT INTO `bp_payment_applications` VALUES (12,278,63,2420180.31,'2026-08-31',NULL,0.00,357,'2026-09-06 20:23:17'),(13,278,64,61735.12,'2026-08-31',NULL,0.00,357,'2026-09-06 20:23:17'),(17,321,84,5650000.00,'2026-04-02',NULL,0.00,358,'2026-09-18 03:45:25'),(18,328,82,3390000.00,'2026-09-12',NULL,0.00,358,'2026-09-18 03:45:26');
/*!40000 ALTER TABLE `bp_payment_applications` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `business_partners`
--

DROP TABLE IF EXISTS `business_partners`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `business_partners` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `company_id` bigint unsigned NOT NULL,
  `code` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'alfanumérico x-xxx',
  `name` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `type` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'client|supplier|both',
  `tax_id` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `identification_type` varchar(2) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'Nota 4: 01 física, 02 jurídica, 03 DIMEX, 04 NITE, 05 extranjero, 06 no contribuyente',
  `email` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `economic_activity_code` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'Código de actividad económica de Hacienda, para facturación electrónica',
  `phone` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `province` varchar(60) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `canton` varchar(60) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `district` varchar(60) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `address_details` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'Otras señas',
  `contact_name` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'Nombre del encargado',
  `partner_since` date DEFAULT NULL COMMENT 'Fecha de inicio como cliente/proveedor',
  `category_id` bigint unsigned DEFAULT NULL,
  `family_id` bigint unsigned DEFAULT NULL,
  `cost_center_id` bigint unsigned DEFAULT NULL,
  `gl_account_id` bigint unsigned NOT NULL,
  `currency_id` bigint unsigned NOT NULL,
  `price_list_id` bigint unsigned DEFAULT NULL,
  `credit_limit` decimal(18,2) DEFAULT NULL,
  `payment_terms_days` smallint unsigned DEFAULT NULL,
  `status` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'active',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `business_partners_company_id_code_unique` (`company_id`,`code`),
  KEY `business_partners_category_id_foreign` (`category_id`),
  KEY `business_partners_family_id_foreign` (`family_id`),
  KEY `business_partners_gl_account_id_foreign` (`gl_account_id`),
  KEY `business_partners_currency_id_foreign` (`currency_id`),
  KEY `business_partners_cost_center_id_foreign` (`cost_center_id`),
  KEY `business_partners_price_list_id_foreign` (`price_list_id`),
  CONSTRAINT `business_partners_category_id_foreign` FOREIGN KEY (`category_id`) REFERENCES `bp_categories` (`id`) ON DELETE SET NULL,
  CONSTRAINT `business_partners_company_id_foreign` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`) ON DELETE CASCADE,
  CONSTRAINT `business_partners_cost_center_id_foreign` FOREIGN KEY (`cost_center_id`) REFERENCES `cost_centers` (`id`) ON DELETE SET NULL,
  CONSTRAINT `business_partners_currency_id_foreign` FOREIGN KEY (`currency_id`) REFERENCES `currencies` (`id`),
  CONSTRAINT `business_partners_family_id_foreign` FOREIGN KEY (`family_id`) REFERENCES `bp_families` (`id`) ON DELETE SET NULL,
  CONSTRAINT `business_partners_gl_account_id_foreign` FOREIGN KEY (`gl_account_id`) REFERENCES `chart_of_accounts` (`id`),
  CONSTRAINT `business_partners_price_list_id_foreign` FOREIGN KEY (`price_list_id`) REFERENCES `price_lists` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=129 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `business_partners`
--

LOCK TABLES `business_partners` WRITE;
/*!40000 ALTER TABLE `business_partners` DISABLE KEYS */;
INSERT INTO `business_partners` VALUES (90,600,'P-001','Vanessa Mendoza Calderón','supplier','701000260',NULL,'vanessamendoza3@hotmail.com',NULL,'85561295',NULL,NULL,NULL,NULL,'Vanessa','2026-01-01',14,NULL,NULL,977,949,NULL,200000.00,30,'active','2026-09-04 03:38:36','2026-09-04 03:44:27'),(91,600,'COO1','RESUSA (PRINCIPAL)','client','3101047990',NULL,'alexander.narajo@resusa.co.cr',NULL,'22786878',NULL,NULL,NULL,NULL,'Alexander','2026-01-01',15,NULL,NULL,964,949,NULL,30000000.00,60,'active','2026-09-05 01:01:11','2026-09-05 01:01:11'),(92,600,'COO2','RESUSA (INTERESES)','client','3101047990',NULL,'alexander.naranjo@resusa.co.cr',NULL,'22786878',NULL,NULL,NULL,NULL,'Alexander N','2026-01-01',16,NULL,NULL,965,949,NULL,5000000.00,60,'active','2026-09-05 01:04:11','2026-09-05 01:04:11'),(93,601,'C-058','Bradtke-Simonis','client',NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,1115,949,NULL,NULL,NULL,'active','2026-09-10 21:11:17','2026-09-10 21:11:17'),(94,604,'CLI-001','Distribuidora La Central S.A.','client','3101123456',NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,'2026-01-01',17,1,46,1136,949,NULL,5000000.00,30,'active','2026-09-18 03:23:42','2026-09-18 03:23:42'),(95,604,'CLI-002','Comercial El Roble S.A.','client','3101234567',NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,'2026-01-01',17,1,46,1136,949,NULL,3000000.00,30,'active','2026-09-18 03:23:42','2026-09-18 03:23:42'),(96,604,'CLI-003','Servicios Técnicos Lumen S.A.','client','3101345678',NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,'2026-01-01',18,2,46,1136,949,NULL,1500000.00,15,'active','2026-09-18 03:23:42','2026-09-18 03:23:42'),(97,604,'CLI-004','María Fernanda Rojas Vega','client','108790456',NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,'2026-01-01',18,2,46,1136,949,NULL,500000.00,8,'active','2026-09-18 03:23:42','2026-09-18 03:23:42'),(98,604,'CLI-005','Northbridge Trading LLC','client','US-882314',NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,'2026-01-01',17,1,46,1137,950,NULL,25000.00,45,'active','2026-09-18 03:23:42','2026-09-18 03:23:42'),(99,604,'CLI-006','Antigua Importaciones S.A. (inactivo)','client','3101456789',NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,'2026-01-01',18,1,46,1136,949,NULL,0.00,0,'inactive','2026-09-18 03:23:42','2026-09-18 03:23:42'),(100,604,'PRV-001','Suministros Industriales del Sur S.A.','supplier','3101567890',NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,'2026-01-01',19,1,47,1196,949,NULL,NULL,30,'active','2026-09-18 03:23:42','2026-09-18 03:23:42'),(101,604,'PRV-002','Transportes Rápidos Mora S.A.','supplier','3101678901',NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,'2026-01-01',19,3,47,1196,949,NULL,NULL,15,'active','2026-09-18 03:23:42','2026-09-18 03:23:42'),(102,604,'PRV-003','Consultores Asociados Quirós y Cía.','supplier','3101789012',NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,'2026-01-01',19,2,45,1196,949,NULL,NULL,30,'active','2026-09-18 03:23:42','2026-09-18 03:23:42'),(103,604,'PRV-004','Pacific Components Inc.','supplier','US-774102',NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,'2026-01-01',20,1,47,1197,950,NULL,NULL,60,'active','2026-09-18 03:23:42','2026-09-18 03:23:42'),(104,604,'AMB-001','Grupo Logístico Talamanca S.A.','both','3101890123',NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,'2026-01-01',17,2,47,1136,949,NULL,2000000.00,30,'active','2026-09-18 03:23:42','2026-09-18 03:23:42'),(105,605,'C001','Blutech SA','client',NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,'2026-01-01',21,NULL,NULL,1391,949,NULL,50000000.00,30,'active','2026-09-29 02:22:41','2026-09-29 02:46:24'),(106,605,'C002','Imp. Vale SM S.A.','client',NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,'2026-01-01',21,NULL,NULL,1391,949,NULL,5000000.00,30,'active','2026-09-29 02:24:29','2026-09-29 02:24:29'),(107,605,'C003','Kunjani','client',NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,'2026-01-01',21,NULL,NULL,1391,949,NULL,5000000.00,30,'active','2026-09-29 02:25:28','2026-09-29 02:25:28'),(108,605,'C004','Corp. Mares Pavones','client',NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,'2026-01-01',21,NULL,NULL,1391,949,NULL,5000000.00,30,'active','2026-09-29 02:26:23','2026-09-29 02:26:23'),(109,605,'C005','Inveersiones Condega Ltda.','client',NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,'2026-01-01',21,NULL,NULL,1391,949,NULL,5000000.00,30,'active','2026-09-29 02:27:59','2026-09-29 02:27:59'),(110,605,'C006','Distribuidora Lozaiza Ltda.','client',NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,'2026-01-01',21,NULL,NULL,1391,949,NULL,5000000.00,30,'active','2026-09-29 02:31:00','2026-09-29 02:31:00'),(111,605,'C007','Clientes diversos','client',NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,'2026-01-01',21,NULL,NULL,1391,949,NULL,5000000.00,30,'active','2026-09-29 02:32:17','2026-09-29 02:32:17'),(112,605,'C008','Etiplast Panamá SA','client',NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,'2026-01-01',22,NULL,NULL,1373,950,NULL,5000000.00,30,'active','2026-09-29 02:34:41','2026-09-29 02:34:41'),(113,605,'C009','Gozaka SA','client',NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,'2026-01-01',22,NULL,NULL,1392,950,NULL,5000000.00,30,'active','2026-09-29 02:35:36','2026-09-29 02:35:36'),(114,605,'C010','Bluetech','client',NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,'2026-01-01',22,NULL,NULL,1392,950,NULL,5000000.00,30,'active','2026-09-29 02:36:31','2026-09-29 02:36:31'),(115,605,'C011','Master Litio','client',NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,'2026-01-01',22,NULL,NULL,1392,950,NULL,5000000.00,30,'active','2026-09-29 02:39:21','2026-09-29 02:39:21'),(116,605,'C012','Potuga Fruit Company','client',NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,'2026-01-01',22,NULL,NULL,1392,950,NULL,5000000.00,30,'active','2026-09-29 02:40:36','2026-09-29 02:40:36'),(117,605,'C013','Etiquetas MyL $','client',NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,'2026-01-01',22,NULL,NULL,1392,950,NULL,5000000.00,30,'active','2026-09-29 02:41:56','2026-09-29 02:41:56'),(118,605,'C014','Central de Pinturas Pintuco SA $','client',NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,'2026-01-01',NULL,NULL,NULL,1392,950,NULL,5000000.00,30,'active','2026-09-29 02:42:53','2026-09-29 02:42:53'),(119,605,'C015','Luis Oscar Guerra','client',NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,'2026-01-01',22,NULL,NULL,1392,950,NULL,5000000.00,30,'active','2026-09-29 02:43:55','2026-09-29 03:13:38'),(120,605,'C016','Clientes varios $','client',NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,'2026-01-01',22,NULL,NULL,1392,950,NULL,5000000.00,30,'active','2026-09-29 02:45:51','2026-09-29 02:45:51'),(121,605,'C017','Gozaka SA','client',NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,'2026-01-01',23,NULL,NULL,1393,950,NULL,5000000.00,30,'active','2026-09-29 02:47:54','2026-09-29 02:47:54'),(122,605,'C018','Bluetech $','client',NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,'2026-01-01',23,NULL,NULL,1393,950,NULL,5000000.00,30,'active','2026-09-29 02:49:01','2026-09-29 02:49:01'),(123,605,'C019','Etiquetas MyL','client',NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,'2026-01-01',23,NULL,NULL,1373,950,NULL,5000000.00,30,'active','2026-09-29 02:51:17','2026-09-29 02:51:17'),(124,605,'C020','Master Litio','client',NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,'2026-01-01',23,NULL,NULL,1393,950,NULL,5000000.00,30,'active','2026-09-29 02:53:02','2026-09-29 02:53:02'),(125,605,'C021','Potuga Fruit Company','client',NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,'2026-01-01',23,NULL,NULL,1393,950,NULL,5000000.00,30,'active','2026-09-29 02:54:06','2026-09-29 02:54:06'),(126,605,'C022','Oscar Guerra','client',NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,'2026-01-01',23,NULL,NULL,1393,950,NULL,5000000.00,30,'active','2026-09-29 02:55:36','2026-09-29 02:55:36'),(127,605,'C023','Reintegros varios $','client',NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,'2026-01-01',23,NULL,NULL,1393,950,NULL,5000000.00,30,'active','2026-09-29 02:56:57','2026-09-29 02:56:57'),(128,605,'C024','Centro Pinturas Pintuco SA','client',NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,'2026-01-01',23,NULL,NULL,1393,950,NULL,5000000.00,30,'active','2026-09-29 03:00:53','2026-09-29 03:00:53');
/*!40000 ALTER TABLE `business_partners` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `cache`
--

DROP TABLE IF EXISTS `cache`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `cache` (
  `key` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `value` mediumtext COLLATE utf8mb4_unicode_ci NOT NULL,
  `expiration` bigint NOT NULL,
  PRIMARY KEY (`key`),
  KEY `cache_expiration_index` (`expiration`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `cache`
--

LOCK TABLES `cache` WRITE;
/*!40000 ALTER TABLE `cache` DISABLE KEYS */;
/*!40000 ALTER TABLE `cache` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `cache_locks`
--

DROP TABLE IF EXISTS `cache_locks`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `cache_locks` (
  `key` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `owner` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `expiration` bigint NOT NULL,
  PRIMARY KEY (`key`),
  KEY `cache_locks_expiration_index` (`expiration`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `cache_locks`
--

LOCK TABLES `cache_locks` WRITE;
/*!40000 ALTER TABLE `cache_locks` DISABLE KEYS */;
/*!40000 ALTER TABLE `cache_locks` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `chart_of_accounts`
--

DROP TABLE IF EXISTS `chart_of_accounts`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `chart_of_accounts` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `company_id` bigint unsigned NOT NULL,
  `code` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'x-xx-xx-xx-xxx',
  `parent_id` bigint unsigned DEFAULT NULL,
  `level` tinyint unsigned NOT NULL DEFAULT '1',
  `description_es` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `description_en` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `account_type` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'asset|liability|equity|income|expense',
  `normal_balance` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'debit|credit',
  `currency_mode` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'local' COMMENT 'local|foreign|both',
  `accepts_posting` tinyint(1) NOT NULL DEFAULT '0' COMMENT 'solo cuentas hoja reciben movimiento',
  `requires_business_partner` tinyint(1) NOT NULL DEFAULT '0' COMMENT 'obliga a indicar socio de negocio en cada línea, ej. CxC/CxP',
  `is_cash_account` tinyint(1) NOT NULL DEFAULT '0' COMMENT 'cuenta monetaria: elegible para vincularse a bank_accounts',
  `requires_cost_center` tinyint(1) NOT NULL DEFAULT '0' COMMENT 'obliga norma de reparto/centro de costo; reservado, el módulo de centros de costo aún no existe',
  `is_financial_report` tinyint(1) NOT NULL DEFAULT '1',
  `section` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `list_order` int NOT NULL DEFAULT '0',
  `tax_classification` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'none' COMMENT 'none|sales|purchases|iva_general|iva_devengado|iva_soportado',
  `tax_rate_id` bigint unsigned DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT '1',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `chart_of_accounts_company_id_code_unique` (`company_id`,`code`),
  KEY `chart_of_accounts_parent_id_foreign` (`parent_id`),
  KEY `chart_of_accounts_tax_rate_id_foreign` (`tax_rate_id`),
  CONSTRAINT `chart_of_accounts_company_id_foreign` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`) ON DELETE CASCADE,
  CONSTRAINT `chart_of_accounts_parent_id_foreign` FOREIGN KEY (`parent_id`) REFERENCES `chart_of_accounts` (`id`) ON DELETE SET NULL,
  CONSTRAINT `chart_of_accounts_tax_rate_id_foreign` FOREIGN KEY (`tax_rate_id`) REFERENCES `tax_rates` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=1571 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `chart_of_accounts`
--

LOCK TABLES `chart_of_accounts` WRITE;
/*!40000 ALTER TABLE `chart_of_accounts` DISABLE KEYS */;
INSERT INTO `chart_of_accounts` VALUES (915,600,'1',NULL,1,'ACTIVOS',NULL,'asset','debit','local',0,0,0,0,1,NULL,0,'none',NULL,1,'2026-09-03 02:47:47','2026-09-03 02:47:47'),(916,600,'1-01',NULL,1,'ACTIVOS CIRCULANTES',NULL,'asset','debit','local',0,0,0,0,1,NULL,0,'none',NULL,1,'2026-09-03 02:47:48','2026-09-03 02:47:48'),(917,600,'1-01-01',NULL,1,'CAJA Y BANCOS',NULL,'asset','debit','local',0,0,0,0,1,NULL,0,'none',NULL,1,'2026-09-03 02:47:48','2026-09-03 02:47:48'),(918,600,'1-01-01-01',NULL,1,'EFECTIVO Y EQUIVALENTES',NULL,'asset','debit','local',0,0,0,0,1,NULL,0,'none',NULL,1,'2026-09-03 02:47:48','2026-09-03 02:47:48'),(919,600,'1-01-01-01-001',NULL,1,'Fondo Operativo',NULL,'asset','debit','local',1,0,1,0,1,NULL,0,'none',NULL,1,'2026-09-03 02:47:48','2026-09-03 02:47:48'),(920,600,'1-01-01-02',NULL,1,'BANCOS',NULL,'asset','debit','local',0,0,0,0,1,NULL,0,'none',NULL,1,'2026-09-03 02:47:48','2026-09-03 02:47:48'),(921,600,'1-01-01-02-001',NULL,1,'Banco Nacional Costa Rica',NULL,'asset','debit','local',1,0,1,0,1,NULL,0,'none',NULL,1,'2026-09-03 02:47:48','2026-09-03 02:47:48'),(922,600,'1-01-02',NULL,1,'INVERSIONES Y RESERVAS DE LIQUIDEZ',NULL,'asset','debit','local',0,0,0,0,1,NULL,0,'none',NULL,1,'2026-09-03 02:47:48','2026-09-03 02:47:48'),(923,600,'1-01-02-01',NULL,1,'RESERVAS DE LIQUIDEZ',NULL,'asset','debit','local',0,0,0,0,1,NULL,0,'none',NULL,1,'2026-09-03 02:47:48','2026-09-03 02:47:48'),(924,600,'1-01-02-01-001',NULL,1,'BCCR reserva liquidez CDP',NULL,'asset','debit','local',1,0,0,0,1,NULL,0,'none',NULL,1,'2026-09-03 02:47:48','2026-09-03 02:47:48'),(925,600,'1-01-02-01-002',NULL,1,'BCCR  Cuenta Líquida',NULL,'asset','debit','local',1,0,0,0,1,NULL,0,'none',NULL,1,'2026-09-03 02:47:48','2026-09-03 02:47:48'),(926,600,'1-01-02-02',NULL,1,'INVERSIONES TEMPORALES',NULL,'asset','debit','local',0,0,0,0,1,NULL,0,'none',NULL,1,'2026-09-03 02:47:48','2026-09-03 02:47:48'),(927,600,'1-01-02-02-001',NULL,1,'BNCR CDP CR79015113440310040617',NULL,'asset','debit','local',1,0,0,0,1,NULL,0,'none',NULL,1,'2026-09-03 02:47:48','2026-09-03 02:47:48'),(928,600,'1-01-02-02-002',NULL,1,'BNCR CDP CR04015113440310041870',NULL,'asset','debit','local',1,0,0,0,1,NULL,0,'none',NULL,1,'2026-09-03 02:47:48','2026-09-03 02:47:48'),(929,600,'1-01-02-02-003',NULL,1,'BN Fondos colones',NULL,'asset','debit','local',1,0,0,0,1,NULL,0,'none',NULL,1,'2026-09-03 02:47:48','2026-09-03 02:47:48'),(930,600,'1-01-02-02-004',NULL,1,'Intereses cobrar instrumentos BNCR',NULL,'asset','debit','local',1,0,0,0,1,NULL,0,'none',NULL,1,'2026-09-03 02:47:48','2026-09-03 02:47:48'),(931,600,'1-01-03',NULL,1,'CUENTAS POR COBRAR',NULL,'asset','debit','local',0,0,0,0,1,NULL,0,'none',NULL,1,'2026-09-03 02:47:48','2026-09-03 02:47:48'),(932,600,'1-01-03-01',NULL,1,'CUENTAS POR COBRAR ASOCIADOS',NULL,'asset','debit','local',0,0,0,0,1,NULL,0,'none',NULL,1,'2026-09-03 02:47:48','2026-09-03 02:47:48'),(933,600,'1-01-03-01-001',NULL,1,'Olmán Mora Rivera',NULL,'asset','debit','local',1,0,0,0,1,NULL,0,'none',NULL,1,'2026-09-03 02:47:48','2026-09-03 02:47:48'),(934,600,'1-01-03-01-002',NULL,1,'Ronald Campos Carranza',NULL,'asset','debit','local',1,0,0,0,1,NULL,0,'none',NULL,1,'2026-09-03 02:47:48','2026-09-03 02:47:48'),(935,600,'1-01-03-01-003',NULL,1,'José Isidro Víquez Villegas',NULL,'asset','debit','local',1,0,0,0,1,NULL,0,'none',NULL,1,'2026-09-03 02:47:48','2026-09-03 02:47:48'),(936,600,'1-01-03-01-004',NULL,1,'Nuria Zúñiga Thames',NULL,'asset','debit','local',1,0,0,0,1,NULL,0,'none',NULL,1,'2026-09-03 02:47:48','2026-09-03 02:47:48'),(937,600,'1-01-03-01-005',NULL,1,'Ana Felicia Navarro Fallas',NULL,'asset','debit','local',1,0,0,0,1,NULL,0,'none',NULL,1,'2026-09-03 02:47:48','2026-09-03 02:47:48'),(938,600,'1-01-03-01-006',NULL,1,'José Miguel Jiménez Masís',NULL,'asset','debit','local',1,0,0,0,1,NULL,0,'none',NULL,1,'2026-09-03 02:47:48','2026-09-03 02:47:48'),(939,600,'1-01-03-01-007',NULL,1,'Fabián Cerdas Camacho',NULL,'asset','debit','local',1,0,0,0,1,NULL,0,'none',NULL,1,'2026-09-03 02:47:48','2026-09-03 02:47:48'),(940,600,'1-01-03-01-008',NULL,1,'César Solís Mata',NULL,'asset','debit','local',1,0,0,0,1,NULL,0,'none',NULL,1,'2026-09-03 02:47:48','2026-09-03 02:47:48'),(941,600,'1-01-03-01-009',NULL,1,'Gustavo Zúñiga Solano',NULL,'asset','debit','local',1,0,0,0,1,NULL,0,'none',NULL,1,'2026-09-03 02:47:48','2026-09-03 02:47:48'),(942,600,'1-01-03-01-010',NULL,1,'Hugo Ruiz Salas',NULL,'asset','debit','local',1,0,0,0,1,NULL,0,'none',NULL,1,'2026-09-03 02:47:48','2026-09-03 02:47:48'),(943,600,'1-01-03-01-011',NULL,1,'Franciny Segura Alvarez',NULL,'asset','debit','local',1,0,0,0,1,NULL,0,'none',NULL,1,'2026-09-03 02:47:48','2026-09-03 02:47:48'),(944,600,'1-01-03-01-012',NULL,1,'José Gabriel Ortega López',NULL,'asset','debit','local',1,0,0,0,1,NULL,0,'none',NULL,1,'2026-09-03 02:47:48','2026-09-03 02:47:48'),(945,600,'1-01-03-01-013',NULL,1,'Jonathan Cedeño Camacho',NULL,'asset','debit','local',1,0,0,0,1,NULL,0,'none',NULL,1,'2026-09-03 02:47:48','2026-09-03 02:47:48'),(946,600,'1-01-03-01-014',NULL,1,'Andrés Chavés Vargas',NULL,'asset','debit','local',1,0,0,0,1,NULL,0,'none',NULL,1,'2026-09-03 02:47:48','2026-09-03 02:47:48'),(947,600,'1-01-03-01-015',NULL,1,'Oscar Umaña masis',NULL,'asset','debit','local',1,0,0,0,1,NULL,0,'none',NULL,1,'2026-09-03 02:47:48','2026-09-03 02:47:48'),(948,600,'1-01-03-01-016',NULL,1,'Josè Fabio Rojas Borbòn',NULL,'asset','debit','local',1,0,0,0,1,NULL,0,'none',NULL,1,'2026-09-03 02:47:48','2026-09-03 02:47:48'),(949,600,'1-01-03-01-017',NULL,1,'Luis Azofeifa Conejo',NULL,'asset','debit','local',1,0,0,0,1,NULL,0,'none',NULL,1,'2026-09-03 02:47:48','2026-09-03 02:47:48'),(950,600,'1-01-03-01-018',NULL,1,'Renzo Solis Chavarría',NULL,'asset','debit','local',1,0,0,0,1,NULL,0,'none',NULL,1,'2026-09-03 02:47:48','2026-09-03 02:47:48'),(951,600,'1-01-03-01-019',NULL,1,'Julián Diaz Granados',NULL,'asset','debit','local',1,0,0,0,1,NULL,0,'none',NULL,1,'2026-09-03 02:47:48','2026-09-03 02:47:48'),(952,600,'1-01-03-02',NULL,1,'CUENTA POR COBRAR RESUSA',NULL,'asset','debit','local',0,0,0,0,1,NULL,0,'none',NULL,1,'2026-09-03 02:47:48','2026-09-03 02:47:48'),(953,600,'1-01-03-02-001',NULL,1,'Resusa Cuota Obrero',NULL,'asset','debit','local',1,0,0,0,1,NULL,0,'none',NULL,1,'2026-09-03 02:47:48','2026-09-05 22:48:45'),(954,600,'1-01-03-02-002',NULL,1,'Resusa Cuota Patronal',NULL,'asset','debit','local',1,0,0,0,1,NULL,0,'none',NULL,1,'2026-09-03 02:47:48','2026-09-03 02:47:48'),(955,600,'1-01-03-02-003',NULL,1,'Resusa Cuota Préstamos',NULL,'asset','debit','local',1,0,0,0,1,NULL,0,'none',NULL,1,'2026-09-03 02:47:48','2026-09-03 02:47:48'),(956,600,'1-01-03-02-004',NULL,1,'Resusa Ahorro Navideño',NULL,'asset','debit','local',1,0,0,0,1,NULL,0,'none',NULL,1,'2026-09-03 02:47:48','2026-09-03 02:47:48'),(957,600,'1-01-03-02-005',NULL,1,'Resusa Ahorro Escolar',NULL,'asset','debit','local',1,0,0,0,1,NULL,0,'none',NULL,1,'2026-09-03 02:47:48','2026-09-03 02:47:48'),(958,600,'1-01-03-02-006',NULL,1,'Resusa Flexiahorro',NULL,'asset','debit','local',1,0,0,0,1,NULL,0,'none',NULL,1,'2026-09-03 02:47:48','2026-09-03 02:47:48'),(959,600,'1-01-03-02-007',NULL,1,'Rifas pendientes recuperar',NULL,'asset','debit','local',1,0,0,0,1,NULL,0,'none',NULL,1,'2026-09-03 02:47:48','2026-09-03 02:47:48'),(960,600,'1-01-03-03',NULL,1,'CUENTAS COBRAR LINEA ESPECIAL',NULL,'asset','debit','local',0,0,0,0,1,NULL,0,'none',NULL,1,'2026-09-03 02:47:48','2026-09-03 02:47:48'),(961,600,'1-01-03-03-001',NULL,1,'Verónica Jiménez Fallas',NULL,'asset','debit','local',1,0,0,0,1,NULL,0,'none',NULL,1,'2026-09-03 02:47:48','2026-09-03 02:47:48'),(962,600,'1-01-03-03-002',NULL,1,'Oscar Umaña masis',NULL,'asset','debit','local',1,0,0,0,1,NULL,0,'none',NULL,1,'2026-09-03 02:47:48','2026-09-03 02:47:48'),(963,600,'1-01-03-10',NULL,1,'CUENTAS POR COBRAR A TERCEROS',NULL,'asset','debit','local',0,0,0,0,1,NULL,0,'none',NULL,1,'2026-09-03 02:47:48','2026-09-03 02:47:48'),(964,600,'1-01-03-10-001',NULL,1,'RESUSA (principal por cobrar)',NULL,'asset','debit','local',1,0,0,0,1,NULL,0,'none',NULL,1,'2026-09-03 02:47:48','2026-09-03 02:47:48'),(965,600,'1-01-03-10-002',NULL,1,'RESUSA (intereses por cobrar)',NULL,'asset','debit','local',1,1,0,0,1,NULL,0,'none',NULL,1,'2026-09-03 02:47:48','2026-09-05 01:06:31'),(966,600,'1-01-10',NULL,1,'PROPIEDAD PLANTA Y EQUIPO',NULL,'asset','debit','local',0,0,0,0,1,NULL,0,'none',NULL,1,'2026-09-03 02:47:48','2026-09-03 02:47:48'),(967,600,'1-01-10-01',NULL,1,'MOBILIARIO DE OFICINA',NULL,'asset','debit','local',0,0,0,0,1,NULL,0,'none',NULL,1,'2026-09-03 02:47:48','2026-09-03 02:47:48'),(968,600,'1-01-10-01-001',NULL,1,'Mobiliario de oficina _ COSTO_',NULL,'asset','debit','local',1,0,0,0,1,NULL,0,'none',NULL,1,'2026-09-03 02:47:48','2026-09-03 02:47:48'),(969,600,'1-01-10-01-002',NULL,1,'Mobiliario y equipo DEPRECIACION',NULL,'asset','debit','local',1,0,0,0,1,NULL,0,'none',NULL,1,'2026-09-03 02:47:48','2026-09-03 02:47:48'),(970,600,'1-01-10-02',NULL,1,'EQUIPO TECNOLOGICO',NULL,'asset','debit','local',0,0,0,0,1,NULL,0,'none',NULL,1,'2026-09-03 02:47:48','2026-09-03 02:47:48'),(971,600,'1-01-10-02-001',NULL,1,'Equipo tecnológico  COSTO',NULL,'asset','debit','local',1,0,0,0,1,NULL,0,'none',NULL,1,'2026-09-03 02:47:48','2026-09-03 02:47:48'),(972,600,'1-01-10-02-002',NULL,1,'Equipo tecnológico  DEPRECIACION',NULL,'asset','debit','local',1,0,0,0,1,NULL,0,'none',NULL,1,'2026-09-03 02:47:48','2026-09-03 02:47:48'),(973,600,'2',NULL,1,'PASIVOS',NULL,'liability','credit','local',0,0,0,0,1,NULL,0,'none',NULL,1,'2026-09-03 02:47:48','2026-09-03 02:47:48'),(974,600,'2-01',NULL,1,'PASIVO CIRCULANTE',NULL,'liability','credit','local',0,0,0,0,1,NULL,0,'none',NULL,1,'2026-09-03 02:47:48','2026-09-03 02:47:48'),(975,600,'2-01-02',NULL,1,'CUENTA POR PAGAR PROVEEDORES',NULL,'liability','credit','local',0,0,0,0,1,NULL,0,'none',NULL,1,'2026-09-03 02:47:48','2026-09-03 02:47:48'),(976,600,'2-01-02-01',NULL,1,'OTRAS CUENTAS POR PAGAR',NULL,'liability','credit','local',0,0,0,0,1,NULL,0,'none',NULL,1,'2026-09-03 02:47:48','2026-09-03 02:47:48'),(977,600,'2-01-02-01-001',NULL,1,'Proveedor de gastos',NULL,'liability','credit','local',1,1,0,0,1,NULL,0,'none',NULL,1,'2026-09-03 02:47:48','2026-09-04 03:46:58'),(978,600,'2-01-04',NULL,1,'INGRESOS DIFERIDOS',NULL,'liability','credit','local',0,0,0,0,1,NULL,0,'none',NULL,1,'2026-09-03 02:47:48','2026-09-03 02:47:48'),(979,600,'2-01-04-01',NULL,1,'RIFAS POR DEVENGAR',NULL,'liability','credit','local',0,0,0,0,1,NULL,0,'none',NULL,1,'2026-09-03 02:47:48','2026-09-03 02:47:48'),(980,600,'2-01-04-01-001',NULL,1,'Rifas',NULL,'liability','credit','local',1,0,0,0,1,NULL,0,'none',NULL,1,'2026-09-03 02:47:48','2026-09-03 02:47:48'),(981,600,'2-01-06',NULL,1,'CAPTACION DE RECURSOS',NULL,'liability','credit','local',0,0,0,0,1,NULL,0,'none',NULL,1,'2026-09-03 02:47:48','2026-09-03 02:47:48'),(982,600,'2-01-06-01',NULL,1,'AHORRO NAVIDEÑO',NULL,'liability','credit','local',0,0,0,0,1,NULL,0,'none',NULL,1,'2026-09-03 02:47:48','2026-09-03 02:47:48'),(983,600,'2-01-06-01-001',NULL,1,'Nuria Zúñiga Thames',NULL,'liability','credit','local',1,0,0,0,1,NULL,0,'none',NULL,1,'2026-09-03 02:47:48','2026-09-03 02:47:48'),(984,600,'2-01-06-01-002',NULL,1,'Franciny Montoya Fallas',NULL,'liability','credit','local',1,0,0,0,1,NULL,0,'none',NULL,1,'2026-09-03 02:47:48','2026-09-03 02:47:48'),(985,600,'2-01-06-01-003',NULL,1,'Verónica Jiménez Fallas',NULL,'liability','credit','local',1,0,0,0,1,NULL,0,'none',NULL,1,'2026-09-03 02:47:48','2026-09-03 02:47:48'),(986,600,'2-01-06-01-004',NULL,1,'Ana Felicia Navarro Fallas',NULL,'liability','credit','local',1,0,0,0,1,NULL,0,'none',NULL,1,'2026-09-03 02:47:48','2026-09-03 02:47:48'),(987,600,'2-01-06-01-005',NULL,1,'Pedro López Morales',NULL,'liability','credit','local',1,0,0,0,1,NULL,0,'none',NULL,1,'2026-09-03 02:47:48','2026-09-03 02:47:48'),(988,600,'2-01-06-01-006',NULL,1,'Josè Fabio Rojas Borbòn',NULL,'liability','credit','local',1,0,0,0,1,NULL,0,'none',NULL,1,'2026-09-03 02:47:48','2026-09-03 02:47:48'),(989,600,'2-01-06-01-007',NULL,1,'Renzo Solis Chavarría',NULL,'liability','credit','local',1,0,0,0,1,NULL,0,'none',NULL,1,'2026-09-03 02:47:48','2026-09-03 02:47:48'),(990,600,'2-01-06-01-008',NULL,1,'José Miguel Jiménez Masís',NULL,'liability','credit','local',1,0,0,0,1,NULL,0,'none',NULL,1,'2026-09-03 02:47:48','2026-09-03 02:47:48'),(991,600,'2-01-06-01-009',NULL,1,'Andrés Chavés Vargas',NULL,'liability','credit','local',1,0,0,0,1,NULL,0,'none',NULL,1,'2026-09-03 02:47:48','2026-09-03 02:47:48'),(992,600,'2-01-06-01-010',NULL,1,'Ronald Campos Carranza',NULL,'liability','credit','local',1,0,0,0,1,NULL,0,'none',NULL,1,'2026-09-03 02:47:48','2026-09-03 02:47:48'),(993,600,'2-01-06-01-011',NULL,1,'César Solís Mata',NULL,'liability','credit','local',1,0,0,0,1,NULL,0,'none',NULL,1,'2026-09-03 02:47:48','2026-09-03 02:47:48'),(994,600,'2-01-06-01-012',NULL,1,'Oscar Umaña masis',NULL,'liability','credit','local',1,0,0,0,1,NULL,0,'none',NULL,1,'2026-09-03 02:47:48','2026-09-03 02:47:48'),(995,600,'2-01-06-01-013',NULL,1,'Franciny Segura Alvarez',NULL,'liability','credit','local',1,0,0,0,1,NULL,0,'none',NULL,1,'2026-09-03 02:47:48','2026-09-03 02:47:48'),(996,600,'2-01-06-01-014',NULL,1,'José Gabriel Ortega López',NULL,'liability','credit','local',1,0,0,0,1,NULL,0,'none',NULL,1,'2026-09-03 02:47:48','2026-09-03 02:47:48'),(997,600,'2-01-06-02',NULL,1,'AHORRO ESCOLAR',NULL,'liability','credit','local',0,0,0,0,1,NULL,0,'none',NULL,1,'2026-09-03 02:47:48','2026-09-03 02:47:48'),(998,600,'2-01-06-02-001',NULL,1,'Verónica Jiménez Fallas',NULL,'liability','credit','local',1,0,0,0,1,NULL,0,'none',NULL,1,'2026-09-03 02:47:48','2026-09-03 02:47:48'),(999,600,'2-01-06-02-002',NULL,1,'Julián Diaz Granados',NULL,'liability','credit','local',1,0,0,0,1,NULL,0,'none',NULL,1,'2026-09-03 02:47:48','2026-09-03 02:47:48'),(1000,600,'2-01-06-02-003',NULL,1,'Renzo Solis Chavarría',NULL,'liability','credit','local',1,0,0,0,1,NULL,0,'none',NULL,1,'2026-09-03 02:47:48','2026-09-03 02:47:48'),(1001,600,'2-01-06-03',NULL,1,'AHORRO FLEXI AHORRO',NULL,'liability','credit','local',0,0,0,0,1,NULL,0,'none',NULL,1,'2026-09-03 02:47:48','2026-09-03 02:47:48'),(1002,600,'2-01-06-03-001',NULL,1,'Olmán Mora Rivera',NULL,'liability','credit','local',1,0,0,0,1,NULL,0,'none',NULL,1,'2026-09-03 02:47:48','2026-09-03 02:47:48'),(1003,600,'2-01-06-03-002',NULL,1,'Ana Felicia Navarro Fallas',NULL,'liability','credit','local',1,0,0,0,1,NULL,0,'none',NULL,1,'2026-09-03 02:47:48','2026-09-03 02:47:48'),(1004,600,'2-01-06-03-003',NULL,1,'José Gabriel Ortega López',NULL,'liability','credit','local',1,0,0,0,1,NULL,0,'none',NULL,1,'2026-09-03 02:47:48','2026-09-03 02:47:48'),(1005,600,'2-01-06-03-004',NULL,1,'Franciny Segura Alvarez',NULL,'liability','credit','local',1,0,0,0,1,NULL,0,'none',NULL,1,'2026-09-03 02:47:48','2026-09-03 02:47:48'),(1006,600,'2-01-06-10',NULL,1,'INTERESES POR PAGAR CAPTACIONES',NULL,'liability','credit','local',0,0,0,0,1,NULL,0,'none',NULL,1,'2026-09-03 02:47:48','2026-09-03 02:47:48'),(1007,600,'2-01-06-10-001',NULL,1,'Intereses por pagar ahorro navideño',NULL,'liability','credit','local',1,0,0,0,1,NULL,0,'none',NULL,1,'2026-09-03 02:47:48','2026-09-03 02:47:48'),(1008,600,'2-01-06-10-002',NULL,1,'Intereses por pagar ahorro escolar',NULL,'liability','credit','local',1,0,0,0,1,NULL,0,'none',NULL,1,'2026-09-03 02:47:48','2026-09-03 02:47:48'),(1009,600,'2-01-07',NULL,1,'FONDOS Y PROVISIONES',NULL,'liability','credit','local',0,0,0,0,1,NULL,0,'none',NULL,1,'2026-09-03 02:47:48','2026-09-03 02:47:48'),(1010,600,'2-01-07-01',NULL,1,'FONDOS ESPECIALES',NULL,'liability','credit','local',0,0,0,0,1,NULL,0,'none',NULL,1,'2026-09-03 02:47:48','2026-09-03 02:47:48'),(1011,600,'2-01-07-01-001',NULL,1,'Fondo de ayuda y socorro mutuo',NULL,'liability','credit','local',1,0,0,0,1,NULL,0,'none',NULL,1,'2026-09-03 02:47:48','2026-09-03 02:47:48'),(1012,600,'2-01-08',NULL,1,'IMP., RETENC y EXCEDENTES POR PAGAR',NULL,'liability','credit','local',0,0,0,0,1,NULL,0,'none',NULL,1,'2026-09-03 02:47:48','2026-09-03 02:47:48'),(1013,600,'2-01-08-01',NULL,1,'RETENCIONES POR PAGAR',NULL,'liability','credit','local',0,0,0,0,1,NULL,0,'none',NULL,1,'2026-09-03 02:47:48','2026-09-03 02:47:48'),(1014,600,'2-01-08-01-001',NULL,1,'Impuesto RCM por pagar',NULL,'liability','credit','local',1,0,0,0,1,NULL,0,'none',NULL,1,'2026-09-03 02:47:48','2026-09-03 02:47:48'),(1015,600,'3',NULL,1,'PATRIMONIO',NULL,'equity','credit','local',0,0,0,0,1,NULL,0,'none',NULL,1,'2026-09-03 02:47:48','2026-09-03 02:47:48'),(1016,600,'3-02',NULL,1,'APORTES',NULL,'equity','credit','local',0,0,0,0,1,NULL,0,'none',NULL,1,'2026-09-03 02:47:48','2026-09-03 02:47:48'),(1017,600,'3-02-01',NULL,1,'APORTES OBREROS',NULL,'equity','credit','local',0,0,0,0,1,NULL,0,'none',NULL,1,'2026-09-03 02:47:48','2026-09-03 02:47:48'),(1018,600,'3-02-01-01',NULL,1,'APORTES OBREROS ASOCIADOS ACTIVOS',NULL,'equity','credit','local',0,0,0,0,1,NULL,0,'none',NULL,1,'2026-09-03 02:47:48','2026-09-03 02:47:48'),(1019,600,'3-02-01-01-001',NULL,1,'Ronald Campos Carranza',NULL,'equity','credit','local',1,0,0,0,1,NULL,0,'none',NULL,1,'2026-09-03 02:47:48','2026-09-03 02:47:48'),(1020,600,'3-02-01-01-011',NULL,1,'Olmán Mora Rivera',NULL,'equity','credit','local',1,0,0,0,1,NULL,0,'none',NULL,1,'2026-09-03 02:47:48','2026-09-03 02:47:48'),(1021,600,'3-02-01-01-012',NULL,1,'Nuria Zúñiga Thames',NULL,'equity','credit','local',1,0,0,0,1,NULL,0,'none',NULL,1,'2026-09-03 02:47:48','2026-09-03 02:47:48'),(1022,600,'3-02-01-01-013',NULL,1,'José Isidro Víquez Villegas',NULL,'equity','credit','local',1,0,0,0,1,NULL,0,'none',NULL,1,'2026-09-03 02:47:48','2026-09-03 02:47:48'),(1023,600,'3-02-01-01-014',NULL,1,'Ana Felicia Navarro Fallas',NULL,'equity','credit','local',1,0,0,0,1,NULL,0,'none',NULL,1,'2026-09-03 02:47:48','2026-09-03 02:47:48'),(1024,600,'3-02-01-01-015',NULL,1,'Anthony Garita Rivera',NULL,'equity','credit','local',1,0,0,0,1,NULL,0,'none',NULL,1,'2026-09-03 02:47:48','2026-09-03 02:47:48'),(1025,600,'3-02-01-01-016',NULL,1,'Fabián Cerdas Camacho',NULL,'equity','credit','local',1,0,0,0,1,NULL,0,'none',NULL,1,'2026-09-03 02:47:48','2026-09-03 02:47:48'),(1026,600,'3-02-01-01-017',NULL,1,'Gerardo Delgado Ruíz',NULL,'equity','credit','local',1,0,0,0,1,NULL,0,'none',NULL,1,'2026-09-03 02:47:48','2026-09-03 02:47:48'),(1027,600,'3-02-01-01-018',NULL,1,'José Miguel Jiménez Masís',NULL,'equity','credit','local',1,0,0,0,1,NULL,0,'none',NULL,1,'2026-09-03 02:47:48','2026-09-03 02:47:48'),(1028,600,'3-02-01-01-019',NULL,1,'Franciny Montoya Fallas',NULL,'equity','credit','local',1,0,0,0,1,NULL,0,'none',NULL,1,'2026-09-03 02:47:48','2026-09-03 02:47:48'),(1029,600,'3-02-01-01-020',NULL,1,'César Solís Mata',NULL,'equity','credit','local',1,0,0,0,1,NULL,0,'none',NULL,1,'2026-09-03 02:47:48','2026-09-03 02:47:48'),(1030,600,'3-02-01-01-021',NULL,1,'Ilsa Janeth Beitia',NULL,'equity','credit','local',1,0,0,0,1,NULL,0,'none',NULL,1,'2026-09-03 02:47:48','2026-09-03 02:47:48'),(1031,600,'3-02-01-01-022',NULL,1,'Gustavo Zúñiga Solano',NULL,'equity','credit','local',1,0,0,0,1,NULL,0,'none',NULL,1,'2026-09-03 02:47:48','2026-09-03 02:47:48'),(1032,600,'3-02-01-01-023',NULL,1,'Verónica Jiménez Fallas',NULL,'equity','credit','local',1,0,0,0,1,NULL,0,'none',NULL,1,'2026-09-03 02:47:48','2026-09-03 02:47:48'),(1033,600,'3-02-01-01-024',NULL,1,'Pedro López Morales',NULL,'equity','credit','local',1,0,0,0,1,NULL,0,'none',NULL,1,'2026-09-03 02:47:48','2026-09-03 02:47:48'),(1034,600,'3-02-01-01-025',NULL,1,'Oscar Umaña masis',NULL,'equity','credit','local',1,0,0,0,1,NULL,0,'none',NULL,1,'2026-09-03 02:47:48','2026-09-03 02:47:48'),(1035,600,'3-02-01-01-026',NULL,1,'Hugo Ruiz Salas',NULL,'equity','credit','local',1,0,0,0,1,NULL,0,'none',NULL,1,'2026-09-03 02:47:48','2026-09-03 02:47:48'),(1036,600,'3-02-01-01-027',NULL,1,'Josè Fabio Rojas Borbòn',NULL,'equity','credit','local',1,0,0,0,1,NULL,0,'none',NULL,1,'2026-09-03 02:47:48','2026-09-03 02:47:48'),(1037,600,'3-02-01-01-028',NULL,1,'Franciny Segura Alvarez',NULL,'equity','credit','local',1,0,0,0,1,NULL,0,'none',NULL,1,'2026-09-03 02:47:48','2026-09-03 02:47:48'),(1038,600,'3-02-01-01-029',NULL,1,'Andrés Chavés Vargas',NULL,'equity','credit','local',1,0,0,0,1,NULL,0,'none',NULL,1,'2026-09-03 02:47:48','2026-09-03 02:47:48'),(1039,600,'3-02-01-01-030',NULL,1,'Jefry Ocampo Gómez',NULL,'equity','credit','local',1,0,0,0,1,NULL,0,'none',NULL,1,'2026-09-03 02:47:48','2026-09-03 02:47:48'),(1040,600,'3-02-01-01-031',NULL,1,'Luis Gmo. Rodríguez Jara',NULL,'equity','credit','local',1,0,0,0,1,NULL,0,'none',NULL,1,'2026-09-03 02:47:48','2026-09-03 02:47:48'),(1041,600,'3-02-01-01-032',NULL,1,'Julián Diaz Granados',NULL,'equity','credit','local',1,0,0,0,1,NULL,0,'none',NULL,1,'2026-09-03 02:47:48','2026-09-03 02:47:48'),(1042,600,'3-02-01-01-033',NULL,1,'José Gabriel Ortega López',NULL,'equity','credit','local',1,0,0,0,1,NULL,0,'none',NULL,1,'2026-09-03 02:47:48','2026-09-03 02:47:48'),(1043,600,'3-02-01-01-034',NULL,1,'Jonathan Cedeño Camacho',NULL,'equity','credit','local',1,0,0,0,1,NULL,0,'none',NULL,1,'2026-09-03 02:47:48','2026-09-03 02:47:48'),(1044,600,'3-02-01-01-035',NULL,1,'Hilda Redondo Mora',NULL,'equity','credit','local',1,0,0,0,1,NULL,0,'none',NULL,1,'2026-09-03 02:47:48','2026-09-03 02:47:48'),(1045,600,'3-02-01-01-036',NULL,1,'Luis Azofeifa Conejo',NULL,'equity','credit','local',1,0,0,0,1,NULL,0,'none',NULL,1,'2026-09-03 02:47:48','2026-09-03 02:47:48'),(1046,600,'3-02-01-01-037',NULL,1,'Christian Campos Castillo',NULL,'equity','credit','local',1,0,0,0,1,NULL,0,'none',NULL,1,'2026-09-03 02:47:48','2026-09-03 02:47:48'),(1047,600,'3-02-01-01-038',NULL,1,'Renzo Solis Chavarría',NULL,'equity','credit','local',1,0,0,0,1,NULL,0,'none',NULL,1,'2026-09-03 02:47:48','2026-09-03 02:47:48'),(1048,600,'3-02-01-01-039',NULL,1,'José Fco. Chavés Conejo',NULL,'equity','credit','local',1,0,0,0,1,NULL,0,'none',NULL,1,'2026-09-03 02:47:48','2026-09-03 02:47:48'),(1049,600,'3-02-01-01-040',NULL,1,'William Vargas Trejos',NULL,'equity','credit','local',1,0,0,0,1,NULL,0,'none',NULL,1,'2026-09-03 02:47:48','2026-09-03 02:47:48'),(1050,600,'3-02-01-01-041',NULL,1,'Yessy Brenes Brenes',NULL,'equity','credit','local',1,0,0,0,1,NULL,0,'none',NULL,1,'2026-09-03 02:47:48','2026-09-03 02:47:48'),(1051,600,'3-02-02',NULL,1,'APORTE PATRONAL',NULL,'equity','credit','local',0,0,0,0,1,NULL,0,'none',NULL,1,'2026-09-03 02:47:48','2026-09-03 02:47:48'),(1052,600,'3-02-02-01',NULL,1,'APORTE PATRONAL ASOCIADOS ACTIVOS',NULL,'equity','credit','local',0,0,0,0,1,NULL,0,'none',NULL,1,'2026-09-03 02:47:48','2026-09-03 02:47:48'),(1053,600,'3-02-02-01-001',NULL,1,'Asociados activos',NULL,'equity','credit','local',1,0,0,0,1,NULL,0,'none',NULL,1,'2026-09-03 02:47:48','2026-09-03 02:47:48'),(1054,600,'3-02-02-02',NULL,1,'APORTE PATRONAL EN CUSTODIA',NULL,'equity','credit','local',0,0,0,0,1,NULL,0,'none',NULL,1,'2026-09-03 02:47:48','2026-09-03 02:47:48'),(1055,600,'3-02-02-02-001',NULL,1,'José Robles Navarro',NULL,'equity','credit','local',1,0,0,0,1,NULL,0,'none',NULL,1,'2026-09-03 02:47:48','2026-09-03 02:47:48'),(1056,600,'3-02-02-02-002',NULL,1,'Fabián Cerdas Camacho',NULL,'equity','credit','local',1,0,0,0,1,NULL,0,'none',NULL,1,'2026-09-03 02:47:48','2026-09-03 02:47:48'),(1057,600,'3-02-02-02-003',NULL,1,'Nuria Zúñiga Thames',NULL,'equity','credit','local',1,0,0,0,1,NULL,0,'none',NULL,1,'2026-09-03 02:47:48','2026-09-03 02:47:48'),(1058,600,'3-02-02-02-004',NULL,1,'Franciny Montoya Fallas',NULL,'equity','credit','local',1,0,0,0,1,NULL,0,'none',NULL,1,'2026-09-03 02:47:48','2026-09-03 02:47:48'),(1059,600,'3-02-02-02-005',NULL,1,'Gustavo Zúñiga Solano',NULL,'equity','credit','local',1,0,0,0,1,NULL,0,'none',NULL,1,'2026-09-03 02:47:48','2026-09-03 02:47:48'),(1060,600,'3-02-02-02-006',NULL,1,'Carlos Mora Alvarez',NULL,'equity','credit','local',1,0,0,0,1,NULL,0,'none',NULL,1,'2026-09-03 02:47:48','2026-09-03 02:47:48'),(1061,600,'3-02-02-02-007',NULL,1,'Ilsa Beitia',NULL,'equity','credit','local',1,0,0,0,1,NULL,0,'none',NULL,1,'2026-09-03 02:47:48','2026-09-03 02:47:48'),(1062,600,'3-02-02-02-008',NULL,1,'César Solís Mata',NULL,'equity','credit','local',1,0,0,0,1,NULL,0,'none',NULL,1,'2026-09-03 02:47:48','2026-09-03 02:47:48'),(1063,600,'3-02-02-02-009',NULL,1,'Verónica Jiménez Fallas',NULL,'equity','credit','local',1,0,0,0,1,NULL,0,'none',NULL,1,'2026-09-03 02:47:48','2026-09-03 02:47:48'),(1064,600,'3-02-02-02-010',NULL,1,'José Miguel Jiménez Masís',NULL,'equity','credit','local',1,0,0,0,1,NULL,0,'none',NULL,1,'2026-09-03 02:47:48','2026-09-03 02:47:48'),(1065,600,'3-02-02-02-011',NULL,1,'Olmán Mora Rivera',NULL,'equity','credit','local',1,0,0,0,1,NULL,0,'none',NULL,1,'2026-09-03 02:47:48','2026-09-03 02:47:48'),(1066,600,'3-02-02-02-012',NULL,1,'Engel Cruz Espinoza',NULL,'equity','credit','local',1,0,0,0,1,NULL,0,'none',NULL,1,'2026-09-03 02:47:48','2026-09-03 02:47:48'),(1067,600,'3-02-02-02-013',NULL,1,'Luis Azofeifa Conejo',NULL,'equity','credit','local',1,0,0,0,1,NULL,0,'none',NULL,1,'2026-09-03 02:47:48','2026-09-03 02:47:48'),(1068,600,'3-02-02-02-014',NULL,1,'Gustavo Blandón Cambronero',NULL,'equity','credit','local',1,0,0,0,1,NULL,0,'none',NULL,1,'2026-09-03 02:47:48','2026-09-03 02:47:48'),(1069,600,'3-02-02-02-015',NULL,1,'Wilberth Villegas Granados',NULL,'equity','credit','local',1,0,0,0,1,NULL,0,'none',NULL,1,'2026-09-03 02:47:48','2026-09-03 02:47:48'),(1070,600,'4',NULL,1,'INGRESOS',NULL,'income','credit','local',0,0,0,0,1,NULL,0,'none',NULL,1,'2026-09-03 02:47:48','2026-09-03 02:47:48'),(1071,600,'4-01',NULL,1,'INGRESOS FINANCIEROS',NULL,'income','credit','local',0,0,0,0,1,NULL,0,'none',NULL,1,'2026-09-03 02:47:48','2026-09-03 02:47:48'),(1072,600,'4-01-01',NULL,1,'INGRESO POR INTERESES',NULL,'income','credit','local',0,0,0,0,1,NULL,0,'none',NULL,1,'2026-09-03 02:47:48','2026-09-03 02:47:48'),(1073,600,'4-01-01-01',NULL,1,'INTERESES PRESTAMOS ASOCIADOS',NULL,'income','credit','local',0,0,0,0,1,NULL,0,'none',NULL,1,'2026-09-03 02:47:48','2026-09-03 02:47:48'),(1074,600,'4-01-01-01-001',NULL,1,'Préstamos ordinarios',NULL,'income','credit','local',1,0,0,0,1,NULL,0,'none',NULL,1,'2026-09-03 02:47:48','2026-09-03 02:47:48'),(1075,600,'4-01-01-01-002',NULL,1,'Rendimientos BN Fondos colones',NULL,'income','credit','local',1,0,0,0,1,NULL,0,'none',NULL,1,'2026-09-03 02:47:48','2026-09-03 02:47:48'),(1076,600,'4-01-01-01-003',NULL,1,'Rendimiento inversión BNCR',NULL,'income','credit','local',1,0,0,0,1,NULL,0,'none',NULL,1,'2026-09-03 02:47:48','2026-09-03 02:47:48'),(1077,600,'4-01-01-01-004',NULL,1,'Intereses crèdito lìnea especial',NULL,'income','credit','local',1,0,0,0,1,NULL,0,'none',NULL,1,'2026-09-03 02:47:48','2026-09-03 02:47:48'),(1078,600,'4-01-01-01-005',NULL,1,'Rendimientos int. por reserva liquidez',NULL,'income','credit','local',1,0,0,0,1,NULL,0,'none',NULL,1,'2026-09-03 02:47:48','2026-09-03 02:47:48'),(1079,600,'4-01-01-01-006',NULL,1,'Comisión por formalizaciones crediticias.',NULL,'income','credit','local',1,0,0,0,1,NULL,0,'none',NULL,1,'2026-09-03 02:47:48','2026-09-03 02:47:48'),(1080,600,'4-01-01-02',NULL,1,'INTERESES SOBRE PRESTAMOS A TERCEROS',NULL,'income','credit','local',0,0,0,0,1,NULL,0,'none',NULL,1,'2026-09-03 02:47:48','2026-09-03 02:47:48'),(1081,600,'4-01-01-02-001',NULL,1,'Intereses corrientes  Resusa',NULL,'income','credit','local',1,0,0,0,1,NULL,0,'none',NULL,1,'2026-09-03 02:47:48','2026-09-03 02:47:48'),(1082,600,'4-01-01-02-999',NULL,1,'Imp.  RCI  ingreso  intereses  prèstamo',NULL,'income','credit','local',1,0,0,0,1,NULL,0,'none',NULL,1,'2026-09-03 02:47:48','2026-09-03 02:47:48'),(1083,600,'4-02',NULL,1,'INGRESOS DIVERSOS',NULL,'income','credit','local',0,0,0,0,1,NULL,0,'none',NULL,1,'2026-09-03 02:47:48','2026-09-03 02:47:48'),(1084,600,'4-02-01',NULL,1,'INGRESOS POR ACTIVIDADES',NULL,'income','credit','local',0,0,0,0,1,NULL,0,'none',NULL,1,'2026-09-03 02:47:48','2026-09-03 02:47:48'),(1085,600,'4-02-01-01',NULL,1,'INGRESOS POR RIFAS',NULL,'income','credit','local',0,0,0,0,1,NULL,0,'none',NULL,1,'2026-09-03 02:47:48','2026-09-03 02:47:48'),(1086,600,'4-02-01-01-001',NULL,1,'Rifas ordinarias',NULL,'income','credit','local',1,0,0,0,1,NULL,0,'none',NULL,1,'2026-09-03 02:47:48','2026-09-03 02:47:48'),(1087,600,'4-02-01-01-002',NULL,1,'Costo rifas',NULL,'income','credit','local',1,0,0,0,1,NULL,0,'none',NULL,1,'2026-09-03 02:47:48','2026-09-03 02:47:48'),(1088,600,'6',NULL,1,'GASTOS',NULL,'expense','debit','local',0,0,0,0,1,NULL,0,'none',NULL,1,'2026-09-03 02:47:48','2026-09-03 02:47:48'),(1089,600,'6-01',NULL,1,'GASTOS ADMINISTRATIVOS',NULL,'expense','debit','local',0,0,0,0,1,NULL,0,'none',NULL,1,'2026-09-03 02:47:48','2026-09-03 02:47:48'),(1090,600,'6-01-01',NULL,1,'GASTOS GENERALES DE ADMINISTRACIÓN',NULL,'expense','debit','local',0,0,0,0,1,NULL,0,'none',NULL,1,'2026-09-03 02:47:49','2026-09-03 02:47:49'),(1091,600,'6-01-01-01',NULL,1,'SERVICIOS Y GASTOS OPERATIVOS',NULL,'expense','debit','local',0,0,0,0,1,NULL,0,'none',NULL,1,'2026-09-03 02:47:49','2026-09-03 02:47:49'),(1092,600,'6-01-01-01-001',NULL,1,'Gasto en atención asociados',NULL,'expense','debit','local',1,0,0,0,1,NULL,0,'none',NULL,1,'2026-09-03 02:47:49','2026-09-03 02:47:49'),(1093,600,'6-01-01-01-002',NULL,1,'Gastos en asambleas generales',NULL,'expense','debit','local',1,0,0,0,1,NULL,0,'none',NULL,1,'2026-09-03 02:47:49','2026-09-03 02:47:49'),(1094,600,'6-01-01-01-006',NULL,1,'Gasto servicios telefònicos',NULL,'expense','debit','local',1,0,0,0,1,NULL,0,'none',NULL,1,'2026-09-03 02:47:49','2026-09-03 02:47:49'),(1095,600,'6-01-01-01-008',NULL,1,'Canon BCCR \"SUGEF\"',NULL,'expense','debit','local',1,0,0,0,1,NULL,0,'none',NULL,1,'2026-09-03 02:47:49','2026-09-03 02:47:49'),(1096,600,'6-01-01-01-009',NULL,1,'Servicios contables',NULL,'expense','debit','local',1,0,0,0,1,NULL,0,'none',NULL,1,'2026-09-03 02:47:49','2026-09-03 02:47:49'),(1097,600,'6-01-01-01-010',NULL,1,'Servicios de consultoría y jurídicos',NULL,'expense','debit','local',1,0,0,0,1,NULL,0,'none',NULL,1,'2026-09-03 02:47:49','2026-09-03 02:47:49'),(1098,600,'6-01-01-01-011',NULL,1,'Servicios Administrativos',NULL,'expense','debit','local',1,0,0,0,1,NULL,0,'none',NULL,1,'2026-09-03 02:47:49','2026-09-03 02:47:49'),(1099,600,'6-01-01-01-012',NULL,1,'Reunión de Junta Directiva',NULL,'expense','debit','local',1,0,0,0,1,NULL,0,'none',NULL,1,'2026-09-03 02:47:49','2026-09-03 02:47:49'),(1100,600,'6-01-01-01-013',NULL,1,'Papelería y útiles',NULL,'expense','debit','local',1,0,0,0,1,NULL,0,'none',NULL,1,'2026-09-03 02:47:49','2026-09-03 02:47:49'),(1101,600,'6-01-01-02',NULL,1,'DEPRECIACIONES',NULL,'expense','debit','local',0,0,0,0,1,NULL,0,'none',NULL,1,'2026-09-03 02:47:49','2026-09-03 02:47:49'),(1102,600,'6-01-01-02-001',NULL,1,'Mobiliario y equipo de oficina',NULL,'expense','debit','local',1,0,0,0,1,NULL,0,'none',NULL,1,'2026-09-03 02:47:49','2026-09-03 02:47:49'),(1103,600,'6-01-01-02-002',NULL,1,'Equipo tecnológico',NULL,'expense','debit','local',1,0,0,0,1,NULL,0,'none',NULL,1,'2026-09-03 02:47:49','2026-09-03 02:47:49'),(1104,600,'6-02',NULL,1,'GASTOS FINANCIEROS',NULL,'expense','debit','local',0,0,0,0,1,NULL,0,'none',NULL,1,'2026-09-03 02:47:49','2026-09-03 02:47:49'),(1105,600,'6-02-01',NULL,1,'GASTOS FINANCIEROS',NULL,'expense','debit','local',0,0,0,0,1,NULL,0,'none',NULL,1,'2026-09-03 02:47:49','2026-09-03 02:47:49'),(1106,600,'6-02-01-01',NULL,1,'INTERESES SOBRE CAPTACIONES',NULL,'expense','debit','local',0,0,0,0,1,NULL,0,'none',NULL,1,'2026-09-03 02:47:49','2026-09-03 02:47:49'),(1107,600,'6-02-01-01-001',NULL,1,'Intereses ahorro navideño',NULL,'expense','debit','local',1,0,0,0,1,NULL,0,'none',NULL,1,'2026-09-03 02:47:49','2026-09-03 02:47:49'),(1108,600,'6-02-01-01-002',NULL,1,'Intereses en ahorro escolar',NULL,'expense','debit','local',1,0,0,0,1,NULL,0,'none',NULL,1,'2026-09-03 02:47:49','2026-09-03 02:47:49'),(1109,600,'6-02-01-02',NULL,1,'COMISIONES BANCARIAS',NULL,'expense','debit','local',0,0,0,0,1,NULL,0,'none',NULL,1,'2026-09-03 02:47:49','2026-09-03 02:47:49'),(1110,600,'6-02-01-02-001',NULL,1,'Comisiones por servicios bancarios',NULL,'expense','debit','local',1,0,0,0,1,NULL,0,'none',NULL,1,'2026-09-03 02:47:49','2026-09-03 02:47:49'),(1111,600,'1-01-03-01-020',NULL,1,'William Vargas Trejos',NULL,'asset','debit','local',1,0,0,0,1,NULL,0,'none',NULL,1,'2026-09-06 00:17:58','2026-09-06 00:18:31'),(1112,600,'1-01-03-01-021',NULL,1,'Christian Campos Castillo',NULL,'asset','debit','local',1,0,0,0,1,NULL,0,'none',NULL,1,'2026-09-06 00:18:53','2026-09-06 00:18:53'),(1113,600,'1-01-03-01-022',NULL,1,'José Fco. Chavés Conejo',NULL,'asset','debit','local',1,0,0,0,1,NULL,0,'none',NULL,1,'2026-09-06 00:19:20','2026-09-06 00:19:20'),(1114,600,'1-01-03-01-023',NULL,1,'Gerardo Delgado Ruíz',NULL,'asset','debit','local',1,0,0,0,1,NULL,0,'none',NULL,1,'2026-09-06 00:19:55','2026-09-06 00:19:55'),(1115,601,'1-01-02-01-001',NULL,1,'aliquid unde vitae',NULL,'asset','debit','local',1,0,0,0,1,NULL,0,'none',NULL,1,'2026-09-10 21:11:17','2026-09-10 21:11:17'),(1116,601,'4-01-01-01-001',NULL,1,'et perferendis odit',NULL,'income','credit','local',1,0,0,0,1,NULL,0,'none',NULL,1,'2026-09-10 21:11:17','2026-09-10 21:11:17'),(1117,602,'3-22-09-83-807',NULL,1,'ut reiciendis eum',NULL,'asset','debit','local',1,0,0,0,1,NULL,0,'none',NULL,1,'2026-09-10 21:11:17','2026-09-10 21:11:17'),(1118,604,'1',NULL,1,'ACTIVOS',NULL,'asset','debit','local',0,0,0,0,1,NULL,0,'none',NULL,1,'2026-09-18 03:07:40','2026-09-18 03:07:40'),(1119,604,'1-01',NULL,1,'ACTIVO CIRCULANTE',NULL,'asset','debit','local',0,0,0,0,1,NULL,0,'none',NULL,1,'2026-09-18 03:07:40','2026-09-18 03:07:40'),(1120,604,'1-01-01',NULL,1,'EFECTIVO Y EQUIVALENTES',NULL,'asset','debit','local',0,0,0,0,1,NULL,0,'none',NULL,1,'2026-09-18 03:07:40','2026-09-18 03:07:40'),(1121,604,'1-01-01-01',NULL,1,'CAJA',NULL,'asset','debit','local',0,0,0,0,1,NULL,0,'none',NULL,1,'2026-09-18 03:07:40','2026-09-18 03:07:40'),(1122,604,'1-01-01-01-001',NULL,1,'Caja general',NULL,'asset','debit','local',1,0,1,0,1,NULL,0,'none',NULL,1,'2026-09-18 03:07:40','2026-09-18 03:07:40'),(1123,604,'1-01-01-01-002',NULL,1,'Caja chica',NULL,'asset','debit','local',1,0,1,0,1,NULL,0,'none',NULL,1,'2026-09-18 03:07:40','2026-09-18 03:07:40'),(1124,604,'1-01-01-02',NULL,1,'BANCOS MONEDA LOCAL',NULL,'asset','debit','local',0,0,0,0,1,NULL,0,'none',NULL,1,'2026-09-18 03:07:40','2026-09-18 03:07:40'),(1125,604,'1-01-01-02-001',NULL,1,'Banco Nacional de Costa Rica CRC',NULL,'asset','debit','local',1,0,1,0,1,NULL,0,'none',NULL,1,'2026-09-18 03:07:40','2026-09-18 03:07:40'),(1126,604,'1-01-01-02-002',NULL,1,'Banco de Costa Rica CRC',NULL,'asset','debit','local',1,0,1,0,1,NULL,0,'none',NULL,1,'2026-09-18 03:07:40','2026-09-18 03:07:40'),(1127,604,'1-01-01-02-003',NULL,1,'BAC Credomatic CRC',NULL,'asset','debit','local',1,0,1,0,1,NULL,0,'none',NULL,1,'2026-09-18 03:07:40','2026-09-18 03:07:40'),(1128,604,'1-01-01-03',NULL,1,'BANCOS MONEDA EXTRANJERA',NULL,'asset','debit','local',0,0,0,0,1,NULL,0,'none',NULL,1,'2026-09-18 03:07:40','2026-09-18 03:07:40'),(1129,604,'1-01-01-03-001',NULL,1,'Banco Nacional de Costa Rica USD',NULL,'asset','debit','foreign',1,0,1,0,1,NULL,0,'none',NULL,1,'2026-09-18 03:07:40','2026-09-18 03:07:40'),(1130,604,'1-01-01-03-002',NULL,1,'BAC Credomatic USD',NULL,'asset','debit','foreign',1,0,1,0,1,NULL,0,'none',NULL,1,'2026-09-18 03:07:40','2026-09-18 03:07:40'),(1131,604,'1-01-01-04',NULL,1,'INVERSIONES TRANSITORIAS',NULL,'asset','debit','local',0,0,0,0,1,NULL,0,'none',NULL,1,'2026-09-18 03:07:40','2026-09-18 03:07:40'),(1132,604,'1-01-01-04-001',NULL,1,'Certificados de depósito a plazo',NULL,'asset','debit','local',1,0,0,0,1,NULL,0,'none',NULL,1,'2026-09-18 03:07:40','2026-09-18 03:07:40'),(1133,604,'1-01-01-04-002',NULL,1,'Intereses por cobrar sobre inversiones',NULL,'asset','debit','local',1,0,0,0,1,NULL,0,'none',NULL,1,'2026-09-18 03:07:40','2026-09-18 03:07:40'),(1134,604,'1-01-02',NULL,1,'CUENTAS POR COBRAR',NULL,'asset','debit','local',0,0,0,0,1,NULL,0,'none',NULL,1,'2026-09-18 03:07:40','2026-09-18 03:07:40'),(1135,604,'1-01-02-01',NULL,1,'CLIENTES',NULL,'asset','debit','local',0,0,0,0,1,NULL,0,'none',NULL,1,'2026-09-18 03:07:40','2026-09-18 03:07:40'),(1136,604,'1-01-02-01-001',NULL,1,'Clientes locales',NULL,'asset','debit','local',1,1,0,0,1,NULL,0,'none',NULL,1,'2026-09-18 03:07:40','2026-09-18 03:07:40'),(1137,604,'1-01-02-01-002',NULL,1,'Clientes del exterior',NULL,'asset','debit','foreign',1,1,0,0,1,NULL,0,'none',NULL,1,'2026-09-18 03:07:40','2026-09-18 03:07:40'),(1138,604,'1-01-02-01-003',NULL,1,'Documentos por cobrar clientes',NULL,'asset','debit','both',1,1,0,0,1,NULL,0,'none',NULL,1,'2026-09-18 03:07:40','2026-09-18 03:07:40'),(1139,604,'1-01-02-02',NULL,1,'OTRAS CUENTAS POR COBRAR',NULL,'asset','debit','local',0,0,0,0,1,NULL,0,'none',NULL,1,'2026-09-18 03:07:40','2026-09-18 03:07:40'),(1140,604,'1-01-02-02-001',NULL,1,'Adelantos a empleados',NULL,'asset','debit','local',1,1,0,0,1,NULL,0,'none',NULL,1,'2026-09-18 03:07:40','2026-09-18 03:07:40'),(1141,604,'1-01-02-02-002',NULL,1,'Adelantos a proveedores',NULL,'asset','debit','local',1,1,0,0,1,NULL,0,'none',NULL,1,'2026-09-18 03:07:40','2026-09-18 03:07:40'),(1142,604,'1-01-02-02-003',NULL,1,'Funcionarios y empleados',NULL,'asset','debit','local',1,1,0,0,1,NULL,0,'none',NULL,1,'2026-09-18 03:07:40','2026-09-18 03:07:40'),(1143,604,'1-01-02-03',NULL,1,'ESTIMACIONES',NULL,'asset','debit','local',0,0,0,0,1,NULL,0,'none',NULL,1,'2026-09-18 03:07:40','2026-09-18 03:07:40'),(1144,604,'1-01-02-03-001',NULL,1,'Estimación para cuentas incobrables',NULL,'asset','debit','local',1,0,0,0,1,NULL,0,'none',NULL,1,'2026-09-18 03:07:40','2026-09-18 03:07:40'),(1145,604,'1-01-03',NULL,1,'INVENTARIOS',NULL,'asset','debit','local',0,0,0,0,1,NULL,0,'none',NULL,1,'2026-09-18 03:07:40','2026-09-18 03:07:40'),(1146,604,'1-01-03-01',NULL,1,'INVENTARIO DE MERCADERÍA',NULL,'asset','debit','local',0,0,0,0,1,NULL,0,'none',NULL,1,'2026-09-18 03:07:40','2026-09-18 03:07:40'),(1147,604,'1-01-03-01-001',NULL,1,'Inventario de mercadería',NULL,'asset','debit','local',1,0,0,0,1,NULL,0,'none',NULL,1,'2026-09-18 03:07:40','2026-09-18 03:07:40'),(1148,604,'1-01-03-01-002',NULL,1,'Mercadería en tránsito',NULL,'asset','debit','both',1,0,0,0,1,NULL,0,'none',NULL,1,'2026-09-18 03:07:40','2026-09-18 03:07:40'),(1149,604,'1-01-03-02',NULL,1,'OTROS INVENTARIOS',NULL,'asset','debit','local',0,0,0,0,1,NULL,0,'none',NULL,1,'2026-09-18 03:07:40','2026-09-18 03:07:40'),(1150,604,'1-01-03-02-001',NULL,1,'Materiales y suministros',NULL,'asset','debit','local',1,0,0,0,1,NULL,0,'none',NULL,1,'2026-09-18 03:07:40','2026-09-18 03:07:40'),(1151,604,'1-01-03-02-002',NULL,1,'Estimación para obsolescencia de inventario',NULL,'asset','debit','local',1,0,0,0,1,NULL,0,'none',NULL,1,'2026-09-18 03:07:40','2026-09-18 03:07:40'),(1152,604,'1-01-04',NULL,1,'IMPUESTOS POR COBRAR',NULL,'asset','debit','local',0,0,0,0,1,NULL,0,'none',NULL,1,'2026-09-18 03:07:40','2026-09-18 03:07:40'),(1153,604,'1-01-04-01',NULL,1,'IVA SOPORTADO (CRÉDITO FISCAL)',NULL,'asset','debit','local',0,0,0,0,1,NULL,0,'none',NULL,1,'2026-09-18 03:07:40','2026-09-18 03:07:40'),(1154,604,'1-01-04-01-001',NULL,1,'IVA soportado 13%',NULL,'asset','debit','local',1,0,0,0,1,NULL,0,'iva_soportado',24,1,'2026-09-18 03:07:40','2026-09-18 03:07:40'),(1155,604,'1-01-04-01-002',NULL,1,'IVA soportado 4%',NULL,'asset','debit','local',1,0,0,0,1,NULL,0,'iva_soportado',25,1,'2026-09-18 03:07:40','2026-09-18 03:07:40'),(1156,604,'1-01-04-01-003',NULL,1,'IVA soportado 2%',NULL,'asset','debit','local',1,0,0,0,1,NULL,0,'iva_soportado',26,1,'2026-09-18 03:07:40','2026-09-18 03:07:40'),(1157,604,'1-01-04-01-004',NULL,1,'IVA soportado 1%',NULL,'asset','debit','local',1,0,0,0,1,NULL,0,'iva_soportado',27,1,'2026-09-18 03:07:40','2026-09-18 03:07:40'),(1158,604,'1-01-04-02',NULL,1,'OTROS IMPUESTOS POR COBRAR',NULL,'asset','debit','local',0,0,0,0,1,NULL,0,'none',NULL,1,'2026-09-18 03:07:40','2026-09-18 03:07:40'),(1159,604,'1-01-04-02-001',NULL,1,'Retenciones de renta soportadas',NULL,'asset','debit','local',1,0,0,0,1,NULL,0,'none',NULL,1,'2026-09-18 03:07:40','2026-09-18 03:07:40'),(1160,604,'1-01-04-02-002',NULL,1,'Impuesto sobre la renta pagado por anticipado',NULL,'asset','debit','local',1,0,0,0,1,NULL,0,'none',NULL,1,'2026-09-18 03:07:40','2026-09-18 03:07:40'),(1161,604,'1-01-05',NULL,1,'GASTOS PAGADOS POR ANTICIPADO',NULL,'asset','debit','local',0,0,0,0,1,NULL,0,'none',NULL,1,'2026-09-18 03:07:40','2026-09-18 03:07:40'),(1162,604,'1-01-05-01',NULL,1,'SEGUROS Y ALQUILERES',NULL,'asset','debit','local',0,0,0,0,1,NULL,0,'none',NULL,1,'2026-09-18 03:07:40','2026-09-18 03:07:40'),(1163,604,'1-01-05-01-001',NULL,1,'Seguros pagados por anticipado',NULL,'asset','debit','local',1,0,0,0,1,NULL,0,'none',NULL,1,'2026-09-18 03:07:40','2026-09-18 03:07:40'),(1164,604,'1-01-05-01-002',NULL,1,'Alquileres pagados por anticipado',NULL,'asset','debit','local',1,0,0,0,1,NULL,0,'none',NULL,1,'2026-09-18 03:07:40','2026-09-18 03:07:40'),(1165,604,'1-01-05-02',NULL,1,'OTROS PAGOS ANTICIPADOS',NULL,'asset','debit','local',0,0,0,0,1,NULL,0,'none',NULL,1,'2026-09-18 03:07:40','2026-09-18 03:07:40'),(1166,604,'1-01-05-02-001',NULL,1,'Suscripciones y licencias de software',NULL,'asset','debit','local',1,0,0,0,1,NULL,0,'none',NULL,1,'2026-09-18 03:07:40','2026-09-18 03:07:40'),(1167,604,'1-01-05-02-002',NULL,1,'Depósitos en garantía',NULL,'asset','debit','local',1,0,0,0,1,NULL,0,'none',NULL,1,'2026-09-18 03:07:40','2026-09-18 03:07:40'),(1168,604,'1-02',NULL,1,'ACTIVO FIJO',NULL,'asset','debit','local',0,0,0,0,1,NULL,0,'none',NULL,1,'2026-09-18 03:07:40','2026-09-18 03:07:40'),(1169,604,'1-02-01',NULL,1,'PROPIEDAD, PLANTA Y EQUIPO',NULL,'asset','debit','local',0,0,0,0,1,NULL,0,'none',NULL,1,'2026-09-18 03:07:40','2026-09-18 03:07:40'),(1170,604,'1-02-01-01',NULL,1,'TERRENOS Y EDIFICIOS',NULL,'asset','debit','local',0,0,0,0,1,NULL,0,'none',NULL,1,'2026-09-18 03:07:40','2026-09-18 03:07:40'),(1171,604,'1-02-01-01-001',NULL,1,'Terrenos',NULL,'asset','debit','local',1,0,0,0,1,NULL,0,'none',NULL,1,'2026-09-18 03:07:40','2026-09-18 03:07:40'),(1172,604,'1-02-01-01-002',NULL,1,'Edificios e instalaciones',NULL,'asset','debit','local',1,0,0,0,1,NULL,0,'none',NULL,1,'2026-09-18 03:07:40','2026-09-18 03:07:40'),(1173,604,'1-02-01-02',NULL,1,'MOBILIARIO Y EQUIPO',NULL,'asset','debit','local',0,0,0,0,1,NULL,0,'none',NULL,1,'2026-09-18 03:07:40','2026-09-18 03:07:40'),(1174,604,'1-02-01-02-001',NULL,1,'Mobiliario y equipo de oficina',NULL,'asset','debit','local',1,0,0,0,1,NULL,0,'none',NULL,1,'2026-09-18 03:07:40','2026-09-18 03:07:40'),(1175,604,'1-02-01-02-002',NULL,1,'Equipo de cómputo',NULL,'asset','debit','local',1,0,0,0,1,NULL,0,'none',NULL,1,'2026-09-18 03:07:40','2026-09-18 03:07:40'),(1176,604,'1-02-01-02-003',NULL,1,'Vehículos',NULL,'asset','debit','local',1,0,0,0,1,NULL,0,'none',NULL,1,'2026-09-18 03:07:40','2026-09-18 03:07:40'),(1177,604,'1-02-01-02-004',NULL,1,'Maquinaria y equipo',NULL,'asset','debit','local',1,0,0,0,1,NULL,0,'none',NULL,1,'2026-09-18 03:07:40','2026-09-18 03:07:40'),(1178,604,'1-02-02',NULL,1,'DEPRECIACIÓN ACUMULADA',NULL,'asset','debit','local',0,0,0,0,1,NULL,0,'none',NULL,1,'2026-09-18 03:07:40','2026-09-18 03:07:40'),(1179,604,'1-02-02-01',NULL,1,'DEPRECIACIÓN ACUMULADA',NULL,'asset','debit','local',0,0,0,0,1,NULL,0,'none',NULL,1,'2026-09-18 03:07:40','2026-09-18 03:07:40'),(1180,604,'1-02-02-01-001',NULL,1,'Depreciación acumulada de edificios',NULL,'asset','debit','local',1,0,0,0,1,NULL,0,'none',NULL,1,'2026-09-18 03:07:40','2026-09-18 03:07:40'),(1181,604,'1-02-02-01-002',NULL,1,'Depreciación acumulada de mobiliario y equipo',NULL,'asset','debit','local',1,0,0,0,1,NULL,0,'none',NULL,1,'2026-09-18 03:07:40','2026-09-18 03:07:40'),(1182,604,'1-02-02-01-003',NULL,1,'Depreciación acumulada de equipo de cómputo',NULL,'asset','debit','local',1,0,0,0,1,NULL,0,'none',NULL,1,'2026-09-18 03:07:40','2026-09-18 03:07:40'),(1183,604,'1-02-02-01-004',NULL,1,'Depreciación acumulada de vehículos',NULL,'asset','debit','local',1,0,0,0,1,NULL,0,'none',NULL,1,'2026-09-18 03:07:40','2026-09-18 03:07:40'),(1184,604,'1-03',NULL,1,'OTROS ACTIVOS',NULL,'asset','debit','local',0,0,0,0,1,NULL,0,'none',NULL,1,'2026-09-18 03:07:40','2026-09-18 03:07:40'),(1185,604,'1-03-01',NULL,1,'ACTIVOS INTANGIBLES',NULL,'asset','debit','local',0,0,0,0,1,NULL,0,'none',NULL,1,'2026-09-18 03:07:40','2026-09-18 03:07:40'),(1186,604,'1-03-01-01',NULL,1,'INTANGIBLES',NULL,'asset','debit','local',0,0,0,0,1,NULL,0,'none',NULL,1,'2026-09-18 03:07:40','2026-09-18 03:07:40'),(1187,604,'1-03-01-01-001',NULL,1,'Software y licencias',NULL,'asset','debit','local',1,0,0,0,1,NULL,0,'none',NULL,1,'2026-09-18 03:07:40','2026-09-18 03:07:40'),(1188,604,'1-03-01-01-002',NULL,1,'Amortización acumulada de intangibles',NULL,'asset','debit','local',1,0,0,0,1,NULL,0,'none',NULL,1,'2026-09-18 03:07:40','2026-09-18 03:07:40'),(1189,604,'1-03-02',NULL,1,'ACTIVOS POR IMPUESTO DIFERIDO',NULL,'asset','debit','local',0,0,0,0,1,NULL,0,'none',NULL,1,'2026-09-18 03:07:40','2026-09-18 03:07:40'),(1190,604,'1-03-02-01',NULL,1,'IMPUESTO DIFERIDO',NULL,'asset','debit','local',0,0,0,0,1,NULL,0,'none',NULL,1,'2026-09-18 03:07:40','2026-09-18 03:07:40'),(1191,604,'1-03-02-01-001',NULL,1,'Activo por impuesto sobre la renta diferido',NULL,'asset','debit','local',1,0,0,0,1,NULL,0,'none',NULL,1,'2026-09-18 03:07:40','2026-09-18 03:07:40'),(1192,604,'2',NULL,1,'PASIVOS',NULL,'liability','credit','local',0,0,0,0,1,NULL,0,'none',NULL,1,'2026-09-18 03:07:40','2026-09-18 03:07:40'),(1193,604,'2-01',NULL,1,'PASIVO CIRCULANTE',NULL,'liability','credit','local',0,0,0,0,1,NULL,0,'none',NULL,1,'2026-09-18 03:07:40','2026-09-18 03:07:40'),(1194,604,'2-01-01',NULL,1,'CUENTAS POR PAGAR',NULL,'liability','credit','local',0,0,0,0,1,NULL,0,'none',NULL,1,'2026-09-18 03:07:40','2026-09-18 03:07:40'),(1195,604,'2-01-01-01',NULL,1,'PROVEEDORES',NULL,'liability','credit','local',0,0,0,0,1,NULL,0,'none',NULL,1,'2026-09-18 03:07:40','2026-09-18 03:07:40'),(1196,604,'2-01-01-01-001',NULL,1,'Proveedores locales',NULL,'liability','credit','local',1,1,0,0,1,NULL,0,'none',NULL,1,'2026-09-18 03:07:40','2026-09-18 03:07:40'),(1197,604,'2-01-01-01-002',NULL,1,'Proveedores del exterior',NULL,'liability','credit','foreign',1,1,0,0,1,NULL,0,'none',NULL,1,'2026-09-18 03:07:40','2026-09-18 03:07:40'),(1198,604,'2-01-01-02',NULL,1,'OTRAS CUENTAS POR PAGAR',NULL,'liability','credit','local',0,0,0,0,1,NULL,0,'none',NULL,1,'2026-09-18 03:07:40','2026-09-18 03:07:40'),(1199,604,'2-01-01-02-001',NULL,1,'Acreedores varios',NULL,'liability','credit','local',1,1,0,0,1,NULL,0,'none',NULL,1,'2026-09-18 03:07:40','2026-09-18 03:07:40'),(1200,604,'2-01-01-02-002',NULL,1,'Gastos acumulados por pagar',NULL,'liability','credit','local',1,0,0,0,1,NULL,0,'none',NULL,1,'2026-09-18 03:07:40','2026-09-18 03:07:40'),(1201,604,'2-01-01-02-003',NULL,1,'Anticipos recibidos de clientes',NULL,'liability','credit','local',1,1,0,0,1,NULL,0,'none',NULL,1,'2026-09-18 03:07:40','2026-09-18 03:07:40'),(1202,604,'2-01-02',NULL,1,'IMPUESTOS POR PAGAR',NULL,'liability','credit','local',0,0,0,0,1,NULL,0,'none',NULL,1,'2026-09-18 03:07:40','2026-09-18 03:07:40'),(1203,604,'2-01-02-01',NULL,1,'IVA DEVENGADO (DÉBITO FISCAL)',NULL,'liability','credit','local',0,0,0,0,1,NULL,0,'none',NULL,1,'2026-09-18 03:07:40','2026-09-18 03:07:40'),(1204,604,'2-01-02-01-001',NULL,1,'IVA devengado 13%',NULL,'liability','credit','local',1,0,0,0,1,NULL,0,'iva_devengado',24,1,'2026-09-18 03:07:40','2026-09-18 03:07:40'),(1205,604,'2-01-02-01-002',NULL,1,'IVA devengado 4%',NULL,'liability','credit','local',1,0,0,0,1,NULL,0,'iva_devengado',25,1,'2026-09-18 03:07:40','2026-09-18 03:07:40'),(1206,604,'2-01-02-01-003',NULL,1,'IVA devengado 2%',NULL,'liability','credit','local',1,0,0,0,1,NULL,0,'iva_devengado',26,1,'2026-09-18 03:07:40','2026-09-18 03:07:40'),(1207,604,'2-01-02-01-004',NULL,1,'IVA devengado 1%',NULL,'liability','credit','local',1,0,0,0,1,NULL,0,'iva_devengado',27,1,'2026-09-18 03:07:40','2026-09-18 03:07:40'),(1208,604,'2-01-02-02',NULL,1,'OTROS IMPUESTOS POR PAGAR',NULL,'liability','credit','local',0,0,0,0,1,NULL,0,'none',NULL,1,'2026-09-18 03:07:40','2026-09-18 03:07:40'),(1209,604,'2-01-02-02-001',NULL,1,'Impuesto sobre la renta por pagar',NULL,'liability','credit','local',1,0,0,0,1,NULL,0,'none',NULL,1,'2026-09-18 03:07:40','2026-09-18 03:07:40'),(1210,604,'2-01-02-02-002',NULL,1,'Retenciones de renta practicadas',NULL,'liability','credit','local',1,0,0,0,1,NULL,0,'none',NULL,1,'2026-09-18 03:07:40','2026-09-18 03:07:40'),(1211,604,'2-01-02-02-003',NULL,1,'Retenciones de IVA practicadas',NULL,'liability','credit','local',1,0,0,0,1,NULL,0,'none',NULL,1,'2026-09-18 03:07:40','2026-09-18 03:07:40'),(1212,604,'2-01-03',NULL,1,'CARGAS SOCIALES Y PLANILLA',NULL,'liability','credit','local',0,0,0,0,1,NULL,0,'none',NULL,1,'2026-09-18 03:07:40','2026-09-18 03:07:40'),(1213,604,'2-01-03-01',NULL,1,'PLANILLA POR PAGAR',NULL,'liability','credit','local',0,0,0,0,1,NULL,0,'none',NULL,1,'2026-09-18 03:07:40','2026-09-18 03:07:40'),(1214,604,'2-01-03-01-001',NULL,1,'Salarios por pagar',NULL,'liability','credit','local',1,1,0,0,1,NULL,0,'none',NULL,1,'2026-09-18 03:07:40','2026-09-18 03:07:40'),(1215,604,'2-01-03-01-002',NULL,1,'Aguinaldo por pagar',NULL,'liability','credit','local',1,0,0,0,1,NULL,0,'none',NULL,1,'2026-09-18 03:07:40','2026-09-18 03:07:40'),(1216,604,'2-01-03-01-003',NULL,1,'Vacaciones por pagar',NULL,'liability','credit','local',1,0,0,0,1,NULL,0,'none',NULL,1,'2026-09-18 03:07:40','2026-09-18 03:07:40'),(1217,604,'2-01-03-02',NULL,1,'CARGAS SOCIALES',NULL,'liability','credit','local',0,0,0,0,1,NULL,0,'none',NULL,1,'2026-09-18 03:07:40','2026-09-18 03:07:40'),(1218,604,'2-01-03-02-001',NULL,1,'CCSS por pagar',NULL,'liability','credit','local',1,0,0,0,1,NULL,0,'none',NULL,1,'2026-09-18 03:07:40','2026-09-18 03:07:40'),(1219,604,'2-01-03-02-002',NULL,1,'INS riesgos del trabajo por pagar',NULL,'liability','credit','local',1,0,0,0,1,NULL,0,'none',NULL,1,'2026-09-18 03:07:40','2026-09-18 03:07:40'),(1220,604,'2-01-03-02-003',NULL,1,'Embargos y deducciones por pagar',NULL,'liability','credit','local',1,0,0,0,1,NULL,0,'none',NULL,1,'2026-09-18 03:07:40','2026-09-18 03:07:40'),(1221,604,'2-01-04',NULL,1,'DEUDA FINANCIERA CORTO PLAZO',NULL,'liability','credit','local',0,0,0,0,1,NULL,0,'none',NULL,1,'2026-09-18 03:07:40','2026-09-18 03:07:40'),(1222,604,'2-01-04-01',NULL,1,'PRÉSTAMOS CORTO PLAZO',NULL,'liability','credit','local',0,0,0,0,1,NULL,0,'none',NULL,1,'2026-09-18 03:07:40','2026-09-18 03:07:40'),(1223,604,'2-01-04-01-001',NULL,1,'Préstamos bancarios corto plazo CRC',NULL,'liability','credit','local',1,0,0,0,1,NULL,0,'none',NULL,1,'2026-09-18 03:07:40','2026-09-18 03:07:40'),(1224,604,'2-01-04-01-002',NULL,1,'Préstamos bancarios corto plazo USD',NULL,'liability','credit','foreign',1,0,0,0,1,NULL,0,'none',NULL,1,'2026-09-18 03:07:40','2026-09-18 03:07:40'),(1225,604,'2-01-04-01-003',NULL,1,'Intereses por pagar',NULL,'liability','credit','local',1,0,0,0,1,NULL,0,'none',NULL,1,'2026-09-18 03:07:40','2026-09-18 03:07:40'),(1226,604,'2-02',NULL,1,'PASIVO A LARGO PLAZO',NULL,'liability','credit','local',0,0,0,0,1,NULL,0,'none',NULL,1,'2026-09-18 03:07:40','2026-09-18 03:07:40'),(1227,604,'2-02-01',NULL,1,'DEUDA FINANCIERA LARGO PLAZO',NULL,'liability','credit','local',0,0,0,0,1,NULL,0,'none',NULL,1,'2026-09-18 03:07:40','2026-09-18 03:07:40'),(1228,604,'2-02-01-01',NULL,1,'PRÉSTAMOS LARGO PLAZO',NULL,'liability','credit','local',0,0,0,0,1,NULL,0,'none',NULL,1,'2026-09-18 03:07:40','2026-09-18 03:07:40'),(1229,604,'2-02-01-01-001',NULL,1,'Préstamos bancarios largo plazo CRC',NULL,'liability','credit','local',1,0,0,0,1,NULL,0,'none',NULL,1,'2026-09-18 03:07:40','2026-09-18 03:07:40'),(1230,604,'2-02-01-01-002',NULL,1,'Préstamos bancarios largo plazo USD',NULL,'liability','credit','foreign',1,0,0,0,1,NULL,0,'none',NULL,1,'2026-09-18 03:07:40','2026-09-18 03:07:40'),(1231,604,'2-02-02',NULL,1,'PROVISIONES Y OTROS PASIVOS',NULL,'liability','credit','local',0,0,0,0,1,NULL,0,'none',NULL,1,'2026-09-18 03:07:40','2026-09-18 03:07:40'),(1232,604,'2-02-02-01',NULL,1,'PROVISIONES',NULL,'liability','credit','local',0,0,0,0,1,NULL,0,'none',NULL,1,'2026-09-18 03:07:40','2026-09-18 03:07:40'),(1233,604,'2-02-02-01-001',NULL,1,'Provisión para prestaciones legales',NULL,'liability','credit','local',1,0,0,0,1,NULL,0,'none',NULL,1,'2026-09-18 03:07:40','2026-09-18 03:07:40'),(1234,604,'2-02-02-01-002',NULL,1,'Pasivo por impuesto sobre la renta diferido',NULL,'liability','credit','local',1,0,0,0,1,NULL,0,'none',NULL,1,'2026-09-18 03:07:40','2026-09-18 03:07:40'),(1235,604,'3',NULL,1,'PATRIMONIO',NULL,'equity','credit','local',0,0,0,0,1,NULL,0,'none',NULL,1,'2026-09-18 03:07:40','2026-09-18 03:07:40'),(1236,604,'3-01',NULL,1,'CAPITAL',NULL,'equity','credit','local',0,0,0,0,1,NULL,0,'none',NULL,1,'2026-09-18 03:07:40','2026-09-18 03:07:40'),(1237,604,'3-01-01',NULL,1,'CAPITAL SOCIAL',NULL,'equity','credit','local',0,0,0,0,1,NULL,0,'none',NULL,1,'2026-09-18 03:07:40','2026-09-18 03:07:40'),(1238,604,'3-01-01-01',NULL,1,'CAPITAL SOCIAL',NULL,'equity','credit','local',0,0,0,0,1,NULL,0,'none',NULL,1,'2026-09-18 03:07:40','2026-09-18 03:07:40'),(1239,604,'3-01-01-01-001',NULL,1,'Capital social suscrito y pagado',NULL,'equity','credit','local',1,0,0,0,1,NULL,0,'none',NULL,1,'2026-09-18 03:07:40','2026-09-18 03:07:40'),(1240,604,'3-01-01-01-002',NULL,1,'Aportes extraordinarios de socios',NULL,'equity','credit','local',1,0,0,0,1,NULL,0,'none',NULL,1,'2026-09-18 03:07:40','2026-09-18 03:07:40'),(1241,604,'3-02',NULL,1,'RESERVAS Y RESULTADOS',NULL,'equity','credit','local',0,0,0,0,1,NULL,0,'none',NULL,1,'2026-09-18 03:07:40','2026-09-18 03:07:40'),(1242,604,'3-02-01',NULL,1,'RESERVAS',NULL,'equity','credit','local',0,0,0,0,1,NULL,0,'none',NULL,1,'2026-09-18 03:07:40','2026-09-18 03:07:40'),(1243,604,'3-02-01-01',NULL,1,'RESERVAS',NULL,'equity','credit','local',0,0,0,0,1,NULL,0,'none',NULL,1,'2026-09-18 03:07:40','2026-09-18 03:07:40'),(1244,604,'3-02-01-01-001',NULL,1,'Reserva legal',NULL,'equity','credit','local',1,0,0,0,1,NULL,0,'none',NULL,1,'2026-09-18 03:07:40','2026-09-18 03:07:40'),(1245,604,'3-02-02',NULL,1,'RESULTADOS ACUMULADOS',NULL,'equity','credit','local',0,0,0,0,1,NULL,0,'none',NULL,1,'2026-09-18 03:07:40','2026-09-18 03:07:40'),(1246,604,'3-02-02-01',NULL,1,'RESULTADOS ACUMULADOS',NULL,'equity','credit','local',0,0,0,0,1,NULL,0,'none',NULL,1,'2026-09-18 03:07:40','2026-09-18 03:07:40'),(1247,604,'3-02-02-01-001',NULL,1,'Utilidades acumuladas de periodos anteriores',NULL,'equity','credit','local',1,0,0,0,1,NULL,0,'none',NULL,1,'2026-09-18 03:07:40','2026-09-18 03:07:40'),(1248,604,'3-02-02-01-002',NULL,1,'Pérdidas acumuladas de periodos anteriores',NULL,'equity','credit','local',1,0,0,0,1,NULL,0,'none',NULL,1,'2026-09-18 03:07:40','2026-09-18 03:07:40'),(1249,604,'3-02-02-01-003',NULL,1,'Utilidad o pérdida del periodo',NULL,'equity','credit','local',1,0,0,0,1,NULL,0,'none',NULL,1,'2026-09-18 03:07:40','2026-09-18 03:07:40'),(1250,604,'3-02-03',NULL,1,'OTROS RESULTADOS INTEGRALES',NULL,'equity','credit','local',0,0,0,0,1,NULL,0,'none',NULL,1,'2026-09-18 03:07:40','2026-09-18 03:07:40'),(1251,604,'3-02-03-01',NULL,1,'OTROS RESULTADOS INTEGRALES',NULL,'equity','credit','local',0,0,0,0,1,NULL,0,'none',NULL,1,'2026-09-18 03:07:40','2026-09-18 03:07:40'),(1252,604,'3-02-03-01-001',NULL,1,'Superávit por revaluación de activos',NULL,'equity','credit','local',1,0,0,0,1,NULL,0,'none',NULL,1,'2026-09-18 03:07:40','2026-09-18 03:07:40'),(1253,604,'4',NULL,1,'INGRESOS',NULL,'income','credit','local',0,0,0,0,1,NULL,0,'none',NULL,1,'2026-09-18 03:07:40','2026-09-18 03:07:40'),(1254,604,'4-01',NULL,1,'INGRESOS OPERATIVOS',NULL,'income','credit','local',0,0,0,0,1,NULL,0,'none',NULL,1,'2026-09-18 03:07:40','2026-09-18 03:07:40'),(1255,604,'4-01-01',NULL,1,'VENTAS DE MERCADERÍA',NULL,'income','credit','local',0,0,0,0,1,NULL,0,'none',NULL,1,'2026-09-18 03:07:40','2026-09-18 03:07:40'),(1256,604,'4-01-01-01',NULL,1,'VENTAS GRAVADAS',NULL,'income','credit','local',0,0,0,0,1,NULL,0,'none',NULL,1,'2026-09-18 03:07:40','2026-09-18 03:07:40'),(1257,604,'4-01-01-01-001',NULL,1,'Ventas de mercadería gravadas 13%',NULL,'income','credit','local',1,0,0,1,1,NULL,0,'sales',24,1,'2026-09-18 03:07:40','2026-09-18 03:07:40'),(1258,604,'4-01-01-01-002',NULL,1,'Ventas de mercadería gravadas 4%',NULL,'income','credit','local',1,0,0,1,1,NULL,0,'sales',25,1,'2026-09-18 03:07:40','2026-09-18 03:07:40'),(1259,604,'4-01-01-02',NULL,1,'VENTAS EXENTAS Y DE EXPORTACIÓN',NULL,'income','credit','local',0,0,0,0,1,NULL,0,'none',NULL,1,'2026-09-18 03:07:40','2026-09-18 03:07:40'),(1260,604,'4-01-01-02-001',NULL,1,'Ventas exentas',NULL,'income','credit','local',1,0,0,1,1,NULL,0,'sales',28,1,'2026-09-18 03:07:40','2026-09-18 03:07:40'),(1261,604,'4-01-01-02-002',NULL,1,'Ventas de exportación',NULL,'income','credit','foreign',1,0,0,1,1,NULL,0,'sales',28,1,'2026-09-18 03:07:40','2026-09-18 03:07:40'),(1262,604,'4-01-02',NULL,1,'INGRESOS POR SERVICIOS',NULL,'income','credit','local',0,0,0,0,1,NULL,0,'none',NULL,1,'2026-09-18 03:07:40','2026-09-18 03:07:40'),(1263,604,'4-01-02-01',NULL,1,'SERVICIOS GRAVADOS',NULL,'income','credit','local',0,0,0,0,1,NULL,0,'none',NULL,1,'2026-09-18 03:07:40','2026-09-18 03:07:40'),(1264,604,'4-01-02-01-001',NULL,1,'Servicios profesionales gravados 13%',NULL,'income','credit','local',1,0,0,1,1,NULL,0,'sales',24,1,'2026-09-18 03:07:41','2026-09-18 03:07:41'),(1265,604,'4-01-02-01-002',NULL,1,'Servicios de mantenimiento gravados 13%',NULL,'income','credit','local',1,0,0,1,1,NULL,0,'sales',24,1,'2026-09-18 03:07:41','2026-09-18 03:07:41'),(1266,604,'4-01-03',NULL,1,'DEVOLUCIONES Y DESCUENTOS',NULL,'income','credit','local',0,0,0,0,1,NULL,0,'none',NULL,1,'2026-09-18 03:07:41','2026-09-18 03:07:41'),(1267,604,'4-01-03-01',NULL,1,'DEVOLUCIONES Y DESCUENTOS SOBRE VENTAS',NULL,'income','credit','local',0,0,0,0,1,NULL,0,'none',NULL,1,'2026-09-18 03:07:41','2026-09-18 03:07:41'),(1268,604,'4-01-03-01-001',NULL,1,'Devoluciones sobre ventas',NULL,'income','credit','local',1,0,0,0,1,NULL,0,'sales',NULL,1,'2026-09-18 03:07:41','2026-09-18 03:07:41'),(1269,604,'4-01-03-01-002',NULL,1,'Descuentos y rebajas sobre ventas',NULL,'income','credit','local',1,0,0,0,1,NULL,0,'sales',NULL,1,'2026-09-18 03:07:41','2026-09-18 03:07:41'),(1270,604,'5',NULL,1,'COSTO DE VENTAS',NULL,'cost_of_sales','debit','local',0,0,0,0,1,NULL,0,'none',NULL,1,'2026-09-18 03:07:41','2026-09-18 03:07:41'),(1271,604,'5-01',NULL,1,'COSTO DE VENTAS',NULL,'cost_of_sales','debit','local',0,0,0,0,1,NULL,0,'none',NULL,1,'2026-09-18 03:07:41','2026-09-18 03:07:41'),(1272,604,'5-01-01',NULL,1,'COSTO DE MERCADERÍA Y SERVICIOS',NULL,'cost_of_sales','debit','local',0,0,0,0,1,NULL,0,'none',NULL,1,'2026-09-18 03:07:41','2026-09-18 03:07:41'),(1273,604,'5-01-01-01',NULL,1,'COSTO DIRECTO',NULL,'cost_of_sales','debit','local',0,0,0,0,1,NULL,0,'none',NULL,1,'2026-09-18 03:07:41','2026-09-18 03:07:41'),(1274,604,'5-01-01-01-001',NULL,1,'Costo de mercadería vendida',NULL,'cost_of_sales','debit','local',1,0,0,1,1,NULL,0,'none',NULL,1,'2026-09-18 03:07:41','2026-09-18 03:07:41'),(1275,604,'5-01-01-01-002',NULL,1,'Costo de servicios prestados',NULL,'cost_of_sales','debit','local',1,0,0,1,1,NULL,0,'none',NULL,1,'2026-09-18 03:07:41','2026-09-18 03:07:41'),(1276,604,'5-01-02',NULL,1,'COSTOS INDIRECTOS',NULL,'cost_of_sales','debit','local',0,0,0,0,1,NULL,0,'none',NULL,1,'2026-09-18 03:07:41','2026-09-18 03:07:41'),(1277,604,'5-01-02-01',NULL,1,'COSTOS INDIRECTOS',NULL,'cost_of_sales','debit','local',0,0,0,0,1,NULL,0,'none',NULL,1,'2026-09-18 03:07:41','2026-09-18 03:07:41'),(1278,604,'5-01-02-01-001',NULL,1,'Fletes y transporte sobre compras',NULL,'cost_of_sales','debit','local',1,0,0,1,1,NULL,0,'purchases',NULL,1,'2026-09-18 03:07:41','2026-09-18 03:07:41'),(1279,604,'5-01-02-01-002',NULL,1,'Aranceles y gastos de desalmacenaje',NULL,'cost_of_sales','debit','local',1,0,0,1,1,NULL,0,'purchases',NULL,1,'2026-09-18 03:07:41','2026-09-18 03:07:41'),(1280,604,'5-01-02-01-003',NULL,1,'Ajustes y mermas de inventario',NULL,'cost_of_sales','debit','local',1,0,0,1,1,NULL,0,'none',NULL,1,'2026-09-18 03:07:41','2026-09-18 03:07:41'),(1281,604,'6',NULL,1,'GASTOS',NULL,'expense','debit','local',0,0,0,0,1,NULL,0,'none',NULL,1,'2026-09-18 03:07:41','2026-09-18 03:07:41'),(1282,604,'6-01',NULL,1,'GASTOS DE OPERACIÓN',NULL,'expense','debit','local',0,0,0,0,1,NULL,0,'none',NULL,1,'2026-09-18 03:07:41','2026-09-18 03:07:41'),(1283,604,'6-01-01',NULL,1,'GASTOS DE PERSONAL',NULL,'expense','debit','local',0,0,0,0,1,NULL,0,'none',NULL,1,'2026-09-18 03:07:41','2026-09-18 03:07:41'),(1284,604,'6-01-01-01',NULL,1,'REMUNERACIONES Y CARGAS',NULL,'expense','debit','local',0,0,0,0,1,NULL,0,'none',NULL,1,'2026-09-18 03:07:41','2026-09-18 03:07:41'),(1285,604,'6-01-01-01-001',NULL,1,'Salarios',NULL,'expense','debit','local',1,0,0,1,1,NULL,0,'none',NULL,1,'2026-09-18 03:07:41','2026-09-18 03:07:41'),(1286,604,'6-01-01-01-002',NULL,1,'Aguinaldo',NULL,'expense','debit','local',1,0,0,1,1,NULL,0,'none',NULL,1,'2026-09-18 03:07:41','2026-09-18 03:07:41'),(1287,604,'6-01-01-01-003',NULL,1,'Vacaciones',NULL,'expense','debit','local',1,0,0,1,1,NULL,0,'none',NULL,1,'2026-09-18 03:07:41','2026-09-18 03:07:41'),(1288,604,'6-01-01-01-004',NULL,1,'Cargas sociales CCSS',NULL,'expense','debit','local',1,0,0,1,1,NULL,0,'none',NULL,1,'2026-09-18 03:07:41','2026-09-18 03:07:41'),(1289,604,'6-01-01-01-005',NULL,1,'Póliza de riesgos del trabajo',NULL,'expense','debit','local',1,0,0,1,1,NULL,0,'none',NULL,1,'2026-09-18 03:07:41','2026-09-18 03:07:41'),(1290,604,'6-01-01-01-006',NULL,1,'Capacitación y bienestar del personal',NULL,'expense','debit','local',1,0,0,1,1,NULL,0,'none',NULL,1,'2026-09-18 03:07:41','2026-09-18 03:07:41'),(1291,604,'6-01-02',NULL,1,'GASTOS GENERALES',NULL,'expense','debit','local',0,0,0,0,1,NULL,0,'none',NULL,1,'2026-09-18 03:07:41','2026-09-18 03:07:41'),(1292,604,'6-01-02-01',NULL,1,'SERVICIOS Y SUMINISTROS',NULL,'expense','debit','local',0,0,0,0,1,NULL,0,'none',NULL,1,'2026-09-18 03:07:41','2026-09-18 03:07:41'),(1293,604,'6-01-02-01-001',NULL,1,'Alquileres',NULL,'expense','debit','local',1,0,0,1,1,NULL,0,'purchases',NULL,1,'2026-09-18 03:07:41','2026-09-18 03:07:41'),(1294,604,'6-01-02-01-002',NULL,1,'Servicios públicos (agua, luz, teléfono)',NULL,'expense','debit','local',1,0,0,1,1,NULL,0,'purchases',NULL,1,'2026-09-18 03:07:41','2026-09-18 03:07:41'),(1295,604,'6-01-02-01-003',NULL,1,'Internet y comunicaciones',NULL,'expense','debit','local',1,0,0,1,1,NULL,0,'purchases',NULL,1,'2026-09-18 03:07:41','2026-09-18 03:07:41'),(1296,604,'6-01-02-01-004',NULL,1,'Papelería y útiles de oficina',NULL,'expense','debit','local',1,0,0,1,1,NULL,0,'purchases',NULL,1,'2026-09-18 03:07:41','2026-09-18 03:07:41'),(1297,604,'6-01-02-01-005',NULL,1,'Limpieza y seguridad',NULL,'expense','debit','local',1,0,0,1,1,NULL,0,'purchases',NULL,1,'2026-09-18 03:07:41','2026-09-18 03:07:41'),(1298,604,'6-01-02-02',NULL,1,'SERVICIOS PROFESIONALES',NULL,'expense','debit','local',0,0,0,0,1,NULL,0,'none',NULL,1,'2026-09-18 03:07:41','2026-09-18 03:07:41'),(1299,604,'6-01-02-02-001',NULL,1,'Honorarios profesionales',NULL,'expense','debit','local',1,0,0,1,1,NULL,0,'purchases',NULL,1,'2026-09-18 03:07:41','2026-09-18 03:44:18'),(1300,604,'6-01-02-02-002',NULL,1,'Auditoría externa',NULL,'expense','debit','local',1,0,0,1,1,NULL,0,'purchases',NULL,1,'2026-09-18 03:07:41','2026-09-18 03:07:41'),(1301,604,'6-01-02-02-003',NULL,1,'Asesoría legal',NULL,'expense','debit','local',1,0,0,1,1,NULL,0,'purchases',NULL,1,'2026-09-18 03:07:41','2026-09-18 03:07:41'),(1302,604,'6-01-02-03',NULL,1,'MANTENIMIENTO Y TRANSPORTE',NULL,'expense','debit','local',0,0,0,0,1,NULL,0,'none',NULL,1,'2026-09-18 03:07:41','2026-09-18 03:07:41'),(1303,604,'6-01-02-03-001',NULL,1,'Mantenimiento y reparaciones',NULL,'expense','debit','local',1,0,0,1,1,NULL,0,'purchases',NULL,1,'2026-09-18 03:07:41','2026-09-18 03:07:41'),(1304,604,'6-01-02-03-002',NULL,1,'Combustibles y lubricantes',NULL,'expense','debit','local',1,0,0,1,1,NULL,0,'purchases',NULL,1,'2026-09-18 03:07:41','2026-09-18 03:07:41'),(1305,604,'6-01-02-03-003',NULL,1,'Viáticos y gastos de viaje',NULL,'expense','debit','local',1,0,0,1,1,NULL,0,'none',NULL,1,'2026-09-18 03:07:41','2026-09-18 03:07:41'),(1306,604,'6-01-02-04',NULL,1,'SEGUROS E IMPUESTOS',NULL,'expense','debit','local',0,0,0,0,1,NULL,0,'none',NULL,1,'2026-09-18 03:07:41','2026-09-18 03:07:41'),(1307,604,'6-01-02-04-001',NULL,1,'Seguros',NULL,'expense','debit','local',1,0,0,1,1,NULL,0,'none',NULL,1,'2026-09-18 03:07:41','2026-09-18 03:07:41'),(1308,604,'6-01-02-04-002',NULL,1,'Impuestos municipales y patentes',NULL,'expense','debit','local',1,0,0,1,1,NULL,0,'none',NULL,1,'2026-09-18 03:07:41','2026-09-18 03:07:41'),(1309,604,'6-01-03',NULL,1,'DEPRECIACIONES Y AMORTIZACIONES',NULL,'expense','debit','local',0,0,0,0,1,NULL,0,'none',NULL,1,'2026-09-18 03:07:41','2026-09-18 03:07:41'),(1310,604,'6-01-03-01',NULL,1,'DEPRECIACIONES Y AMORTIZACIONES',NULL,'expense','debit','local',0,0,0,0,1,NULL,0,'none',NULL,1,'2026-09-18 03:07:41','2026-09-18 03:07:41'),(1311,604,'6-01-03-01-001',NULL,1,'Depreciación de edificios',NULL,'expense','debit','local',1,0,0,1,1,NULL,0,'none',NULL,1,'2026-09-18 03:07:41','2026-09-18 03:07:41'),(1312,604,'6-01-03-01-002',NULL,1,'Depreciación de mobiliario y equipo',NULL,'expense','debit','local',1,0,0,1,1,NULL,0,'none',NULL,1,'2026-09-18 03:07:41','2026-09-18 03:07:41'),(1313,604,'6-01-03-01-003',NULL,1,'Depreciación de equipo de cómputo',NULL,'expense','debit','local',1,0,0,1,1,NULL,0,'none',NULL,1,'2026-09-18 03:07:41','2026-09-18 03:07:41'),(1314,604,'6-01-03-01-004',NULL,1,'Depreciación de vehículos',NULL,'expense','debit','local',1,0,0,1,1,NULL,0,'none',NULL,1,'2026-09-18 03:07:41','2026-09-18 03:07:41'),(1315,604,'6-01-03-01-005',NULL,1,'Amortización de intangibles',NULL,'expense','debit','local',1,0,0,1,1,NULL,0,'none',NULL,1,'2026-09-18 03:07:41','2026-09-18 03:07:41'),(1316,604,'6-02',NULL,1,'GASTOS DE VENTA',NULL,'expense','debit','local',0,0,0,0,1,NULL,0,'none',NULL,1,'2026-09-18 03:07:41','2026-09-18 03:07:41'),(1317,604,'6-02-01',NULL,1,'GASTOS DE VENTA',NULL,'expense','debit','local',0,0,0,0,1,NULL,0,'none',NULL,1,'2026-09-18 03:07:41','2026-09-18 03:07:41'),(1318,604,'6-02-01-01',NULL,1,'MERCADEO Y DISTRIBUCIÓN',NULL,'expense','debit','local',0,0,0,0,1,NULL,0,'none',NULL,1,'2026-09-18 03:07:41','2026-09-18 03:07:41'),(1319,604,'6-02-01-01-001',NULL,1,'Publicidad y mercadeo',NULL,'expense','debit','local',1,0,0,1,1,NULL,0,'purchases',NULL,1,'2026-09-18 03:07:41','2026-09-18 03:07:41'),(1320,604,'6-02-01-01-002',NULL,1,'Comisiones sobre ventas',NULL,'expense','debit','local',1,0,0,1,1,NULL,0,'none',NULL,1,'2026-09-18 03:07:41','2026-09-18 03:07:41'),(1321,604,'6-02-01-01-003',NULL,1,'Fletes sobre ventas',NULL,'expense','debit','local',1,0,0,1,1,NULL,0,'purchases',NULL,1,'2026-09-18 03:07:41','2026-09-18 03:07:41'),(1322,604,'6-02-01-01-004',NULL,1,'Gasto por estimación de incobrables',NULL,'expense','debit','local',1,0,0,1,1,NULL,0,'none',NULL,1,'2026-09-18 03:07:41','2026-09-18 03:07:41'),(1323,604,'6-02-01-01-005',NULL,1,'Publicidad impresa (descontinuada)',NULL,'expense','debit','local',1,0,0,1,1,NULL,0,'none',NULL,0,'2026-09-18 03:07:41','2026-09-18 03:07:41'),(1324,604,'7',NULL,1,'OTROS INGRESOS',NULL,'other_income','credit','local',0,0,0,0,1,NULL,0,'none',NULL,1,'2026-09-18 03:07:41','2026-09-18 03:07:41'),(1325,604,'7-01',NULL,1,'INGRESOS FINANCIEROS',NULL,'other_income','credit','local',0,0,0,0,1,NULL,0,'none',NULL,1,'2026-09-18 03:07:41','2026-09-18 03:07:41'),(1326,604,'7-01-01',NULL,1,'PRODUCTOS FINANCIEROS',NULL,'other_income','credit','local',0,0,0,0,1,NULL,0,'none',NULL,1,'2026-09-18 03:07:41','2026-09-18 03:07:41'),(1327,604,'7-01-01-01',NULL,1,'INTERESES Y RENDIMIENTOS',NULL,'other_income','credit','local',0,0,0,0,1,NULL,0,'none',NULL,1,'2026-09-18 03:07:41','2026-09-18 03:07:41'),(1328,604,'7-01-01-01-001',NULL,1,'Intereses ganados sobre inversiones',NULL,'other_income','credit','local',1,0,0,0,1,NULL,0,'none',NULL,1,'2026-09-18 03:07:41','2026-09-18 03:07:41'),(1329,604,'7-01-01-01-002',NULL,1,'Descuentos ganados sobre compras',NULL,'other_income','credit','local',1,0,0,0,1,NULL,0,'none',NULL,1,'2026-09-18 03:07:41','2026-09-18 03:07:41'),(1330,604,'7-01-02',NULL,1,'DIFERENCIAL CAMBIARIO',NULL,'other_income','credit','local',0,0,0,0,1,NULL,0,'none',NULL,1,'2026-09-18 03:07:41','2026-09-18 03:07:41'),(1331,604,'7-01-02-01',NULL,1,'DIFERENCIAL CAMBIARIO',NULL,'other_income','credit','local',0,0,0,0,1,NULL,0,'none',NULL,1,'2026-09-18 03:07:41','2026-09-18 03:07:41'),(1332,604,'7-01-02-01-001',NULL,1,'Ganancia por diferencial cambiario',NULL,'other_income','credit','local',1,0,0,0,1,NULL,0,'none',NULL,1,'2026-09-18 03:07:41','2026-09-18 03:07:41'),(1333,604,'7-02',NULL,1,'OTROS INGRESOS NO OPERATIVOS',NULL,'other_income','credit','local',0,0,0,0,1,NULL,0,'none',NULL,1,'2026-09-18 03:07:41','2026-09-18 03:07:41'),(1334,604,'7-02-01',NULL,1,'OTROS INGRESOS',NULL,'other_income','credit','local',0,0,0,0,1,NULL,0,'none',NULL,1,'2026-09-18 03:07:41','2026-09-18 03:07:41'),(1335,604,'7-02-01-01',NULL,1,'OTROS INGRESOS',NULL,'other_income','credit','local',0,0,0,0,1,NULL,0,'none',NULL,1,'2026-09-18 03:07:41','2026-09-18 03:07:41'),(1336,604,'7-02-01-01-001',NULL,1,'Ganancia en venta de activo fijo',NULL,'other_income','credit','local',1,0,0,0,1,NULL,0,'none',NULL,1,'2026-09-18 03:07:41','2026-09-18 03:07:41'),(1337,604,'7-02-01-01-002',NULL,1,'Ingresos varios',NULL,'other_income','credit','local',1,0,0,0,1,NULL,0,'none',NULL,1,'2026-09-18 03:07:41','2026-09-18 03:07:41'),(1338,604,'8',NULL,1,'OTROS GASTOS',NULL,'other_expense','debit','local',0,0,0,0,1,NULL,0,'none',NULL,1,'2026-09-18 03:07:41','2026-09-18 03:07:41'),(1339,604,'8-01',NULL,1,'GASTOS FINANCIEROS',NULL,'other_expense','debit','local',0,0,0,0,1,NULL,0,'none',NULL,1,'2026-09-18 03:07:41','2026-09-18 03:07:41'),(1340,604,'8-01-01',NULL,1,'GASTOS FINANCIEROS',NULL,'other_expense','debit','local',0,0,0,0,1,NULL,0,'none',NULL,1,'2026-09-18 03:07:41','2026-09-18 03:07:41'),(1341,604,'8-01-01-01',NULL,1,'INTERESES Y COMISIONES',NULL,'other_expense','debit','local',0,0,0,0,1,NULL,0,'none',NULL,1,'2026-09-18 03:07:41','2026-09-18 03:07:41'),(1342,604,'8-01-01-01-001',NULL,1,'Intereses sobre préstamos',NULL,'other_expense','debit','local',1,0,0,0,1,NULL,0,'none',NULL,1,'2026-09-18 03:07:41','2026-09-18 03:07:41'),(1343,604,'8-01-01-01-002',NULL,1,'Comisiones y gastos bancarios',NULL,'other_expense','debit','local',1,0,0,0,1,NULL,0,'none',NULL,1,'2026-09-18 03:07:41','2026-09-18 03:07:41'),(1344,604,'8-01-02',NULL,1,'DIFERENCIAL CAMBIARIO',NULL,'other_expense','debit','local',0,0,0,0,1,NULL,0,'none',NULL,1,'2026-09-18 03:07:41','2026-09-18 03:07:41'),(1345,604,'8-01-02-01',NULL,1,'DIFERENCIAL CAMBIARIO',NULL,'other_expense','debit','local',0,0,0,0,1,NULL,0,'none',NULL,1,'2026-09-18 03:07:41','2026-09-18 03:07:41'),(1346,604,'8-01-02-01-001',NULL,1,'Pérdida por diferencial cambiario',NULL,'other_expense','debit','local',1,0,0,0,1,NULL,0,'none',NULL,1,'2026-09-18 03:07:41','2026-09-18 03:07:41'),(1347,604,'8-02',NULL,1,'OTROS GASTOS NO OPERATIVOS',NULL,'other_expense','debit','local',0,0,0,0,1,NULL,0,'none',NULL,1,'2026-09-18 03:07:41','2026-09-18 03:07:41'),(1348,604,'8-02-01',NULL,1,'OTROS GASTOS',NULL,'other_expense','debit','local',0,0,0,0,1,NULL,0,'none',NULL,1,'2026-09-18 03:07:41','2026-09-18 03:07:41'),(1349,604,'8-02-01-01',NULL,1,'OTROS GASTOS',NULL,'other_expense','debit','local',0,0,0,0,1,NULL,0,'none',NULL,1,'2026-09-18 03:07:41','2026-09-18 03:07:41'),(1350,604,'8-02-01-01-001',NULL,1,'Pérdida en venta de activo fijo',NULL,'other_expense','debit','local',1,0,0,0,1,NULL,0,'none',NULL,1,'2026-09-18 03:07:41','2026-09-18 03:07:41'),(1351,604,'8-02-01-01-002',NULL,1,'Gastos no deducibles',NULL,'other_expense','debit','local',1,0,0,0,1,NULL,0,'none',NULL,1,'2026-09-18 03:07:41','2026-09-18 03:07:41'),(1352,604,'8-02-01-01-003',NULL,1,'Multas y sanciones',NULL,'other_expense','debit','local',1,0,0,0,1,NULL,0,'none',NULL,1,'2026-09-18 03:07:41','2026-09-18 03:07:41'),(1353,604,'8-03',NULL,1,'IMPUESTO SOBRE LA RENTA',NULL,'other_expense','debit','local',0,0,0,0,1,NULL,0,'none',NULL,1,'2026-09-18 03:07:41','2026-09-18 03:07:41'),(1354,604,'8-03-01',NULL,1,'IMPUESTO SOBRE LA RENTA',NULL,'other_expense','debit','local',0,0,0,0,1,NULL,0,'none',NULL,1,'2026-09-18 03:07:41','2026-09-18 03:07:41'),(1355,604,'8-03-01-01',NULL,1,'IMPUESTO SOBRE LA RENTA',NULL,'other_expense','debit','local',0,0,0,0,1,NULL,0,'none',NULL,1,'2026-09-18 03:07:41','2026-09-18 03:07:41'),(1356,604,'8-03-01-01-001',NULL,1,'Impuesto sobre la renta del periodo',NULL,'other_expense','debit','local',1,0,0,0,1,NULL,0,'none',NULL,1,'2026-09-18 03:07:41','2026-09-18 03:07:41'),(1357,604,'1-01-03-01-003',NULL,1,'Producto en proceso (WIP)',NULL,'asset','debit','local',1,0,0,0,1,NULL,0,'none',NULL,1,'2026-09-18 03:53:57','2026-09-18 03:53:57'),(1358,604,'2-01-01-02-004',NULL,1,'Transitoria de compras (GR/IR)',NULL,'liability','credit','local',1,0,0,0,1,NULL,0,'none',NULL,1,'2026-09-18 03:53:57','2026-09-18 03:53:57'),(1359,604,'5-01-03',NULL,1,'AJUSTES Y DESVIACIONES DE INVENTARIO',NULL,'cost_of_sales','debit','local',0,0,0,0,1,NULL,0,'none',NULL,1,'2026-09-18 03:53:58','2026-09-18 03:53:58'),(1360,604,'5-01-03-01',NULL,1,'AJUSTES Y DESVIACIONES',NULL,'cost_of_sales','debit','local',0,0,0,0,1,NULL,0,'none',NULL,1,'2026-09-18 03:53:58','2026-09-18 03:53:58'),(1361,604,'5-01-03-01-001',NULL,1,'Ajuste de inventario — aumento',NULL,'cost_of_sales','debit','local',1,0,0,0,1,NULL,0,'none',NULL,1,'2026-09-18 03:53:58','2026-09-18 03:53:58'),(1362,604,'5-01-03-01-002',NULL,1,'Ajuste de inventario — disminución',NULL,'cost_of_sales','debit','local',1,0,0,0,1,NULL,0,'none',NULL,1,'2026-09-18 03:53:58','2026-09-18 03:53:58'),(1363,604,'5-01-03-01-003',NULL,1,'Diferencia de precio de compra',NULL,'cost_of_sales','debit','local',1,0,0,0,1,NULL,0,'none',NULL,1,'2026-09-18 03:53:58','2026-09-18 03:53:58'),(1364,604,'5-01-03-01-004',NULL,1,'Desviación de fabricación',NULL,'cost_of_sales','debit','local',1,0,0,0,1,NULL,0,'none',NULL,1,'2026-09-18 03:53:58','2026-09-18 03:53:58'),(1365,604,'3-03',NULL,1,'SALDOS INICIALES',NULL,'equity','credit','local',0,0,0,0,1,NULL,0,'none',NULL,1,'2026-09-18 04:07:08','2026-09-18 04:07:08'),(1366,604,'3-03-01',NULL,1,'SALDOS INICIALES',NULL,'equity','credit','local',0,0,0,0,1,NULL,0,'none',NULL,1,'2026-09-18 04:07:08','2026-09-18 04:07:08'),(1367,604,'3-03-01-01',NULL,1,'SALDOS INICIALES',NULL,'equity','credit','local',0,0,0,0,1,NULL,0,'none',NULL,1,'2026-09-18 04:07:08','2026-09-18 04:07:08'),(1368,604,'3-03-01-01-001',NULL,1,'Saldos iniciales de existencias',NULL,'equity','credit','local',1,0,0,0,1,NULL,0,'none',NULL,1,'2026-09-18 04:07:08','2026-09-18 04:07:08'),(1369,605,'1-00-00-00-000',NULL,1,'Activos','Assets','asset','debit','both',0,0,0,0,1,NULL,0,'none',NULL,1,'2026-09-28 02:01:24','2026-09-28 02:01:24'),(1370,605,'1-01-00-00-000',NULL,1,'Activo corriente','Current assets','asset','debit','both',0,0,0,0,1,NULL,0,'none',NULL,1,'2026-09-28 02:01:24','2026-09-28 02:01:24'),(1371,605,'1-01-01-00-000',NULL,1,'Efectivo y equivalentes de efectivo','Cash and cash equivalents','asset','debit','both',0,0,0,0,1,NULL,0,'none',NULL,1,'2026-09-28 02:01:24','2026-09-28 02:01:24'),(1372,605,'1-01-01-01-000',NULL,1,'Caja chica','Petty cash','asset','debit','both',0,0,0,0,1,NULL,0,'none',NULL,1,'2026-09-28 02:01:24','2026-09-28 02:01:24'),(1373,605,'1-01-01-01-001',NULL,1,'Caja chica administrativa ¢','Administrative petty cash CRC','asset','debit','local',1,0,1,0,1,NULL,0,'none',NULL,1,'2026-09-28 02:01:24','2026-09-28 02:01:24'),(1374,605,'1-01-01-01-002',NULL,1,'Caja chica administrativa $','Administrative petty cash USD','asset','debit','foreign',1,0,1,0,1,NULL,0,'none',NULL,1,'2026-09-28 02:01:24','2026-09-28 02:01:24'),(1375,605,'1-01-01-01-003',NULL,1,'Caja chica / viajes / viáticos','Petty cash - travel and per diem','asset','debit','local',1,0,1,0,1,NULL,0,'none',NULL,1,'2026-09-28 02:01:24','2026-09-28 02:01:24'),(1376,605,'1-01-01-02-000',NULL,1,'Fondo gestión transitoria','Transitory management fund','asset','debit','both',0,0,0,0,1,NULL,0,'none',NULL,1,'2026-09-28 02:01:24','2026-09-28 02:01:24'),(1377,605,'1-01-01-02-001',NULL,1,'Fondo líquido colones en tránsito','Liquid funds in transit CRC','asset','debit','local',1,0,1,0,1,NULL,0,'none',NULL,1,'2026-09-28 02:01:24','2026-09-28 02:01:24'),(1378,605,'1-01-01-02-002',NULL,1,'Fondo líquido dólares en tránsito','Liquid funds in transit USD','asset','debit','foreign',1,0,1,0,1,NULL,0,'none',NULL,1,'2026-09-28 02:01:24','2026-09-28 02:01:24'),(1379,605,'1-01-01-02-003',NULL,1,'Fondo INVU ahorro','INVU savings fund','asset','debit','local',1,0,1,0,1,NULL,0,'none',NULL,1,'2026-09-28 02:01:24','2026-09-28 02:01:24'),(1380,605,'1-01-01-02-004',NULL,1,'Fondo colones','CRC fund','asset','debit','local',1,0,1,0,1,NULL,0,'none',NULL,1,'2026-09-28 02:01:24','2026-09-28 02:01:24'),(1381,605,'1-01-01-03-000',NULL,1,'Bancos','Banks','asset','debit','both',0,0,0,0,1,NULL,0,'none',NULL,1,'2026-09-28 02:01:24','2026-09-28 02:01:24'),(1382,605,'1-01-01-03-001',NULL,1,'BNCR ¢ Cta N° 100-01-125-000642-9','BNCR CRC account 100-01-125-000642-9','asset','debit','local',1,0,1,0,1,NULL,0,'none',NULL,1,'2026-09-28 02:01:24','2026-09-28 02:01:24'),(1383,605,'1-01-01-03-002',NULL,1,'BNCR $ Cta N° 100-02-125-000740-1','BNCR USD account 100-02-125-000740-1','asset','debit','foreign',1,0,1,0,1,NULL,0,'none',NULL,1,'2026-09-28 02:01:24','2026-09-28 02:01:24'),(1384,605,'1-01-01-03-003',NULL,1,'BNCR $ Cta N° 100-02-095-601701-5','BNCR USD account 100-02-095-601701-5','asset','debit','foreign',1,0,1,0,1,NULL,0,'none',NULL,1,'2026-09-28 02:01:24','2026-09-28 02:01:24'),(1385,605,'1-01-01-03-004',NULL,1,'BNCR ¢ Cta N° 2959-9','BNCR CRC account 2959-9','asset','debit','local',1,0,1,0,1,NULL,0,'none',NULL,1,'2026-09-28 02:01:24','2026-09-28 02:01:24'),(1386,605,'1-01-01-03-005',NULL,1,'BCR ¢ Cta N° 265-0002404-0','BCR CRC account 265-0002404-0','asset','debit','local',1,0,1,0,1,NULL,0,'none',NULL,1,'2026-09-28 02:01:24','2026-09-28 02:01:24'),(1387,605,'1-01-01-03-006',NULL,1,'BAC SJ $ Cta 9405403966','BAC SJ USD account 9405403966','asset','debit','foreign',1,0,1,0,1,NULL,0,'none',NULL,1,'2026-09-28 02:01:24','2026-09-28 02:01:24'),(1388,605,'1-01-01-03-007',NULL,1,'BAC SJ ¢ Cta 3231-9027','BAC SJ CRC account 3231-9027','asset','debit','local',1,0,1,0,1,NULL,0,'none',NULL,1,'2026-09-28 02:01:24','2026-09-28 02:01:24'),(1389,605,'1-01-01-03-008',NULL,1,'BAC SJ $ Cta 969377204','BAC SJ USD account 969377204','asset','debit','foreign',1,0,1,0,1,NULL,0,'none',NULL,1,'2026-09-28 02:01:24','2026-09-28 02:01:24'),(1390,605,'1-01-02-00-000',NULL,1,'Cuentas por cobrar','Accounts receivable','asset','debit','both',0,0,0,0,1,NULL,0,'none',NULL,1,'2026-09-28 02:01:24','2026-09-28 02:01:24'),(1391,605,'1-01-02-01-000',NULL,1,'Comerciales en colones ¢','Trade receivables CRC','asset','debit','both',1,1,0,0,1,NULL,0,'none',NULL,1,'2026-09-28 02:01:24','2026-09-28 02:01:24'),(1392,605,'1-01-02-02-000',NULL,1,'Comerciales en dólares $','Trade receivables USD','asset','debit','both',1,1,0,0,1,NULL,0,'none',NULL,1,'2026-09-28 02:01:24','2026-09-28 02:01:24'),(1393,605,'1-01-02-03-000',NULL,1,'Reintegro logística aduanal $','Customs logistics reimbursements USD','asset','debit','both',1,1,0,0,1,NULL,0,'none',NULL,1,'2026-09-28 02:01:24','2026-09-28 02:01:24'),(1394,605,'1-01-02-04-000',NULL,1,'Otras cuentas por cobrar','Other receivables','asset','debit','both',0,0,0,0,1,NULL,0,'none',NULL,1,'2026-09-28 02:01:24','2026-09-28 02:01:24'),(1395,605,'1-01-02-04-001',NULL,1,'José Alberto Sequeira E. $','José Alberto Sequeira E. USD','asset','debit','foreign',1,0,0,0,1,NULL,0,'none',NULL,1,'2026-09-28 02:01:24','2026-09-28 02:01:24'),(1396,605,'1-01-02-04-002',NULL,1,'Transportes Sequeira y Elizondo Ltda ¢','Transportes Sequeira y Elizondo Ltda CRC','asset','debit','local',1,0,0,0,1,NULL,0,'none',NULL,1,'2026-09-28 02:01:24','2026-09-28 02:01:24'),(1397,605,'1-01-02-04-003',NULL,1,'Transportes Chiricanos de CR, SA','Transportes Chiricanos de CR, SA','asset','debit','local',1,0,0,0,1,NULL,0,'none',NULL,1,'2026-09-28 02:01:24','2026-09-28 02:01:24'),(1398,605,'1-01-02-04-004',NULL,1,'Atlantic-Pacific CR Ltda','Atlantic-Pacific CR Ltda','asset','debit','local',1,0,0,0,1,NULL,0,'none',NULL,1,'2026-09-28 02:01:24','2026-09-28 02:01:24'),(1399,605,'1-01-02-04-005',NULL,1,'Johan Sequeira - inmueble ¢','Johan Sequeira - real estate CRC','asset','debit','local',1,0,0,0,1,NULL,0,'none',NULL,1,'2026-09-28 02:01:24','2026-09-28 02:01:24'),(1400,605,'1-01-02-04-006',NULL,1,'José Alberto Sequeira INVU','José Alberto Sequeira INVU','asset','debit','local',1,0,0,0,1,NULL,0,'none',NULL,1,'2026-09-28 02:01:24','2026-09-28 02:01:24'),(1401,605,'1-01-02-04-007',NULL,1,'Johan / Carlos Sequeira CCSS','Johan / Carlos Sequeira CCSS','asset','debit','local',1,0,0,0,1,NULL,0,'none',NULL,1,'2026-09-28 02:01:24','2026-09-28 02:01:24'),(1402,605,'1-02-00-00-000',NULL,1,'Propiedad, planta y equipos','Fixed assets','asset','debit','both',0,0,0,0,1,NULL,0,'none',NULL,1,'2026-09-28 02:01:24','2026-09-28 02:01:24'),(1403,605,'1-02-00-00-001',NULL,1,'Terrenos','Land','asset','debit','local',1,0,0,0,1,NULL,0,'none',NULL,1,'2026-09-28 02:01:24','2026-09-28 02:01:24'),(1404,605,'1-02-00-00-002',NULL,1,'Infraestructura','Infrastructure','asset','debit','local',1,0,0,0,1,NULL,0,'none',NULL,1,'2026-09-28 02:01:24','2026-09-28 02:01:24'),(1405,605,'1-02-01-00-000',NULL,1,'Vehículos','Vehicles','asset','debit','both',0,0,0,0,1,NULL,0,'none',NULL,1,'2026-09-28 02:01:24','2026-09-28 02:01:24'),(1406,605,'1-02-01-00-001',NULL,1,'Costo vehículos','Vehicles - cost','asset','debit','local',1,0,0,0,1,NULL,0,'none',NULL,1,'2026-09-28 02:01:24','2026-09-28 02:01:24'),(1407,605,'1-02-01-00-002',NULL,1,'Depreciación acumulada vehículos','Accumulated depreciation - vehicles','asset','debit','local',1,0,0,0,1,NULL,0,'none',NULL,1,'2026-09-28 02:01:24','2026-09-28 02:01:24'),(1408,605,'1-02-02-00-000',NULL,1,'Muebles de oficina','Office furniture','asset','debit','both',0,0,0,0,1,NULL,0,'none',NULL,1,'2026-09-28 02:01:24','2026-09-28 02:01:24'),(1409,605,'1-02-02-00-001',NULL,1,'Costo muebles','Furniture - cost','asset','debit','local',1,0,0,0,1,NULL,0,'none',NULL,1,'2026-09-28 02:01:24','2026-09-28 02:01:24'),(1410,605,'1-02-02-00-002',NULL,1,'Depreciación acumulada muebles','Accumulated depreciation - furniture','asset','debit','local',1,0,0,0,1,NULL,0,'none',NULL,1,'2026-09-28 02:01:24','2026-09-28 02:01:24'),(1411,605,'1-02-03-00-000',NULL,1,'Equipos de cómputo','Computer equipment','asset','debit','both',0,0,0,0,1,NULL,0,'none',NULL,1,'2026-09-28 02:01:24','2026-09-28 02:01:24'),(1412,605,'1-02-03-00-001',NULL,1,'Costo equipos de cómputo','Computer equipment - cost','asset','debit','local',1,0,0,0,1,NULL,0,'none',NULL,1,'2026-09-28 02:01:24','2026-09-28 02:01:24'),(1413,605,'1-02-03-00-002',NULL,1,'Depreciación acumulada equipos de cómputo','Accumulated depreciation - computer equipment','asset','debit','local',1,0,0,0,1,NULL,0,'none',NULL,1,'2026-09-28 02:01:24','2026-09-28 02:01:24'),(1414,605,'1-02-04-00-000',NULL,1,'Maquinaria y equipos','Machinery and equipment','asset','debit','both',0,0,0,0,1,NULL,0,'none',NULL,1,'2026-09-28 02:01:24','2026-09-28 02:01:24'),(1415,605,'1-02-04-00-001',NULL,1,'Costo maquinaria y equipos','Machinery and equipment - cost','asset','debit','local',1,0,0,0,1,NULL,0,'none',NULL,1,'2026-09-28 02:01:24','2026-09-28 02:01:24'),(1416,605,'1-02-04-00-002',NULL,1,'Depreciación acumulada maquinaria y equipos','Accumulated depreciation - machinery and equipment','asset','debit','local',1,0,0,0,1,NULL,0,'none',NULL,1,'2026-09-28 02:01:24','2026-09-28 02:01:24'),(1417,605,'1-03-00-00-000',NULL,1,'Otros activos','Other assets','asset','debit','both',0,0,0,0,1,NULL,0,'none',NULL,1,'2026-09-28 02:01:24','2026-09-28 02:01:24'),(1418,605,'1-03-01-00-000',NULL,1,'Impuestos diferidos','Deferred taxes','asset','debit','both',0,0,0,0,1,NULL,0,'none',NULL,1,'2026-09-28 02:01:24','2026-09-28 02:01:24'),(1419,605,'1-03-01-00-001',NULL,1,'Impuesto de ventas','Sales tax','asset','debit','local',1,0,0,0,1,NULL,0,'none',NULL,1,'2026-09-28 02:01:24','2026-09-28 02:01:24'),(1420,605,'1-03-01-00-002',NULL,1,'Impuesto de renta \"parciales\"','Income tax prepayments','asset','debit','local',1,0,0,0,1,NULL,0,'none',NULL,1,'2026-09-28 02:01:24','2026-09-28 02:01:24'),(1421,605,'1-03-02-00-000',NULL,1,'Gastos diferidos','Deferred expenses','asset','debit','both',0,0,0,0,1,NULL,0,'none',NULL,1,'2026-09-28 02:01:24','2026-09-28 02:01:24'),(1422,605,'1-03-02-01-000',NULL,1,'Pólizas','Insurance policies','asset','debit','both',0,0,0,0,1,NULL,0,'none',NULL,1,'2026-09-28 02:01:24','2026-09-28 02:01:24'),(1423,605,'1-03-02-01-001',NULL,1,'Póliza riesgos del trabajo','Workers\' compensation policy','asset','debit','local',1,0,0,0,1,NULL,0,'none',NULL,1,'2026-09-28 02:01:24','2026-09-28 02:01:24'),(1424,605,'1-03-02-01-002',NULL,1,'Póliza de vehículos','Vehicle insurance policy','asset','debit','local',1,0,0,0,1,NULL,0,'none',NULL,1,'2026-09-28 02:01:24','2026-09-28 02:01:24'),(1425,605,'1-03-02-01-003',NULL,1,'Póliza de carga','Cargo insurance policy','asset','debit','local',1,0,0,0,1,NULL,0,'none',NULL,1,'2026-09-28 02:01:24','2026-09-28 02:01:24'),(1426,605,'1-03-02-01-004',NULL,1,'Desembolsos compra camión','Truck purchase disbursements','asset','debit','local',1,0,0,0,1,NULL,0,'none',NULL,1,'2026-09-28 02:01:24','2026-09-28 02:01:24'),(1427,605,'1-03-02-01-005',NULL,1,'Derechos de circulación','Vehicle circulation fees','asset','debit','local',1,0,0,0,1,NULL,0,'none',NULL,1,'2026-09-28 02:01:24','2026-09-28 02:01:24'),(1428,605,'1-03-03-00-000',NULL,1,'IVA soportado','Input VAT','asset','debit','both',0,0,0,0,1,NULL,0,'none',NULL,1,'2026-09-28 02:01:24','2026-09-28 02:01:24'),(1429,605,'1-03-03-00-001',NULL,1,'IVA soportado directo 13%','Direct input VAT 13%','asset','debit','local',1,0,0,0,1,NULL,0,'iva_soportado',NULL,1,'2026-09-28 02:01:24','2026-09-28 02:01:24'),(1430,605,'1-03-03-00-002',NULL,1,'IVA soportado directo 2%','Direct input VAT 2%','asset','debit','local',1,0,0,0,1,NULL,0,'iva_soportado',NULL,1,'2026-09-28 02:01:24','2026-09-28 02:01:24'),(1431,605,'1-03-03-00-003',NULL,1,'IVA soportado directo 1%','Direct input VAT 1%','asset','debit','local',1,0,0,0,1,NULL,0,'iva_soportado',NULL,1,'2026-09-28 02:01:24','2026-09-28 02:01:24'),(1432,605,'2-00-00-00-000',NULL,1,'Pasivos','Liabilities','liability','credit','both',0,0,0,0,1,NULL,0,'none',NULL,1,'2026-09-28 02:01:24','2026-09-28 02:01:24'),(1433,605,'2-01-00-00-000',NULL,1,'Pasivo circulante','Current liabilities','liability','credit','both',0,0,0,0,1,NULL,0,'none',NULL,1,'2026-09-28 02:01:24','2026-09-28 02:01:24'),(1434,605,'2-01-01-00-000',NULL,1,'Cuentas por pagar proveedores','Trade payables','liability','credit','both',0,0,0,0,1,NULL,0,'none',NULL,1,'2026-09-28 02:01:24','2026-09-28 02:01:24'),(1435,605,'2-01-01-00-001',NULL,1,'Servicios Contables','Servicios Contables','liability','credit','local',1,1,0,0,1,NULL,0,'none',NULL,1,'2026-09-28 02:01:24','2026-09-28 02:05:47'),(1436,605,'2-01-01-00-002',NULL,1,'Hermanos Marín Quirós ¢','Hermanos Marín Quirós CRC','liability','credit','local',1,1,0,0,1,NULL,0,'none',NULL,1,'2026-09-28 02:01:24','2026-09-28 02:01:24'),(1437,605,'2-01-01-00-003',NULL,1,'Imprenta Argentina ¢','Imprenta Argentina CRC','liability','credit','local',1,1,0,0,1,NULL,0,'none',NULL,1,'2026-09-28 02:01:24','2026-09-28 02:01:24'),(1438,605,'2-01-01-00-004',NULL,1,'Central de Mangueras S.A.','Central de Mangueras S.A.','liability','credit','local',1,1,0,0,1,NULL,0,'none',NULL,1,'2026-09-28 02:01:24','2026-09-28 02:01:24'),(1439,605,'2-01-02-00-000',NULL,1,'Otras cuentas por pagar','Other payables','liability','credit','both',0,0,0,0,1,NULL,0,'none',NULL,1,'2026-09-28 02:01:24','2026-09-28 02:01:24'),(1440,605,'2-01-02-00-001',NULL,1,'Comercial AJJK ¢','Comercial AJJK CRC','liability','credit','local',1,0,0,0,1,NULL,0,'none',NULL,1,'2026-09-28 02:01:24','2026-09-28 02:01:24'),(1441,605,'2-01-02-00-002',NULL,1,'Carlos Sequeira ¢','Carlos Sequeira CRC','liability','credit','local',1,0,0,0,1,NULL,0,'none',NULL,1,'2026-09-28 02:01:24','2026-09-28 02:01:24'),(1442,605,'2-01-02-00-003',NULL,1,'Airton Sequeira ¢','Airton Sequeira CRC','liability','credit','local',1,0,0,0,1,NULL,0,'none',NULL,1,'2026-09-28 02:01:24','2026-09-28 02:01:24'),(1443,605,'2-01-02-00-004',NULL,1,'Carrocerías Cachorro, SA','Carrocerías Cachorro, SA','liability','credit','local',1,0,0,0,1,NULL,0,'none',NULL,1,'2026-09-28 02:01:24','2026-09-28 02:01:24'),(1444,605,'2-01-02-00-005',NULL,1,'Extremos laborales (Pablo Ureña)','Severance payable (Pablo Ureña)','liability','credit','local',1,0,0,0,1,NULL,0,'none',NULL,1,'2026-09-28 02:01:24','2026-09-28 02:01:24'),(1445,605,'2-01-02-00-006',NULL,1,'BNCR crédito Isuzu CL-362866 $','BNCR Isuzu loan CL-362866 USD','liability','credit','foreign',1,0,0,0,1,NULL,0,'none',NULL,1,'2026-09-28 02:01:24','2026-09-28 02:01:24'),(1446,605,'2-01-03-00-000',NULL,1,'Cuentas por pagar financieras','Financial payables','liability','credit','both',0,0,0,0,1,NULL,0,'none',NULL,1,'2026-09-28 02:01:24','2026-09-28 02:01:24'),(1447,605,'2-01-03-00-001',NULL,1,'BAC San José \"tasa cero\"','BAC San José zero-rate plan','liability','credit','local',1,0,0,0,1,NULL,0,'none',NULL,1,'2026-09-28 02:01:24','2026-09-28 02:01:24'),(1448,605,'2-01-03-00-002',NULL,1,'Cuenta por pagar gestión empresarial','Business management payable','liability','credit','local',1,0,0,0,1,NULL,0,'none',NULL,1,'2026-09-28 02:01:24','2026-09-28 02:01:24'),(1449,605,'2-01-03-00-003',NULL,1,'BNCR T/C N° 7983','BNCR credit card 7983','liability','credit','local',1,0,0,0,1,NULL,0,'none',NULL,1,'2026-09-28 02:01:24','2026-09-28 02:01:24'),(1450,605,'2-01-03-00-004',NULL,1,'BNCR T/C N° 9495','BNCR credit card 9495','liability','credit','local',1,0,0,0,1,NULL,0,'none',NULL,1,'2026-09-28 02:01:24','2026-09-28 02:01:24'),(1451,605,'2-01-03-00-005',NULL,1,'BAC T/C N° 2264 / 3782','BAC credit card 2264 / 3782','liability','credit','local',1,0,0,0,1,NULL,0,'none',NULL,1,'2026-09-28 02:01:24','2026-09-28 02:01:24'),(1452,605,'2-01-03-00-006',NULL,1,'BAC T/C N° 1955','BAC credit card 1955','liability','credit','local',1,0,0,0,1,NULL,0,'none',NULL,1,'2026-09-28 02:01:24','2026-09-28 02:01:24'),(1453,605,'2-01-03-00-007',NULL,1,'BAC T/C N° 1232','BAC credit card 1232','liability','credit','local',1,0,0,0,1,NULL,0,'none',NULL,1,'2026-09-28 02:01:24','2026-09-28 02:01:24'),(1454,605,'2-01-03-00-008',NULL,1,'BCO Promerica T/C N° 6769','Promerica credit card 6769','liability','credit','local',1,0,0,0,1,NULL,0,'none',NULL,1,'2026-09-28 02:01:24','2026-09-28 02:01:24'),(1455,605,'2-01-03-00-009',NULL,1,'BAC Cta. 9717','BAC account 9717','liability','credit','local',1,0,0,0,1,NULL,0,'none',NULL,1,'2026-09-28 02:01:24','2026-09-28 02:01:24'),(1456,605,'2-01-03-00-010',NULL,1,'Scotiabank N° 9142','Scotiabank 9142','liability','credit','local',1,0,0,0,1,NULL,0,'none',NULL,1,'2026-09-28 02:01:24','2026-09-28 02:01:24'),(1457,605,'2-01-03-00-011',NULL,1,'Tarjetas Johan','Johan credit cards','liability','credit','local',1,0,0,0,1,NULL,0,'none',NULL,1,'2026-09-28 02:01:24','2026-09-28 02:01:24'),(1458,605,'2-02-00-00-000',NULL,1,'Otros pasivos','Other liabilities','liability','credit','both',0,0,0,0,1,NULL,0,'none',NULL,1,'2026-09-28 02:01:24','2026-09-28 02:01:24'),(1459,605,'2-02-01-00-000',NULL,1,'Impuestos','Taxes payable','liability','credit','both',0,0,0,0,1,NULL,0,'none',NULL,1,'2026-09-28 02:01:24','2026-09-28 02:01:24'),(1460,605,'2-02-01-00-001',NULL,1,'Retenciones en la fuente por pagar','Withholding taxes payable','liability','credit','local',1,0,0,0,1,NULL,0,'none',NULL,1,'2026-09-28 02:01:24','2026-09-28 02:01:24'),(1461,605,'2-02-01-00-002',NULL,1,'Impuesto sobre la renta por pagar','Income tax payable','liability','credit','local',1,0,0,0,1,NULL,0,'none',NULL,1,'2026-09-28 02:01:24','2026-09-28 02:01:24'),(1462,605,'2-02-01-00-003',NULL,1,'Impuesto de renta \"parciales\"','Income tax installments','liability','credit','local',1,0,0,0,1,NULL,0,'none',NULL,1,'2026-09-28 02:01:24','2026-09-28 02:01:24'),(1463,605,'2-02-01-00-004',NULL,1,'Impuesto de renta','Income tax','liability','credit','local',1,0,0,0,1,NULL,0,'none',NULL,1,'2026-09-28 02:01:24','2026-09-28 02:01:24'),(1464,605,'2-02-01-00-005',NULL,1,'Ministerio de Hacienda (III parcial por pagar)','Ministry of Finance (3rd installment payable)','liability','credit','local',1,0,0,0,1,NULL,0,'none',NULL,1,'2026-09-28 02:01:24','2026-09-28 02:01:24'),(1465,605,'2-02-02-00-000',NULL,1,'IVA devengado','Output VAT','liability','credit','both',0,0,0,0,1,NULL,0,'none',NULL,1,'2026-09-28 02:01:24','2026-09-28 02:01:24'),(1466,605,'2-02-02-00-001',NULL,1,'IVA devengado directo 13%','Direct output VAT 13%','liability','credit','local',1,0,0,0,1,NULL,0,'iva_devengado',NULL,1,'2026-09-28 02:01:25','2026-09-28 02:01:25'),(1467,605,'2-02-00-00-001',NULL,1,'IVA neto por pagar o a favor','Net VAT payable or receivable','liability','credit','local',1,0,0,0,1,NULL,0,'iva_general',NULL,1,'2026-09-28 02:01:25','2026-09-28 02:01:25'),(1468,605,'2-02-00-00-002',NULL,1,'IVA saldos a favor','VAT credit balances','liability','credit','local',1,0,0,0,1,NULL,0,'iva_general',NULL,1,'2026-09-28 02:01:25','2026-09-28 02:01:25'),(1469,605,'2-03-00-00-000',NULL,1,'Provisiones y contingencias','Provisions and contingencies','liability','credit','both',0,0,0,0,1,NULL,0,'none',NULL,1,'2026-09-28 02:01:25','2026-09-28 02:01:25'),(1470,605,'2-03-00-00-001',NULL,1,'Provisión de aguinaldos','Christmas bonus provision','liability','credit','local',1,0,0,0,1,NULL,0,'none',NULL,1,'2026-09-28 02:01:25','2026-09-28 02:01:25'),(1471,605,'2-04-00-00-000',NULL,1,'Gastos acumulados por pagar','Accrued expenses payable','liability','credit','both',0,0,0,0,1,NULL,0,'none',NULL,1,'2026-09-28 02:01:25','2026-09-28 02:01:25'),(1472,605,'2-04-00-00-001',NULL,1,'Salarios por pagar','Salaries payable','liability','credit','local',1,0,0,0,1,NULL,0,'none',NULL,1,'2026-09-28 02:01:25','2026-09-28 02:01:25'),(1473,605,'2-04-00-00-002',NULL,1,'Cargas sociales por pagar','Social security payable','liability','credit','local',1,0,0,0,1,NULL,0,'none',NULL,1,'2026-09-28 02:01:25','2026-09-28 02:01:25'),(1474,605,'3-00-00-00-000',NULL,1,'Patrimonio','Equity','equity','credit','both',0,0,0,0,1,NULL,0,'none',NULL,1,'2026-09-28 02:01:25','2026-09-28 02:01:25'),(1475,605,'3-00-00-00-001',NULL,1,'Capital social','Share capital','equity','credit','local',1,0,0,0,1,NULL,0,'none',NULL,1,'2026-09-28 02:01:25','2026-09-28 02:01:25'),(1476,605,'3-00-00-00-002',NULL,1,'Utilidad / pérdida neta','Net income / loss','equity','credit','local',1,0,0,0,1,NULL,0,'none',NULL,1,'2026-09-28 02:01:25','2026-09-28 02:01:25'),(1477,605,'3-00-00-00-003',NULL,1,'Utilidades / pérdidas acumuladas','Retained earnings / accumulated losses','equity','credit','local',1,0,0,0,1,NULL,0,'none',NULL,1,'2026-09-28 02:01:25','2026-09-28 02:01:25'),(1478,605,'3-00-00-00-004',NULL,1,'Reserva legal','Legal reserve','equity','credit','local',1,0,0,0,1,NULL,0,'none',NULL,1,'2026-09-28 02:01:25','2026-09-28 02:01:25'),(1479,605,'4-00-00-00-000',NULL,1,'Ingresos','Revenue','income','credit','both',0,0,0,0,1,NULL,0,'none',NULL,1,'2026-09-28 02:01:25','2026-09-28 02:01:25'),(1480,605,'4-01-00-00-000',NULL,1,'Servicio de transporte','Transportation services','income','credit','both',0,0,0,0,1,NULL,0,'none',NULL,1,'2026-09-28 02:01:25','2026-09-28 02:01:25'),(1481,605,'4-01-00-00-001',NULL,1,'Clientes diversos locales gravados','Local taxable customers','income','credit','both',1,0,0,0,1,NULL,0,'sales',NULL,1,'2026-09-28 02:01:25','2026-09-28 02:01:25'),(1482,605,'4-01-00-00-002',NULL,1,'Clientes exportación / zona franca','Export / free zone customers','income','credit','both',1,0,0,0,1,NULL,0,'sales',NULL,1,'2026-09-28 02:01:25','2026-09-28 02:01:25'),(1483,605,'4-02-00-00-000',NULL,1,'Otros ingresos','Other income','other_income','credit','both',0,0,0,0,1,NULL,0,'none',NULL,1,'2026-09-28 02:01:25','2026-09-28 02:01:25'),(1484,605,'4-02-01-00-000',NULL,1,'Intereses','Interest income','other_income','credit','both',0,0,0,0,1,NULL,0,'none',NULL,1,'2026-09-28 02:01:25','2026-09-28 02:01:25'),(1485,605,'4-02-01-00-001',NULL,1,'Intereses por préstamos a terceros','Interest on third-party loans','other_income','credit','both',1,0,0,0,1,NULL,0,'none',NULL,1,'2026-09-28 02:01:25','2026-09-28 02:01:25'),(1486,605,'4-02-01-00-002',NULL,1,'Intereses por inversiones','Interest on investments','other_income','credit','both',1,0,0,0,1,NULL,0,'none',NULL,1,'2026-09-28 02:01:25','2026-09-28 02:01:25'),(1487,605,'4-02-02-00-000',NULL,1,'Arrendamientos','Leases','other_income','credit','both',0,0,0,0,1,NULL,0,'none',NULL,1,'2026-09-28 02:01:25','2026-09-28 02:01:25'),(1488,605,'4-02-02-00-001',NULL,1,'Arrendamiento de maquinaria y equipo','Machinery and equipment leasing','other_income','credit','both',1,0,0,0,1,NULL,0,'sales',NULL,1,'2026-09-28 02:01:25','2026-09-28 02:01:25'),(1489,605,'5-00-00-00-000',NULL,1,'Gastos','Expenses','expense','debit','both',0,0,0,0,1,NULL,0,'none',NULL,1,'2026-09-28 02:01:25','2026-09-28 02:01:25'),(1490,605,'5-01-00-00-000',NULL,1,'Gastos de personal administrativo','Administrative personnel expenses','expense','debit','both',0,0,0,0,1,NULL,0,'none',NULL,1,'2026-09-28 02:01:25','2026-09-28 02:01:25'),(1491,605,'5-01-00-00-001',NULL,1,'Salarios','Salaries','expense','debit','both',1,0,0,0,1,NULL,0,'none',NULL,1,'2026-09-28 02:01:25','2026-09-28 02:01:25'),(1492,605,'5-01-00-00-002',NULL,1,'Cargas patronales','Employer social charges','expense','debit','both',1,0,0,0,1,NULL,0,'none',NULL,1,'2026-09-28 02:01:25','2026-09-28 02:01:25'),(1493,605,'5-01-00-00-003',NULL,1,'Aguinaldos','Christmas bonus','expense','debit','both',1,0,0,0,1,NULL,0,'none',NULL,1,'2026-09-28 02:01:25','2026-09-28 02:01:25'),(1494,605,'5-01-00-00-004',NULL,1,'Vacaciones','Vacations','expense','debit','both',1,0,0,0,1,NULL,0,'none',NULL,1,'2026-09-28 02:01:25','2026-09-28 02:01:25'),(1495,605,'5-01-00-00-005',NULL,1,'Prestaciones legales','Severance benefits','expense','debit','both',1,0,0,0,1,NULL,0,'none',NULL,1,'2026-09-28 02:01:25','2026-09-28 02:01:25'),(1496,605,'5-01-00-00-006',NULL,1,'Salud ocupacional / uniformes','Occupational health / uniforms','expense','debit','both',1,0,0,0,1,NULL,0,'purchases',NULL,1,'2026-09-28 02:01:25','2026-09-28 02:01:25'),(1497,605,'5-01-00-00-007',NULL,1,'Seguro riesgos del trabajo','Workers\' compensation insurance','expense','debit','both',1,0,0,0,1,NULL,0,'none',NULL,1,'2026-09-28 02:01:25','2026-09-28 02:01:25'),(1498,605,'5-02-00-00-000',NULL,1,'Gastos de personal operativo','Operating personnel expenses','expense','debit','both',0,0,0,0,1,NULL,0,'none',NULL,1,'2026-09-28 02:01:25','2026-09-28 02:01:25'),(1499,605,'5-02-00-00-001',NULL,1,'Salarios','Salaries','expense','debit','both',1,0,0,0,1,NULL,0,'none',NULL,1,'2026-09-28 02:01:25','2026-09-28 02:01:25'),(1500,605,'5-02-00-00-002',NULL,1,'Cargas patronales','Employer social charges','expense','debit','both',1,0,0,0,1,NULL,0,'none',NULL,1,'2026-09-28 02:01:25','2026-09-28 02:01:25'),(1501,605,'5-02-00-00-003',NULL,1,'Aguinaldos','Christmas bonus','expense','debit','both',1,0,0,0,1,NULL,0,'none',NULL,1,'2026-09-28 02:01:25','2026-09-28 02:01:25'),(1502,605,'5-02-00-00-004',NULL,1,'Vacaciones','Vacations','expense','debit','both',1,0,0,0,1,NULL,0,'none',NULL,1,'2026-09-28 02:01:25','2026-09-28 02:01:25'),(1503,605,'5-02-00-00-005',NULL,1,'Prestaciones legales','Severance benefits','expense','debit','both',1,0,0,0,1,NULL,0,'none',NULL,1,'2026-09-28 02:01:25','2026-09-28 02:01:25'),(1504,605,'5-02-00-00-006',NULL,1,'Salud ocupacional / uniformes','Occupational health / uniforms','expense','debit','both',1,0,0,0,1,NULL,0,'purchases',NULL,1,'2026-09-28 02:01:25','2026-09-28 02:01:25'),(1505,605,'5-02-00-00-007',NULL,1,'Seguro riesgos del trabajo INS','Workers\' compensation insurance INS','expense','debit','both',1,0,0,0,1,NULL,0,'none',NULL,1,'2026-09-28 02:01:25','2026-09-28 02:01:25'),(1506,605,'5-02-00-00-008',NULL,1,'Capacitaciones de personal','Staff training','expense','debit','both',1,0,0,0,1,NULL,0,'purchases',NULL,1,'2026-09-28 02:01:25','2026-09-28 02:01:25'),(1507,605,'5-02-00-00-009',NULL,1,'Gastos de botiquín / médicos','First aid / medical expenses','expense','debit','both',1,0,0,0,1,NULL,0,'purchases',NULL,1,'2026-09-28 02:01:25','2026-09-28 02:01:25'),(1508,605,'5-03-00-00-000',NULL,1,'Gastos en transporte','Transportation expenses','expense','debit','both',0,0,0,0,1,NULL,0,'none',NULL,1,'2026-09-28 02:01:25','2026-09-28 02:01:25'),(1509,605,'5-03-01-00-000',NULL,1,'Combustibles y peajes','Fuel and tolls','expense','debit','both',0,0,0,0,1,NULL,0,'none',NULL,1,'2026-09-28 02:01:25','2026-09-28 02:01:25'),(1510,605,'5-03-01-00-001',NULL,1,'Combustibles en territorio nacional','Fuel - domestic','expense','debit','both',1,0,0,0,1,NULL,0,'none',NULL,1,'2026-09-28 02:01:25','2026-09-28 02:01:25'),(1511,605,'5-03-01-00-002',NULL,1,'Combustibles en el exterior','Fuel - abroad','expense','debit','both',1,0,0,0,1,NULL,0,'none',NULL,1,'2026-09-28 02:01:25','2026-09-28 02:01:25'),(1512,605,'5-03-01-00-003',NULL,1,'Peajes','Tolls','expense','debit','both',1,0,0,0,1,NULL,0,'none',NULL,1,'2026-09-28 02:01:25','2026-09-28 02:01:25'),(1513,605,'5-03-01-00-004',NULL,1,'Pasajes y tiquetes','Fares and tickets','expense','debit','both',1,0,0,0,1,NULL,0,'none',NULL,1,'2026-09-28 02:01:25','2026-09-28 02:01:25'),(1514,605,'5-03-01-00-005',NULL,1,'Parqueos','Parking','expense','debit','both',1,0,0,0,1,NULL,0,'purchases',NULL,1,'2026-09-28 02:01:25','2026-09-28 02:01:25'),(1515,605,'5-03-02-00-000',NULL,1,'Mantenimiento de vehículos','Vehicle maintenance','expense','debit','both',0,0,0,0,1,NULL,0,'none',NULL,1,'2026-09-28 02:01:25','2026-09-28 02:01:25'),(1516,605,'5-03-02-00-001',NULL,1,'Cambios de aceite','Oil changes','expense','debit','both',1,0,0,0,1,NULL,0,'purchases',NULL,1,'2026-09-28 02:01:25','2026-09-28 02:01:25'),(1517,605,'5-03-02-00-002',NULL,1,'Repuestos e insumos','Spare parts and supplies','expense','debit','both',1,0,0,0,1,NULL,0,'purchases',NULL,1,'2026-09-28 02:01:25','2026-09-28 02:01:25'),(1518,605,'5-03-02-00-003',NULL,1,'Reparaciones \"mano de obra\"','Repairs - labor','expense','debit','both',1,0,0,0,1,NULL,0,'purchases',NULL,1,'2026-09-28 02:01:25','2026-09-28 02:01:25'),(1519,605,'5-03-02-00-004',NULL,1,'Revisión técnica vehicular RTV','Vehicle technical inspection RTV','expense','debit','both',1,0,0,0,1,NULL,0,'purchases',NULL,1,'2026-09-28 02:01:25','2026-09-28 02:01:25'),(1520,605,'5-03-02-00-005',NULL,1,'Repuestos e insumos \"Panamá\"','Spare parts and supplies - Panama','expense','debit','both',1,0,0,0,1,NULL,0,'none',NULL,1,'2026-09-28 02:01:25','2026-09-28 02:01:25'),(1521,605,'5-03-00-00-001',NULL,1,'Arrendamiento de camiones','Truck leasing','expense','debit','both',1,0,0,0,1,NULL,0,'purchases',NULL,1,'2026-09-28 02:01:25','2026-09-28 02:01:25'),(1522,605,'5-03-00-00-002',NULL,1,'Gastos de embalaje','Packaging expenses','expense','debit','both',1,0,0,0,1,NULL,0,'purchases',NULL,1,'2026-09-28 02:01:25','2026-09-28 02:01:25'),(1523,605,'5-03-00-00-003',NULL,1,'Servicio de transporte \"subcontrato\"','Subcontracted transportation services','expense','debit','both',1,0,0,0,1,NULL,0,'purchases',NULL,1,'2026-09-28 02:01:25','2026-09-28 02:01:25'),(1524,605,'5-03-00-00-004',NULL,1,'Fletes y encomiendas','Freight and parcels','expense','debit','both',1,0,0,0,1,NULL,0,'purchases',NULL,1,'2026-09-28 02:01:25','2026-09-28 02:01:25'),(1525,605,'5-04-00-00-000',NULL,1,'Seguros','Insurance','expense','debit','both',0,0,0,0,1,NULL,0,'none',NULL,1,'2026-09-28 02:01:25','2026-09-28 02:01:25'),(1526,605,'5-04-00-00-001',NULL,1,'Seguro de vehículos','Vehicle insurance','expense','debit','both',1,0,0,0,1,NULL,0,'none',NULL,1,'2026-09-28 02:01:25','2026-09-28 02:01:25'),(1527,605,'5-04-00-00-002',NULL,1,'Seguro de carga','Cargo insurance','expense','debit','both',1,0,0,0,1,NULL,0,'none',NULL,1,'2026-09-28 02:01:25','2026-09-28 02:01:25'),(1528,605,'5-04-00-00-003',NULL,1,'Derechos de circulación','Vehicle circulation fees','expense','debit','both',1,0,0,0,1,NULL,0,'none',NULL,1,'2026-09-28 02:01:25','2026-09-28 02:01:25'),(1529,605,'5-05-00-00-000',NULL,1,'Depreciaciones','Depreciation','expense','debit','both',0,0,0,0,1,NULL,0,'none',NULL,1,'2026-09-28 02:01:25','2026-09-28 02:01:25'),(1530,605,'5-05-00-00-001',NULL,1,'Depreciación de vehículos','Depreciation - vehicles','expense','debit','both',1,0,0,0,1,NULL,0,'none',NULL,1,'2026-09-28 02:01:25','2026-09-28 02:01:25'),(1531,605,'5-05-00-00-002',NULL,1,'Depreciación de equipos','Depreciation - equipment','expense','debit','both',1,0,0,0,1,NULL,0,'none',NULL,1,'2026-09-28 02:01:25','2026-09-28 02:01:25'),(1532,605,'5-06-00-00-000',NULL,1,'Gastos de representación','Representation expenses','expense','debit','both',0,0,0,0,1,NULL,0,'none',NULL,1,'2026-09-28 02:01:25','2026-09-28 02:01:25'),(1533,605,'5-06-00-00-001',NULL,1,'Viáticos locales gravados','Local per diem - taxable','expense','debit','both',1,0,0,0,1,NULL,0,'purchases',NULL,1,'2026-09-28 02:01:25','2026-09-28 02:01:25'),(1534,605,'5-06-00-00-002',NULL,1,'Viáticos locales régimen simplificado','Local per diem - simplified regime','expense','debit','both',1,0,0,0,1,NULL,0,'none',NULL,1,'2026-09-28 02:01:25','2026-09-28 02:01:25'),(1535,605,'5-06-00-00-003',NULL,1,'Viáticos en el exterior','Per diem abroad','expense','debit','both',1,0,0,0,1,NULL,0,'none',NULL,1,'2026-09-28 02:01:25','2026-09-28 02:01:25'),(1536,605,'5-06-00-00-004',NULL,1,'Gastos de hospedaje local','Local lodging','expense','debit','both',1,0,0,0,1,NULL,0,'purchases',NULL,1,'2026-09-28 02:01:25','2026-09-28 02:01:25'),(1537,605,'5-06-00-00-005',NULL,1,'Gastos de hospedaje exterior','Lodging abroad','expense','debit','both',1,0,0,0,1,NULL,0,'none',NULL,1,'2026-09-28 02:01:25','2026-09-28 02:01:25'),(1538,605,'5-06-00-00-006',NULL,1,'Gastos en comunicación','Communication expenses','expense','debit','both',1,0,0,0,1,NULL,0,'purchases',NULL,1,'2026-09-28 02:01:25','2026-09-28 02:01:25'),(1539,605,'5-07-00-00-000',NULL,1,'Servicios públicos','Utilities','expense','debit','both',0,0,0,0,1,NULL,0,'none',NULL,1,'2026-09-28 02:01:25','2026-09-28 02:01:25'),(1540,605,'5-07-00-00-001',NULL,1,'Electricidad','Electricity','expense','debit','both',1,0,0,0,1,NULL,0,'purchases',NULL,1,'2026-09-28 02:01:25','2026-09-28 02:01:25'),(1541,605,'5-07-00-00-002',NULL,1,'Agua potable','Water','expense','debit','both',1,0,0,0,1,NULL,0,'purchases',NULL,1,'2026-09-28 02:01:25','2026-09-28 02:01:25'),(1542,605,'5-07-00-00-003',NULL,1,'Teléfono','Telephone','expense','debit','both',1,0,0,0,1,NULL,0,'purchases',NULL,1,'2026-09-28 02:01:25','2026-09-28 02:01:25'),(1543,605,'5-07-00-00-004',NULL,1,'Suscripciones (internet, facturación, etc.)','Subscriptions (internet, invoicing, etc.)','expense','debit','both',1,0,0,0,1,NULL,0,'purchases',NULL,1,'2026-09-28 02:01:25','2026-09-28 02:01:25'),(1544,605,'5-07-00-00-005',NULL,1,'Servicios municipales','Municipal services','expense','debit','both',1,0,0,0,1,NULL,0,'none',NULL,1,'2026-09-28 02:01:25','2026-09-28 02:01:25'),(1545,605,'5-08-00-00-000',NULL,1,'Gastos de oficina','Office expenses','expense','debit','both',0,0,0,0,1,NULL,0,'none',NULL,1,'2026-09-28 02:01:25','2026-09-28 02:01:25'),(1546,605,'5-08-00-00-001',NULL,1,'Alquiler de oficinas Paso Canoas','Office rent Paso Canoas','expense','debit','both',1,0,0,0,1,NULL,0,'purchases',NULL,1,'2026-09-28 02:01:25','2026-09-28 02:01:25'),(1547,605,'5-08-00-00-002',NULL,1,'Alquiler de bodega','Warehouse rent','expense','debit','both',1,0,0,0,1,NULL,0,'purchases',NULL,1,'2026-09-28 02:01:25','2026-09-28 02:01:25'),(1548,605,'5-08-00-00-003',NULL,1,'Mantenimiento y reparación de instalaciones','Facilities maintenance and repair','expense','debit','both',1,0,0,0,1,NULL,0,'purchases',NULL,1,'2026-09-28 02:01:25','2026-09-28 02:01:25'),(1549,605,'5-08-00-00-004',NULL,1,'Suministros e insumos de limpieza e higiene','Cleaning and hygiene supplies','expense','debit','both',1,0,0,0,1,NULL,0,'purchases',NULL,1,'2026-09-28 02:01:25','2026-09-28 02:01:25'),(1550,605,'5-08-00-00-005',NULL,1,'Papelería y útiles de oficina','Stationery and office supplies','expense','debit','both',1,0,0,0,1,NULL,0,'purchases',NULL,1,'2026-09-28 02:01:25','2026-09-28 02:01:25'),(1551,605,'5-08-00-00-006',NULL,1,'Mantenimiento de mobiliario y equipo','Furniture and equipment maintenance','expense','debit','both',1,0,0,0,1,NULL,0,'purchases',NULL,1,'2026-09-28 02:01:25','2026-09-28 02:01:25'),(1552,605,'5-08-00-00-007',NULL,1,'Equipos y herramientas menores','Minor equipment and tools','expense','debit','both',1,0,0,0,1,NULL,0,'purchases',NULL,1,'2026-09-28 02:01:25','2026-09-28 02:01:25'),(1553,605,'5-08-00-00-008',NULL,1,'Donaciones','Donations','expense','debit','both',1,0,0,0,1,NULL,0,'none',NULL,1,'2026-09-28 02:01:25','2026-09-28 02:01:25'),(1554,605,'5-09-00-00-000',NULL,1,'Impuestos y trámites aduanales','Taxes and customs procedures','expense','debit','both',0,0,0,0,1,NULL,0,'none',NULL,1,'2026-09-28 02:01:25','2026-09-28 02:01:25'),(1555,605,'5-09-00-00-001',NULL,1,'Impuesto timbre de Educación y Cultura','Education and Culture stamp tax','expense','debit','both',1,0,0,0,1,NULL,0,'none',NULL,1,'2026-09-28 02:01:25','2026-09-28 02:01:25'),(1556,605,'5-09-00-00-002',NULL,1,'Impuestos y trámites aduanales CR','Taxes and customs procedures CR','expense','debit','both',1,0,0,0,1,NULL,0,'none',NULL,1,'2026-09-28 02:01:25','2026-09-28 02:01:25'),(1557,605,'5-09-00-00-003',NULL,1,'Impuestos y trámites aduanales Panamá','Taxes and customs procedures Panama','expense','debit','both',1,0,0,0,1,NULL,0,'none',NULL,1,'2026-09-28 02:01:25','2026-09-28 02:01:25'),(1558,605,'5-09-00-00-004',NULL,1,'Patente municipal','Municipal business license','expense','debit','both',1,0,0,0,1,NULL,0,'none',NULL,1,'2026-09-28 02:01:25','2026-09-28 02:01:25'),(1559,605,'5-09-00-00-005',NULL,1,'Impuesto de bienes inmuebles','Property tax','expense','debit','both',1,0,0,0,1,NULL,0,'none',NULL,1,'2026-09-28 02:01:25','2026-09-28 02:01:25'),(1560,605,'5-09-00-00-006',NULL,1,'Impuesto a las personas jurídicas','Corporate entity tax','expense','debit','both',1,0,0,0,1,NULL,0,'none',NULL,1,'2026-09-28 02:01:25','2026-09-28 02:01:25'),(1561,605,'5-10-00-00-000',NULL,1,'Servicios profesionales','Professional services','expense','debit','both',0,0,0,0,1,NULL,0,'none',NULL,1,'2026-09-28 02:01:25','2026-09-28 02:01:25'),(1562,605,'5-10-00-00-001',NULL,1,'A&B Technology Solutions SA','A&B Technology Solutions SA','expense','debit','both',1,0,0,0,1,NULL,0,'purchases',NULL,1,'2026-09-28 02:01:25','2026-09-28 02:01:25'),(1563,605,'5-10-00-00-002',NULL,1,'Contabilidad','Accounting','expense','debit','both',1,0,0,0,1,NULL,0,'purchases',NULL,1,'2026-09-28 02:01:25','2026-09-28 02:01:25'),(1564,605,'5-10-00-00-003',NULL,1,'Mario Alonso Arias Agüero (abogado)','Mario Alonso Arias Agüero (lawyer)','expense','debit','both',1,0,0,0,1,NULL,0,'purchases',NULL,1,'2026-09-28 02:01:25','2026-09-28 02:01:25'),(1565,605,'5-10-00-00-004',NULL,1,'Servicio logístico indirecto','Indirect logistics services','expense','debit','both',1,0,0,0,1,NULL,0,'purchases',NULL,1,'2026-09-28 02:01:25','2026-09-28 02:01:25'),(1566,605,'5-11-00-00-000',NULL,1,'Gastos financieros','Financial expenses','other_expense','debit','both',0,0,0,0,1,NULL,0,'none',NULL,1,'2026-09-28 02:01:25','2026-09-28 02:01:25'),(1567,605,'5-11-00-00-001',NULL,1,'Intereses comerciales','Commercial interest','other_expense','debit','both',1,0,0,0,1,NULL,0,'none',NULL,1,'2026-09-28 02:01:25','2026-09-28 02:01:25'),(1568,605,'5-11-00-00-002',NULL,1,'Servicios bancarios','Bank charges','other_expense','debit','both',1,0,0,0,1,NULL,0,'purchases',NULL,1,'2026-09-28 02:01:25','2026-09-28 02:01:25'),(1569,605,'5-11-00-00-003',NULL,1,'Diferencial cambiario realizado - cuentas líquidas','Realized FX difference - liquid accounts','other_expense','debit','both',1,0,0,0,1,NULL,0,'none',NULL,1,'2026-09-28 02:01:25','2026-09-28 02:01:25'),(1570,605,'5-11-00-00-004',NULL,1,'Diferencial cambiario realizado - cobros / pagos','Realized FX difference - collections / payments','other_expense','debit','both',1,0,0,0,1,NULL,0,'none',NULL,1,'2026-09-28 02:01:25','2026-09-28 02:01:25');
/*!40000 ALTER TABLE `chart_of_accounts` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `commercial_follow_ups`
--

DROP TABLE IF EXISTS `commercial_follow_ups`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `commercial_follow_ups` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `commercial_profile_id` bigint unsigned NOT NULL,
  `next_action_date` date NOT NULL,
  `action_type` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'ej. "llamar antes de renovar", "confirmar aumento de cupo"',
  `status` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'pending' COMMENT 'pending|completed',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `commercial_follow_ups_commercial_profile_id_status_index` (`commercial_profile_id`,`status`),
  CONSTRAINT `commercial_follow_ups_commercial_profile_id_foreign` FOREIGN KEY (`commercial_profile_id`) REFERENCES `commercial_profiles` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=4 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `commercial_follow_ups`
--

LOCK TABLES `commercial_follow_ups` WRITE;
/*!40000 ALTER TABLE `commercial_follow_ups` DISABLE KEYS */;
/*!40000 ALTER TABLE `commercial_follow_ups` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `commercial_interactions`
--

DROP TABLE IF EXISTS `commercial_interactions`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `commercial_interactions` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `commercial_profile_id` bigint unsigned NOT NULL,
  `type` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'call|email|meeting|whatsapp|other',
  `occurred_at` date NOT NULL,
  `author_id` bigint unsigned DEFAULT NULL,
  `summary` text COLLATE utf8mb4_unicode_ci NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `commercial_interactions_commercial_profile_id_occurred_at_index` (`commercial_profile_id`,`occurred_at`),
  KEY `commercial_interactions_author_id_new_foreign` (`author_id`),
  CONSTRAINT `commercial_interactions_author_id_new_foreign` FOREIGN KEY (`author_id`) REFERENCES `propietarios` (`id`) ON DELETE SET NULL,
  CONSTRAINT `commercial_interactions_commercial_profile_id_foreign` FOREIGN KEY (`commercial_profile_id`) REFERENCES `commercial_profiles` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `commercial_interactions`
--

LOCK TABLES `commercial_interactions` WRITE;
/*!40000 ALTER TABLE `commercial_interactions` DISABLE KEYS */;
/*!40000 ALTER TABLE `commercial_interactions` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `commercial_profiles`
--

DROP TABLE IF EXISTS `commercial_profiles`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `commercial_profiles` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `license_id` bigint unsigned NOT NULL,
  `contact_name` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `phone` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `commercial_email` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'puede diferir del correo de acceso al sistema',
  `referral_source` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'referido, campaña, prospección directa...',
  `notes` text COLLATE utf8mb4_unicode_ci,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `commercial_profiles_license_id_unique` (`license_id`),
  CONSTRAINT `commercial_profiles_license_id_foreign` FOREIGN KEY (`license_id`) REFERENCES `licenses` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=2 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `commercial_profiles`
--

LOCK TABLES `commercial_profiles` WRITE;
/*!40000 ALTER TABLE `commercial_profiles` DISABLE KEYS */;
/*!40000 ALTER TABLE `commercial_profiles` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `companies`
--

DROP TABLE IF EXISTS `companies`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `companies` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `license_id` bigint unsigned DEFAULT NULL,
  `legal_name` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `trade_name` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `tax_id` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'cédula jurídica',
  `address` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `country_code` varchar(2) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'CR',
  `local_currency_id` bigint unsigned DEFAULT NULL,
  `foreign_currency_id` bigint unsigned DEFAULT NULL,
  `system_currency_id` bigint unsigned DEFAULT NULL,
  `timezone` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'America/Costa_Rica',
  `logo_path` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `theme` varchar(20) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `status` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'active' COMMENT 'active|suspended',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `companies_local_currency_id_foreign` (`local_currency_id`),
  KEY `companies_foreign_currency_id_foreign` (`foreign_currency_id`),
  KEY `companies_system_currency_id_foreign` (`system_currency_id`),
  KEY `companies_license_id_foreign` (`license_id`),
  CONSTRAINT `companies_foreign_currency_id_foreign` FOREIGN KEY (`foreign_currency_id`) REFERENCES `currencies` (`id`),
  CONSTRAINT `companies_license_id_foreign` FOREIGN KEY (`license_id`) REFERENCES `licenses` (`id`) ON DELETE SET NULL,
  CONSTRAINT `companies_local_currency_id_foreign` FOREIGN KEY (`local_currency_id`) REFERENCES `currencies` (`id`),
  CONSTRAINT `companies_system_currency_id_foreign` FOREIGN KEY (`system_currency_id`) REFERENCES `currencies` (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=606 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `companies`
--

LOCK TABLES `companies` WRITE;
/*!40000 ALTER TABLE `companies` DISABLE KEYS */;
INSERT INTO `companies` VALUES (599,39,'Servicios Integrales Empresariales','SIE','999999999',NULL,'CR',949,950,950,'America/Costa_Rica',NULL,NULL,'active','2026-09-02 02:20:32','2026-09-02 02:20:32'),(600,39,'ASOCIACION SOLIDARISTA EMPLEADOS DE RESUSA, S.A.','ASERESUSA','3002540244',NULL,'CR',949,950,950,'America/Costa_Rica',NULL,NULL,'active','2026-09-02 02:34:14','2026-09-02 02:34:14'),(601,NULL,'Stiedemann-Bergstrom S.A.','Weber LLC','1-648-879031','9518 Considine Street\nPort Afton, MD 12708-9219','CR',949,950,950,'America/Costa_Rica',NULL,NULL,'active','2026-09-10 21:11:16','2026-09-10 21:11:16'),(602,NULL,'Nitzsche, Schmitt and Bartell S.A.','Schuppe-Keebler','8-822-870447','362 Edwardo Brooks Apt. 052\nNew Jamie, MA 44276','CR',949,950,950,'America/Costa_Rica',NULL,NULL,'active','2026-09-10 21:11:17','2026-09-10 21:11:17'),(603,40,'NCODE','NCODE','117778899',NULL,'CR',949,950,950,'America/Costa_Rica',NULL,NULL,'active','2026-09-18 02:18:02','2026-09-18 02:18:02'),(604,40,'kuvo pruebas','kuvo','3333333333',NULL,'CR',949,950,950,'America/Costa_Rica',NULL,NULL,'active','2026-09-18 02:23:21','2026-09-18 02:23:21'),(605,39,'Transportes Senna S.A.','Senna','3-101-247369',NULL,'CR',949,950,950,'America/Costa_Rica',NULL,'onix','active','2026-09-27 21:08:01','2026-10-01 01:09:05');
/*!40000 ALTER TABLE `companies` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `company_economic_activities`
--

DROP TABLE IF EXISTS `company_economic_activities`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `company_economic_activities` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `company_id` bigint unsigned NOT NULL,
  `code` varchar(6) COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'código de actividad económica de Hacienda',
  `name` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `revenue_account_id` bigint unsigned DEFAULT NULL,
  `is_default` tinyint(1) NOT NULL DEFAULT '0',
  `status` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'active' COMMENT 'active|inactive',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `company_economic_activities_company_id_code_unique` (`company_id`,`code`),
  KEY `company_economic_activities_revenue_account_id_foreign` (`revenue_account_id`),
  CONSTRAINT `company_economic_activities_company_id_foreign` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`) ON DELETE CASCADE,
  CONSTRAINT `company_economic_activities_revenue_account_id_foreign` FOREIGN KEY (`revenue_account_id`) REFERENCES `chart_of_accounts` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `company_economic_activities`
--

LOCK TABLES `company_economic_activities` WRITE;
/*!40000 ALTER TABLE `company_economic_activities` DISABLE KEYS */;
/*!40000 ALTER TABLE `company_economic_activities` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `company_modules`
--

DROP TABLE IF EXISTS `company_modules`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `company_modules` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `company_id` bigint unsigned NOT NULL,
  `module_id` bigint unsigned NOT NULL,
  `enabled` tinyint(1) NOT NULL DEFAULT '1',
  `enabled_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `company_modules_company_id_module_id_unique` (`company_id`,`module_id`),
  KEY `company_modules_module_id_foreign` (`module_id`),
  CONSTRAINT `company_modules_company_id_foreign` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`) ON DELETE CASCADE,
  CONSTRAINT `company_modules_module_id_foreign` FOREIGN KEY (`module_id`) REFERENCES `modules` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `company_modules`
--

LOCK TABLES `company_modules` WRITE;
/*!40000 ALTER TABLE `company_modules` DISABLE KEYS */;
/*!40000 ALTER TABLE `company_modules` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `company_user`
--

DROP TABLE IF EXISTS `company_user`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `company_user` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `company_id` bigint unsigned NOT NULL,
  `user_id` bigint unsigned NOT NULL,
  `is_default` tinyint(1) NOT NULL DEFAULT '0',
  `status` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'active',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `company_user_company_id_user_id_unique` (`company_id`,`user_id`),
  KEY `company_user_user_id_foreign` (`user_id`),
  CONSTRAINT `company_user_company_id_foreign` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`) ON DELETE CASCADE,
  CONSTRAINT `company_user_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=347 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `company_user`
--

LOCK TABLES `company_user` WRITE;
/*!40000 ALTER TABLE `company_user` DISABLE KEYS */;
INSERT INTO `company_user` VALUES (337,599,355,1,'active','2026-09-02 02:20:33','2026-09-02 02:20:33'),(338,600,355,0,'active','2026-09-02 02:34:14','2026-09-02 02:34:14'),(339,600,356,1,'active','2026-09-02 04:09:59','2026-09-02 04:09:59'),(340,600,357,1,'active','2026-09-02 04:12:15','2026-09-02 04:12:15'),(341,603,358,1,'active','2026-09-18 02:18:03','2026-09-18 02:18:03'),(342,603,356,0,'active','2026-09-18 02:21:59','2026-09-18 02:21:59'),(343,604,358,0,'active','2026-09-18 02:23:21','2026-09-18 02:23:21'),(344,604,356,0,'active','2026-09-18 02:24:33','2026-09-18 02:24:33'),(345,604,359,1,'active','2026-09-18 02:51:59','2026-09-18 02:51:59'),(346,605,355,0,'active','2026-09-27 21:08:01','2026-09-27 21:08:01');
/*!40000 ALTER TABLE `company_user` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `cost_allocation_rule_lines`
--

DROP TABLE IF EXISTS `cost_allocation_rule_lines`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `cost_allocation_rule_lines` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `cost_allocation_rule_id` bigint unsigned NOT NULL,
  `cost_center_id` bigint unsigned NOT NULL,
  `percentage` decimal(5,2) NOT NULL,
  `position` smallint unsigned NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `cost_allocation_rule_lines_rule_center_unique` (`cost_allocation_rule_id`,`cost_center_id`),
  KEY `cost_allocation_rule_lines_cost_center_id_foreign` (`cost_center_id`),
  CONSTRAINT `cost_allocation_rule_lines_cost_allocation_rule_id_foreign` FOREIGN KEY (`cost_allocation_rule_id`) REFERENCES `cost_allocation_rules` (`id`) ON DELETE CASCADE,
  CONSTRAINT `cost_allocation_rule_lines_cost_center_id_foreign` FOREIGN KEY (`cost_center_id`) REFERENCES `cost_centers` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB AUTO_INCREMENT=72 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `cost_allocation_rule_lines`
--

LOCK TABLES `cost_allocation_rule_lines` WRITE;
/*!40000 ALTER TABLE `cost_allocation_rule_lines` DISABLE KEYS */;
INSERT INTO `cost_allocation_rule_lines` VALUES (63,20,45,100.00,1,'2026-09-18 04:02:31','2026-09-18 04:02:31'),(64,21,46,100.00,1,'2026-09-18 04:02:31','2026-09-18 04:02:31'),(65,22,47,100.00,1,'2026-09-18 04:02:31','2026-09-18 04:02:31'),(66,23,45,40.00,1,'2026-09-18 04:02:31','2026-09-18 04:02:31'),(67,23,46,30.00,2,'2026-09-18 04:02:32','2026-09-18 04:02:32'),(68,23,47,30.00,3,'2026-09-18 04:02:32','2026-09-18 04:02:32'),(69,24,46,50.00,1,'2026-09-18 04:02:32','2026-09-18 04:02:32'),(70,24,47,50.00,2,'2026-09-18 04:02:32','2026-09-18 04:02:32'),(71,25,45,100.00,1,'2026-09-18 04:02:32','2026-09-18 04:02:32');
/*!40000 ALTER TABLE `cost_allocation_rule_lines` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `cost_allocation_rules`
--

DROP TABLE IF EXISTS `cost_allocation_rules`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `cost_allocation_rules` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `company_id` bigint unsigned NOT NULL,
  `code` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `name` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `valid_from` date NOT NULL,
  `valid_until` date DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT '1',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `cost_allocation_rules_company_id_code_unique` (`company_id`,`code`),
  CONSTRAINT `cost_allocation_rules_company_id_foreign` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=26 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `cost_allocation_rules`
--

LOCK TABLES `cost_allocation_rules` WRITE;
/*!40000 ALTER TABLE `cost_allocation_rules` DISABLE KEYS */;
INSERT INTO `cost_allocation_rules` VALUES (20,604,'ADM100','100% Administración','2026-01-01',NULL,1,'2026-09-18 03:23:42','2026-09-18 03:23:42'),(21,604,'VEN100','100% Ventas','2026-01-01',NULL,1,'2026-09-18 03:23:42','2026-09-18 03:23:42'),(22,604,'OPE100','100% Operaciones','2026-01-01',NULL,1,'2026-09-18 03:23:42','2026-09-18 03:23:42'),(23,604,'GRAL','Reparto general 40/30/30','2026-01-01',NULL,1,'2026-09-18 03:23:42','2026-09-18 03:23:42'),(24,604,'MITAD','Mitad ventas / mitad operaciones','2026-01-01',NULL,1,'2026-09-18 03:23:42','2026-09-18 03:23:42'),(25,604,'VENC','Norma vencida (prueba)','2026-01-01','2026-06-30',1,'2026-09-18 03:23:42','2026-09-18 03:23:42');
/*!40000 ALTER TABLE `cost_allocation_rules` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `cost_centers`
--

DROP TABLE IF EXISTS `cost_centers`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `cost_centers` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `company_id` bigint unsigned NOT NULL,
  `code` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `name` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `start_date` date NOT NULL COMMENT 'vigente desde',
  `end_date` date DEFAULT NULL COMMENT 'vigente hasta; null = sin fecha de fin definida',
  `is_active` tinyint(1) NOT NULL DEFAULT '1',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `cost_centers_company_id_code_unique` (`company_id`,`code`),
  CONSTRAINT `cost_centers_company_id_foreign` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=50 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `cost_centers`
--

LOCK TABLES `cost_centers` WRITE;
/*!40000 ALTER TABLE `cost_centers` DISABLE KEYS */;
INSERT INTO `cost_centers` VALUES (45,604,'ADM','Administración','2026-01-01',NULL,1,'2026-09-18 03:23:42','2026-09-18 03:23:42'),(46,604,'VEN','Ventas y mercadeo','2026-01-01',NULL,1,'2026-09-18 03:23:42','2026-09-18 03:23:42'),(47,604,'OPE','Operaciones','2026-01-01',NULL,1,'2026-09-18 03:23:42','2026-09-18 03:23:42'),(48,604,'PRY','Proyectos especiales','2026-01-01',NULL,1,'2026-09-18 03:23:42','2026-09-18 03:23:42'),(49,604,'TMP','Proyecto temporal (cerrado)','2026-01-01','2026-06-30',1,'2026-09-18 03:23:42','2026-09-18 03:23:42');
/*!40000 ALTER TABLE `cost_centers` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `currencies`
--

DROP TABLE IF EXISTS `currencies`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `currencies` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `code` varchar(3) COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'ISO 4217: CRC, USD...',
  `name` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `symbol` varchar(5) COLLATE utf8mb4_unicode_ci NOT NULL,
  `decimal_places` tinyint unsigned NOT NULL DEFAULT '2',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `currencies_code_unique` (`code`)
) ENGINE=InnoDB AUTO_INCREMENT=951 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `currencies`
--

LOCK TABLES `currencies` WRITE;
/*!40000 ALTER TABLE `currencies` DISABLE KEYS */;
INSERT INTO `currencies` VALUES (949,'CRC','Colón costarricense','₡',2,'2026-09-01 01:38:20','2026-09-01 01:38:20'),(950,'USD','Dólar estadounidense','$',2,'2026-09-01 01:38:20','2026-09-01 01:38:20');
/*!40000 ALTER TABLE `currencies` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `departments`
--

DROP TABLE IF EXISTS `departments`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `departments` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `company_id` bigint unsigned NOT NULL,
  `code` varchar(20) COLLATE utf8mb4_unicode_ci NOT NULL,
  `name` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `cost_center_id` bigint unsigned DEFAULT NULL,
  `status` varchar(20) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'active',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `departments_company_id_code_unique` (`company_id`,`code`),
  KEY `departments_cost_center_id_foreign` (`cost_center_id`),
  KEY `departments_company_id_status_index` (`company_id`,`status`),
  CONSTRAINT `departments_company_id_foreign` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`) ON DELETE CASCADE,
  CONSTRAINT `departments_cost_center_id_foreign` FOREIGN KEY (`cost_center_id`) REFERENCES `cost_centers` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `departments`
--

LOCK TABLES `departments` WRITE;
/*!40000 ALTER TABLE `departments` DISABLE KEYS */;
/*!40000 ALTER TABLE `departments` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `document_type_number_series`
--

DROP TABLE IF EXISTS `document_type_number_series`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `document_type_number_series` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `company_id` bigint unsigned NOT NULL,
  `document_type_id` bigint unsigned NOT NULL,
  `name` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'ej. "Serie A", "Caja principal"',
  `holder_name` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'a quién se le entregó esta serie/talonario, ej. "Pedro Jiménez" — para identificar quién cobró',
  `range_from` bigint unsigned NOT NULL,
  `range_to` bigint unsigned NOT NULL,
  `next_number` bigint unsigned NOT NULL COMMENT 'siguiente número a asignar dentro del rango',
  `is_active` tinyint(1) NOT NULL DEFAULT '1',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `doc_type_number_series_name_unique` (`company_id`,`document_type_id`,`name`),
  KEY `document_type_number_series_document_type_id_foreign` (`document_type_id`),
  CONSTRAINT `document_type_number_series_company_id_foreign` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`) ON DELETE CASCADE,
  CONSTRAINT `document_type_number_series_document_type_id_foreign` FOREIGN KEY (`document_type_id`) REFERENCES `document_types` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=22 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `document_type_number_series`
--

LOCK TABLES `document_type_number_series` WRITE;
/*!40000 ALTER TABLE `document_type_number_series` DISABLE KEYS */;
/*!40000 ALTER TABLE `document_type_number_series` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `document_type_officers`
--

DROP TABLE IF EXISTS `document_type_officers`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `document_type_officers` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `document_type_id` bigint unsigned NOT NULL,
  `officer_role` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'elaborated_by|reviewed_by|approved_by',
  `required` tinyint(1) NOT NULL DEFAULT '0',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `document_type_officers_document_type_id_foreign` (`document_type_id`),
  CONSTRAINT `document_type_officers_document_type_id_foreign` FOREIGN KEY (`document_type_id`) REFERENCES `document_types` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `document_type_officers`
--

LOCK TABLES `document_type_officers` WRITE;
/*!40000 ALTER TABLE `document_type_officers` DISABLE KEYS */;
/*!40000 ALTER TABLE `document_type_officers` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `document_type_permissions`
--

DROP TABLE IF EXISTS `document_type_permissions`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `document_type_permissions` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `document_type_id` bigint unsigned NOT NULL,
  `subject_type` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'user|role',
  `subject_id` bigint unsigned NOT NULL,
  `scope` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'individual' COMMENT 'individual|group',
  `can_create` tinyint(1) NOT NULL DEFAULT '0',
  `can_modify` tinyint(1) NOT NULL DEFAULT '0',
  `can_delete` tinyint(1) NOT NULL DEFAULT '0' COMMENT 'UI lo respeta; borrado real de contabilizado solo lo fuerza el super admin desde código',
  `can_void` tinyint(1) NOT NULL DEFAULT '0',
  `can_vary_consecutive` tinyint(1) NOT NULL DEFAULT '0',
  `can_backdate` tinyint(1) NOT NULL DEFAULT '0',
  `can_modify_integrated_documents` tinyint(1) NOT NULL DEFAULT '0',
  `can_create_out_of_range` tinyint(1) NOT NULL DEFAULT '0',
  `can_modify_document_dates` tinyint(1) NOT NULL DEFAULT '0',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `document_type_permissions_subject_unique` (`document_type_id`,`subject_type`,`subject_id`),
  CONSTRAINT `document_type_permissions_document_type_id_foreign` FOREIGN KEY (`document_type_id`) REFERENCES `document_types` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=3 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `document_type_permissions`
--

LOCK TABLES `document_type_permissions` WRITE;
/*!40000 ALTER TABLE `document_type_permissions` DISABLE KEYS */;
/*!40000 ALTER TABLE `document_type_permissions` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `document_type_printing`
--

DROP TABLE IF EXISTS `document_type_printing`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `document_type_printing` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `document_type_id` bigint unsigned NOT NULL,
  `workstation` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `assigned_printer` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `print_on_save` tinyint(1) NOT NULL DEFAULT '0',
  `enable_direct_print` tinyint(1) NOT NULL DEFAULT '0',
  `confirm_on_reprint` tinyint(1) NOT NULL DEFAULT '0',
  `resume_lines_on_print` tinyint(1) NOT NULL DEFAULT '0',
  `print_format_file` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `document_type_printing_document_type_id_foreign` (`document_type_id`),
  CONSTRAINT `document_type_printing_document_type_id_foreign` FOREIGN KEY (`document_type_id`) REFERENCES `document_types` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `document_type_printing`
--

LOCK TABLES `document_type_printing` WRITE;
/*!40000 ALTER TABLE `document_type_printing` DISABLE KEYS */;
/*!40000 ALTER TABLE `document_type_printing` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `document_type_visible_columns`
--

DROP TABLE IF EXISTS `document_type_visible_columns`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `document_type_visible_columns` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `document_type_id` bigint unsigned NOT NULL,
  `column_key` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `visible` tinyint(1) NOT NULL DEFAULT '1',
  `language` varchar(2) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'es',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `document_type_visible_columns_document_type_id_foreign` (`document_type_id`),
  CONSTRAINT `document_type_visible_columns_document_type_id_foreign` FOREIGN KEY (`document_type_id`) REFERENCES `document_types` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `document_type_visible_columns`
--

LOCK TABLES `document_type_visible_columns` WRITE;
/*!40000 ALTER TABLE `document_type_visible_columns` DISABLE KEYS */;
/*!40000 ALTER TABLE `document_type_visible_columns` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `document_types`
--

DROP TABLE IF EXISTS `document_types`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `document_types` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `company_id` bigint unsigned NOT NULL,
  `code` varchar(3) COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'FVE, NCC, DVC, NDC, TRB, CKB, DEB, ADD, ADC, ACC',
  `name` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `origin_module` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'ventas|compras|bancos|contable|cxc|cxp|activos_fijos',
  `generates_journal` tinyint(1) NOT NULL DEFAULT '1',
  `requires_electronic_key` tinyint(1) NOT NULL DEFAULT '0' COMMENT 'exige la clave numérica de 50 dígitos de Hacienda al registrar el documento',
  `bp_line_requirement` varchar(20) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'none' COMMENT 'none|due_date|application|either — qué exige este tipo de documento en sus líneas con socio de negocio',
  `is_opening_type` tinyint(1) NOT NULL DEFAULT '0' COMMENT 'true solo en el tipo de documento reservado que el sistema crea de oficio para la carga de saldos iniciales (ver OpeningBalanceBulkImporter) — no seleccionable en el asiento manual',
  `is_reconciliation_type` tinyint(1) NOT NULL DEFAULT '0' COMMENT 'true solo en el tipo de documento reservado que el sistema crea de oficio para traspasos de reconciliación interna (código ARR, ver AccountReconciliationController) — no seleccionable en el asiento manual',
  `is_closing_type` tinyint(1) NOT NULL DEFAULT '0' COMMENT 'true solo en el tipo de documento reservado que el sistema crea de oficio para el asiento de cierre anual (código ACC, ver PeriodCloseService::closeYear()) — no seleccionable en el asiento manual, y LedgerService lo excluye del mayor auxiliar de cuentas de resultados para no esconder la actividad real del año detrás del asiento que la cancela',
  `default_debit_account_id` bigint unsigned DEFAULT NULL,
  `default_credit_account_id` bigint unsigned DEFAULT NULL,
  `numbering_mask` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '99999999',
  `field_count` tinyint unsigned NOT NULL DEFAULT '8',
  `next_consecutive` bigint unsigned NOT NULL DEFAULT '1',
  `range_from` bigint unsigned DEFAULT NULL,
  `range_to` bigint unsigned DEFAULT NULL,
  `consecutive_on_save` tinyint(1) NOT NULL DEFAULT '0',
  `allow_out_of_range_dates` tinyint(1) NOT NULL DEFAULT '0',
  `prevent_admins_out_of_range` tinyint(1) NOT NULL DEFAULT '0',
  `date_range_from` date DEFAULT NULL,
  `date_range_to` date DEFAULT NULL,
  `currency_mode` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'libre' COMMENT 'local_fija|extranjera_fija|libre',
  `allows_balance_increase` tinyint(1) NOT NULL DEFAULT '0',
  `reads_document_classifications` tinyint(1) NOT NULL DEFAULT '0',
  `status` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'active',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `document_types_company_id_code_unique` (`company_id`,`code`),
  KEY `document_types_default_debit_account_id_foreign` (`default_debit_account_id`),
  KEY `document_types_default_credit_account_id_foreign` (`default_credit_account_id`),
  CONSTRAINT `document_types_company_id_foreign` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`) ON DELETE CASCADE,
  CONSTRAINT `document_types_default_credit_account_id_foreign` FOREIGN KEY (`default_credit_account_id`) REFERENCES `chart_of_accounts` (`id`) ON DELETE SET NULL,
  CONSTRAINT `document_types_default_debit_account_id_foreign` FOREIGN KEY (`default_debit_account_id`) REFERENCES `chart_of_accounts` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=427 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `document_types`
--

LOCK TABLES `document_types` WRITE;
/*!40000 ALTER TABLE `document_types` DISABLE KEYS */;
INSERT INTO `document_types` VALUES (408,600,'ADD','ASIENTO DE DIARIO','contable',1,0,'either',0,0,0,NULL,NULL,'99999999',8,4,NULL,NULL,0,0,0,NULL,NULL,'libre',0,0,'active','2026-09-03 03:06:56','2026-09-09 02:14:45'),(409,600,'TRF','TRANSFRENCIA DE FONDOS','bancos',1,0,'either',0,0,0,NULL,NULL,'99999999',8,5,NULL,NULL,0,0,0,NULL,NULL,'libre',0,0,'active','2026-09-03 03:07:34','2026-09-06 21:32:31'),(410,600,'DEP','DEPOSITOS DE FONDOS','bancos',1,0,'application',0,0,0,NULL,NULL,'99999999',8,2,NULL,NULL,0,0,0,NULL,NULL,'libre',0,0,'active','2026-09-03 03:08:41','2026-09-06 20:23:16'),(411,600,'APE','Asiento de apertura (saldos iniciales)','contable',1,0,'due_date',1,0,0,NULL,NULL,'99999999',8,2,NULL,NULL,0,0,0,NULL,NULL,'libre',0,0,'active','2026-09-03 03:31:48','2026-09-03 03:42:43'),(412,600,'ARR','Asiento de reconciliación','contable',1,0,'none',0,1,0,NULL,NULL,'99999999',8,6,NULL,NULL,0,0,0,NULL,NULL,'libre',0,0,'active','2026-09-06 21:57:25','2026-09-09 03:05:18'),(413,600,'FCP','Factura de compra proveedor','compras',1,1,'due_date',0,0,0,NULL,NULL,'99999999',8,2,NULL,NULL,0,0,0,NULL,NULL,'libre',0,0,'active','2026-09-09 01:18:01','2026-09-09 01:34:19'),(414,601,'FVE','aut praesentium quo','contable',1,0,'none',0,0,0,NULL,NULL,'99999999',8,2,NULL,NULL,1,0,0,NULL,NULL,'libre',0,0,'active','2026-09-10 21:11:17','2026-09-10 21:11:17'),(415,604,'ADD','Asiento de diario','contable',1,0,'either',0,0,0,NULL,NULL,'99999999',8,7,NULL,NULL,1,0,0,NULL,NULL,'libre',0,0,'active','2026-09-18 03:23:41','2026-09-18 04:07:24'),(416,604,'ADC','Asiento diferencial cambiario','contable',1,0,'none',0,0,0,NULL,NULL,'99999999',8,1,NULL,NULL,1,0,0,NULL,NULL,'libre',0,0,'active','2026-09-18 03:23:41','2026-09-18 03:23:41'),(417,604,'FVE','Factura de venta','ventas',1,1,'due_date',0,0,0,NULL,NULL,'99999999',8,4,NULL,NULL,1,0,0,NULL,NULL,'libre',0,0,'active','2026-09-18 03:23:41','2026-09-18 03:45:25'),(418,604,'FCP','Factura de compra','compras',1,0,'due_date',0,0,0,NULL,NULL,'99999999',8,7,NULL,NULL,1,0,0,NULL,NULL,'libre',0,0,'active','2026-09-18 03:23:41','2026-09-18 04:02:42'),(419,604,'REC','Recibo de dinero','cxc',1,0,'application',0,0,0,NULL,NULL,'99999999',8,2,NULL,NULL,1,0,0,NULL,NULL,'libre',0,0,'active','2026-09-18 03:23:41','2026-09-18 03:45:25'),(420,604,'PAG','Pago a proveedor','cxp',1,0,'application',0,0,0,NULL,NULL,'99999999',8,2,NULL,NULL,1,0,0,NULL,NULL,'libre',0,0,'active','2026-09-18 03:23:41','2026-09-18 03:45:26'),(421,604,'TRB','Transferencia bancaria','bancos',1,0,'none',0,0,0,NULL,NULL,'99999999',8,1,NULL,NULL,1,0,0,NULL,NULL,'libre',0,0,'active','2026-09-18 03:23:41','2026-09-18 03:23:41'),(422,604,'ACC','Asiento de cierre contable','contable',1,0,'none',0,0,1,NULL,NULL,'99999999',8,1,NULL,NULL,0,0,0,NULL,NULL,'local_fija',0,0,'active','2026-09-18 03:23:41','2026-09-18 03:23:41'),(423,604,'EIN','Entrada de inventario','inventario',1,0,'none',0,0,0,NULL,NULL,'99999999',8,6,NULL,NULL,1,0,0,NULL,NULL,'local_fija',0,0,'active','2026-09-18 03:56:18','2026-09-23 03:54:08'),(424,604,'SIN','Salida de inventario','inventario',1,0,'none',0,0,0,NULL,NULL,'99999999',8,4,NULL,NULL,1,0,0,NULL,NULL,'local_fija',0,0,'active','2026-09-18 03:56:18','2026-09-23 03:54:08'),(425,604,'TRA','Traslado entre almacenes','inventario',1,0,'none',0,0,0,NULL,NULL,'99999999',8,2,NULL,NULL,1,0,0,NULL,NULL,'local_fija',0,0,'active','2026-09-18 03:56:18','2026-09-20 01:55:19'),(426,604,'ECP','Entrada por compra','inventario',1,0,'none',0,0,0,NULL,NULL,'99999999',8,2,NULL,NULL,1,0,0,NULL,NULL,'local_fija',0,0,'active','2026-09-18 04:02:31','2026-09-18 04:02:42');
/*!40000 ALTER TABLE `document_types` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `employee_deduction_applications`
--

DROP TABLE IF EXISTS `employee_deduction_applications`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `employee_deduction_applications` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `employee_deduction_id` bigint unsigned NOT NULL,
  `payroll_entry_id` bigint unsigned DEFAULT NULL,
  `applied_on` date NOT NULL,
  `amount` decimal(18,2) NOT NULL,
  `balance_after` decimal(18,2) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `employee_deduction_applications_payroll_entry_id_foreign` (`payroll_entry_id`),
  KEY `employee_deduction_applications_employee_deduction_id_index` (`employee_deduction_id`),
  CONSTRAINT `employee_deduction_applications_employee_deduction_id_foreign` FOREIGN KEY (`employee_deduction_id`) REFERENCES `employee_deductions` (`id`) ON DELETE CASCADE,
  CONSTRAINT `employee_deduction_applications_payroll_entry_id_foreign` FOREIGN KEY (`payroll_entry_id`) REFERENCES `payroll_entries` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `employee_deduction_applications`
--

LOCK TABLES `employee_deduction_applications` WRITE;
/*!40000 ALTER TABLE `employee_deduction_applications` DISABLE KEYS */;
/*!40000 ALTER TABLE `employee_deduction_applications` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `employee_deductions`
--

DROP TABLE IF EXISTS `employee_deductions`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `employee_deductions` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `company_id` bigint unsigned NOT NULL,
  `employee_id` bigint unsigned NOT NULL,
  `type` varchar(30) COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'advance|loan|solidarista_savings|solidarista_fee|alimony|garnishment|other',
  `reference` varchar(60) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'n.º de préstamo, expediente judicial',
  `description` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `payroll_concept_id` bigint unsigned DEFAULT NULL,
  `start_date` date NOT NULL,
  `end_date` date DEFAULT NULL,
  `original_amount` decimal(18,2) DEFAULT NULL COMMENT 'monto otorgado; null si no tiene saldo que extinguir',
  `balance` decimal(18,2) DEFAULT NULL,
  `calculation` varchar(20) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'amount' COMMENT 'amount|percentage',
  `installment_amount` decimal(18,2) DEFAULT NULL,
  `installment_percentage` decimal(8,4) DEFAULT NULL COMMENT '% del salario bruto del período',
  `priority` smallint unsigned NOT NULL DEFAULT '100',
  `account_id` bigint unsigned DEFAULT NULL,
  `status` varchar(20) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'active' COMMENT 'active|suspended|settled|cancelled',
  `notes` text COLLATE utf8mb4_unicode_ci,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `employee_deductions_employee_id_foreign` (`employee_id`),
  KEY `employee_deductions_payroll_concept_id_foreign` (`payroll_concept_id`),
  KEY `employee_deductions_account_id_foreign` (`account_id`),
  KEY `employee_deductions_company_id_employee_id_status_index` (`company_id`,`employee_id`,`status`),
  KEY `employee_deductions_company_id_type_index` (`company_id`,`type`),
  CONSTRAINT `employee_deductions_account_id_foreign` FOREIGN KEY (`account_id`) REFERENCES `chart_of_accounts` (`id`) ON DELETE SET NULL,
  CONSTRAINT `employee_deductions_company_id_foreign` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`) ON DELETE CASCADE,
  CONSTRAINT `employee_deductions_employee_id_foreign` FOREIGN KEY (`employee_id`) REFERENCES `employees` (`id`) ON DELETE CASCADE,
  CONSTRAINT `employee_deductions_payroll_concept_id_foreign` FOREIGN KEY (`payroll_concept_id`) REFERENCES `payroll_concepts` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `employee_deductions`
--

LOCK TABLES `employee_deductions` WRITE;
/*!40000 ALTER TABLE `employee_deductions` DISABLE KEYS */;
/*!40000 ALTER TABLE `employee_deductions` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `employee_notes`
--

DROP TABLE IF EXISTS `employee_notes`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `employee_notes` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `company_id` bigint unsigned NOT NULL,
  `employee_id` bigint unsigned NOT NULL,
  `happened_on` date NOT NULL,
  `category` varchar(30) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'observation' COMMENT 'observation|recognition|warning|incident|meeting|other',
  `title` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `body` text COLLATE utf8mb4_unicode_ci NOT NULL,
  `is_confidential` tinyint(1) NOT NULL DEFAULT '0',
  `created_by` bigint unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `employee_notes_employee_id_foreign` (`employee_id`),
  KEY `employee_notes_created_by_foreign` (`created_by`),
  KEY `employee_notes_company_id_employee_id_happened_on_index` (`company_id`,`employee_id`,`happened_on`),
  CONSTRAINT `employee_notes_company_id_foreign` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`) ON DELETE CASCADE,
  CONSTRAINT `employee_notes_created_by_foreign` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `employee_notes_employee_id_foreign` FOREIGN KEY (`employee_id`) REFERENCES `employees` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `employee_notes`
--

LOCK TABLES `employee_notes` WRITE;
/*!40000 ALTER TABLE `employee_notes` DISABLE KEYS */;
/*!40000 ALTER TABLE `employee_notes` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `employee_recurring_inputs`
--

DROP TABLE IF EXISTS `employee_recurring_inputs`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `employee_recurring_inputs` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `company_id` bigint unsigned NOT NULL,
  `employee_id` bigint unsigned NOT NULL,
  `payroll_concept_id` bigint unsigned NOT NULL,
  `amount` decimal(18,2) DEFAULT NULL,
  `quantity` decimal(12,4) DEFAULT NULL,
  `start_date` date NOT NULL,
  `end_date` date DEFAULT NULL COMMENT 'nullable = indefinido',
  `notes` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `status` varchar(20) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'active' COMMENT 'active|suspended',
  `created_by` bigint unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `employee_recurring_unique` (`employee_id`,`payroll_concept_id`,`start_date`),
  KEY `employee_recurring_inputs_payroll_concept_id_foreign` (`payroll_concept_id`),
  KEY `employee_recurring_inputs_created_by_foreign` (`created_by`),
  KEY `employee_recurring_inputs_company_id_status_index` (`company_id`,`status`),
  CONSTRAINT `employee_recurring_inputs_company_id_foreign` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`) ON DELETE CASCADE,
  CONSTRAINT `employee_recurring_inputs_created_by_foreign` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `employee_recurring_inputs_employee_id_foreign` FOREIGN KEY (`employee_id`) REFERENCES `employees` (`id`) ON DELETE CASCADE,
  CONSTRAINT `employee_recurring_inputs_payroll_concept_id_foreign` FOREIGN KEY (`payroll_concept_id`) REFERENCES `payroll_concepts` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `employee_recurring_inputs`
--

LOCK TABLES `employee_recurring_inputs` WRITE;
/*!40000 ALTER TABLE `employee_recurring_inputs` DISABLE KEYS */;
/*!40000 ALTER TABLE `employee_recurring_inputs` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `employees`
--

DROP TABLE IF EXISTS `employees`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `employees` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `company_id` bigint unsigned NOT NULL,
  `code` varchar(20) COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'número de empleado dentro de la compañía',
  `identification_type` varchar(20) COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'cedula|dimex|pasaporte',
  `identification_number` varchar(30) COLLATE utf8mb4_unicode_ci NOT NULL,
  `ccss_number` varchar(30) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'número de asegurado',
  `first_name` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `last_name1` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `last_name2` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `birth_date` date DEFAULT NULL,
  `gender` varchar(20) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `nationality` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `email` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `phone` varchar(30) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `address` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `photo_path` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `hire_date` date NOT NULL,
  `termination_date` date DEFAULT NULL,
  `termination_reason` varchar(40) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'renuncia|despido_con_causa|despido_sin_causa|vencimiento|mutuo_acuerdo|fallecimiento',
  `position` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'puesto',
  `job_position_id` bigint unsigned DEFAULT NULL,
  `department` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `department_id` bigint unsigned DEFAULT NULL,
  `cost_center_id` bigint unsigned DEFAULT NULL,
  `salary_expense_account_id` bigint unsigned DEFAULT NULL,
  `contract_type` varchar(30) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'indefinido' COMMENT 'indefinido|plazo_fijo|obra_determinada|ocasional',
  `journey_type` varchar(20) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'diurna' COMMENT 'diurna|mixta|nocturna',
  `weekly_hours` decimal(6,2) NOT NULL DEFAULT '48.00',
  `weekly_salary_divisor` tinyint unsigned NOT NULL DEFAULT '6' COMMENT 'solo para salario semanal: 6 sin descanso pagado, 7 con descanso pagado',
  `salary_type` varchar(20) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'mensual' COMMENT 'mensual|quincenal|semanal|diario|hora',
  `base_salary` decimal(18,2) NOT NULL,
  `payment_method` varchar(20) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'transferencia' COMMENT 'transferencia|cheque|efectivo',
  `bank_name` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `bank_account` varchar(34) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `has_spouse_credit` tinyint(1) NOT NULL DEFAULT '0',
  `children_credit_count` smallint unsigned NOT NULL DEFAULT '0',
  `is_income_tax_exempt` tinyint(1) NOT NULL DEFAULT '0',
  `is_ccss_exempt` tinyint(1) NOT NULL DEFAULT '0',
  `is_pensioner` tinyint(1) NOT NULL DEFAULT '0' COMMENT 'pensionado: cotiza SEM pero no IVM, ni obrero ni patronal',
  `status` varchar(20) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'active' COMMENT 'active|suspended|terminated',
  `notes` text COLLATE utf8mb4_unicode_ci,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `employees_company_id_code_unique` (`company_id`,`code`),
  UNIQUE KEY `employees_identification_unique` (`company_id`,`identification_number`),
  KEY `employees_cost_center_id_foreign` (`cost_center_id`),
  KEY `employees_company_id_status_index` (`company_id`,`status`),
  KEY `employees_company_id_cost_center_id_index` (`company_id`,`cost_center_id`),
  KEY `employees_salary_expense_account_id_foreign` (`salary_expense_account_id`),
  KEY `employees_department_id_foreign` (`department_id`),
  KEY `employees_job_position_id_foreign` (`job_position_id`),
  CONSTRAINT `employees_company_id_foreign` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`) ON DELETE CASCADE,
  CONSTRAINT `employees_cost_center_id_foreign` FOREIGN KEY (`cost_center_id`) REFERENCES `cost_centers` (`id`) ON DELETE SET NULL,
  CONSTRAINT `employees_department_id_foreign` FOREIGN KEY (`department_id`) REFERENCES `departments` (`id`) ON DELETE SET NULL,
  CONSTRAINT `employees_job_position_id_foreign` FOREIGN KEY (`job_position_id`) REFERENCES `job_positions` (`id`) ON DELETE SET NULL,
  CONSTRAINT `employees_salary_expense_account_id_foreign` FOREIGN KEY (`salary_expense_account_id`) REFERENCES `chart_of_accounts` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `employees`
--

LOCK TABLES `employees` WRITE;
/*!40000 ALTER TABLE `employees` DISABLE KEYS */;
/*!40000 ALTER TABLE `employees` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `exchange_rates`
--

DROP TABLE IF EXISTS `exchange_rates`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `exchange_rates` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `company_id` bigint unsigned NOT NULL,
  `currency_id` bigint unsigned NOT NULL,
  `rate_date` date NOT NULL,
  `rate_type` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'buy|sell|reference',
  `rate` decimal(18,6) NOT NULL,
  `source` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'manual' COMMENT 'bccr_api|manual',
  `is_locked` tinyint(1) NOT NULL DEFAULT '0',
  `created_by` bigint unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `exchange_rates_currency_id_foreign` (`currency_id`),
  KEY `exchange_rates_created_by_foreign` (`created_by`),
  KEY `exchange_rates_lookup` (`company_id`,`currency_id`,`rate_date`,`rate_type`),
  CONSTRAINT `exchange_rates_company_id_foreign` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`) ON DELETE CASCADE,
  CONSTRAINT `exchange_rates_created_by_foreign` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `exchange_rates_currency_id_foreign` FOREIGN KEY (`currency_id`) REFERENCES `currencies` (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=356 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `exchange_rates`
--

LOCK TABLES `exchange_rates` WRITE;
/*!40000 ALTER TABLE `exchange_rates` DISABLE KEYS */;
INSERT INTO `exchange_rates` VALUES (341,600,950,'2026-01-01','sell',1.000000,'manual',0,357,'2026-09-03 03:41:25'),(342,600,950,'2026-01-01','buy',1.000000,'manual',0,357,'2026-09-03 03:41:32'),(343,601,950,'2026-01-01','reference',520.000000,'manual',0,NULL,'2026-09-10 21:11:16'),(344,604,950,'2026-01-01','reference',505.250000,'manual',0,NULL,'2026-09-18 03:27:20'),(345,604,950,'2026-02-01','reference',508.400000,'manual',0,NULL,'2026-09-18 03:27:20'),(346,604,950,'2026-03-01','reference',511.750000,'manual',0,NULL,'2026-09-18 03:27:20'),(347,604,950,'2026-04-01','reference',509.900000,'manual',0,NULL,'2026-09-18 03:27:20'),(348,604,950,'2026-05-01','reference',513.300000,'manual',0,NULL,'2026-09-18 03:27:20'),(349,604,950,'2026-06-01','reference',516.800000,'manual',0,NULL,'2026-09-18 03:27:20'),(350,604,950,'2026-07-01','reference',514.150000,'manual',0,NULL,'2026-09-18 03:27:20'),(351,604,950,'2026-08-01','reference',518.600000,'manual',0,NULL,'2026-09-18 03:27:20'),(352,604,950,'2026-09-01','reference',521.050000,'manual',0,NULL,'2026-09-18 03:27:20'),(353,604,950,'2026-10-01','reference',523.700000,'manual',0,NULL,'2026-09-18 03:27:20'),(354,604,950,'2026-11-01','reference',525.400000,'manual',0,NULL,'2026-09-18 03:27:20'),(355,604,950,'2026-12-01','reference',527.150000,'manual',0,NULL,'2026-09-18 03:27:20');
/*!40000 ALTER TABLE `exchange_rates` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `failed_jobs`
--

DROP TABLE IF EXISTS `failed_jobs`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `failed_jobs` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `uuid` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `connection` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `queue` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `payload` longtext COLLATE utf8mb4_unicode_ci NOT NULL,
  `exception` longtext COLLATE utf8mb4_unicode_ci NOT NULL,
  `failed_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `failed_jobs_uuid_unique` (`uuid`),
  KEY `failed_jobs_connection_queue_failed_at_index` (`connection`,`queue`,`failed_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `failed_jobs`
--

LOCK TABLES `failed_jobs` WRITE;
/*!40000 ALTER TABLE `failed_jobs` DISABLE KEYS */;
/*!40000 ALTER TABLE `failed_jobs` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `fiscal_periods`
--

DROP TABLE IF EXISTS `fiscal_periods`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `fiscal_periods` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `fiscal_year_id` bigint unsigned NOT NULL,
  `period_number` tinyint unsigned NOT NULL,
  `start_date` date NOT NULL,
  `end_date` date NOT NULL,
  `status` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'open' COMMENT 'open|blocked|closed',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `fiscal_periods_fiscal_year_id_period_number_unique` (`fiscal_year_id`,`period_number`),
  CONSTRAINT `fiscal_periods_fiscal_year_id_foreign` FOREIGN KEY (`fiscal_year_id`) REFERENCES `fiscal_years` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=389 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `fiscal_periods`
--

LOCK TABLES `fiscal_periods` WRITE;
/*!40000 ALTER TABLE `fiscal_periods` DISABLE KEYS */;
INSERT INTO `fiscal_periods` VALUES (364,325,1,'2026-01-01','2026-01-31','open','2026-09-03 03:32:55','2026-09-03 03:32:55'),(365,325,2,'2026-02-01','2026-02-28','open','2026-09-03 03:32:55','2026-09-03 03:32:55'),(366,325,3,'2026-03-01','2026-03-31','open','2026-09-03 03:32:55','2026-09-03 03:32:55'),(367,325,4,'2026-04-01','2026-04-30','open','2026-09-03 03:32:55','2026-09-03 03:32:55'),(368,325,5,'2026-05-01','2026-05-31','open','2026-09-03 03:32:55','2026-09-03 03:32:55'),(369,325,6,'2026-06-01','2026-06-30','open','2026-09-03 03:32:55','2026-09-03 03:32:55'),(370,325,7,'2026-07-01','2026-07-31','open','2026-09-03 03:32:55','2026-09-03 03:32:55'),(371,325,8,'2026-08-01','2026-08-31','open','2026-09-03 03:32:55','2026-09-03 03:32:55'),(372,325,9,'2026-09-01','2026-09-30','open','2026-09-03 03:32:55','2026-09-03 03:32:55'),(373,325,10,'2026-10-01','2026-10-31','open','2026-09-03 03:32:55','2026-09-03 03:32:55'),(374,325,11,'2026-11-01','2026-11-30','open','2026-09-03 03:32:55','2026-09-03 03:32:55'),(375,325,12,'2026-12-01','2026-12-31','open','2026-09-03 03:32:55','2026-09-03 03:32:55'),(376,326,1,'2026-01-01','2026-01-31','open','2026-09-10 21:11:17','2026-09-10 21:11:17'),(377,327,1,'2026-01-01','2026-01-31','open','2026-09-18 03:23:41','2026-09-18 03:23:41'),(378,327,2,'2026-02-01','2026-02-28','open','2026-09-18 03:23:41','2026-09-18 03:23:41'),(379,327,3,'2026-03-01','2026-03-31','open','2026-09-18 03:23:41','2026-09-18 03:23:41'),(380,327,4,'2026-04-01','2026-04-30','open','2026-09-18 03:23:41','2026-09-18 03:23:41'),(381,327,5,'2026-05-01','2026-05-31','open','2026-09-18 03:23:41','2026-09-18 03:23:41'),(382,327,6,'2026-06-01','2026-06-30','open','2026-09-18 03:23:41','2026-09-18 03:23:41'),(383,327,7,'2026-07-01','2026-07-31','open','2026-09-18 03:23:41','2026-09-18 03:23:41'),(384,327,8,'2026-08-01','2026-08-31','open','2026-09-18 03:23:41','2026-09-18 03:23:41'),(385,327,9,'2026-09-01','2026-09-30','open','2026-09-18 03:23:41','2026-09-18 03:23:41'),(386,327,10,'2026-10-01','2026-10-31','open','2026-09-18 03:23:41','2026-09-18 03:23:41'),(387,327,11,'2026-11-01','2026-11-30','open','2026-09-18 03:23:41','2026-09-18 03:23:41'),(388,327,12,'2026-12-01','2026-12-31','open','2026-09-18 03:23:41','2026-09-18 03:23:41');
/*!40000 ALTER TABLE `fiscal_periods` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `fiscal_years`
--

DROP TABLE IF EXISTS `fiscal_years`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `fiscal_years` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `company_id` bigint unsigned NOT NULL,
  `year` smallint unsigned NOT NULL,
  `status` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'open' COMMENT 'open|closed',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `fiscal_years_company_id_year_unique` (`company_id`,`year`),
  CONSTRAINT `fiscal_years_company_id_foreign` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=328 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `fiscal_years`
--

LOCK TABLES `fiscal_years` WRITE;
/*!40000 ALTER TABLE `fiscal_years` DISABLE KEYS */;
INSERT INTO `fiscal_years` VALUES (325,600,2026,'open','2026-09-03 03:32:55','2026-09-03 03:32:55'),(326,601,2026,'open','2026-09-10 21:11:17','2026-09-10 21:11:17'),(327,604,2026,'open','2026-09-18 03:23:41','2026-09-18 03:23:41');
/*!40000 ALTER TABLE `fiscal_years` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `fx_revaluation_details`
--

DROP TABLE IF EXISTS `fx_revaluation_details`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `fx_revaluation_details` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `fx_revaluation_run_id` bigint unsigned NOT NULL,
  `account_id` bigint unsigned NOT NULL,
  `business_partner_id` bigint unsigned DEFAULT NULL,
  `foreign_balance` decimal(18,2) NOT NULL,
  `historical_local_amount` decimal(18,2) NOT NULL,
  `revalued_local_amount` decimal(18,2) NOT NULL,
  `difference` decimal(18,2) NOT NULL,
  `journal_detail_id` bigint unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `fx_revaluation_details_fx_revaluation_run_id_foreign` (`fx_revaluation_run_id`),
  KEY `fx_revaluation_details_account_id_foreign` (`account_id`),
  KEY `fx_revaluation_details_business_partner_id_foreign` (`business_partner_id`),
  KEY `fx_revaluation_details_journal_detail_id_foreign` (`journal_detail_id`),
  CONSTRAINT `fx_revaluation_details_account_id_foreign` FOREIGN KEY (`account_id`) REFERENCES `chart_of_accounts` (`id`),
  CONSTRAINT `fx_revaluation_details_business_partner_id_foreign` FOREIGN KEY (`business_partner_id`) REFERENCES `business_partners` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fx_revaluation_details_fx_revaluation_run_id_foreign` FOREIGN KEY (`fx_revaluation_run_id`) REFERENCES `fx_revaluation_runs` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fx_revaluation_details_journal_detail_id_foreign` FOREIGN KEY (`journal_detail_id`) REFERENCES `journal_details` (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=5 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `fx_revaluation_details`
--

LOCK TABLES `fx_revaluation_details` WRITE;
/*!40000 ALTER TABLE `fx_revaluation_details` DISABLE KEYS */;
/*!40000 ALTER TABLE `fx_revaluation_details` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `fx_revaluation_runs`
--

DROP TABLE IF EXISTS `fx_revaluation_runs`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `fx_revaluation_runs` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `company_id` bigint unsigned NOT NULL,
  `cutoff_date` date NOT NULL,
  `exchange_rate_used` decimal(18,6) NOT NULL,
  `gain_account_id` bigint unsigned DEFAULT NULL,
  `loss_account_id` bigint unsigned DEFAULT NULL,
  `document_type_id` bigint unsigned NOT NULL,
  `journal_entry_id` bigint unsigned DEFAULT NULL,
  `status` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'completed',
  `executed_by` bigint unsigned DEFAULT NULL,
  `executed_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `fx_revaluation_runs_company_id_foreign` (`company_id`),
  KEY `fx_revaluation_runs_document_type_id_foreign` (`document_type_id`),
  KEY `fx_revaluation_runs_journal_entry_id_foreign` (`journal_entry_id`),
  KEY `fx_revaluation_runs_executed_by_foreign` (`executed_by`),
  KEY `fx_revaluation_runs_gain_account_id_foreign` (`gain_account_id`),
  KEY `fx_revaluation_runs_loss_account_id_foreign` (`loss_account_id`),
  CONSTRAINT `fx_revaluation_runs_company_id_foreign` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fx_revaluation_runs_document_type_id_foreign` FOREIGN KEY (`document_type_id`) REFERENCES `document_types` (`id`),
  CONSTRAINT `fx_revaluation_runs_executed_by_foreign` FOREIGN KEY (`executed_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fx_revaluation_runs_gain_account_id_foreign` FOREIGN KEY (`gain_account_id`) REFERENCES `chart_of_accounts` (`id`),
  CONSTRAINT `fx_revaluation_runs_journal_entry_id_foreign` FOREIGN KEY (`journal_entry_id`) REFERENCES `journal_entries` (`id`),
  CONSTRAINT `fx_revaluation_runs_loss_account_id_foreign` FOREIGN KEY (`loss_account_id`) REFERENCES `chart_of_accounts` (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=6 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `fx_revaluation_runs`
--

LOCK TABLES `fx_revaluation_runs` WRITE;
/*!40000 ALTER TABLE `fx_revaluation_runs` DISABLE KEYS */;
/*!40000 ALTER TABLE `fx_revaluation_runs` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `gl_determinations`
--

DROP TABLE IF EXISTS `gl_determinations`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `gl_determinations` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `company_id` bigint unsigned NOT NULL,
  `scope_level` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'company|warehouse|item_group|item',
  `scope_id` bigint unsigned DEFAULT NULL COMMENT 'null solo cuando scope_level=company',
  `category` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'inventory|stock_increase|stock_decrease (el resto del diseño llega con su fase)',
  `account_id` bigint unsigned NOT NULL,
  `cost_allocation_rule_id` bigint unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `gl_determinations_scope_category_unique` (`company_id`,`scope_level`,`scope_id`,`category`),
  KEY `gl_determinations_account_id_foreign` (`account_id`),
  KEY `gl_determinations_cost_allocation_rule_id_foreign` (`cost_allocation_rule_id`),
  CONSTRAINT `gl_determinations_account_id_foreign` FOREIGN KEY (`account_id`) REFERENCES `chart_of_accounts` (`id`),
  CONSTRAINT `gl_determinations_company_id_foreign` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`) ON DELETE CASCADE,
  CONSTRAINT `gl_determinations_cost_allocation_rule_id_foreign` FOREIGN KEY (`cost_allocation_rule_id`) REFERENCES `cost_allocation_rules` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=12 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `gl_determinations`
--

LOCK TABLES `gl_determinations` WRITE;
/*!40000 ALTER TABLE `gl_determinations` DISABLE KEYS */;
INSERT INTO `gl_determinations` VALUES (1,604,'company',NULL,'inventory',1147,NULL,'2026-09-18 03:54:05','2026-09-18 03:54:05'),(2,604,'company',NULL,'gr_ir_clearing',1358,NULL,'2026-09-18 03:54:05','2026-09-18 03:54:05'),(3,604,'company',NULL,'stock_increase',1361,NULL,'2026-09-18 03:54:05','2026-09-18 03:54:05'),(4,604,'company',NULL,'stock_decrease',1362,NULL,'2026-09-18 03:54:05','2026-09-18 03:54:05'),(5,604,'company',NULL,'price_difference',1363,NULL,'2026-09-18 03:54:05','2026-09-18 03:54:05'),(6,604,'company',NULL,'wip',1357,NULL,'2026-09-18 03:54:05','2026-09-18 03:54:05'),(7,604,'company',NULL,'production_variance',1364,NULL,'2026-09-18 03:54:05','2026-09-18 03:54:05'),(8,604,'company',NULL,'cogs',1274,21,'2026-09-18 03:54:05','2026-09-18 03:54:05'),(9,604,'item_group',5,'inventory',1150,NULL,'2026-09-18 03:54:05','2026-09-18 03:54:05'),(10,604,'warehouse',4,'inventory',1147,NULL,'2026-09-18 03:54:05','2026-09-23 02:30:18'),(11,604,'item',13,'cogs',1275,21,'2026-09-18 03:54:05','2026-09-18 03:54:05');
/*!40000 ALTER TABLE `gl_determinations` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `import_cost_allocations`
--

DROP TABLE IF EXISTS `import_cost_allocations`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `import_cost_allocations` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `import_cost_document_id` bigint unsigned NOT NULL,
  `landed_cost_document_id` bigint unsigned NOT NULL,
  `amount` decimal(18,2) NOT NULL COMMENT 'cuánto de este rubro se asignó a esa importación',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `import_cost_allocations_landed_cost_document_id_foreign` (`landed_cost_document_id`),
  KEY `ica_rubro_costeo_idx` (`import_cost_document_id`,`landed_cost_document_id`),
  CONSTRAINT `import_cost_allocations_import_cost_document_id_foreign` FOREIGN KEY (`import_cost_document_id`) REFERENCES `import_cost_documents` (`id`) ON DELETE CASCADE,
  CONSTRAINT `import_cost_allocations_landed_cost_document_id_foreign` FOREIGN KEY (`landed_cost_document_id`) REFERENCES `landed_cost_documents` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `import_cost_allocations`
--

LOCK TABLES `import_cost_allocations` WRITE;
/*!40000 ALTER TABLE `import_cost_allocations` DISABLE KEYS */;
/*!40000 ALTER TABLE `import_cost_allocations` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `import_cost_documents`
--

DROP TABLE IF EXISTS `import_cost_documents`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `import_cost_documents` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `company_id` bigint unsigned NOT NULL,
  `number` varchar(20) COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'consecutivo interno por compañía; no es fiscal',
  `document_type_id` bigint unsigned NOT NULL,
  `journal_entry_id` bigint unsigned DEFAULT NULL,
  `business_partner_id` bigint unsigned NOT NULL,
  `concept` varchar(30) COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'flete|seguro|aranceles|almacenaje|agencia|transporte_interno|otros',
  `document_date` date NOT NULL,
  `posting_date` date NOT NULL,
  `due_date` date DEFAULT NULL,
  `amount` decimal(18,2) NOT NULL COMMENT 'monto del rubro',
  `allocated_amount` decimal(18,2) NOT NULL DEFAULT '0.00' COMMENT 'ya asignado a importaciones; lo pendiente es amount - allocated_amount',
  `status` varchar(20) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'pending' COMMENT 'pending|partial|allocated|cancelled',
  `description` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_by` bigint unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `import_cost_documents_company_id_number_unique` (`company_id`,`number`),
  KEY `import_cost_documents_document_type_id_foreign` (`document_type_id`),
  KEY `import_cost_documents_journal_entry_id_foreign` (`journal_entry_id`),
  KEY `import_cost_documents_business_partner_id_foreign` (`business_partner_id`),
  KEY `import_cost_documents_created_by_foreign` (`created_by`),
  KEY `import_cost_documents_company_id_status_index` (`company_id`,`status`),
  CONSTRAINT `import_cost_documents_business_partner_id_foreign` FOREIGN KEY (`business_partner_id`) REFERENCES `business_partners` (`id`),
  CONSTRAINT `import_cost_documents_company_id_foreign` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`) ON DELETE CASCADE,
  CONSTRAINT `import_cost_documents_created_by_foreign` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `import_cost_documents_document_type_id_foreign` FOREIGN KEY (`document_type_id`) REFERENCES `document_types` (`id`),
  CONSTRAINT `import_cost_documents_journal_entry_id_foreign` FOREIGN KEY (`journal_entry_id`) REFERENCES `journal_entries` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `import_cost_documents`
--

LOCK TABLES `import_cost_documents` WRITE;
/*!40000 ALTER TABLE `import_cost_documents` DISABLE KEYS */;
/*!40000 ALTER TABLE `import_cost_documents` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `inventory_document_lines`
--

DROP TABLE IF EXISTS `inventory_document_lines`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `inventory_document_lines` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `inventory_document_id` bigint unsigned NOT NULL,
  `line_number` int unsigned NOT NULL,
  `item_id` bigint unsigned NOT NULL,
  `warehouse_id` bigint unsigned NOT NULL,
  `warehouse_bin_id` bigint unsigned DEFAULT NULL,
  `item_lot_id` bigint unsigned DEFAULT NULL,
  `to_warehouse_id` bigint unsigned DEFAULT NULL,
  `to_warehouse_bin_id` bigint unsigned DEFAULT NULL,
  `quantity` decimal(18,6) NOT NULL COMMENT 'siempre positiva; en un conteo es la cantidad CONTADA, no la diferencia',
  `unit_cost_local` decimal(18,6) NOT NULL COMMENT 'en entradas lo digita el usuario; en salidas y conteos lo resuelve el promedio',
  `unit_cost_foreign` decimal(18,6) NOT NULL,
  `description` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `inventory_document_lines_item_id_foreign` (`item_id`),
  KEY `inventory_document_lines_warehouse_id_foreign` (`warehouse_id`),
  KEY `inventory_document_lines_inventory_document_id_line_number_index` (`inventory_document_id`,`line_number`),
  KEY `inventory_document_lines_warehouse_bin_id_foreign` (`warehouse_bin_id`),
  KEY `inventory_document_lines_to_warehouse_id_foreign` (`to_warehouse_id`),
  KEY `inventory_document_lines_to_warehouse_bin_id_foreign` (`to_warehouse_bin_id`),
  KEY `inventory_document_lines_item_lot_id_foreign` (`item_lot_id`),
  CONSTRAINT `inventory_document_lines_inventory_document_id_foreign` FOREIGN KEY (`inventory_document_id`) REFERENCES `inventory_documents` (`id`) ON DELETE CASCADE,
  CONSTRAINT `inventory_document_lines_item_id_foreign` FOREIGN KEY (`item_id`) REFERENCES `items` (`id`),
  CONSTRAINT `inventory_document_lines_item_lot_id_foreign` FOREIGN KEY (`item_lot_id`) REFERENCES `item_lots` (`id`) ON DELETE SET NULL,
  CONSTRAINT `inventory_document_lines_to_warehouse_bin_id_foreign` FOREIGN KEY (`to_warehouse_bin_id`) REFERENCES `warehouse_bins` (`id`) ON DELETE SET NULL,
  CONSTRAINT `inventory_document_lines_to_warehouse_id_foreign` FOREIGN KEY (`to_warehouse_id`) REFERENCES `warehouses` (`id`) ON DELETE SET NULL,
  CONSTRAINT `inventory_document_lines_warehouse_bin_id_foreign` FOREIGN KEY (`warehouse_bin_id`) REFERENCES `warehouse_bins` (`id`) ON DELETE SET NULL,
  CONSTRAINT `inventory_document_lines_warehouse_id_foreign` FOREIGN KEY (`warehouse_id`) REFERENCES `warehouses` (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=81 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `inventory_document_lines`
--

LOCK TABLES `inventory_document_lines` WRITE;
/*!40000 ALTER TABLE `inventory_document_lines` DISABLE KEYS */;
INSERT INTO `inventory_document_lines` VALUES (22,4,1,2,3,NULL,NULL,NULL,NULL,10.000000,450000.000000,890.648193,'Saldo inicial de ART-0001','2026-09-18 03:56:36','2026-09-18 03:56:36'),(23,4,2,3,3,NULL,NULL,NULL,NULL,15.000000,95000.000000,188.025729,'Saldo inicial de ART-0002','2026-09-18 03:56:36','2026-09-18 03:56:36'),(24,4,3,4,3,NULL,NULL,NULL,NULL,40.000000,12000.000000,23.750618,'Saldo inicial de ART-0003','2026-09-18 03:56:36','2026-09-18 03:56:36'),(25,4,4,5,3,NULL,NULL,NULL,NULL,50.000000,6500.000000,12.864918,'Saldo inicial de ART-0004','2026-09-18 03:56:36','2026-09-18 03:56:36'),(26,4,5,6,3,NULL,NULL,NULL,NULL,8.000000,140000.000000,277.090549,'Saldo inicial de ART-0005','2026-09-18 03:56:36','2026-09-18 03:56:36'),(27,4,6,7,3,NULL,NULL,NULL,NULL,60.000000,3500.000000,6.927263,'Saldo inicial de ART-0006','2026-09-18 03:56:36','2026-09-18 03:56:36'),(28,4,7,8,3,NULL,NULL,NULL,NULL,20.000000,38000.000000,75.210291,'Saldo inicial de ART-0007','2026-09-18 03:56:36','2026-09-18 03:56:36'),(29,4,8,9,3,NULL,NULL,NULL,NULL,12.000000,85000.000000,168.233547,'Saldo inicial de ART-0008','2026-09-18 03:56:36','2026-09-18 03:56:36'),(30,4,9,10,3,NULL,NULL,NULL,NULL,500.000000,1800.000000,3.562592,'Saldo inicial de MAT-0001','2026-09-18 03:56:36','2026-09-18 03:56:36'),(31,4,10,11,3,NULL,NULL,NULL,NULL,120.000000,4200.000000,8.312716,'Saldo inicial de MAT-0002','2026-09-18 03:56:36','2026-09-18 03:56:36'),(32,4,11,12,3,NULL,NULL,NULL,NULL,25.000000,9500.000000,18.802572,'Saldo inicial de MAT-0003','2026-09-18 03:56:36','2026-09-18 03:56:36'),(33,4,12,13,3,NULL,NULL,NULL,NULL,6.000000,210000.000000,415.635823,'Saldo inicial de PTE-0001','2026-09-18 03:56:36','2026-09-18 03:56:36'),(34,4,13,14,3,NULL,NULL,NULL,NULL,3.000000,620000.000000,1227.115289,'Saldo inicial de PTE-0002','2026-09-18 03:56:36','2026-09-18 03:56:36'),(35,4,14,15,3,NULL,NULL,NULL,NULL,30.000000,18000.000000,35.625927,'Saldo inicial de SUM-0001','2026-09-18 03:56:36','2026-09-18 03:56:36'),(36,4,15,16,3,NULL,NULL,NULL,NULL,14.000000,22000.000000,43.542800,'Saldo inicial de SUM-0002','2026-09-18 03:56:36','2026-09-18 03:56:36'),(37,4,16,17,3,NULL,NULL,NULL,NULL,20.000000,7800.000000,15.437902,'Saldo inicial de SUM-0003','2026-09-18 03:56:36','2026-09-18 03:56:36'),(38,5,1,2,4,NULL,NULL,NULL,NULL,5.000000,450000.000000,890.648193,'Saldo inicial de ART-0001','2026-09-18 03:56:36','2026-09-18 03:56:36'),(39,5,2,6,4,NULL,NULL,NULL,NULL,3.000000,140000.000000,277.090549,'Saldo inicial de ART-0005','2026-09-18 03:56:36','2026-09-18 03:56:36'),(40,6,1,4,5,1,NULL,NULL,NULL,20.000000,12000.000000,23.750618,'Saldo inicial de ART-0003','2026-09-18 03:56:36','2026-09-18 03:56:36'),(41,6,2,5,5,2,NULL,NULL,NULL,25.000000,6500.000000,12.864918,'Saldo inicial de ART-0004','2026-09-18 03:56:36','2026-09-18 03:56:36'),(42,6,3,7,5,4,NULL,NULL,NULL,30.000000,3500.000000,6.927263,'Saldo inicial de ART-0006','2026-09-18 03:56:36','2026-09-18 03:56:36'),(48,9,1,2,3,NULL,NULL,NULL,NULL,10.000000,450000.000000,885.129819,'Compra de ART-0001','2026-09-18 04:02:42','2026-09-18 04:02:42'),(49,9,2,6,3,NULL,NULL,NULL,NULL,25.000000,140000.000000,275.373721,'Compra de ART-0005','2026-09-18 04:02:42','2026-09-18 04:02:42'),(50,10,1,2,3,NULL,NULL,NULL,NULL,6.000000,450000.000000,888.440843,'Costo de venta de ART-0001','2026-09-18 04:02:42','2026-09-18 04:02:42'),(51,10,2,3,3,NULL,NULL,NULL,NULL,4.000000,95000.000000,188.025729,'Costo de venta de ART-0002','2026-09-18 04:02:42','2026-09-18 04:02:42'),(52,10,3,4,3,NULL,NULL,NULL,NULL,10.000000,12000.000000,23.750618,'Costo de venta de ART-0003','2026-09-18 04:02:42','2026-09-18 04:02:42'),(74,14,1,4,3,NULL,NULL,4,NULL,1.000000,12000.000000,23.750618,NULL,'2026-09-20 01:55:19','2026-09-20 01:55:19'),(75,15,1,10,3,NULL,NULL,NULL,NULL,26.000000,1800.000000,3.562592,NULL,'2026-09-23 03:54:08','2026-09-23 03:54:08'),(76,15,2,11,3,NULL,NULL,NULL,NULL,3.000000,4200.000000,8.312716,NULL,'2026-09-23 03:54:08','2026-09-23 03:54:08'),(77,15,3,12,3,NULL,NULL,NULL,NULL,2.000000,9500.000000,18.802572,NULL,'2026-09-23 03:54:08','2026-09-23 03:54:08'),(78,16,1,13,3,NULL,NULL,NULL,NULL,10.000000,7840.000000,15.046540,'Recibo de producción — 10 gabinetes terminados','2026-09-23 03:54:08','2026-09-23 03:54:08'),(79,17,1,10,3,NULL,NULL,NULL,NULL,61.800000,1800.000000,3.562592,NULL,'2026-09-23 03:54:08','2026-09-23 03:54:08'),(80,17,2,12,3,NULL,NULL,NULL,NULL,4.000000,9500.000000,18.802572,NULL,'2026-09-23 03:54:08','2026-09-23 03:54:08');
/*!40000 ALTER TABLE `inventory_document_lines` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `inventory_documents`
--

DROP TABLE IF EXISTS `inventory_documents`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `inventory_documents` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `company_id` bigint unsigned NOT NULL,
  `document_type_id` bigint unsigned NOT NULL,
  `journal_entry_id` bigint unsigned DEFAULT NULL,
  `invoice_journal_entry_id` bigint unsigned DEFAULT NULL,
  `operation` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'goods_receipt|goods_issue|count_adjustment',
  `business_partner_id` bigint unsigned DEFAULT NULL,
  `is_import` tinyint(1) NOT NULL DEFAULT '0' COMMENT 'solo una entrada por compra puede serlo; habilita el costeo de nacionalización',
  `customs_declaration` varchar(40) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'número de DUA (Declaración Única Aduanera)',
  `customs_office` varchar(30) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'aduana por la que ingresó',
  `transport_document` varchar(60) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'conocimiento de embarque, guía aérea o carta de porte',
  `origin_country` varchar(60) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `customs_date` date DEFAULT NULL COMMENT 'fecha del DUA; puede diferir de la de recepción',
  `production_order_id` bigint unsigned DEFAULT NULL,
  `source_document_id` bigint unsigned DEFAULT NULL,
  `purchase_order_id` bigint unsigned DEFAULT NULL,
  `document_date` date NOT NULL,
  `posting_date` date NOT NULL COMMENT 'fecha rectora, la misma del asiento',
  `description` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `status` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'posted' COMMENT 'posted|voided',
  `reversal_of_id` bigint unsigned DEFAULT NULL,
  `created_by` bigint unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `inventory_documents_document_type_id_foreign` (`document_type_id`),
  KEY `inventory_documents_journal_entry_id_foreign` (`journal_entry_id`),
  KEY `inventory_documents_reversal_of_id_foreign` (`reversal_of_id`),
  KEY `inventory_documents_created_by_foreign` (`created_by`),
  KEY `inventory_documents_company_id_posting_date_index` (`company_id`,`posting_date`),
  KEY `inventory_documents_business_partner_id_foreign` (`business_partner_id`),
  KEY `inventory_documents_invoice_journal_entry_id_foreign` (`invoice_journal_entry_id`),
  KEY `inventory_documents_production_order_id_foreign` (`production_order_id`),
  KEY `inventory_documents_source_document_id_foreign` (`source_document_id`),
  KEY `inventory_documents_company_id_is_import_index` (`company_id`,`is_import`),
  KEY `inventory_documents_purchase_order_id_foreign` (`purchase_order_id`),
  CONSTRAINT `inventory_documents_business_partner_id_foreign` FOREIGN KEY (`business_partner_id`) REFERENCES `business_partners` (`id`) ON DELETE SET NULL,
  CONSTRAINT `inventory_documents_company_id_foreign` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`) ON DELETE CASCADE,
  CONSTRAINT `inventory_documents_created_by_foreign` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `inventory_documents_document_type_id_foreign` FOREIGN KEY (`document_type_id`) REFERENCES `document_types` (`id`),
  CONSTRAINT `inventory_documents_invoice_journal_entry_id_foreign` FOREIGN KEY (`invoice_journal_entry_id`) REFERENCES `journal_entries` (`id`) ON DELETE SET NULL,
  CONSTRAINT `inventory_documents_journal_entry_id_foreign` FOREIGN KEY (`journal_entry_id`) REFERENCES `journal_entries` (`id`),
  CONSTRAINT `inventory_documents_production_order_id_foreign` FOREIGN KEY (`production_order_id`) REFERENCES `production_orders` (`id`) ON DELETE SET NULL,
  CONSTRAINT `inventory_documents_purchase_order_id_foreign` FOREIGN KEY (`purchase_order_id`) REFERENCES `purchase_orders` (`id`) ON DELETE SET NULL,
  CONSTRAINT `inventory_documents_reversal_of_id_foreign` FOREIGN KEY (`reversal_of_id`) REFERENCES `inventory_documents` (`id`) ON DELETE SET NULL,
  CONSTRAINT `inventory_documents_source_document_id_foreign` FOREIGN KEY (`source_document_id`) REFERENCES `inventory_documents` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=18 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `inventory_documents`
--

LOCK TABLES `inventory_documents` WRITE;
/*!40000 ALTER TABLE `inventory_documents` DISABLE KEYS */;
INSERT INTO `inventory_documents` VALUES (4,604,423,332,NULL,'goods_receipt',NULL,0,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,'2026-01-10','2026-01-10','[PRUEBA] Existencias iniciales — almacén principal','posted',NULL,358,'2026-09-18 03:56:36','2026-09-18 03:56:36'),(5,604,423,333,NULL,'goods_receipt',NULL,0,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,'2026-01-10','2026-01-10','[PRUEBA] Existencias iniciales — bodega de tránsito','posted',NULL,358,'2026-09-18 03:56:36','2026-09-18 03:56:36'),(6,604,423,334,NULL,'goods_receipt',NULL,0,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,'2026-01-10','2026-01-10','[PRUEBA] Existencias iniciales — almacén con ubicaciones','posted',NULL,358,'2026-09-18 03:56:36','2026-09-18 03:56:36'),(9,604,426,342,343,'purchase_receipt',100,0,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,'2026-02-10','2026-02-10','[PRUEBA] Entrada por compra de mercadería','posted',NULL,358,'2026-09-18 04:02:42','2026-09-18 04:02:42'),(10,604,424,344,NULL,'sales_issue',NULL,0,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,'2026-03-05','2026-03-05','[PRUEBA] Salida por venta de marzo','posted',NULL,358,'2026-09-18 04:02:42','2026-09-18 04:02:42'),(14,604,425,361,NULL,'transfer',NULL,0,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,'2026-09-20','2026-09-20','TRASLADO POR VENTA COMPROBADA','posted',NULL,359,'2026-09-20 01:55:19','2026-09-20 01:55:19'),(15,604,424,362,NULL,'production_issue',NULL,0,NULL,NULL,NULL,NULL,NULL,3,NULL,NULL,'2026-09-14','2026-09-14','Emisión a producción — tanda de 10 gabinetes','posted',NULL,NULL,'2026-09-23 03:54:08','2026-09-23 03:54:08'),(16,604,423,363,NULL,'production_receipt',NULL,0,NULL,NULL,NULL,NULL,NULL,3,NULL,NULL,'2026-09-15','2026-09-15','Recibo de producción — 10 gabinetes terminados','posted',NULL,NULL,'2026-09-23 03:54:08','2026-09-23 03:54:08'),(17,604,424,364,NULL,'production_issue',NULL,0,NULL,NULL,NULL,NULL,NULL,4,NULL,NULL,'2026-09-18','2026-09-18','Emisión a producción — tanda de 5 racks','posted',NULL,NULL,'2026-09-23 03:54:08','2026-09-23 03:54:08');
/*!40000 ALTER TABLE `inventory_documents` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `inventory_write_down_lines`
--

DROP TABLE IF EXISTS `inventory_write_down_lines`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `inventory_write_down_lines` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `inventory_write_down_id` bigint unsigned NOT NULL,
  `line_number` int unsigned NOT NULL,
  `item_id` bigint unsigned NOT NULL,
  `quantity` decimal(18,6) NOT NULL,
  `unit_cost_local` decimal(18,6) NOT NULL,
  `cost_value_local` decimal(18,2) NOT NULL,
  `nrv_unit_local` decimal(18,6) NOT NULL,
  `nrv_value_local` decimal(18,2) NOT NULL,
  `target_allowance_local` decimal(18,2) NOT NULL,
  `previous_allowance_local` decimal(18,2) NOT NULL,
  `movement_local` decimal(18,2) NOT NULL,
  `reason` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `inventory_write_down_lines_inventory_write_down_id_foreign` (`inventory_write_down_id`),
  KEY `inventory_write_down_lines_item_id_index` (`item_id`),
  CONSTRAINT `inventory_write_down_lines_inventory_write_down_id_foreign` FOREIGN KEY (`inventory_write_down_id`) REFERENCES `inventory_write_downs` (`id`) ON DELETE CASCADE,
  CONSTRAINT `inventory_write_down_lines_item_id_foreign` FOREIGN KEY (`item_id`) REFERENCES `items` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `inventory_write_down_lines`
--

LOCK TABLES `inventory_write_down_lines` WRITE;
/*!40000 ALTER TABLE `inventory_write_down_lines` DISABLE KEYS */;
/*!40000 ALTER TABLE `inventory_write_down_lines` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `inventory_write_downs`
--

DROP TABLE IF EXISTS `inventory_write_downs`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `inventory_write_downs` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `company_id` bigint unsigned NOT NULL,
  `document_type_id` bigint unsigned NOT NULL,
  `journal_entry_id` bigint unsigned NOT NULL,
  `as_of` date NOT NULL,
  `description` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `status` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'posted',
  `reversal_of_id` bigint unsigned DEFAULT NULL,
  `created_by` bigint unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `inventory_write_downs_document_type_id_foreign` (`document_type_id`),
  KEY `inventory_write_downs_journal_entry_id_foreign` (`journal_entry_id`),
  KEY `inventory_write_downs_reversal_of_id_foreign` (`reversal_of_id`),
  KEY `inventory_write_downs_created_by_foreign` (`created_by`),
  KEY `inventory_write_downs_company_id_as_of_index` (`company_id`,`as_of`),
  CONSTRAINT `inventory_write_downs_company_id_foreign` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`) ON DELETE CASCADE,
  CONSTRAINT `inventory_write_downs_created_by_foreign` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`),
  CONSTRAINT `inventory_write_downs_document_type_id_foreign` FOREIGN KEY (`document_type_id`) REFERENCES `document_types` (`id`),
  CONSTRAINT `inventory_write_downs_journal_entry_id_foreign` FOREIGN KEY (`journal_entry_id`) REFERENCES `journal_entries` (`id`),
  CONSTRAINT `inventory_write_downs_reversal_of_id_foreign` FOREIGN KEY (`reversal_of_id`) REFERENCES `inventory_write_downs` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `inventory_write_downs`
--

LOCK TABLES `inventory_write_downs` WRITE;
/*!40000 ALTER TABLE `inventory_write_downs` DISABLE KEYS */;
/*!40000 ALTER TABLE `inventory_write_downs` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `item_bins`
--

DROP TABLE IF EXISTS `item_bins`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `item_bins` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `item_id` bigint unsigned NOT NULL,
  `warehouse_bin_id` bigint unsigned NOT NULL,
  `on_hand` decimal(18,6) NOT NULL DEFAULT '0.000000',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `item_bins_item_id_warehouse_bin_id_unique` (`item_id`,`warehouse_bin_id`),
  KEY `item_bins_warehouse_bin_id_foreign` (`warehouse_bin_id`),
  CONSTRAINT `item_bins_item_id_foreign` FOREIGN KEY (`item_id`) REFERENCES `items` (`id`) ON DELETE CASCADE,
  CONSTRAINT `item_bins_warehouse_bin_id_foreign` FOREIGN KEY (`warehouse_bin_id`) REFERENCES `warehouse_bins` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=7 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `item_bins`
--

LOCK TABLES `item_bins` WRITE;
/*!40000 ALTER TABLE `item_bins` DISABLE KEYS */;
INSERT INTO `item_bins` VALUES (4,4,1,20.000000,'2026-09-18 03:56:36','2026-09-18 03:56:36'),(5,5,2,25.000000,'2026-09-18 03:56:36','2026-09-18 03:56:36'),(6,7,4,30.000000,'2026-09-18 03:56:36','2026-09-18 03:56:36');
/*!40000 ALTER TABLE `item_bins` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `item_groups`
--

DROP TABLE IF EXISTS `item_groups`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `item_groups` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `company_id` bigint unsigned NOT NULL,
  `code` varchar(20) COLLATE utf8mb4_unicode_ci NOT NULL,
  `name` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `status` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'active' COMMENT 'active|inactive',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `item_groups_company_id_code_unique` (`company_id`,`code`),
  CONSTRAINT `item_groups_company_id_foreign` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=7 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `item_groups`
--

LOCK TABLES `item_groups` WRITE;
/*!40000 ALTER TABLE `item_groups` DISABLE KEYS */;
INSERT INTO `item_groups` VALUES (2,604,'MERC','Mercadería para reventa','active','2026-09-18 03:54:05','2026-09-18 03:54:05'),(3,604,'MATP','Materia prima','active','2026-09-18 03:54:05','2026-09-18 03:54:05'),(4,604,'PTER','Producto terminado','active','2026-09-18 03:54:05','2026-09-18 03:54:05'),(5,604,'SUMI','Suministros y consumibles','active','2026-09-18 03:54:05','2026-09-18 03:54:05'),(6,604,'SERV','Servicios','active','2026-09-18 03:54:05','2026-09-18 03:54:05');
/*!40000 ALTER TABLE `item_groups` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `item_lot_stock`
--

DROP TABLE IF EXISTS `item_lot_stock`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `item_lot_stock` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `item_lot_id` bigint unsigned NOT NULL,
  `warehouse_id` bigint unsigned NOT NULL,
  `warehouse_bin_id` bigint unsigned DEFAULT NULL,
  `on_hand` decimal(18,6) NOT NULL DEFAULT '0.000000',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `item_lot_stock_unique` (`item_lot_id`,`warehouse_id`,`warehouse_bin_id`),
  KEY `item_lot_stock_warehouse_id_foreign` (`warehouse_id`),
  KEY `item_lot_stock_warehouse_bin_id_foreign` (`warehouse_bin_id`),
  CONSTRAINT `item_lot_stock_item_lot_id_foreign` FOREIGN KEY (`item_lot_id`) REFERENCES `item_lots` (`id`) ON DELETE CASCADE,
  CONSTRAINT `item_lot_stock_warehouse_bin_id_foreign` FOREIGN KEY (`warehouse_bin_id`) REFERENCES `warehouse_bins` (`id`) ON DELETE CASCADE,
  CONSTRAINT `item_lot_stock_warehouse_id_foreign` FOREIGN KEY (`warehouse_id`) REFERENCES `warehouses` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `item_lot_stock`
--

LOCK TABLES `item_lot_stock` WRITE;
/*!40000 ALTER TABLE `item_lot_stock` DISABLE KEYS */;
/*!40000 ALTER TABLE `item_lot_stock` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `item_lots`
--

DROP TABLE IF EXISTS `item_lots`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `item_lots` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `item_id` bigint unsigned NOT NULL,
  `code` varchar(40) COLLATE utf8mb4_unicode_ci NOT NULL,
  `expires_at` date DEFAULT NULL,
  `status` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'active',
  `notes` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `item_lots_item_id_code_unique` (`item_id`,`code`),
  KEY `item_lots_item_id_expires_at_index` (`item_id`,`expires_at`),
  CONSTRAINT `item_lots_item_id_foreign` FOREIGN KEY (`item_id`) REFERENCES `items` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `item_lots`
--

LOCK TABLES `item_lots` WRITE;
/*!40000 ALTER TABLE `item_lots` DISABLE KEYS */;
/*!40000 ALTER TABLE `item_lots` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `item_serials`
--

DROP TABLE IF EXISTS `item_serials`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `item_serials` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `item_id` bigint unsigned NOT NULL,
  `serial_number` varchar(80) COLLATE utf8mb4_unicode_ci NOT NULL,
  `status` varchar(20) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'in_stock',
  `warehouse_id` bigint unsigned DEFAULT NULL,
  `warehouse_bin_id` bigint unsigned DEFAULT NULL,
  `item_lot_id` bigint unsigned DEFAULT NULL,
  `received_document_id` bigint unsigned DEFAULT NULL,
  `issued_document_id` bigint unsigned DEFAULT NULL,
  `received_at` date DEFAULT NULL,
  `issued_at` date DEFAULT NULL,
  `warranty_until` date DEFAULT NULL,
  `notes` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `item_serials_item_id_serial_number_unique` (`item_id`,`serial_number`),
  KEY `item_serials_warehouse_id_foreign` (`warehouse_id`),
  KEY `item_serials_warehouse_bin_id_foreign` (`warehouse_bin_id`),
  KEY `item_serials_item_lot_id_foreign` (`item_lot_id`),
  KEY `item_serials_received_document_id_foreign` (`received_document_id`),
  KEY `item_serials_issued_document_id_foreign` (`issued_document_id`),
  KEY `item_serials_stock_index` (`item_id`,`status`,`warehouse_id`),
  CONSTRAINT `item_serials_issued_document_id_foreign` FOREIGN KEY (`issued_document_id`) REFERENCES `inventory_documents` (`id`) ON DELETE SET NULL,
  CONSTRAINT `item_serials_item_id_foreign` FOREIGN KEY (`item_id`) REFERENCES `items` (`id`) ON DELETE CASCADE,
  CONSTRAINT `item_serials_item_lot_id_foreign` FOREIGN KEY (`item_lot_id`) REFERENCES `item_lots` (`id`) ON DELETE SET NULL,
  CONSTRAINT `item_serials_received_document_id_foreign` FOREIGN KEY (`received_document_id`) REFERENCES `inventory_documents` (`id`) ON DELETE SET NULL,
  CONSTRAINT `item_serials_warehouse_bin_id_foreign` FOREIGN KEY (`warehouse_bin_id`) REFERENCES `warehouse_bins` (`id`) ON DELETE SET NULL,
  CONSTRAINT `item_serials_warehouse_id_foreign` FOREIGN KEY (`warehouse_id`) REFERENCES `warehouses` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `item_serials`
--

LOCK TABLES `item_serials` WRITE;
/*!40000 ALTER TABLE `item_serials` DISABLE KEYS */;
/*!40000 ALTER TABLE `item_serials` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `item_warehouses`
--

DROP TABLE IF EXISTS `item_warehouses`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `item_warehouses` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `item_id` bigint unsigned NOT NULL,
  `warehouse_id` bigint unsigned NOT NULL,
  `on_hand` decimal(18,6) NOT NULL DEFAULT '0.000000',
  `reserved` decimal(18,6) NOT NULL DEFAULT '0.000000' COMMENT 'apartado por órdenes de pedido abiertas; disponible = on_hand - reserved',
  `ordered` decimal(18,6) NOT NULL DEFAULT '0.000000' COMMENT 'pendiente de recibir por órdenes de compra abiertas; informativo, no restringe',
  `minimum_stock` decimal(18,6) DEFAULT NULL,
  `maximum_stock` decimal(18,6) DEFAULT NULL COMMENT 'nivel hasta el que se repone; null = reponer solo hasta el mínimo',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `item_warehouses_item_id_warehouse_id_unique` (`item_id`,`warehouse_id`),
  KEY `item_warehouses_warehouse_id_foreign` (`warehouse_id`),
  CONSTRAINT `item_warehouses_item_id_foreign` FOREIGN KEY (`item_id`) REFERENCES `items` (`id`) ON DELETE CASCADE,
  CONSTRAINT `item_warehouses_warehouse_id_foreign` FOREIGN KEY (`warehouse_id`) REFERENCES `warehouses` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=46 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `item_warehouses`
--

LOCK TABLES `item_warehouses` WRITE;
/*!40000 ALTER TABLE `item_warehouses` DISABLE KEYS */;
INSERT INTO `item_warehouses` VALUES (24,2,3,14.000000,0.000000,0.000000,NULL,NULL,'2026-09-18 03:56:36','2026-09-18 04:02:42'),(25,3,3,11.000000,0.000000,0.000000,NULL,NULL,'2026-09-18 03:56:36','2026-09-18 04:02:42'),(26,4,3,29.000000,0.000000,0.000000,NULL,NULL,'2026-09-18 03:56:36','2026-09-20 01:55:19'),(27,5,3,50.000000,0.000000,0.000000,NULL,NULL,'2026-09-18 03:56:36','2026-09-18 03:56:36'),(28,6,3,33.000000,0.000000,0.000000,NULL,NULL,'2026-09-18 03:56:36','2026-09-18 04:02:42'),(29,7,3,60.000000,0.000000,0.000000,NULL,NULL,'2026-09-18 03:56:36','2026-09-18 03:56:36'),(30,8,3,20.000000,0.000000,0.000000,NULL,NULL,'2026-09-18 03:56:36','2026-09-18 03:56:36'),(31,9,3,12.000000,0.000000,0.000000,NULL,NULL,'2026-09-18 03:56:36','2026-09-18 03:56:36'),(32,10,3,412.200000,0.000000,0.000000,NULL,NULL,'2026-09-18 03:56:36','2026-09-23 03:54:08'),(33,11,3,117.000000,0.000000,0.000000,NULL,NULL,'2026-09-18 03:56:36','2026-09-23 03:54:08'),(34,12,3,19.000000,0.000000,0.000000,NULL,NULL,'2026-09-18 03:56:36','2026-09-23 03:54:08'),(35,13,3,16.000000,0.000000,0.000000,NULL,NULL,'2026-09-18 03:56:36','2026-09-23 03:54:08'),(36,14,3,3.000000,0.000000,0.000000,NULL,NULL,'2026-09-18 03:56:36','2026-09-18 03:56:36'),(37,15,3,30.000000,0.000000,0.000000,NULL,NULL,'2026-09-18 03:56:36','2026-09-18 03:56:36'),(38,16,3,14.000000,0.000000,0.000000,NULL,NULL,'2026-09-18 03:56:36','2026-09-18 03:56:36'),(39,17,3,20.000000,0.000000,0.000000,NULL,NULL,'2026-09-18 03:56:36','2026-09-18 03:56:36'),(40,2,4,5.000000,0.000000,0.000000,NULL,NULL,'2026-09-18 03:56:36','2026-09-18 03:56:36'),(41,6,4,3.000000,0.000000,0.000000,NULL,NULL,'2026-09-18 03:56:36','2026-09-18 03:56:36'),(42,4,5,20.000000,0.000000,0.000000,NULL,NULL,'2026-09-18 03:56:36','2026-09-18 03:56:36'),(43,5,5,25.000000,0.000000,0.000000,NULL,NULL,'2026-09-18 03:56:36','2026-09-18 03:56:36'),(44,7,5,30.000000,0.000000,0.000000,NULL,NULL,'2026-09-18 03:56:36','2026-09-18 03:56:36'),(45,4,4,1.000000,0.000000,0.000000,NULL,NULL,'2026-09-20 01:55:19','2026-09-20 01:55:19');
/*!40000 ALTER TABLE `item_warehouses` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `items`
--

DROP TABLE IF EXISTS `items`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `items` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `company_id` bigint unsigned NOT NULL,
  `code` varchar(40) COLLATE utf8mb4_unicode_ci NOT NULL,
  `name` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `item_group_id` bigint unsigned DEFAULT NULL,
  `uom_id` bigint unsigned NOT NULL,
  `barcode` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `cabys_code` varchar(13) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'Código CAByS del BCCR, 13 dígitos; obligatorio para facturar el artículo',
  `fiscal_unit_code` varchar(15) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'Unidad de medida oficial v4.4 (Nota 15): Unid, kg, L, Sp...',
  `iva_rate_code` varchar(2) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'Código de tarifa de IVA v4.4 (Nota 8.1) por defecto del artículo',
  `is_inventory_item` tinyint(1) NOT NULL DEFAULT '1' COMMENT 'false = servicio: se compra/vende pero no lleva kardex ni costo',
  `is_sales_item` tinyint(1) NOT NULL DEFAULT '1',
  `is_purchase_item` tinyint(1) NOT NULL DEFAULT '1',
  `tracks_lots` tinyint(1) NOT NULL DEFAULT '0',
  `tracks_serials` tinyint(1) NOT NULL DEFAULT '0',
  `minimum_stock` decimal(18,6) NOT NULL DEFAULT '0.000000' COMMENT 'mínimo por defecto del artículo; cada almacén puede sobrescribirlo',
  `maximum_stock` decimal(18,6) DEFAULT NULL COMMENT 'máximo por defecto; null = reponer solo hasta el mínimo',
  `tax_rate_id` bigint unsigned DEFAULT NULL,
  `avg_cost_local` decimal(18,6) NOT NULL DEFAULT '0.000000',
  `avg_cost_foreign` decimal(18,6) NOT NULL DEFAULT '0.000000' COMMENT 'avg_cost_local/avg_cost_foreign es el TC congelado del stock',
  `status` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'active' COMMENT 'active|inactive',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `items_company_id_code_unique` (`company_id`,`code`),
  KEY `items_item_group_id_foreign` (`item_group_id`),
  KEY `items_uom_id_foreign` (`uom_id`),
  KEY `items_tax_rate_id_foreign` (`tax_rate_id`),
  KEY `items_company_id_item_group_id_index` (`company_id`,`item_group_id`),
  CONSTRAINT `items_company_id_foreign` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`) ON DELETE CASCADE,
  CONSTRAINT `items_item_group_id_foreign` FOREIGN KEY (`item_group_id`) REFERENCES `item_groups` (`id`) ON DELETE SET NULL,
  CONSTRAINT `items_tax_rate_id_foreign` FOREIGN KEY (`tax_rate_id`) REFERENCES `tax_rates` (`id`) ON DELETE SET NULL,
  CONSTRAINT `items_uom_id_foreign` FOREIGN KEY (`uom_id`) REFERENCES `units_of_measure` (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=22 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `items`
--

LOCK TABLES `items` WRITE;
/*!40000 ALTER TABLE `items` DISABLE KEYS */;
INSERT INTO `items` VALUES (2,604,'ART-0001','Laptop 14\" 16GB RAM',2,3,NULL,NULL,NULL,NULL,1,1,1,0,0,0.000000,NULL,24,450000.000000,888.440843,'active','2026-09-18 03:54:05','2026-09-21 04:16:57'),(3,604,'ART-0002','Monitor 24\" Full HD',2,3,NULL,'8528520000000','Unid','08',1,1,1,0,0,0.000000,NULL,24,95000.000000,188.025729,'active','2026-09-18 03:54:05','2026-09-18 03:56:36'),(4,604,'ART-0003','Teclado inalámbrico',2,3,NULL,'8471600000000','Unid','08',1,1,1,0,0,0.000000,NULL,24,12000.000000,23.750618,'active','2026-09-18 03:54:05','2026-09-18 03:56:36'),(5,604,'ART-0004','Mouse óptico USB',2,3,NULL,'8471600000001','Unid','08',1,1,1,0,0,0.000000,NULL,24,6500.000000,12.864918,'active','2026-09-18 03:54:05','2026-09-18 03:56:36'),(6,604,'ART-0005','Impresora multifuncional',2,3,NULL,'8443310000000','Unid','08',1,1,1,0,0,0.000000,NULL,24,140000.000000,275.898307,'active','2026-09-18 03:54:05','2026-09-18 04:02:42'),(7,604,'ART-0006','Cable HDMI 2 m',2,3,NULL,'8544420000000','Unid','08',1,1,1,0,0,0.000000,NULL,24,3500.000000,6.927263,'active','2026-09-18 03:54:05','2026-09-18 03:56:36'),(8,604,'ART-0007','Disco duro externo 1 TB',2,3,NULL,'8471700000000','Unid','08',1,1,1,0,0,0.000000,NULL,24,38000.000000,75.210291,'active','2026-09-18 03:54:05','2026-09-18 03:56:36'),(9,604,'ART-0008','Silla ergonómica de oficina',2,3,NULL,'9401300000000','Unid','08',1,1,1,0,0,0.000000,NULL,24,85000.000000,168.233547,'active','2026-09-18 03:54:05','2026-09-18 03:56:36'),(10,604,'MAT-0001','Lámina de acero 1,2 mm',3,5,NULL,'7209160000000','kg','08',1,0,1,0,0,0.000000,NULL,24,1800.000000,3.562592,'active','2026-09-18 03:54:05','2026-09-18 03:56:36'),(11,604,'MAT-0002','Pintura electrostática negra',3,5,NULL,'3208100000000','kg','08',1,0,1,0,0,0.000000,NULL,24,4200.000000,8.312716,'active','2026-09-18 03:54:05','2026-09-18 03:56:36'),(12,604,'MAT-0003','Tornillería surtida',3,4,NULL,'7318150000000','Unid','08',1,0,1,0,0,0.000000,NULL,24,9500.000000,18.802572,'active','2026-09-18 03:54:05','2026-09-18 03:56:36'),(13,604,'PTE-0001','Gabinete metálico ensamblado',4,3,NULL,'9403200000000','Unid','08',1,1,0,0,0,0.000000,NULL,24,83650.000000,165.267521,'active','2026-09-18 03:54:05','2026-09-23 03:54:08'),(14,604,'PTE-0002','Rack de servidores 42U',4,3,NULL,'9403200000001','Unid','08',1,1,0,0,0,0.000000,NULL,24,620000.000000,1227.115289,'active','2026-09-18 03:54:05','2026-09-18 03:56:36'),(15,604,'SUM-0001','Resma de papel bond carta',5,4,NULL,'4802560000000','Unid','08',1,0,1,0,0,0.000000,NULL,24,18000.000000,35.625927,'active','2026-09-18 03:54:05','2026-09-18 03:56:36'),(16,604,'SUM-0002','Tóner negro compatible',5,3,NULL,'3215900000000','Unid','08',1,0,1,0,0,0.000000,NULL,24,22000.000000,43.542800,'active','2026-09-18 03:54:05','2026-09-18 03:56:36'),(17,604,'SUM-0003','Café molido 1 kg (cafetería)',5,5,NULL,'0901210000000','kg','02',1,0,1,0,0,0.000000,NULL,27,7800.000000,15.437902,'active','2026-09-18 03:54:05','2026-09-18 03:56:36'),(18,604,'SRV-0001','Hora de soporte técnico',6,8,NULL,'8020000000000','Sp','08',0,1,0,0,0,0.000000,NULL,24,0.000000,0.000000,'active','2026-09-18 03:54:05','2026-09-18 03:54:05'),(19,604,'SRV-0002','Instalación y configuración',6,9,NULL,'8020000000001','Sp','08',0,1,0,0,0,0.000000,NULL,24,0.000000,0.000000,'active','2026-09-18 03:54:05','2026-09-18 03:54:05'),(20,604,'SRV-0003','Capacitación exenta a entidad pública',6,8,NULL,'9200000000000','Sp','10',0,1,0,0,0,0.000000,NULL,28,0.000000,0.000000,'active','2026-09-18 03:54:05','2026-09-18 03:54:05'),(21,604,'ART-0099','Adaptador VGA (descontinuado)',2,3,NULL,'8471800000000','Unid','08',1,0,0,0,0,0.000000,NULL,24,0.000000,0.000000,'inactive','2026-09-18 03:54:05','2026-09-18 03:54:05');
/*!40000 ALTER TABLE `items` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `job_batches`
--

DROP TABLE IF EXISTS `job_batches`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `job_batches` (
  `id` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `name` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `total_jobs` int NOT NULL,
  `pending_jobs` int NOT NULL,
  `failed_jobs` int NOT NULL,
  `failed_job_ids` longtext COLLATE utf8mb4_unicode_ci NOT NULL,
  `options` mediumtext COLLATE utf8mb4_unicode_ci,
  `cancelled_at` int DEFAULT NULL,
  `created_at` int NOT NULL,
  `finished_at` int DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `job_batches`
--

LOCK TABLES `job_batches` WRITE;
/*!40000 ALTER TABLE `job_batches` DISABLE KEYS */;
/*!40000 ALTER TABLE `job_batches` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `job_positions`
--

DROP TABLE IF EXISTS `job_positions`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `job_positions` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `company_id` bigint unsigned NOT NULL,
  `code` varchar(20) COLLATE utf8mb4_unicode_ci NOT NULL,
  `name` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `department_id` bigint unsigned DEFAULT NULL,
  `ccss_occupation_code` varchar(20) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `ccss_occupation_name` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `min_salary` decimal(18,2) DEFAULT NULL,
  `max_salary` decimal(18,2) DEFAULT NULL,
  `description` text COLLATE utf8mb4_unicode_ci,
  `status` varchar(20) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'active',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `job_positions_company_id_code_unique` (`company_id`,`code`),
  KEY `job_positions_department_id_foreign` (`department_id`),
  KEY `job_positions_company_id_status_index` (`company_id`,`status`),
  CONSTRAINT `job_positions_company_id_foreign` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`) ON DELETE CASCADE,
  CONSTRAINT `job_positions_department_id_foreign` FOREIGN KEY (`department_id`) REFERENCES `departments` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `job_positions`
--

LOCK TABLES `job_positions` WRITE;
/*!40000 ALTER TABLE `job_positions` DISABLE KEYS */;
/*!40000 ALTER TABLE `job_positions` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `jobs`
--

DROP TABLE IF EXISTS `jobs`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `jobs` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `queue` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `payload` longtext COLLATE utf8mb4_unicode_ci NOT NULL,
  `attempts` smallint unsigned NOT NULL,
  `reserved_at` int unsigned DEFAULT NULL,
  `available_at` int unsigned NOT NULL,
  `created_at` int unsigned NOT NULL,
  PRIMARY KEY (`id`),
  KEY `jobs_queue_index` (`queue`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `jobs`
--

LOCK TABLES `jobs` WRITE;
/*!40000 ALTER TABLE `jobs` DISABLE KEYS */;
/*!40000 ALTER TABLE `jobs` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `journal_detail_taxes`
--

DROP TABLE IF EXISTS `journal_detail_taxes`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `journal_detail_taxes` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `journal_detail_id` bigint unsigned NOT NULL,
  `tax_rate_id` bigint unsigned NOT NULL,
  `taxable_base` decimal(18,2) NOT NULL,
  `tax_amount` decimal(18,2) NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `journal_detail_taxes_journal_detail_id_foreign` (`journal_detail_id`),
  KEY `journal_detail_taxes_tax_rate_id_foreign` (`tax_rate_id`),
  CONSTRAINT `journal_detail_taxes_journal_detail_id_foreign` FOREIGN KEY (`journal_detail_id`) REFERENCES `journal_details` (`id`) ON DELETE CASCADE,
  CONSTRAINT `journal_detail_taxes_tax_rate_id_foreign` FOREIGN KEY (`tax_rate_id`) REFERENCES `tax_rates` (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=33 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `journal_detail_taxes`
--

LOCK TABLES `journal_detail_taxes` WRITE;
/*!40000 ALTER TABLE `journal_detail_taxes` DISABLE KEYS */;
INSERT INTO `journal_detail_taxes` VALUES (20,1267,24,3000000.00,390000.00,'2026-09-18 03:45:25','2026-09-18 03:45:25'),(21,1270,24,8000000.00,1040000.00,'2026-09-18 03:45:25','2026-09-18 03:45:25'),(22,1274,24,5000000.00,650000.00,'2026-09-18 03:45:25','2026-09-18 03:45:25'),(23,1289,24,1050000.00,136500.00,'2026-09-18 03:45:25','2026-09-18 03:45:25'),(24,1293,24,8000000.00,1040000.00,'2026-09-18 03:45:25','2026-09-18 03:45:25'),(25,1299,24,1450000.00,188500.00,'2026-09-18 03:45:26','2026-09-18 03:45:26'),(27,1417,24,8000000.00,1040000.00,'2026-09-18 04:02:42','2026-09-18 04:02:42');
/*!40000 ALTER TABLE `journal_detail_taxes` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `journal_details`
--

DROP TABLE IF EXISTS `journal_details`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `journal_details` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `journal_entry_id` bigint unsigned NOT NULL,
  `line_number` int unsigned NOT NULL,
  `account_id` bigint unsigned NOT NULL,
  `description` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `electronic_key` varchar(50) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `business_partner_id` bigint unsigned DEFAULT NULL COMMENT 'FK a business_partners se agrega en la migración de Fase 1 (CxC/CxP)',
  `cost_center_id` bigint unsigned DEFAULT NULL,
  `cost_allocation_rule_id` bigint unsigned DEFAULT NULL,
  `currency_id` bigint unsigned NOT NULL,
  `exchange_rate_lc_fc` decimal(18,6) DEFAULT NULL,
  `exchange_rate_fc_sc` decimal(18,6) DEFAULT NULL,
  `debit_local` decimal(18,2) NOT NULL DEFAULT '0.00',
  `credit_local` decimal(18,2) NOT NULL DEFAULT '0.00',
  `debit_foreign` decimal(18,2) NOT NULL DEFAULT '0.00',
  `credit_foreign` decimal(18,2) NOT NULL DEFAULT '0.00',
  `debit_system` decimal(18,2) NOT NULL DEFAULT '0.00',
  `credit_system` decimal(18,2) NOT NULL DEFAULT '0.00',
  `due_date` date DEFAULT NULL,
  `reference_document` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `reference_document_date` date DEFAULT NULL,
  `bank_reconciled` tinyint(1) NOT NULL DEFAULT '0',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `journal_details_currency_id_foreign` (`currency_id`),
  KEY `journal_details_journal_entry_id_line_number_index` (`journal_entry_id`,`line_number`),
  KEY `journal_details_account_id_index` (`account_id`),
  KEY `journal_details_business_partner_id_foreign` (`business_partner_id`),
  KEY `journal_details_cost_center_id_foreign` (`cost_center_id`),
  KEY `journal_details_cost_allocation_rule_id_foreign` (`cost_allocation_rule_id`),
  CONSTRAINT `journal_details_account_id_foreign` FOREIGN KEY (`account_id`) REFERENCES `chart_of_accounts` (`id`),
  CONSTRAINT `journal_details_business_partner_id_foreign` FOREIGN KEY (`business_partner_id`) REFERENCES `business_partners` (`id`) ON DELETE SET NULL,
  CONSTRAINT `journal_details_cost_allocation_rule_id_foreign` FOREIGN KEY (`cost_allocation_rule_id`) REFERENCES `cost_allocation_rules` (`id`) ON DELETE SET NULL,
  CONSTRAINT `journal_details_cost_center_id_foreign` FOREIGN KEY (`cost_center_id`) REFERENCES `cost_centers` (`id`) ON DELETE SET NULL,
  CONSTRAINT `journal_details_currency_id_foreign` FOREIGN KEY (`currency_id`) REFERENCES `currencies` (`id`),
  CONSTRAINT `journal_details_journal_entry_id_foreign` FOREIGN KEY (`journal_entry_id`) REFERENCES `journal_entries` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=1523 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `journal_details`
--

LOCK TABLES `journal_details` WRITE;
/*!40000 ALTER TABLE `journal_details` DISABLE KEYS */;
INSERT INTO `journal_details` VALUES (550,275,1,919,'Saldo incial corte 31 jul.26 empresa marcha.',NULL,NULL,NULL,NULL,949,1.000000,1.000000,135000.00,0.00,135000.00,0.00,135000.00,0.00,NULL,NULL,NULL,0,'2026-09-03 03:42:43','2026-09-03 03:42:43'),(551,275,2,921,'Saldo incial corte 31 jul.26 empresa marcha.',NULL,NULL,NULL,NULL,949,1.000000,1.000000,598224.55,0.00,598224.55,0.00,598224.55,0.00,NULL,NULL,NULL,0,'2026-09-03 03:42:43','2026-09-03 03:42:43'),(552,275,3,924,'Saldo incial corte 31 jul.26 empresa marcha.',NULL,NULL,NULL,NULL,949,1.000000,1.000000,16000000.00,0.00,16000000.00,0.00,16000000.00,0.00,NULL,NULL,NULL,0,'2026-09-03 03:42:43','2026-09-03 03:42:43'),(553,275,4,925,'Saldo incial corte 31 jul.26 empresa marcha.',NULL,NULL,NULL,NULL,949,1.000000,1.000000,93500.11,0.00,93500.11,0.00,93500.11,0.00,NULL,NULL,NULL,0,'2026-09-03 03:42:43','2026-09-03 03:42:43'),(554,275,5,927,'Saldo incial corte 31 jul.26 empresa marcha.',NULL,NULL,NULL,NULL,949,1.000000,1.000000,25000000.00,0.00,25000000.00,0.00,25000000.00,0.00,NULL,NULL,NULL,0,'2026-09-03 03:42:43','2026-09-03 03:42:43'),(555,275,6,928,'Saldo incial corte 31 jul.26 empresa marcha.',NULL,NULL,NULL,NULL,949,1.000000,1.000000,44000000.00,0.00,44000000.00,0.00,44000000.00,0.00,NULL,NULL,NULL,0,'2026-09-03 03:42:43','2026-09-03 03:42:43'),(556,275,7,929,'Saldo incial corte 31 jul.26 empresa marcha.',NULL,NULL,NULL,NULL,949,1.000000,1.000000,33671931.61,0.00,33671931.61,0.00,33671931.61,0.00,NULL,NULL,NULL,0,'2026-09-03 03:42:43','2026-09-03 03:42:43'),(557,275,8,930,'Saldo incial corte 31 jul.26 empresa marcha.',NULL,NULL,NULL,NULL,949,1.000000,1.000000,306129.03,0.00,306129.03,0.00,306129.03,0.00,NULL,NULL,NULL,0,'2026-09-03 03:42:43','2026-09-03 03:42:43'),(558,275,9,933,'Saldo incial corte 31 jul.26 empresa marcha.',NULL,NULL,NULL,NULL,949,1.000000,1.000000,794198.05,0.00,794198.05,0.00,794198.05,0.00,NULL,NULL,NULL,0,'2026-09-03 03:42:43','2026-09-03 03:42:43'),(559,275,10,934,'Saldo incial corte 31 jul.26 empresa marcha.',NULL,NULL,NULL,NULL,949,1.000000,1.000000,9947735.00,0.00,9947735.00,0.00,9947735.00,0.00,NULL,NULL,NULL,0,'2026-09-03 03:42:43','2026-09-03 03:42:43'),(560,275,11,935,'Saldo incial corte 31 jul.26 empresa marcha.',NULL,NULL,NULL,NULL,949,1.000000,1.000000,9832018.56,0.00,9832018.56,0.00,9832018.56,0.00,NULL,NULL,NULL,0,'2026-09-03 03:42:43','2026-09-03 03:42:43'),(561,275,12,936,'Saldo incial corte 31 jul.26 empresa marcha.',NULL,NULL,NULL,NULL,949,1.000000,1.000000,2342023.00,0.00,2342023.00,0.00,2342023.00,0.00,NULL,NULL,NULL,0,'2026-09-03 03:42:43','2026-09-03 03:42:43'),(562,275,13,937,'Saldo incial corte 31 jul.26 empresa marcha.',NULL,NULL,NULL,NULL,949,1.000000,1.000000,26965.00,0.00,26965.00,0.00,26965.00,0.00,NULL,NULL,NULL,0,'2026-09-03 03:42:43','2026-09-03 03:42:43'),(563,275,14,938,'Saldo incial corte 31 jul.26 empresa marcha.',NULL,NULL,NULL,NULL,949,1.000000,1.000000,191775.32,0.00,191775.32,0.00,191775.32,0.00,NULL,NULL,NULL,0,'2026-09-03 03:42:43','2026-09-03 03:42:43'),(564,275,15,939,'Saldo incial corte 31 jul.26 empresa marcha.',NULL,NULL,NULL,NULL,949,1.000000,1.000000,661755.69,0.00,661755.69,0.00,661755.69,0.00,NULL,NULL,NULL,0,'2026-09-03 03:42:43','2026-09-03 03:42:43'),(565,275,16,940,'Saldo incial corte 31 jul.26 empresa marcha.',NULL,NULL,NULL,NULL,949,1.000000,1.000000,187212.00,0.00,187212.00,0.00,187212.00,0.00,NULL,NULL,NULL,0,'2026-09-03 03:42:43','2026-09-03 03:42:43'),(566,275,17,941,'Saldo incial corte 31 jul.26 empresa marcha.',NULL,NULL,NULL,NULL,949,1.000000,1.000000,41304.00,0.00,41304.00,0.00,41304.00,0.00,NULL,NULL,NULL,0,'2026-09-03 03:42:43','2026-09-03 03:42:43'),(567,275,18,942,'Saldo incial corte 31 jul.26 empresa marcha.',NULL,NULL,NULL,NULL,949,1.000000,1.000000,775782.66,0.00,775782.66,0.00,775782.66,0.00,NULL,NULL,NULL,0,'2026-09-03 03:42:43','2026-09-03 03:42:43'),(568,275,19,943,'Saldo incial corte 31 jul.26 empresa marcha.',NULL,NULL,NULL,NULL,949,1.000000,1.000000,687058.63,0.00,687058.63,0.00,687058.63,0.00,NULL,NULL,NULL,0,'2026-09-03 03:42:43','2026-09-03 03:42:43'),(569,275,20,944,'Saldo incial corte 31 jul.26 empresa marcha.',NULL,NULL,NULL,NULL,949,1.000000,1.000000,106325.25,0.00,106325.25,0.00,106325.25,0.00,NULL,NULL,NULL,0,'2026-09-03 03:42:43','2026-09-03 03:42:43'),(570,275,21,945,'Saldo incial corte 31 jul.26 empresa marcha.',NULL,NULL,NULL,NULL,949,1.000000,1.000000,1625419.09,0.00,1625419.09,0.00,1625419.09,0.00,NULL,NULL,NULL,0,'2026-09-03 03:42:43','2026-09-03 03:42:43'),(571,275,22,946,'Saldo incial corte 31 jul.26 empresa marcha.',NULL,NULL,NULL,NULL,949,1.000000,1.000000,311751.41,0.00,311751.41,0.00,311751.41,0.00,NULL,NULL,NULL,0,'2026-09-03 03:42:43','2026-09-03 03:42:43'),(572,275,23,947,'Saldo incial corte 31 jul.26 empresa marcha.',NULL,NULL,NULL,NULL,949,1.000000,1.000000,13135.40,0.00,13135.40,0.00,13135.40,0.00,NULL,NULL,NULL,0,'2026-09-03 03:42:43','2026-09-03 03:42:43'),(573,275,24,948,'Saldo incial corte 31 jul.26 empresa marcha.',NULL,NULL,NULL,NULL,949,1.000000,1.000000,162794.36,0.00,162794.36,0.00,162794.36,0.00,NULL,NULL,NULL,0,'2026-09-03 03:42:43','2026-09-03 03:42:43'),(574,275,25,949,'Saldo incial corte 31 jul.26 empresa marcha.',NULL,NULL,NULL,NULL,949,1.000000,1.000000,19413.00,0.00,19413.00,0.00,19413.00,0.00,NULL,NULL,NULL,0,'2026-09-03 03:42:43','2026-09-03 03:42:43'),(575,275,26,950,'Saldo incial corte 31 jul.26 empresa marcha.',NULL,NULL,NULL,NULL,949,1.000000,1.000000,162525.34,0.00,162525.34,0.00,162525.34,0.00,NULL,NULL,NULL,0,'2026-09-03 03:42:43','2026-09-03 03:42:43'),(576,275,27,951,'Saldo incial corte 31 jul.26 empresa marcha.',NULL,NULL,NULL,NULL,949,1.000000,1.000000,1415879.85,0.00,1415879.85,0.00,1415879.85,0.00,NULL,NULL,NULL,0,'2026-09-03 03:42:43','2026-09-03 03:42:43'),(577,275,28,953,'Saldo incial corte 31 jul.26 empresa marcha.',NULL,NULL,NULL,NULL,949,1.000000,1.000000,1762131.45,0.00,1762131.45,0.00,1762131.45,0.00,NULL,NULL,NULL,0,'2026-09-03 03:42:43','2026-09-03 03:42:43'),(578,275,29,954,'Saldo incial corte 31 jul.26 empresa marcha.',NULL,NULL,NULL,NULL,949,1.000000,1.000000,1057278.95,0.00,1057278.95,0.00,1057278.95,0.00,NULL,NULL,NULL,0,'2026-09-03 03:42:43','2026-09-03 03:42:43'),(579,275,30,955,'Saldo incial corte 31 jul.26 empresa marcha.',NULL,NULL,NULL,NULL,949,1.000000,1.000000,1406567.40,0.00,1406567.40,0.00,1406567.40,0.00,NULL,NULL,NULL,0,'2026-09-03 03:42:43','2026-09-03 03:42:43'),(580,275,31,956,'Saldo incial corte 31 jul.26 empresa marcha.',NULL,NULL,NULL,NULL,949,1.000000,1.000000,624500.00,0.00,624500.00,0.00,624500.00,0.00,NULL,NULL,NULL,0,'2026-09-03 03:42:43','2026-09-03 03:42:43'),(581,275,32,957,'Saldo incial corte 31 jul.26 empresa marcha.',NULL,NULL,NULL,NULL,949,1.000000,1.000000,70000.00,0.00,70000.00,0.00,70000.00,0.00,NULL,NULL,NULL,0,'2026-09-03 03:42:43','2026-09-03 03:42:43'),(582,275,33,958,'Saldo incial corte 31 jul.26 empresa marcha.',NULL,NULL,NULL,NULL,949,1.000000,1.000000,59000.00,0.00,59000.00,0.00,59000.00,0.00,NULL,NULL,NULL,0,'2026-09-03 03:42:43','2026-09-03 03:42:43'),(583,275,34,959,'Saldo incial corte 31 jul.26 empresa marcha.',NULL,NULL,NULL,NULL,949,1.000000,1.000000,248000.00,0.00,248000.00,0.00,248000.00,0.00,NULL,NULL,NULL,0,'2026-09-03 03:42:43','2026-09-03 03:42:43'),(584,275,35,961,'Saldo incial corte 31 jul.26 empresa marcha.',NULL,NULL,NULL,NULL,949,1.000000,1.000000,42500.00,0.00,42500.00,0.00,42500.00,0.00,NULL,NULL,NULL,0,'2026-09-03 03:42:43','2026-09-03 03:42:43'),(585,275,36,962,'Saldo incial corte 31 jul.26 empresa marcha.',NULL,NULL,NULL,NULL,949,1.000000,1.000000,77165.00,0.00,77165.00,0.00,77165.00,0.00,NULL,NULL,NULL,0,'2026-09-03 03:42:43','2026-09-03 03:42:43'),(586,275,37,964,'Saldo incial corte 31 jul.26 empresa marcha.',NULL,91,NULL,NULL,949,1.000000,1.000000,14759217.30,0.00,14759217.30,0.00,14759217.30,0.00,'2026-09-30',NULL,NULL,0,'2026-09-03 03:42:43','2026-09-06 19:47:24'),(587,275,38,965,'Saldo incial corte 31 jul.26 empresa marcha.',NULL,92,NULL,NULL,949,1.000000,1.000000,1118379.58,0.00,1118379.58,0.00,1118379.58,0.00,'2026-09-30',NULL,NULL,0,'2026-09-03 03:42:43','2026-09-06 19:47:25'),(588,275,39,968,'Saldo incial corte 31 jul.26 empresa marcha.',NULL,NULL,NULL,NULL,949,1.000000,1.000000,203490.00,0.00,203490.00,0.00,203490.00,0.00,NULL,NULL,NULL,0,'2026-09-03 03:42:43','2026-09-03 03:42:43'),(589,275,40,969,'Saldo incial corte 31 jul.26 empresa marcha.',NULL,NULL,NULL,NULL,949,1.000000,1.000000,0.00,51300.00,0.00,51300.00,0.00,51300.00,NULL,NULL,NULL,0,'2026-09-03 03:42:43','2026-09-03 03:42:43'),(590,275,41,971,'Saldo incial corte 31 jul.26 empresa marcha.',NULL,NULL,NULL,NULL,949,1.000000,1.000000,255200.00,0.00,255200.00,0.00,255200.00,0.00,NULL,NULL,NULL,0,'2026-09-03 03:42:43','2026-09-03 03:42:43'),(591,275,42,972,'Saldo incial corte 31 jul.26 empresa marcha.',NULL,NULL,NULL,NULL,949,1.000000,1.000000,0.00,110586.58,0.00,110586.58,0.00,110586.58,NULL,NULL,NULL,0,'2026-09-03 03:42:43','2026-09-03 03:42:43'),(592,275,43,977,'Saldo incial corte 31 jul.26 empresa marcha.',NULL,90,NULL,NULL,949,1.000000,1.000000,0.00,113000.00,0.00,113000.00,0.00,113000.00,'2026-08-31',NULL,NULL,0,'2026-09-03 03:42:43','2026-09-06 19:47:25'),(593,275,44,980,'Saldo incial corte 31 jul.26 empresa marcha.',NULL,NULL,NULL,NULL,949,1.000000,1.000000,0.00,285000.00,0.00,285000.00,0.00,285000.00,NULL,NULL,NULL,0,'2026-09-03 03:42:43','2026-09-03 03:42:43'),(594,275,45,983,'Saldo incial corte 31 jul.26 empresa marcha.',NULL,NULL,NULL,NULL,949,1.000000,1.000000,0.00,320000.00,0.00,320000.00,0.00,320000.00,NULL,NULL,NULL,0,'2026-09-03 03:42:43','2026-09-03 03:42:43'),(595,275,46,984,'Saldo incial corte 31 jul.26 empresa marcha.',NULL,NULL,NULL,NULL,949,1.000000,1.000000,0.00,140000.00,0.00,140000.00,0.00,140000.00,NULL,NULL,NULL,0,'2026-09-03 03:42:43','2026-09-03 03:42:43'),(596,275,47,985,'Saldo incial corte 31 jul.26 empresa marcha.',NULL,NULL,NULL,NULL,949,1.000000,1.000000,0.00,1225000.00,0.00,1225000.00,0.00,1225000.00,NULL,NULL,NULL,0,'2026-09-03 03:42:43','2026-09-03 03:42:43'),(597,275,48,986,'Saldo incial corte 31 jul.26 empresa marcha.',NULL,NULL,NULL,NULL,949,1.000000,1.000000,0.00,70000.00,0.00,70000.00,0.00,70000.00,NULL,NULL,NULL,0,'2026-09-03 03:42:43','2026-09-03 03:42:43'),(598,275,49,987,'Saldo incial corte 31 jul.26 empresa marcha.',NULL,NULL,NULL,NULL,949,1.000000,1.000000,0.00,320000.00,0.00,320000.00,0.00,320000.00,NULL,NULL,NULL,0,'2026-09-03 03:42:43','2026-09-03 03:42:43'),(599,275,50,988,'Saldo incial corte 31 jul.26 empresa marcha.',NULL,NULL,NULL,NULL,949,1.000000,1.000000,0.00,160000.00,0.00,160000.00,0.00,160000.00,NULL,NULL,NULL,0,'2026-09-03 03:42:43','2026-09-03 03:42:43'),(600,275,51,989,'Saldo incial corte 31 jul.26 empresa marcha.',NULL,NULL,NULL,NULL,949,1.000000,1.000000,0.00,80000.00,0.00,80000.00,0.00,80000.00,NULL,NULL,NULL,0,'2026-09-03 03:42:43','2026-09-03 03:42:43'),(601,275,52,990,'Saldo incial corte 31 jul.26 empresa marcha.',NULL,NULL,NULL,NULL,949,1.000000,1.000000,0.00,160000.00,0.00,160000.00,0.00,160000.00,NULL,NULL,NULL,0,'2026-09-03 03:42:43','2026-09-03 03:42:43'),(602,275,53,991,'Saldo incial corte 31 jul.26 empresa marcha.',NULL,NULL,NULL,NULL,949,1.000000,1.000000,0.00,240000.00,0.00,240000.00,0.00,240000.00,NULL,NULL,NULL,0,'2026-09-03 03:42:43','2026-09-03 03:42:43'),(603,275,54,992,'Saldo incial corte 31 jul.26 empresa marcha.',NULL,NULL,NULL,NULL,949,1.000000,1.000000,0.00,800000.00,0.00,800000.00,0.00,800000.00,NULL,NULL,NULL,0,'2026-09-03 03:42:43','2026-09-03 03:42:43'),(604,275,55,993,'Saldo incial corte 31 jul.26 empresa marcha.',NULL,NULL,NULL,NULL,949,1.000000,1.000000,0.00,320000.00,0.00,320000.00,0.00,320000.00,NULL,NULL,NULL,0,'2026-09-03 03:42:43','2026-09-03 03:42:43'),(605,275,56,994,'Saldo incial corte 31 jul.26 empresa marcha.',NULL,NULL,NULL,NULL,949,1.000000,1.000000,0.00,800000.00,0.00,800000.00,0.00,800000.00,NULL,NULL,NULL,0,'2026-09-03 03:42:43','2026-09-03 03:42:43'),(606,275,57,995,'Saldo incial corte 31 jul.26 empresa marcha.',NULL,NULL,NULL,NULL,949,1.000000,1.000000,0.00,48000.00,0.00,48000.00,0.00,48000.00,NULL,NULL,NULL,0,'2026-09-03 03:42:43','2026-09-03 03:42:43'),(607,275,58,996,'Saldo incial corte 31 jul.26 empresa marcha.',NULL,NULL,NULL,NULL,949,1.000000,1.000000,0.00,68000.00,0.00,68000.00,0.00,68000.00,NULL,NULL,NULL,0,'2026-09-03 03:42:43','2026-09-03 03:42:43'),(608,275,59,998,'Saldo incial corte 31 jul.26 empresa marcha.',NULL,NULL,NULL,NULL,949,1.000000,1.000000,0.00,140000.00,0.00,140000.00,0.00,140000.00,NULL,NULL,NULL,0,'2026-09-03 03:42:43','2026-09-03 03:42:43'),(609,275,60,999,'Saldo incial corte 31 jul.26 empresa marcha.',NULL,NULL,NULL,NULL,949,1.000000,1.000000,0.00,30000.00,0.00,30000.00,0.00,30000.00,NULL,NULL,NULL,0,'2026-09-03 03:42:43','2026-09-03 03:42:43'),(610,275,61,1000,'Saldo incial corte 31 jul.26 empresa marcha.',NULL,NULL,NULL,NULL,949,1.000000,1.000000,0.00,65000.00,0.00,65000.00,0.00,65000.00,NULL,NULL,NULL,0,'2026-09-03 03:42:43','2026-09-03 03:42:43'),(611,275,62,1002,'Saldo incial corte 31 jul.26 empresa marcha.',NULL,NULL,NULL,NULL,949,1.000000,1.000000,0.00,20000.00,0.00,20000.00,0.00,20000.00,NULL,NULL,NULL,0,'2026-09-03 03:42:43','2026-09-03 03:42:43'),(612,275,63,1003,'Saldo incial corte 31 jul.26 empresa marcha.',NULL,NULL,NULL,NULL,949,1.000000,1.000000,0.00,120000.00,0.00,120000.00,0.00,120000.00,NULL,NULL,NULL,0,'2026-09-03 03:42:43','2026-09-03 03:42:43'),(613,275,64,1004,'Saldo incial corte 31 jul.26 empresa marcha.',NULL,NULL,NULL,NULL,949,1.000000,1.000000,0.00,84000.00,0.00,84000.00,0.00,84000.00,NULL,NULL,NULL,0,'2026-09-03 03:42:43','2026-09-03 03:42:43'),(614,275,65,1005,'Saldo incial corte 31 jul.26 empresa marcha.',NULL,NULL,NULL,NULL,949,1.000000,1.000000,0.00,60500.00,0.00,60500.00,0.00,60500.00,NULL,NULL,NULL,0,'2026-09-03 03:42:43','2026-09-03 03:42:43'),(615,275,66,1007,'Saldo incial corte 31 jul.26 empresa marcha.',NULL,NULL,NULL,NULL,949,1.000000,1.000000,0.00,67574.95,0.00,67574.95,0.00,67574.95,NULL,NULL,NULL,0,'2026-09-03 03:42:43','2026-09-03 03:42:43'),(616,275,67,1008,'Saldo incial corte 31 jul.26 empresa marcha.',NULL,NULL,NULL,NULL,949,1.000000,1.000000,0.00,6013.15,0.00,6013.15,0.00,6013.15,NULL,NULL,NULL,0,'2026-09-03 03:42:43','2026-09-03 03:42:43'),(617,275,68,1011,'Saldo incial corte 31 jul.26 empresa marcha.',NULL,NULL,NULL,NULL,949,1.000000,1.000000,0.00,500370.00,0.00,500370.00,0.00,500370.00,NULL,NULL,NULL,0,'2026-09-03 03:42:43','2026-09-03 03:42:43'),(618,275,69,1014,'Saldo incial corte 31 jul.26 empresa marcha.',NULL,NULL,NULL,NULL,949,1.000000,1.000000,0.00,57098.85,0.00,57098.85,0.00,57098.85,NULL,NULL,NULL,0,'2026-09-03 03:42:43','2026-09-03 03:42:43'),(619,275,70,1019,'Saldo incial corte 31 jul.26 empresa marcha.',NULL,NULL,NULL,NULL,949,1.000000,1.000000,0.00,13390715.39,0.00,13390715.39,0.00,13390715.39,NULL,NULL,NULL,0,'2026-09-03 03:42:43','2026-09-03 03:42:43'),(620,275,71,1020,'Saldo incial corte 31 jul.26 empresa marcha.',NULL,NULL,NULL,NULL,949,1.000000,1.000000,0.00,1197126.20,0.00,1197126.20,0.00,1197126.20,NULL,NULL,NULL,0,'2026-09-03 03:42:43','2026-09-03 03:42:43'),(621,275,72,1021,'Saldo incial corte 31 jul.26 empresa marcha.',NULL,NULL,NULL,NULL,949,1.000000,1.000000,0.00,2671038.50,0.00,2671038.50,0.00,2671038.50,NULL,NULL,NULL,0,'2026-09-03 03:42:43','2026-09-03 03:42:43'),(622,275,73,1022,'Saldo incial corte 31 jul.26 empresa marcha.',NULL,NULL,NULL,NULL,949,1.000000,1.000000,0.00,19101979.74,0.00,19101979.74,0.00,19101979.74,NULL,NULL,NULL,0,'2026-09-03 03:42:43','2026-09-03 03:42:43'),(623,275,74,1023,'Saldo incial corte 31 jul.26 empresa marcha.',NULL,NULL,NULL,NULL,949,1.000000,1.000000,0.00,2556273.60,0.00,2556273.60,0.00,2556273.60,NULL,NULL,NULL,0,'2026-09-03 03:42:43','2026-09-03 03:42:43'),(624,275,75,1024,'Saldo incial corte 31 jul.26 empresa marcha.',NULL,NULL,NULL,NULL,949,1.000000,1.000000,0.00,8460967.95,0.00,8460967.95,0.00,8460967.95,NULL,NULL,NULL,0,'2026-09-03 03:42:43','2026-09-03 03:42:43'),(625,275,76,1025,'Saldo incial corte 31 jul.26 empresa marcha.',NULL,NULL,NULL,NULL,949,1.000000,1.000000,0.00,767583.25,0.00,767583.25,0.00,767583.25,NULL,NULL,NULL,0,'2026-09-03 03:42:43','2026-09-03 03:42:43'),(626,275,77,1026,'Saldo incial corte 31 jul.26 empresa marcha.',NULL,NULL,NULL,NULL,949,1.000000,1.000000,0.00,8453261.63,0.00,8453261.63,0.00,8453261.63,NULL,NULL,NULL,0,'2026-09-03 03:42:43','2026-09-03 03:42:43'),(627,275,78,1027,'Saldo incial corte 31 jul.26 empresa marcha.',NULL,NULL,NULL,NULL,949,1.000000,1.000000,0.00,728850.00,0.00,728850.00,0.00,728850.00,NULL,NULL,NULL,0,'2026-09-03 03:42:43','2026-09-03 03:42:43'),(628,275,79,1028,'Saldo incial corte 31 jul.26 empresa marcha.',NULL,NULL,NULL,NULL,949,1.000000,1.000000,0.00,3500137.42,0.00,3500137.42,0.00,3500137.42,NULL,NULL,NULL,0,'2026-09-03 03:42:43','2026-09-03 03:42:43'),(629,275,80,1029,'Saldo incial corte 31 jul.26 empresa marcha.',NULL,NULL,NULL,NULL,949,1.000000,1.000000,0.00,5193658.25,0.00,5193658.25,0.00,5193658.25,NULL,NULL,NULL,0,'2026-09-03 03:42:43','2026-09-03 03:42:43'),(630,275,81,1030,'Saldo incial corte 31 jul.26 empresa marcha.',NULL,NULL,NULL,NULL,949,1.000000,1.000000,0.00,225000.00,0.00,225000.00,0.00,225000.00,NULL,NULL,NULL,0,'2026-09-03 03:42:43','2026-09-03 03:42:43'),(631,275,82,1031,'Saldo incial corte 31 jul.26 empresa marcha.',NULL,NULL,NULL,NULL,949,1.000000,1.000000,0.00,237459.15,0.00,237459.15,0.00,237459.15,NULL,NULL,NULL,0,'2026-09-03 03:42:43','2026-09-03 03:42:43'),(632,275,83,1032,'Saldo incial corte 31 jul.26 empresa marcha.',NULL,NULL,NULL,NULL,949,1.000000,1.000000,0.00,1678119.35,0.00,1678119.35,0.00,1678119.35,NULL,NULL,NULL,0,'2026-09-03 03:42:43','2026-09-03 03:42:43'),(633,275,84,1033,'Saldo incial corte 31 jul.26 empresa marcha.',NULL,NULL,NULL,NULL,949,1.000000,1.000000,0.00,2006001.16,0.00,2006001.16,0.00,2006001.16,NULL,NULL,NULL,0,'2026-09-03 03:42:43','2026-09-03 03:42:43'),(634,275,85,1034,'Saldo incial corte 31 jul.26 empresa marcha.',NULL,NULL,NULL,NULL,949,1.000000,1.000000,0.00,3841029.30,0.00,3841029.30,0.00,3841029.30,NULL,NULL,NULL,0,'2026-09-03 03:42:43','2026-09-03 03:42:43'),(635,275,86,1035,'Saldo incial corte 31 jul.26 empresa marcha.',NULL,NULL,NULL,NULL,949,1.000000,1.000000,0.00,3196968.00,0.00,3196968.00,0.00,3196968.00,NULL,NULL,NULL,0,'2026-09-03 03:42:43','2026-09-03 03:42:43'),(636,275,87,1036,'Saldo incial corte 31 jul.26 empresa marcha.',NULL,NULL,NULL,NULL,949,1.000000,1.000000,0.00,1663117.30,0.00,1663117.30,0.00,1663117.30,NULL,NULL,NULL,0,'2026-09-03 03:42:43','2026-09-03 03:42:43'),(637,275,88,1037,'Saldo incial corte 31 jul.26 empresa marcha.',NULL,NULL,NULL,NULL,949,1.000000,1.000000,0.00,1222588.70,0.00,1222588.70,0.00,1222588.70,NULL,NULL,NULL,0,'2026-09-03 03:42:43','2026-09-03 03:42:43'),(638,275,89,1038,'Saldo incial corte 31 jul.26 empresa marcha.',NULL,NULL,NULL,NULL,949,1.000000,1.000000,0.00,1164200.35,0.00,1164200.35,0.00,1164200.35,NULL,NULL,NULL,0,'2026-09-03 03:42:43','2026-09-03 03:42:43'),(639,275,90,1039,'Saldo incial corte 31 jul.26 empresa marcha.',NULL,NULL,NULL,NULL,949,1.000000,1.000000,0.00,808564.25,0.00,808564.25,0.00,808564.25,NULL,NULL,NULL,0,'2026-09-03 03:42:43','2026-09-03 03:42:43'),(640,275,91,1040,'Saldo incial corte 31 jul.26 empresa marcha.',NULL,NULL,NULL,NULL,949,1.000000,1.000000,0.00,1868706.05,0.00,1868706.05,0.00,1868706.05,NULL,NULL,NULL,0,'2026-09-03 03:42:43','2026-09-03 03:42:43'),(641,275,92,1041,'Saldo incial corte 31 jul.26 empresa marcha.',NULL,NULL,NULL,NULL,949,1.000000,1.000000,0.00,1713833.95,0.00,1713833.95,0.00,1713833.95,NULL,NULL,NULL,0,'2026-09-03 03:42:43','2026-09-03 03:42:43'),(642,275,93,1042,'Saldo incial corte 31 jul.26 empresa marcha.',NULL,NULL,NULL,NULL,949,1.000000,1.000000,0.00,987610.50,0.00,987610.50,0.00,987610.50,NULL,NULL,NULL,0,'2026-09-03 03:42:43','2026-09-03 03:42:43'),(643,275,94,1043,'Saldo incial corte 31 jul.26 empresa marcha.',NULL,NULL,NULL,NULL,949,1.000000,1.000000,0.00,1813272.20,0.00,1813272.20,0.00,1813272.20,NULL,NULL,NULL,0,'2026-09-03 03:42:43','2026-09-03 03:42:43'),(644,275,95,1044,'Saldo incial corte 31 jul.26 empresa marcha.',NULL,NULL,NULL,NULL,949,1.000000,1.000000,0.00,1163706.35,0.00,1163706.35,0.00,1163706.35,NULL,NULL,NULL,0,'2026-09-03 03:42:43','2026-09-03 03:42:43'),(645,275,96,1045,'Saldo incial corte 31 jul.26 empresa marcha.',NULL,NULL,NULL,NULL,949,1.000000,1.000000,0.00,145215.00,0.00,145215.00,0.00,145215.00,NULL,NULL,NULL,0,'2026-09-03 03:42:43','2026-09-03 03:42:43'),(646,275,97,1046,'Saldo incial corte 31 jul.26 empresa marcha.',NULL,NULL,NULL,NULL,949,1.000000,1.000000,0.00,743396.90,0.00,743396.90,0.00,743396.90,NULL,NULL,NULL,0,'2026-09-03 03:42:43','2026-09-03 03:42:43'),(647,275,98,1047,'Saldo incial corte 31 jul.26 empresa marcha.',NULL,NULL,NULL,NULL,949,1.000000,1.000000,0.00,563658.75,0.00,563658.75,0.00,563658.75,NULL,NULL,NULL,0,'2026-09-03 03:42:43','2026-09-03 03:42:43'),(648,275,99,1048,'Saldo incial corte 31 jul.26 empresa marcha.',NULL,NULL,NULL,NULL,949,1.000000,1.000000,0.00,470156.60,0.00,470156.60,0.00,470156.60,NULL,NULL,NULL,0,'2026-09-03 03:42:43','2026-09-03 03:42:43'),(649,275,100,1049,'Saldo incial corte 31 jul.26 empresa marcha.',NULL,NULL,NULL,NULL,949,1.000000,1.000000,0.00,1185580.45,0.00,1185580.45,0.00,1185580.45,NULL,NULL,NULL,0,'2026-09-03 03:42:43','2026-09-03 03:42:43'),(650,275,101,1050,'Saldo incial corte 31 jul.26 empresa marcha.',NULL,NULL,NULL,NULL,949,1.000000,1.000000,0.00,466229.25,0.00,466229.25,0.00,466229.25,NULL,NULL,NULL,0,'2026-09-03 03:42:43','2026-09-03 03:42:43'),(651,275,102,1053,'Saldo incial corte 31 jul.26 empresa marcha.',NULL,NULL,NULL,NULL,949,1.000000,1.000000,0.00,55911523.91,0.00,55911523.91,0.00,55911523.91,NULL,NULL,NULL,0,'2026-09-03 03:42:43','2026-09-03 03:42:43'),(652,275,103,1055,'Saldo incial corte 31 jul.26 empresa marcha.',NULL,NULL,NULL,NULL,949,1.000000,1.000000,0.00,1409147.95,0.00,1409147.95,0.00,1409147.95,NULL,NULL,NULL,0,'2026-09-03 03:42:43','2026-09-03 03:42:43'),(653,275,104,1056,'Saldo incial corte 31 jul.26 empresa marcha.',NULL,NULL,NULL,NULL,949,1.000000,1.000000,0.00,938342.65,0.00,938342.65,0.00,938342.65,NULL,NULL,NULL,0,'2026-09-03 03:42:43','2026-09-03 03:42:43'),(654,275,105,1057,'Saldo incial corte 31 jul.26 empresa marcha.',NULL,NULL,NULL,NULL,949,1.000000,1.000000,0.00,1562095.33,0.00,1562095.33,0.00,1562095.33,NULL,NULL,NULL,0,'2026-09-03 03:42:43','2026-09-03 03:42:43'),(655,275,106,1058,'Saldo incial corte 31 jul.26 empresa marcha.',NULL,NULL,NULL,NULL,949,1.000000,1.000000,0.00,10260.00,0.00,10260.00,0.00,10260.00,NULL,NULL,NULL,0,'2026-09-03 03:42:43','2026-09-03 03:42:43'),(656,275,107,1059,'Saldo incial corte 31 jul.26 empresa marcha.',NULL,NULL,NULL,NULL,949,1.000000,1.000000,0.00,7532.25,0.00,7532.25,0.00,7532.25,NULL,NULL,NULL,0,'2026-09-03 03:42:43','2026-09-03 03:42:43'),(657,275,108,1060,'Saldo incial corte 31 jul.26 empresa marcha.',NULL,NULL,NULL,NULL,949,1.000000,1.000000,0.00,273819.00,0.00,273819.00,0.00,273819.00,NULL,NULL,NULL,0,'2026-09-03 03:42:43','2026-09-03 03:42:43'),(658,275,109,1061,'Saldo incial corte 31 jul.26 empresa marcha.',NULL,NULL,NULL,NULL,949,1.000000,1.000000,0.00,376560.00,0.00,376560.00,0.00,376560.00,NULL,NULL,NULL,0,'2026-09-03 03:42:43','2026-09-03 03:42:43'),(659,275,110,1062,'Saldo incial corte 31 jul.26 empresa marcha.',NULL,NULL,NULL,NULL,949,1.000000,1.000000,0.00,1268569.00,0.00,1268569.00,0.00,1268569.00,NULL,NULL,NULL,0,'2026-09-03 03:42:43','2026-09-03 03:42:43'),(660,275,111,1063,'Saldo incial corte 31 jul.26 empresa marcha.',NULL,NULL,NULL,NULL,949,1.000000,1.000000,0.00,478460.25,0.00,478460.25,0.00,478460.25,NULL,NULL,NULL,0,'2026-09-03 03:42:43','2026-09-03 03:42:43'),(661,275,112,1064,'Saldo incial corte 31 jul.26 empresa marcha.',NULL,NULL,NULL,NULL,949,1.000000,1.000000,0.00,1165744.62,0.00,1165744.62,0.00,1165744.62,NULL,NULL,NULL,0,'2026-09-03 03:42:43','2026-09-03 03:42:43'),(662,275,113,1065,'Saldo incial corte 31 jul.26 empresa marcha.',NULL,NULL,NULL,NULL,949,1.000000,1.000000,0.00,2308402.50,0.00,2308402.50,0.00,2308402.50,NULL,NULL,NULL,0,'2026-09-03 03:42:43','2026-09-03 03:42:43'),(663,275,114,1066,'Saldo incial corte 31 jul.26 empresa marcha.',NULL,NULL,NULL,NULL,949,1.000000,1.000000,0.00,131783.22,0.00,131783.22,0.00,131783.22,NULL,NULL,NULL,0,'2026-09-03 03:42:43','2026-09-03 03:42:43'),(664,275,115,1067,'Saldo incial corte 31 jul.26 empresa marcha.',NULL,NULL,NULL,NULL,949,1.000000,1.000000,0.00,135030.00,0.00,135030.00,0.00,135030.00,NULL,NULL,NULL,0,'2026-09-03 03:42:43','2026-09-03 03:42:43'),(665,275,116,1068,'Saldo incial corte 31 jul.26 empresa marcha.',NULL,NULL,NULL,NULL,949,1.000000,1.000000,0.00,335563.00,0.00,335563.00,0.00,335563.00,NULL,NULL,NULL,0,'2026-09-03 03:42:43','2026-09-03 03:42:43'),(666,275,117,1069,'Saldo incial corte 31 jul.26 empresa marcha.',NULL,NULL,NULL,NULL,949,1.000000,1.000000,0.00,922445.00,0.00,922445.00,0.00,922445.00,NULL,NULL,NULL,0,'2026-09-03 03:42:43','2026-09-03 03:42:43'),(667,275,118,1074,'Saldo incial corte 31 jul.26 empresa marcha.',NULL,NULL,NULL,NULL,949,1.000000,1.000000,0.00,1940920.94,0.00,1940920.94,0.00,1940920.94,NULL,NULL,NULL,0,'2026-09-03 03:42:43','2026-09-03 03:42:43'),(668,275,119,1075,'Saldo incial corte 31 jul.26 empresa marcha.',NULL,NULL,NULL,NULL,949,1.000000,1.000000,0.00,12077.14,0.00,12077.14,0.00,12077.14,NULL,NULL,NULL,0,'2026-09-03 03:42:43','2026-09-03 03:42:43'),(669,275,120,1076,'Saldo incial corte 31 jul.26 empresa marcha.',NULL,NULL,NULL,NULL,949,1.000000,1.000000,0.00,1354382.41,0.00,1354382.41,0.00,1354382.41,NULL,NULL,NULL,0,'2026-09-03 03:42:43','2026-09-03 03:42:43'),(670,275,121,1077,'Saldo incial corte 31 jul.26 empresa marcha.',NULL,NULL,NULL,NULL,949,1.000000,1.000000,0.00,106815.00,0.00,106815.00,0.00,106815.00,NULL,NULL,NULL,0,'2026-09-03 03:42:43','2026-09-03 03:42:43'),(671,275,122,1078,'Saldo incial corte 31 jul.26 empresa marcha.',NULL,NULL,NULL,NULL,949,1.000000,1.000000,0.00,174988.11,0.00,174988.11,0.00,174988.11,NULL,NULL,NULL,0,'2026-09-03 03:42:43','2026-09-03 03:42:43'),(672,275,123,1079,'Saldo incial corte 31 jul.26 empresa marcha.',NULL,NULL,NULL,NULL,949,1.000000,1.000000,0.00,378164.00,0.00,378164.00,0.00,378164.00,NULL,NULL,NULL,0,'2026-09-03 03:42:43','2026-09-03 03:42:43'),(673,275,124,1081,'Saldo incial corte 31 jul.26 empresa marcha.',NULL,NULL,NULL,NULL,949,1.000000,1.000000,0.00,1856281.91,0.00,1856281.91,0.00,1856281.91,NULL,NULL,NULL,0,'2026-09-03 03:42:43','2026-09-03 03:42:43'),(674,275,125,1082,'Saldo incial corte 31 jul.26 empresa marcha.',NULL,NULL,NULL,NULL,949,1.000000,1.000000,278442.30,0.00,278442.30,0.00,278442.30,0.00,NULL,NULL,NULL,0,'2026-09-03 03:42:43','2026-09-03 03:42:43'),(675,275,126,1086,'Saldo incial corte 31 jul.26 empresa marcha.',NULL,NULL,NULL,NULL,949,1.000000,1.000000,0.00,950000.00,0.00,950000.00,0.00,950000.00,NULL,NULL,NULL,0,'2026-09-03 03:42:43','2026-09-03 03:42:43'),(676,275,127,1087,'Saldo incial corte 31 jul.26 empresa marcha.',NULL,NULL,NULL,NULL,949,1.000000,1.000000,440000.00,0.00,440000.00,0.00,440000.00,0.00,NULL,NULL,NULL,0,'2026-09-03 03:42:43','2026-09-03 03:42:43'),(677,275,128,1092,'Saldo incial corte 31 jul.26 empresa marcha.',NULL,NULL,NULL,NULL,949,1.000000,1.000000,50000.00,0.00,50000.00,0.00,50000.00,0.00,NULL,NULL,NULL,0,'2026-09-03 03:42:43','2026-09-03 03:42:43'),(678,275,129,1093,'Saldo incial corte 31 jul.26 empresa marcha.',NULL,NULL,NULL,NULL,949,1.000000,1.000000,558750.00,0.00,558750.00,0.00,558750.00,0.00,NULL,NULL,NULL,0,'2026-09-03 03:42:43','2026-09-03 03:42:43'),(679,275,130,1094,'Saldo incial corte 31 jul.26 empresa marcha.',NULL,NULL,NULL,NULL,949,1.000000,1.000000,4000.00,0.00,4000.00,0.00,4000.00,0.00,NULL,NULL,NULL,0,'2026-09-03 03:42:43','2026-09-03 03:42:43'),(680,275,131,1095,'Saldo incial corte 31 jul.26 empresa marcha.',NULL,NULL,NULL,NULL,949,1.000000,1.000000,91496.74,0.00,91496.74,0.00,91496.74,0.00,NULL,NULL,NULL,0,'2026-09-03 03:42:43','2026-09-03 03:42:43'),(681,275,132,1096,'Saldo incial corte 31 jul.26 empresa marcha.',NULL,NULL,NULL,NULL,949,1.000000,1.000000,960500.00,0.00,960500.00,0.00,960500.00,0.00,NULL,NULL,NULL,0,'2026-09-03 03:42:43','2026-09-03 03:42:43'),(682,275,133,1097,'Saldo incial corte 31 jul.26 empresa marcha.',NULL,NULL,NULL,NULL,949,1.000000,1.000000,56500.00,0.00,56500.00,0.00,56500.00,0.00,NULL,NULL,NULL,0,'2026-09-03 03:42:43','2026-09-03 03:42:43'),(683,275,134,1098,'Saldo incial corte 31 jul.26 empresa marcha.',NULL,NULL,NULL,NULL,949,1.000000,1.000000,280000.00,0.00,280000.00,0.00,280000.00,0.00,NULL,NULL,NULL,0,'2026-09-03 03:42:43','2026-09-03 03:42:43'),(684,275,135,1099,'Saldo incial corte 31 jul.26 empresa marcha.',NULL,NULL,NULL,NULL,949,1.000000,1.000000,21700.00,0.00,21700.00,0.00,21700.00,0.00,NULL,NULL,NULL,0,'2026-09-03 03:42:43','2026-09-03 03:42:43'),(685,275,136,1100,'Saldo incial corte 31 jul.26 empresa marcha.',NULL,NULL,NULL,NULL,949,1.000000,1.000000,900.00,0.00,900.00,0.00,900.00,0.00,NULL,NULL,NULL,0,'2026-09-03 03:42:43','2026-09-03 03:42:43'),(686,275,137,1102,'Saldo incial corte 31 jul.26 empresa marcha.',NULL,NULL,NULL,NULL,949,1.000000,1.000000,11970.00,0.00,11970.00,0.00,11970.00,0.00,NULL,NULL,NULL,0,'2026-09-03 03:42:43','2026-09-03 03:42:43'),(687,275,138,1103,'Saldo incial corte 31 jul.26 empresa marcha.',NULL,NULL,NULL,NULL,949,1.000000,1.000000,29773.31,0.00,29773.31,0.00,29773.31,0.00,NULL,NULL,NULL,0,'2026-09-03 03:42:43','2026-09-03 03:42:43'),(688,275,139,1107,'Saldo incial corte 31 jul.26 empresa marcha.',NULL,NULL,NULL,NULL,949,1.000000,1.000000,66985.32,0.00,66985.32,0.00,66985.32,0.00,NULL,NULL,NULL,0,'2026-09-03 03:42:43','2026-09-03 03:42:43'),(689,275,140,1108,'Saldo incial corte 31 jul.26 empresa marcha.',NULL,NULL,NULL,NULL,949,1.000000,1.000000,6012.95,0.00,6012.95,0.00,6012.95,0.00,NULL,NULL,NULL,0,'2026-09-03 03:42:43','2026-09-03 03:42:43'),(690,275,141,1110,'Saldo incial corte 31 jul.26 empresa marcha.',NULL,NULL,NULL,NULL,949,1.000000,1.000000,6040.00,0.00,6040.00,0.00,6040.00,0.00,NULL,NULL,NULL,0,'2026-09-03 03:42:43','2026-09-03 03:42:43'),(792,276,1,933,'Deduccion  planilla amortiza créditos',NULL,NULL,NULL,NULL,949,1.000000,1.000000,0.00,80440.22,0.00,80440.22,0.00,80440.22,NULL,NULL,NULL,0,'2026-09-05 22:57:26','2026-09-05 22:57:26'),(793,276,2,934,'Deduccion  planilla amortiza créditos',NULL,NULL,NULL,NULL,949,1.000000,1.000000,0.00,178337.00,0.00,178337.00,0.00,178337.00,NULL,NULL,NULL,0,'2026-09-05 22:57:26','2026-09-05 22:57:26'),(794,276,3,935,'Deduccion  planilla amortiza créditos',NULL,NULL,NULL,NULL,949,1.000000,1.000000,0.00,259078.61,0.00,259078.61,0.00,259078.61,NULL,NULL,NULL,0,'2026-09-05 22:57:26','2026-09-05 22:57:26'),(795,276,4,936,'Deduccion  planilla amortiza créditos',NULL,NULL,NULL,NULL,949,1.000000,1.000000,0.00,91469.00,0.00,91469.00,0.00,91469.00,NULL,NULL,NULL,0,'2026-09-05 22:57:26','2026-09-05 22:57:26'),(796,276,5,937,'Deduccion  planilla amortiza créditos',NULL,NULL,NULL,NULL,949,1.000000,1.000000,0.00,8855.00,0.00,8855.00,0.00,8855.00,NULL,NULL,NULL,0,'2026-09-05 22:57:26','2026-09-05 22:57:26'),(797,276,6,938,'Deduccion  planilla amortiza créditos',NULL,NULL,NULL,NULL,949,1.000000,1.000000,0.00,8122.00,0.00,8122.00,0.00,8122.00,NULL,NULL,NULL,0,'2026-09-05 22:57:26','2026-09-05 22:57:26'),(798,276,7,939,'Deduccion  planilla amortiza créditos',NULL,NULL,NULL,NULL,949,1.000000,1.000000,0.00,37898.00,0.00,37898.00,0.00,37898.00,NULL,NULL,NULL,0,'2026-09-05 22:57:26','2026-09-05 22:57:26'),(799,276,8,940,'Deduccion  planilla amortiza créditos',NULL,NULL,NULL,NULL,949,1.000000,1.000000,0.00,45763.00,0.00,45763.00,0.00,45763.00,NULL,NULL,NULL,0,'2026-09-05 22:57:26','2026-09-05 22:57:26'),(800,276,9,941,'Deduccion  planilla amortiza créditos',NULL,NULL,NULL,NULL,949,1.000000,1.000000,0.00,20498.00,0.00,20498.00,0.00,20498.00,NULL,NULL,NULL,0,'2026-09-05 22:57:26','2026-09-05 22:57:26'),(801,276,10,942,'Deduccion  planilla amortiza créditos',NULL,NULL,NULL,NULL,949,1.000000,1.000000,0.00,79332.95,0.00,79332.95,0.00,79332.95,NULL,NULL,NULL,0,'2026-09-05 22:57:26','2026-09-05 22:57:26'),(802,276,11,943,'Deduccion  planilla amortiza créditos',NULL,NULL,NULL,NULL,949,1.000000,1.000000,0.00,58840.00,0.00,58840.00,0.00,58840.00,NULL,NULL,NULL,0,'2026-09-05 22:57:26','2026-09-05 22:57:26'),(803,276,12,944,'Deduccion  planilla amortiza créditos',NULL,NULL,NULL,NULL,949,1.000000,1.000000,0.00,14516.00,0.00,14516.00,0.00,14516.00,NULL,NULL,NULL,0,'2026-09-05 22:57:26','2026-09-05 22:57:26'),(804,276,13,945,'Deduccion  planilla amortiza créditos',NULL,NULL,NULL,NULL,949,1.000000,1.000000,0.00,82542.00,0.00,82542.00,0.00,82542.00,NULL,NULL,NULL,0,'2026-09-05 22:57:26','2026-09-05 22:57:26'),(805,276,14,946,'Deduccion  planilla amortiza créditos',NULL,NULL,NULL,NULL,949,1.000000,1.000000,0.00,29360.00,0.00,29360.00,0.00,29360.00,NULL,NULL,NULL,0,'2026-09-05 22:57:26','2026-09-05 22:57:26'),(806,276,15,947,'Deduccion  planilla amortiza créditos',NULL,NULL,NULL,NULL,949,1.000000,1.000000,0.00,44791.11,0.00,44791.11,0.00,44791.11,NULL,NULL,NULL,0,'2026-09-05 22:57:26','2026-09-05 22:57:26'),(807,276,16,948,'Deduccion  planilla amortiza créditos',NULL,NULL,NULL,NULL,949,1.000000,1.000000,0.00,39794.00,0.00,39794.00,0.00,39794.00,NULL,NULL,NULL,0,'2026-09-05 22:57:26','2026-09-05 22:57:26'),(808,276,17,949,'Deduccion  planilla amortiza créditos',NULL,NULL,NULL,NULL,949,1.000000,1.000000,0.00,9634.00,0.00,9634.00,0.00,9634.00,NULL,NULL,NULL,0,'2026-09-05 22:57:26','2026-09-05 22:57:26'),(809,276,18,950,'Deduccion  planilla amortiza créditos',NULL,NULL,NULL,NULL,949,1.000000,1.000000,0.00,33675.00,0.00,33675.00,0.00,33675.00,NULL,NULL,NULL,0,'2026-09-05 22:57:26','2026-09-05 22:57:26'),(810,276,19,951,'Deduccion  planilla amortiza créditos',NULL,NULL,NULL,NULL,949,1.000000,1.000000,0.00,21442.00,0.00,21442.00,0.00,21442.00,NULL,NULL,NULL,0,'2026-09-05 22:57:26','2026-09-05 22:57:26'),(811,276,20,1074,'Deducción planilla intereses Olmán Mora Rivera',NULL,NULL,NULL,NULL,949,1.000000,1.000000,0.00,11612.78,0.00,11612.78,0.00,11612.78,NULL,NULL,NULL,0,'2026-09-05 22:57:26','2026-09-05 22:57:26'),(812,276,21,1074,'Deducción planilla intereses Ronald Campos Carranza',NULL,NULL,NULL,NULL,949,1.000000,1.000000,0.00,82898.00,0.00,82898.00,0.00,82898.00,NULL,NULL,NULL,0,'2026-09-05 22:57:26','2026-09-05 22:57:26'),(813,276,22,1074,'Deducción planilla intereses José Isidro Víquez Villegas',NULL,NULL,NULL,NULL,949,1.000000,1.000000,0.00,109921.39,0.00,109921.39,0.00,109921.39,NULL,NULL,NULL,0,'2026-09-05 22:57:26','2026-09-05 22:57:26'),(814,276,23,1074,'Deducción planilla intereses Nuria Zúñiga Thames',NULL,NULL,NULL,NULL,949,1.000000,1.000000,0.00,15107.00,0.00,15107.00,0.00,15107.00,NULL,NULL,NULL,0,'2026-09-05 22:57:26','2026-09-05 22:57:26'),(815,276,24,1074,'Deducción planilla intereses Ana Felicia Navarro Fallas',NULL,NULL,NULL,NULL,949,1.000000,1.000000,0.00,405.00,0.00,405.00,0.00,405.00,NULL,NULL,NULL,0,'2026-09-05 22:57:26','2026-09-05 22:57:26'),(816,276,25,1074,'Deducción planilla intereses José Miguel Jiménez Masís',NULL,NULL,NULL,NULL,949,1.000000,1.000000,0.00,1279.00,0.00,1279.00,0.00,1279.00,NULL,NULL,NULL,0,'2026-09-05 22:57:26','2026-09-05 22:57:26'),(817,276,26,1074,'Deducción planilla intereses Fabián Cerdas Camacho',NULL,NULL,NULL,NULL,949,1.000000,1.000000,0.00,9926.00,0.00,9926.00,0.00,9926.00,NULL,NULL,NULL,0,'2026-09-05 22:57:26','2026-09-05 22:57:26'),(818,276,27,1074,'Deducción planilla intereses César Solís Mata',NULL,NULL,NULL,NULL,949,1.000000,1.000000,0.00,2808.00,0.00,2808.00,0.00,2808.00,NULL,NULL,NULL,0,'2026-09-05 22:57:26','2026-09-05 22:57:26'),(819,276,28,1074,'Deducción planilla intereses Gustavo Zúñiga Solano',NULL,NULL,NULL,NULL,949,1.000000,1.000000,0.00,620.00,0.00,620.00,0.00,620.00,NULL,NULL,NULL,0,'2026-09-05 22:57:26','2026-09-05 22:57:26'),(820,276,29,1074,'Deducción planilla intereses Hugo Ruiz Salas',NULL,NULL,NULL,NULL,949,1.000000,1.000000,0.00,11943.43,0.00,11943.43,0.00,11943.43,NULL,NULL,NULL,0,'2026-09-05 22:57:26','2026-09-05 22:57:26'),(821,276,30,1074,'Deducción planilla intereses Franciny Segura Alvarez',NULL,NULL,NULL,NULL,949,1.000000,1.000000,0.00,10225.00,0.00,10225.00,0.00,10225.00,NULL,NULL,NULL,0,'2026-09-05 22:57:26','2026-09-05 22:57:26'),(822,276,31,1074,'Deducción planilla intereses José Gabriel Ortega López',NULL,NULL,NULL,NULL,949,1.000000,1.000000,0.00,1595.00,0.00,1595.00,0.00,1595.00,NULL,NULL,NULL,0,'2026-09-05 22:57:26','2026-09-05 22:57:26'),(823,276,32,1074,'Deducción planilla intereses Jonathan Cedeño Camacho',NULL,NULL,NULL,NULL,949,1.000000,1.000000,0.00,24382.00,0.00,24382.00,0.00,24382.00,NULL,NULL,NULL,0,'2026-09-05 22:57:26','2026-09-05 22:57:26'),(824,276,33,1074,'Deducción planilla intereses Andrés Chavés Vargas',NULL,NULL,NULL,NULL,949,1.000000,1.000000,0.00,4682.00,0.00,4682.00,0.00,4682.00,NULL,NULL,NULL,0,'2026-09-05 22:57:26','2026-09-05 22:57:26'),(825,276,34,1074,'Deducción planilla intereses Oscar Umaña masis',NULL,NULL,NULL,NULL,949,1.000000,1.000000,0.00,68.01,0.00,68.01,0.00,68.01,NULL,NULL,NULL,0,'2026-09-05 22:57:26','2026-09-05 22:57:26'),(826,276,35,1074,'Deducción planilla intereses Josè Fabio Rojas Borbòn',NULL,NULL,NULL,NULL,949,1.000000,1.000000,0.00,2442.00,0.00,2442.00,0.00,2442.00,NULL,NULL,NULL,0,'2026-09-05 22:57:26','2026-09-05 22:57:26'),(827,276,36,1074,'Deducción planilla intereses Luis Azofeifa Conejo',NULL,NULL,NULL,NULL,949,1.000000,1.000000,0.00,291.00,0.00,291.00,0.00,291.00,NULL,NULL,NULL,0,'2026-09-05 22:57:26','2026-09-05 22:57:26'),(828,276,37,1074,'Deducción planilla intereses Renzo Solis Chavarría',NULL,NULL,NULL,NULL,949,1.000000,1.000000,0.00,2439.00,0.00,2439.00,0.00,2439.00,NULL,NULL,NULL,0,'2026-09-05 22:57:26','2026-09-05 22:57:26'),(829,276,38,1074,'Deducción planilla intereses Julián Diaz Granados',NULL,NULL,NULL,NULL,949,1.000000,1.000000,0.00,13496.00,0.00,13496.00,0.00,13496.00,NULL,NULL,NULL,0,'2026-09-05 22:57:26','2026-09-05 22:57:26'),(830,276,39,953,'Deducciones planilla agosto 2026',NULL,NULL,NULL,NULL,949,1.000000,1.000000,1671949.15,0.00,1671949.15,0.00,1671949.15,0.00,NULL,NULL,NULL,0,'2026-09-05 22:57:26','2026-09-05 22:57:26'),(831,276,40,954,'Deducciones planilla agosto 2026',NULL,NULL,NULL,NULL,949,1.000000,1.000000,1003169.55,0.00,1003169.55,0.00,1003169.55,0.00,NULL,NULL,NULL,0,'2026-09-05 22:57:26','2026-09-05 22:57:26'),(832,276,41,955,'Deducciones planilla agosto 2026',NULL,NULL,NULL,NULL,949,1.000000,1.000000,1490528.50,0.00,1490528.50,0.00,1490528.50,0.00,NULL,NULL,NULL,0,'2026-09-05 22:57:26','2026-09-05 22:57:26'),(833,276,42,956,'Deducciones planilla agosto 2026',NULL,NULL,NULL,NULL,949,1.000000,1.000000,624500.00,0.00,624500.00,0.00,624500.00,0.00,NULL,NULL,NULL,0,'2026-09-05 22:57:26','2026-09-05 22:57:26'),(834,276,43,957,'Deducciones planilla agosto 2026',NULL,NULL,NULL,NULL,949,1.000000,1.000000,126000.00,0.00,126000.00,0.00,126000.00,0.00,NULL,NULL,NULL,0,'2026-09-05 22:57:26','2026-09-05 22:57:26'),(835,276,44,958,'Deducciones planilla agosto 2026',NULL,NULL,NULL,NULL,949,1.000000,1.000000,59000.00,0.00,59000.00,0.00,59000.00,0.00,NULL,NULL,NULL,0,'2026-09-05 22:57:26','2026-09-05 22:57:26'),(836,276,45,959,'Deducciones planilla agosto 2026',NULL,NULL,NULL,NULL,949,1.000000,1.000000,71000.00,0.00,71000.00,0.00,71000.00,0.00,NULL,NULL,NULL,0,'2026-09-05 22:57:26','2026-09-05 22:57:26'),(837,276,46,961,'Deducciones planilla agosto 2026',NULL,NULL,NULL,NULL,949,1.000000,1.000000,0.00,40000.00,0.00,40000.00,0.00,40000.00,NULL,NULL,NULL,0,'2026-09-05 22:57:26','2026-09-05 22:57:26'),(838,276,47,983,'Deducciones planilla agosto 2026',NULL,NULL,NULL,NULL,949,1.000000,1.000000,0.00,40000.00,0.00,40000.00,0.00,40000.00,NULL,NULL,NULL,0,'2026-09-05 22:57:26','2026-09-05 22:57:26'),(839,276,48,984,'Deducciones planilla agosto 2026',NULL,NULL,NULL,NULL,949,1.000000,1.000000,0.00,20000.00,0.00,20000.00,0.00,20000.00,NULL,NULL,NULL,0,'2026-09-05 22:57:26','2026-09-05 22:57:26'),(840,276,49,985,'Deducciones planilla agosto 2026',NULL,NULL,NULL,NULL,949,1.000000,1.000000,0.00,180000.00,0.00,180000.00,0.00,180000.00,NULL,NULL,NULL,0,'2026-09-05 22:57:26','2026-09-05 22:57:26'),(841,276,50,986,'Deducciones planilla agosto 2026',NULL,NULL,NULL,NULL,949,1.000000,1.000000,0.00,10000.00,0.00,10000.00,0.00,10000.00,NULL,NULL,NULL,0,'2026-09-05 22:57:26','2026-09-05 22:57:26'),(842,276,51,987,'Deducciones planilla agosto 2026',NULL,NULL,NULL,NULL,949,1.000000,1.000000,0.00,40000.00,0.00,40000.00,0.00,40000.00,NULL,NULL,NULL,0,'2026-09-05 22:57:26','2026-09-05 22:57:26'),(843,276,52,988,'Deducciones planilla agosto 2026',NULL,NULL,NULL,NULL,949,1.000000,1.000000,0.00,20000.00,0.00,20000.00,0.00,20000.00,NULL,NULL,NULL,0,'2026-09-05 22:57:26','2026-09-05 22:57:26'),(844,276,53,989,'Deducciones planilla agosto 2026',NULL,NULL,NULL,NULL,949,1.000000,1.000000,0.00,10000.00,0.00,10000.00,0.00,10000.00,NULL,NULL,NULL,0,'2026-09-05 22:57:26','2026-09-05 22:57:26'),(845,276,54,990,'Deducciones planilla agosto 2026',NULL,NULL,NULL,NULL,949,1.000000,1.000000,0.00,20000.00,0.00,20000.00,0.00,20000.00,NULL,NULL,NULL,0,'2026-09-05 22:57:26','2026-09-05 22:57:26'),(846,276,55,991,'Deducciones planilla agosto 2026',NULL,NULL,NULL,NULL,949,1.000000,1.000000,0.00,30000.00,0.00,30000.00,0.00,30000.00,NULL,NULL,NULL,0,'2026-09-05 22:57:26','2026-09-05 22:57:26'),(847,276,56,992,'Deducciones planilla agosto 2026',NULL,NULL,NULL,NULL,949,1.000000,1.000000,0.00,100000.00,0.00,100000.00,0.00,100000.00,NULL,NULL,NULL,0,'2026-09-05 22:57:26','2026-09-05 22:57:26'),(848,276,57,993,'Deducciones planilla agosto 2026',NULL,NULL,NULL,NULL,949,1.000000,1.000000,0.00,40000.00,0.00,40000.00,0.00,40000.00,NULL,NULL,NULL,0,'2026-09-05 22:57:26','2026-09-05 22:57:26'),(849,276,58,994,'Deducciones planilla agosto 2026',NULL,NULL,NULL,NULL,949,1.000000,1.000000,0.00,100000.00,0.00,100000.00,0.00,100000.00,NULL,NULL,NULL,0,'2026-09-05 22:57:26','2026-09-05 22:57:26'),(850,276,59,995,'Deducciones planilla agosto 2026',NULL,NULL,NULL,NULL,949,1.000000,1.000000,0.00,6000.00,0.00,6000.00,0.00,6000.00,NULL,NULL,NULL,0,'2026-09-05 22:57:26','2026-09-05 22:57:26'),(851,276,60,996,'Deducciones planilla agosto 2026',NULL,NULL,NULL,NULL,949,1.000000,1.000000,0.00,8500.00,0.00,8500.00,0.00,8500.00,NULL,NULL,NULL,0,'2026-09-05 22:57:26','2026-09-05 22:57:26'),(852,276,61,998,'Deducciones planilla agosto 2026',NULL,NULL,NULL,NULL,949,1.000000,1.000000,0.00,20000.00,0.00,20000.00,0.00,20000.00,NULL,NULL,NULL,0,'2026-09-05 22:57:26','2026-09-05 22:57:26'),(853,276,62,999,'Deducciones planilla agosto 2026',NULL,NULL,NULL,NULL,949,1.000000,1.000000,0.00,96000.00,0.00,96000.00,0.00,96000.00,NULL,NULL,NULL,0,'2026-09-05 22:57:26','2026-09-05 22:57:26'),(854,276,63,1000,'Deducciones planilla agosto 2026',NULL,NULL,NULL,NULL,949,1.000000,1.000000,0.00,10000.00,0.00,10000.00,0.00,10000.00,NULL,NULL,NULL,0,'2026-09-05 22:57:26','2026-09-05 22:57:26'),(855,276,64,1002,'Deducciones planilla agosto 2026',NULL,NULL,NULL,NULL,949,1.000000,1.000000,0.00,20000.00,0.00,20000.00,0.00,20000.00,NULL,NULL,NULL,0,'2026-09-05 22:57:26','2026-09-05 22:57:26'),(856,276,65,1003,'Deducciones planilla agosto 2026',NULL,NULL,NULL,NULL,949,1.000000,1.000000,0.00,10000.00,0.00,10000.00,0.00,10000.00,NULL,NULL,NULL,0,'2026-09-05 22:57:26','2026-09-05 22:57:26'),(857,276,66,1004,'Deducciones planilla agosto 2026',NULL,NULL,NULL,NULL,949,1.000000,1.000000,0.00,12000.00,0.00,12000.00,0.00,12000.00,NULL,NULL,NULL,0,'2026-09-05 22:57:27','2026-09-05 22:57:27'),(858,276,67,1005,'Deducciones planilla agosto 2026',NULL,NULL,NULL,NULL,949,1.000000,1.000000,0.00,17000.00,0.00,17000.00,0.00,17000.00,NULL,NULL,NULL,0,'2026-09-05 22:57:27','2026-09-05 22:57:27'),(859,276,68,1019,'Deducciones planilla agosto 2026',NULL,NULL,NULL,NULL,949,1.000000,1.000000,0.00,89500.00,0.00,89500.00,0.00,89500.00,NULL,NULL,NULL,0,'2026-09-05 22:57:27','2026-09-05 22:57:27'),(860,276,69,1020,'Deducciones planilla agosto 2026',NULL,NULL,NULL,NULL,949,1.000000,1.000000,0.00,26873.45,0.00,26873.45,0.00,26873.45,NULL,NULL,NULL,0,'2026-09-05 22:57:27','2026-09-05 22:57:27'),(861,276,70,1021,'Deducciones planilla agosto 2026',NULL,NULL,NULL,NULL,949,1.000000,1.000000,0.00,35200.00,0.00,35200.00,0.00,35200.00,NULL,NULL,NULL,0,'2026-09-05 22:57:27','2026-09-05 22:57:27'),(862,276,71,1022,'Deducciones planilla agosto 2026',NULL,NULL,NULL,NULL,949,1.000000,1.000000,0.00,122750.00,0.00,122750.00,0.00,122750.00,NULL,NULL,NULL,0,'2026-09-05 22:57:27','2026-09-05 22:57:27'),(863,276,72,1023,'Deducciones planilla agosto 2026',NULL,NULL,NULL,NULL,949,1.000000,1.000000,0.00,19400.00,0.00,19400.00,0.00,19400.00,NULL,NULL,NULL,0,'2026-09-05 22:57:27','2026-09-05 22:57:27'),(864,276,73,1024,'Deducciones planilla agosto 2026',NULL,NULL,NULL,NULL,949,1.000000,1.000000,0.00,127458.45,0.00,127458.45,0.00,127458.45,NULL,NULL,NULL,0,'2026-09-05 22:57:27','2026-09-05 22:57:27'),(865,276,74,1025,'Deducciones planilla agosto 2026',NULL,NULL,NULL,NULL,949,1.000000,1.000000,0.00,35786.65,0.00,35786.65,0.00,35786.65,NULL,NULL,NULL,0,'2026-09-05 22:57:27','2026-09-05 22:57:27'),(866,276,75,1026,'Deducciones planilla agosto 2026',NULL,NULL,NULL,NULL,949,1.000000,1.000000,0.00,147339.90,0.00,147339.90,0.00,147339.90,NULL,NULL,NULL,0,'2026-09-05 22:57:27','2026-09-05 22:57:27'),(867,276,76,1027,'Deducciones planilla agosto 2026',NULL,NULL,NULL,NULL,949,1.000000,1.000000,0.00,27000.00,0.00,27000.00,0.00,27000.00,NULL,NULL,NULL,0,'2026-09-05 22:57:27','2026-09-05 22:57:27'),(868,276,77,1028,'Deducciones planilla agosto 2026',NULL,NULL,NULL,NULL,949,1.000000,1.000000,0.00,35200.00,0.00,35200.00,0.00,35200.00,NULL,NULL,NULL,0,'2026-09-05 22:57:27','2026-09-05 22:57:27'),(869,276,78,1029,'Deducciones planilla agosto 2026',NULL,NULL,NULL,NULL,949,1.000000,1.000000,0.00,115000.00,0.00,115000.00,0.00,115000.00,NULL,NULL,NULL,0,'2026-09-05 22:57:27','2026-09-05 22:57:27'),(870,276,79,1030,'Deducciones planilla agosto 2026',NULL,NULL,NULL,NULL,949,1.000000,1.000000,0.00,37500.00,0.00,37500.00,0.00,37500.00,NULL,NULL,NULL,0,'2026-09-05 22:57:27','2026-09-05 22:57:27'),(871,276,80,1031,'Deducciones planilla agosto 2026',NULL,NULL,NULL,NULL,949,1.000000,1.000000,0.00,32517.70,0.00,32517.70,0.00,32517.70,NULL,NULL,NULL,0,'2026-09-05 22:57:27','2026-09-05 22:57:27'),(872,276,81,1032,'Deducciones planilla agosto 2026',NULL,NULL,NULL,NULL,949,1.000000,1.000000,0.00,32700.00,0.00,32700.00,0.00,32700.00,NULL,NULL,NULL,0,'2026-09-05 22:57:27','2026-09-05 22:57:27'),(873,276,82,1033,'Deducciones planilla agosto 2026',NULL,NULL,NULL,NULL,949,1.000000,1.000000,0.00,25700.00,0.00,25700.00,0.00,25700.00,NULL,NULL,NULL,0,'2026-09-05 22:57:27','2026-09-05 22:57:27'),(874,276,83,1034,'Deducciones planilla agosto 2026',NULL,NULL,NULL,NULL,949,1.000000,1.000000,0.00,98133.35,0.00,98133.35,0.00,98133.35,NULL,NULL,NULL,0,'2026-09-05 22:57:27','2026-09-05 22:57:27'),(875,276,84,1035,'Deducciones planilla agosto 2026',NULL,NULL,NULL,NULL,949,1.000000,1.000000,0.00,54400.00,0.00,54400.00,0.00,54400.00,NULL,NULL,NULL,0,'2026-09-05 22:57:27','2026-09-05 22:57:27'),(876,276,85,1036,'Deducciones planilla agosto 2026',NULL,NULL,NULL,NULL,949,1.000000,1.000000,0.00,31901.05,0.00,31901.05,0.00,31901.05,NULL,NULL,NULL,0,'2026-09-05 22:57:27','2026-09-05 22:57:27'),(877,276,86,1037,'Deducciones planilla agosto 2026',NULL,NULL,NULL,NULL,949,1.000000,1.000000,0.00,26500.00,0.00,26500.00,0.00,26500.00,NULL,NULL,NULL,0,'2026-09-05 22:57:27','2026-09-05 22:57:27'),(878,276,87,1038,'Deducciones planilla agosto 2026',NULL,NULL,NULL,NULL,949,1.000000,1.000000,0.00,46459.50,0.00,46459.50,0.00,46459.50,NULL,NULL,NULL,0,'2026-09-05 22:57:27','2026-09-05 22:57:27'),(879,276,88,1039,'Deducciones planilla agosto 2026',NULL,NULL,NULL,NULL,949,1.000000,1.000000,0.00,24500.00,0.00,24500.00,0.00,24500.00,NULL,NULL,NULL,0,'2026-09-05 22:57:27','2026-09-05 22:57:27'),(880,276,89,1040,'Deducciones planilla agosto 2026',NULL,NULL,NULL,NULL,949,1.000000,1.000000,0.00,43200.00,0.00,43200.00,0.00,43200.00,NULL,NULL,NULL,0,'2026-09-05 22:57:27','2026-09-05 22:57:27'),(881,276,90,1041,'Deducciones planilla agosto 2026',NULL,NULL,NULL,NULL,949,1.000000,1.000000,0.00,57250.00,0.00,57250.00,0.00,57250.00,NULL,NULL,NULL,0,'2026-09-05 22:57:27','2026-09-05 22:57:27'),(882,276,91,1042,'Deducciones planilla agosto 2026',NULL,NULL,NULL,NULL,949,1.000000,1.000000,0.00,32700.00,0.00,32700.00,0.00,32700.00,NULL,NULL,NULL,0,'2026-09-05 22:57:27','2026-09-05 22:57:27'),(883,276,92,1043,'Deducciones planilla agosto 2026',NULL,NULL,NULL,NULL,949,1.000000,1.000000,0.00,47900.45,0.00,47900.45,0.00,47900.45,NULL,NULL,NULL,0,'2026-09-05 22:57:27','2026-09-05 22:57:27'),(884,276,93,1044,'Deducciones planilla agosto 2026',NULL,NULL,NULL,NULL,949,1.000000,1.000000,0.00,49000.00,0.00,49000.00,0.00,49000.00,NULL,NULL,NULL,0,'2026-09-05 22:57:27','2026-09-05 22:57:27'),(885,276,94,1045,'Deducciones planilla agosto 2026',NULL,NULL,NULL,NULL,949,1.000000,1.000000,0.00,24068.35,0.00,24068.35,0.00,24068.35,NULL,NULL,NULL,0,'2026-09-05 22:57:27','2026-09-05 22:57:27'),(886,276,95,1046,'Deducciones planilla agosto 2026',NULL,NULL,NULL,NULL,949,1.000000,1.000000,0.00,30127.50,0.00,30127.50,0.00,30127.50,NULL,NULL,NULL,0,'2026-09-05 22:57:27','2026-09-05 22:57:27'),(887,276,96,1047,'Deducciones planilla agosto 2026',NULL,NULL,NULL,NULL,949,1.000000,1.000000,0.00,24253.10,0.00,24253.10,0.00,24253.10,NULL,NULL,NULL,0,'2026-09-05 22:57:27','2026-09-05 22:57:27'),(888,276,97,1048,'Deducciones planilla agosto 2026',NULL,NULL,NULL,NULL,949,1.000000,1.000000,0.00,30529.70,0.00,30529.70,0.00,30529.70,NULL,NULL,NULL,0,'2026-09-05 22:57:27','2026-09-05 22:57:27'),(889,276,98,1049,'Deducciones planilla agosto 2026',NULL,NULL,NULL,NULL,949,1.000000,1.000000,0.00,68600.00,0.00,68600.00,0.00,68600.00,NULL,NULL,NULL,0,'2026-09-05 22:57:27','2026-09-05 22:57:27'),(890,276,99,1050,'Deducciones planilla agosto 2026',NULL,NULL,NULL,NULL,949,1.000000,1.000000,0.00,72500.00,0.00,72500.00,0.00,72500.00,NULL,NULL,NULL,0,'2026-09-05 22:57:27','2026-09-05 22:57:27'),(891,276,100,1053,'Deducciones planilla agosto 2026',NULL,NULL,NULL,NULL,949,1.000000,1.000000,0.00,1003169.55,0.00,1003169.55,0.00,1003169.55,NULL,NULL,NULL,0,'2026-09-05 22:57:27','2026-09-05 22:57:27'),(892,276,101,1086,'Deducciones planilla agosto 2026',NULL,NULL,NULL,NULL,949,1.000000,1.000000,0.00,71000.00,0.00,71000.00,0.00,71000.00,NULL,NULL,NULL,0,'2026-09-05 22:57:27','2026-09-05 22:57:27'),(995,277,1,921,'William V. premio rifia tómbola 05-08-2026',NULL,NULL,NULL,NULL,949,1.000000,1.000000,0.00,10000.00,0.00,10000.00,0.00,10000.00,NULL,'22613374','2026-08-05',0,'2026-09-06 01:22:40','2026-09-06 01:22:40'),(996,277,2,921,'Franciny Segura dev. Flexiahorro  05-08-2026',NULL,NULL,NULL,NULL,949,1.000000,1.000000,0.00,40000.00,0.00,40000.00,0.00,40000.00,NULL,'22610227','2026-08-05',0,'2026-09-06 01:22:40','2026-09-06 01:22:40'),(997,277,3,921,'Renzo Soliss Préstamo PP-0640 05-08-2026',NULL,NULL,NULL,NULL,949,1.000000,1.000000,0.00,170000.00,0.00,170000.00,0.00,170000.00,NULL,'22610612','2026-08-05',0,'2026-09-06 01:22:40','2026-09-06 01:22:40'),(998,277,4,921,'Cristhian C. préstamo PP-0642  05-08-2026',NULL,NULL,NULL,NULL,949,1.000000,1.000000,0.00,200000.00,0.00,200000.00,0.00,200000.00,NULL,'22610945','2026-08-05',0,'2026-09-06 01:22:40','2026-09-06 01:22:40'),(999,277,5,921,'William V. préstamo PP0641   06-08-2026',NULL,NULL,NULL,NULL,949,1.000000,1.000000,0.00,1000000.00,0.00,1000000.00,0.00,1000000.00,NULL,'22716995','2026-08-06',0,'2026-09-06 01:22:40','2026-09-06 01:22:40'),(1000,277,6,921,'BN Fondos inversión 679761,01540 partic.  06-08-26',NULL,NULL,NULL,NULL,949,1.000000,1.000000,0.00,3500000.00,0.00,3500000.00,0.00,3500000.00,NULL,'14859401','2026-08-06',0,'2026-09-06 01:22:40','2026-09-06 01:22:40'),(1001,277,7,921,'José Chavés préstamo PP-0643 06-08-2026',NULL,NULL,NULL,NULL,949,1.000000,1.000000,0.00,350000.00,0.00,350000.00,0.00,350000.00,NULL,'22716697','2026-08-06',0,'2026-09-06 01:22:40','2026-09-06 01:22:40'),(1002,277,8,921,'Hilda Redondo  premio día madre  06-08-2026',NULL,NULL,NULL,NULL,949,1.000000,1.000000,0.00,100000.00,0.00,100000.00,0.00,100000.00,NULL,'22964554','2026-08-10',0,'2026-09-06 01:22:40','2026-09-06 01:22:40'),(1003,277,9,921,'vanessa Mendoza pago FC-28  10-08-2026',NULL,NULL,NULL,NULL,949,1.000000,1.000000,0.00,113000.00,0.00,113000.00,0.00,113000.00,NULL,'22964262','2026-08-10',0,'2026-09-06 01:22:40','2026-09-06 01:22:40'),(1004,277,10,921,'Ministerio de Hacienda _ Rtención RCM_',NULL,NULL,NULL,NULL,949,1.000000,1.000000,0.00,57096.00,0.00,57096.00,0.00,57096.00,NULL,'14905996','2026-08-10',0,'2026-09-06 01:22:40','2026-09-06 01:22:40'),(1005,277,11,921,'Olmán Mora préstamo PP-0644 13-08-2026',NULL,NULL,NULL,NULL,949,1.000000,1.000000,0.00,150000.00,0.00,150000.00,0.00,150000.00,NULL,'23196146','2026-08-13',0,'2026-09-06 01:22:40','2026-09-06 01:22:40'),(1006,277,12,921,'Préstamo RESUSA PR-0129   13-08-2026',NULL,NULL,NULL,NULL,949,1.000000,1.000000,0.00,3509477.26,0.00,3509477.26,0.00,3509477.26,NULL,'23196601','2026-08-13',0,'2026-09-06 01:22:40','2026-09-06 01:22:40'),(1007,277,13,921,'Gerardo Delgado préstamo PP-0645 20-08-2026',NULL,NULL,NULL,NULL,949,1.000000,1.000000,0.00,1180000.00,0.00,1180000.00,0.00,1180000.00,NULL,'23730504','2026-08-20',0,'2026-09-06 01:22:40','2026-09-06 01:22:40'),(1008,277,14,921,'Verónica Jiménez préstamo PP-0646 20-08-2026',NULL,NULL,NULL,NULL,949,1.000000,1.000000,0.00,78000.00,0.00,78000.00,0.00,78000.00,NULL,'23730922','2026-08-20',0,'2026-09-06 01:22:40','2026-09-06 01:22:40'),(1009,277,15,921,'Préstamo RESUSA PR-0130  20-08-2026',NULL,NULL,NULL,NULL,949,1.000000,1.000000,0.00,4175524.31,0.00,4175524.31,0.00,4175524.31,NULL,'23731289','2026-08-20',0,'2026-09-06 01:22:40','2026-09-06 01:22:40'),(1010,277,16,921,'Hugo Ruiz  Préstamo PP-0647  27-08-26',NULL,NULL,NULL,NULL,949,1.000000,1.000000,0.00,200000.00,0.00,200000.00,0.00,200000.00,NULL,'24143669','2026-08-27',0,'2026-09-06 01:22:40','2026-09-06 01:22:40'),(1011,277,17,921,'Verónica Jiménez préstamo PP-0648  27-08-2026',NULL,NULL,NULL,NULL,949,1.000000,1.000000,0.00,29000.00,0.00,29000.00,0.00,29000.00,NULL,'24143962','2026-08-27',0,'2026-09-06 01:22:40','2026-09-06 01:22:40'),(1012,277,18,921,'Nuria Zúñiga REC-386711  27-08-2026',NULL,NULL,NULL,NULL,949,1.000000,1.000000,0.00,40000.00,0.00,40000.00,0.00,40000.00,NULL,'24144777','2026-08-27',0,'2026-09-06 01:22:40','2026-09-06 01:22:40'),(1013,277,19,929,'BN Fondos inversión 679761,01540 partic.  06-08-26',NULL,NULL,NULL,NULL,949,1.000000,1.000000,3500000.00,0.00,3500000.00,0.00,3500000.00,0.00,NULL,'14859401','2026-08-06',0,'2026-09-06 01:22:40','2026-09-06 01:22:40'),(1014,277,20,933,'Olmán Mora préstamo PP-0644 13-08-2026',NULL,NULL,NULL,NULL,949,1.000000,1.000000,151500.00,0.00,151500.00,0.00,151500.00,0.00,NULL,'23196146','2026-08-13',0,'2026-09-06 01:22:40','2026-09-06 01:22:40'),(1015,277,21,942,'Hugo Ruiz  Préstamo PP-0647  27-08-26',NULL,NULL,NULL,NULL,949,1.000000,1.000000,202000.00,0.00,202000.00,0.00,202000.00,0.00,NULL,'24143669','2026-08-27',0,'2026-09-06 01:22:40','2026-09-06 01:22:40'),(1016,277,22,950,'Renzo Soliss Préstamo PP-0640 05-08-2026',NULL,NULL,NULL,NULL,949,1.000000,1.000000,171700.00,0.00,171700.00,0.00,171700.00,0.00,NULL,'22610612','2026-08-05',0,'2026-09-06 01:22:40','2026-09-06 01:22:40'),(1017,277,23,1111,'William V. préstamo PP0641   06-08-2026',NULL,NULL,NULL,NULL,949,1.000000,1.000000,1030000.00,0.00,1030000.00,0.00,1030000.00,0.00,NULL,'22716995','2026-08-06',0,'2026-09-06 01:22:40','2026-09-06 01:22:40'),(1018,277,24,1112,'Cristhian C. préstamo PP-0642  05-08-2026',NULL,NULL,NULL,NULL,949,1.000000,1.000000,202000.00,0.00,202000.00,0.00,202000.00,0.00,NULL,'22610945','2026-08-05',0,'2026-09-06 01:22:40','2026-09-06 01:22:40'),(1019,277,25,1113,'José Chavés préstamo PP-0643 06-08-2026',NULL,NULL,NULL,NULL,949,1.000000,1.000000,353500.00,0.00,353500.00,0.00,353500.00,0.00,NULL,'22716697','2026-08-06',0,'2026-09-06 01:22:40','2026-09-06 01:22:40'),(1020,277,26,1114,'Gerardo Delgado préstamo PP-0645 20-08-2026',NULL,NULL,NULL,NULL,949,1.000000,1.000000,1191800.00,0.00,1191800.00,0.00,1191800.00,0.00,NULL,'23730504','2026-08-20',0,'2026-09-06 01:22:40','2026-09-06 01:22:40'),(1021,277,27,961,'Verónica Jiménez préstamo PP-0646 20-08-2026',NULL,NULL,NULL,NULL,949,1.000000,1.000000,78000.00,0.00,78000.00,0.00,78000.00,0.00,NULL,'23730922','2026-08-20',0,'2026-09-06 01:22:40','2026-09-06 01:22:40'),(1022,277,28,961,'Verónica Jiménez préstamo PP-0648  27-08-2026',NULL,NULL,NULL,NULL,949,1.000000,1.000000,29000.00,0.00,29000.00,0.00,29000.00,0.00,NULL,'24143962','2026-08-27',0,'2026-09-06 01:22:40','2026-09-06 01:22:40'),(1023,277,29,964,'Préstamo RESUSA PR-0129   13-08-2026',NULL,91,NULL,NULL,949,1.000000,1.000000,3509477.26,0.00,3509477.26,0.00,3509477.26,0.00,'2026-10-12','23196601','2026-08-13',0,'2026-09-06 01:22:40','2026-09-06 17:36:16'),(1024,277,30,964,'Préstamo RESUSA PR-0130  20-08-2026',NULL,91,NULL,NULL,949,1.000000,1.000000,4175524.31,0.00,4175524.31,0.00,4175524.31,0.00,'2026-10-19','23731289','2026-08-20',0,'2026-09-06 01:22:40','2026-09-06 17:36:16'),(1025,277,31,965,'Intereses préstamo RESUSA PR-0129   13-08-2026',NULL,92,NULL,NULL,949,1.000000,1.000000,92133.95,0.00,92133.95,0.00,92133.95,0.00,'2026-10-12','fc-23','2026-08-13',0,'2026-09-06 01:22:40','2026-09-06 17:36:16'),(1026,277,32,965,'Préstamo RESUSA PR-0130  20-08-2026',NULL,92,NULL,NULL,949,1.000000,1.000000,117358.49,0.00,117358.49,0.00,117358.49,0.00,'2026-10-19','fc-24','2026-08-20',0,'2026-09-06 01:22:40','2026-09-06 17:36:16'),(1027,277,33,977,'vanessa Mendoza pago FC-28  10-08-2026',NULL,90,NULL,NULL,949,1.000000,1.000000,113000.00,0.00,113000.00,0.00,113000.00,0.00,'2026-09-09','22964262','2026-08-10',0,'2026-09-06 01:22:40','2026-09-06 17:36:16'),(1028,277,34,1005,'Franciny Segura dev. Flexiahorro  05-08-2026',NULL,NULL,NULL,NULL,949,1.000000,1.000000,40000.00,0.00,40000.00,0.00,40000.00,0.00,NULL,'22610227','2026-08-05',0,'2026-09-06 01:22:40','2026-09-06 01:22:40'),(1029,277,35,1014,'Ministerio de Hacienda _ Rtención RCM_',NULL,NULL,NULL,NULL,949,1.000000,1.000000,57096.00,0.00,57096.00,0.00,57096.00,0.00,NULL,'14905996','2026-08-10',0,'2026-09-06 01:22:40','2026-09-06 01:22:40'),(1030,277,36,1014,'Intereses préstamo RESUSA PR-0129   13-08-2026',NULL,NULL,NULL,NULL,949,1.000000,1.000000,0.00,13820.09,0.00,13820.09,0.00,13820.09,NULL,'fc-23','2026-08-13',0,'2026-09-06 01:22:40','2026-09-06 01:22:40'),(1031,277,37,1014,'Préstamo RESUSA PR-0130  20-08-2026',NULL,NULL,NULL,NULL,949,1.000000,1.000000,0.00,17603.77,0.00,17603.77,0.00,17603.77,NULL,'fc-24','2026-08-20',0,'2026-09-06 01:22:40','2026-09-06 01:22:40'),(1032,277,38,1079,'Renzo Soliss Préstamo PP-0640 05-08-2026',NULL,NULL,NULL,NULL,949,1.000000,1.000000,0.00,1700.00,0.00,1700.00,0.00,1700.00,NULL,'22610612','2026-08-05',0,'2026-09-06 01:22:40','2026-09-06 01:22:40'),(1033,277,39,1079,'Cristhian C. préstamo PP-0642  05-08-2026',NULL,NULL,NULL,NULL,949,1.000000,1.000000,0.00,2000.00,0.00,2000.00,0.00,2000.00,NULL,'22610945','2026-08-05',0,'2026-09-06 01:22:40','2026-09-06 01:22:40'),(1034,277,40,1079,'William V. préstamo PP0641   06-08-2026',NULL,NULL,NULL,NULL,949,1.000000,1.000000,0.00,30000.00,0.00,30000.00,0.00,30000.00,NULL,'22716995','2026-08-06',0,'2026-09-06 01:22:40','2026-09-06 01:22:40'),(1035,277,41,1079,'José Chavés préstamo PP-0643 06-08-2026',NULL,NULL,NULL,NULL,949,1.000000,1.000000,0.00,3500.00,0.00,3500.00,0.00,3500.00,NULL,'22716697','2026-08-06',0,'2026-09-06 01:22:40','2026-09-06 01:22:40'),(1036,277,42,1079,'Olmán Mora préstamo PP-0644 13-08-2026',NULL,NULL,NULL,NULL,949,1.000000,1.000000,0.00,1500.00,0.00,1500.00,0.00,1500.00,NULL,'23196146','2026-08-13',0,'2026-09-06 01:22:40','2026-09-06 01:22:40'),(1037,277,43,1079,'Gerardo Delgado préstamo PP-0645 20-08-2026',NULL,NULL,NULL,NULL,949,1.000000,1.000000,0.00,11800.00,0.00,11800.00,0.00,11800.00,NULL,'23730504','2026-08-20',0,'2026-09-06 01:22:40','2026-09-06 01:22:40'),(1038,277,44,1079,'Hugo Ruiz  Préstamo PP-0647  27-08-26',NULL,NULL,NULL,NULL,949,1.000000,1.000000,0.00,2000.00,0.00,2000.00,0.00,2000.00,NULL,'24143669','2026-08-27',0,'2026-09-06 01:22:40','2026-09-06 01:22:40'),(1039,277,45,1081,'Intereses préstamo RESUSA PR-0129   13-08-2026',NULL,NULL,NULL,NULL,949,1.000000,1.000000,0.00,92133.95,0.00,92133.95,0.00,92133.95,NULL,'fc-23','2026-08-13',0,'2026-09-06 01:22:40','2026-09-06 01:22:40'),(1040,277,46,1081,'Préstamo RESUSA PR-0130  20-08-2026',NULL,NULL,NULL,NULL,949,1.000000,1.000000,0.00,117358.49,0.00,117358.49,0.00,117358.49,NULL,'fc-24','2026-08-20',0,'2026-09-06 01:22:40','2026-09-06 01:22:40'),(1041,277,47,1082,'Intereses préstamo RESUSA PR-0129   13-08-2026',NULL,NULL,NULL,NULL,949,1.000000,1.000000,13820.09,0.00,13820.09,0.00,13820.09,0.00,NULL,'fc-23','2026-08-13',0,'2026-09-06 01:22:40','2026-09-06 01:22:40'),(1042,277,48,1082,'Préstamo RESUSA PR-0130  20-08-2026',NULL,NULL,NULL,NULL,949,1.000000,1.000000,17603.77,0.00,17603.77,0.00,17603.77,0.00,NULL,'fc-24','2026-08-20',0,'2026-09-06 01:22:40','2026-09-06 01:22:40'),(1043,277,49,1087,'William V. premio rifia tómbola 05-08-2026',NULL,NULL,NULL,NULL,949,1.000000,1.000000,10000.00,0.00,10000.00,0.00,10000.00,0.00,NULL,'22613374','2026-08-05',0,'2026-09-06 01:22:40','2026-09-06 01:22:40'),(1044,277,50,1087,'Hilda Redondo  premio día madre  06-08-2026',NULL,NULL,NULL,NULL,949,1.000000,1.000000,100000.00,0.00,100000.00,0.00,100000.00,0.00,NULL,'22964554','2026-08-10',0,'2026-09-06 01:22:40','2026-09-06 01:22:40'),(1045,277,51,1098,'Nuria Zúñiga REC-386711  27-08-2026',NULL,NULL,NULL,NULL,949,1.000000,1.000000,40000.00,0.00,40000.00,0.00,40000.00,0.00,NULL,'24144777','2026-08-27',0,'2026-09-06 01:22:40','2026-09-06 01:22:40'),(1076,278,1,921,'Resusa pago deducciones planilla jul.26',NULL,NULL,NULL,NULL,949,1.000000,1.000000,5227477.80,0.00,5227477.80,0.00,5227477.80,0.00,NULL,'22661331','2026-08-06',0,'2026-09-06 20:23:17','2026-09-06 20:23:17'),(1077,278,2,921,'Retiro BN Fondos  86426,75767270 part.',NULL,NULL,NULL,NULL,949,1.000000,1.000000,445000.00,0.00,445000.00,0.00,445000.00,0.00,NULL,'4326099','2026-08-05',0,'2026-09-06 20:23:17','2026-09-06 20:23:17'),(1078,278,3,921,'Retiro BN Fondos  700840,0668545 part.',NULL,NULL,NULL,NULL,949,1.000000,1.000000,3610000.00,0.00,3610000.00,0.00,3610000.00,0.00,NULL,'4335933','2026-08-12',0,'2026-09-06 20:23:17','2026-09-06 20:23:17'),(1079,278,4,921,'Abono Verónica J. (Engel) PP-0617',NULL,NULL,NULL,NULL,949,1.000000,1.000000,7000.00,0.00,7000.00,0.00,7000.00,0.00,NULL,'99372829','2026-08-14',0,'2026-09-06 20:23:17','2026-09-06 20:23:17'),(1080,278,5,921,'Retiro BN Fondos  1039222,57063411 part.',NULL,NULL,NULL,NULL,949,1.000000,1.000000,5355525.00,0.00,5355525.00,0.00,5355525.00,0.00,NULL,'4346284','2026-08-19',0,'2026-09-06 20:23:17','2026-09-06 20:23:17'),(1081,278,6,921,'Intereses anticipados OP N|PP-0646  Verónica J.',NULL,NULL,NULL,NULL,949,1.000000,1.000000,10000.00,0.00,10000.00,0.00,10000.00,0.00,NULL,'19899457','2026-08-20',0,'2026-09-06 20:23:17','2026-09-06 20:23:17'),(1082,278,7,921,'Intereses anticipados OP N|PP-0648 Verónica J.',NULL,NULL,NULL,NULL,949,1.000000,1.000000,8000.00,0.00,8000.00,0.00,8000.00,0.00,NULL,'10374423','2026-08-28',0,'2026-09-06 20:23:17','2026-09-06 20:23:17'),(1083,278,8,921,'Números rifa tómbola agosto 26 Nuria Z.',NULL,NULL,NULL,NULL,949,1.000000,1.000000,4000.00,0.00,4000.00,0.00,4000.00,0.00,NULL,'10371997','2026-08-28',0,'2026-09-06 20:23:17','2026-09-06 20:23:17'),(1084,278,9,921,'Nuria Z abono PP-0634',NULL,NULL,NULL,NULL,949,1.000000,1.000000,20000.00,0.00,20000.00,0.00,20000.00,0.00,NULL,'10375988','2026-08-28',0,'2026-09-06 20:23:17','2026-09-06 20:23:17'),(1085,278,10,921,'Resusa cobro PR-0126  28-08-2026',NULL,NULL,NULL,NULL,949,1.000000,1.000000,2481915.43,0.00,2481915.43,0.00,2481915.43,0.00,NULL,'24275463','2026-08-28',0,'2026-09-06 20:23:17','2026-09-06 20:23:17'),(1086,278,11,921,'Franciny Montoya  número tómbola',NULL,NULL,NULL,NULL,949,1.000000,1.000000,1000.00,0.00,1000.00,0.00,1000.00,0.00,NULL,'10474342','2026-08-28',0,'2026-09-06 20:23:17','2026-09-06 20:23:17'),(1087,278,12,929,'Retiro BN Fondos  86426,75767270 part.',NULL,NULL,NULL,NULL,949,1.000000,1.000000,0.00,445000.00,0.00,445000.00,0.00,445000.00,NULL,'4326099','2026-08-05',0,'2026-09-06 20:23:17','2026-09-06 20:23:17'),(1088,278,13,929,'Retiro BN Fondos  700840,0668545 part.',NULL,NULL,NULL,NULL,949,1.000000,1.000000,0.00,3610000.00,0.00,3610000.00,0.00,3610000.00,NULL,'4335933','2026-08-12',0,'2026-09-06 20:23:17','2026-09-06 20:23:17'),(1089,278,14,929,'Retiro BN Fondos  1039222,57063411 part.',NULL,NULL,NULL,NULL,949,1.000000,1.000000,0.00,5355525.00,0.00,5355525.00,0.00,5355525.00,NULL,'4346284','2026-08-19',0,'2026-09-06 20:23:17','2026-09-06 20:23:17'),(1090,278,15,953,'Resusa pago deducciones planilla jul.26',NULL,NULL,NULL,NULL,949,1.000000,1.000000,0.00,1762131.45,0.00,1762131.45,0.00,1762131.45,NULL,'22661331','2026-08-06',0,'2026-09-06 20:23:17','2026-09-06 20:23:17'),(1091,278,16,954,'Resusa pago deducciones planilla jul.26',NULL,NULL,NULL,NULL,949,1.000000,1.000000,0.00,1057278.95,0.00,1057278.95,0.00,1057278.95,NULL,'22661331','2026-08-06',0,'2026-09-06 20:23:17','2026-09-06 20:23:17'),(1092,278,17,955,'Resusa pago deducciones planilla jul.26',NULL,NULL,NULL,NULL,949,1.000000,1.000000,0.00,1406567.40,0.00,1406567.40,0.00,1406567.40,NULL,'22661331','2026-08-06',0,'2026-09-06 20:23:17','2026-09-06 20:23:17'),(1093,278,18,956,'Resusa pago deducciones planilla jul.26',NULL,NULL,NULL,NULL,949,1.000000,1.000000,0.00,624500.00,0.00,624500.00,0.00,624500.00,NULL,'22661331','2026-08-06',0,'2026-09-06 20:23:17','2026-09-06 20:23:17'),(1094,278,19,957,'Resusa pago deducciones planilla jul.26',NULL,NULL,NULL,NULL,949,1.000000,1.000000,0.00,70000.00,0.00,70000.00,0.00,70000.00,NULL,'22661331','2026-08-06',0,'2026-09-06 20:23:17','2026-09-06 20:23:17'),(1095,278,20,958,'Resusa pago deducciones planilla jul.26',NULL,NULL,NULL,NULL,949,1.000000,1.000000,0.00,59000.00,0.00,59000.00,0.00,59000.00,NULL,'22661331','2026-08-06',0,'2026-09-06 20:23:17','2026-09-06 20:23:17'),(1096,278,21,959,'Resusa pago deducciones planilla jul.26',NULL,NULL,NULL,NULL,949,1.000000,1.000000,0.00,248000.00,0.00,248000.00,0.00,248000.00,NULL,'22661331','2026-08-06',0,'2026-09-06 20:23:17','2026-09-06 20:23:17'),(1097,278,22,961,'Abono Verónica J. (Engel) PP-0617',NULL,NULL,NULL,NULL,949,1.000000,1.000000,0.00,7000.00,0.00,7000.00,0.00,7000.00,NULL,'99372829','2026-08-14',0,'2026-09-06 20:23:17','2026-09-06 20:23:17'),(1098,278,23,964,'Resusa cobro PR-0126  28-08-2026',NULL,91,NULL,NULL,949,1.000000,1.000000,0.00,2420180.31,0.00,2420180.31,0.00,2420180.31,NULL,'24275463','2026-08-28',0,'2026-09-06 20:23:17','2026-09-06 20:23:17'),(1099,278,24,965,'Resusa cobro PR-0126  28-08-2026',NULL,92,NULL,NULL,949,1.000000,1.000000,0.00,61735.12,0.00,61735.12,0.00,61735.12,NULL,'24275463','2026-08-28',0,'2026-09-06 20:23:17','2026-09-06 20:23:17'),(1100,278,25,983,'Nuria Z abono PP-0634',NULL,NULL,NULL,NULL,949,1.000000,1.000000,0.00,12611.37,0.00,12611.37,0.00,12611.37,NULL,'10375988','2026-08-28',0,'2026-09-06 20:23:17','2026-09-06 20:23:17'),(1101,278,26,1074,'Nuria Z abono PP-0634',NULL,NULL,NULL,NULL,949,1.000000,1.000000,0.00,7388.63,0.00,7388.63,0.00,7388.63,NULL,'10375988','2026-08-28',0,'2026-09-06 20:23:17','2026-09-06 20:23:17'),(1102,278,27,1077,'Intereses anticipados OP N|PP-0646  Verónica J.',NULL,NULL,NULL,NULL,949,1.000000,1.000000,0.00,10000.00,0.00,10000.00,0.00,10000.00,NULL,'19899457','2026-08-20',0,'2026-09-06 20:23:17','2026-09-06 20:23:17'),(1103,278,28,1077,'Intereses anticipados OP N|PP-0648 Verónica J.',NULL,NULL,NULL,NULL,949,1.000000,1.000000,0.00,8000.00,0.00,8000.00,0.00,8000.00,NULL,'10374423','2026-08-28',0,'2026-09-06 20:23:17','2026-09-06 20:23:17'),(1104,278,29,1086,'Números rifa tómbola agosto 26 Nuria Z.',NULL,NULL,NULL,NULL,949,1.000000,1.000000,0.00,4000.00,0.00,4000.00,0.00,4000.00,NULL,'10371997','2026-08-28',0,'2026-09-06 20:23:17','2026-09-06 20:23:17'),(1105,278,30,1086,'Franciny Montoya  número tómbola',NULL,NULL,NULL,NULL,949,1.000000,1.000000,0.00,1000.00,0.00,1000.00,0.00,1000.00,NULL,'10474342','2026-08-28',0,'2026-09-06 20:23:17','2026-09-06 20:23:17'),(1106,279,1,921,'William Vargas premio tómbola',NULL,NULL,NULL,NULL,949,1.000000,1.000000,0.00,10000.00,0.00,10000.00,0.00,10000.00,'2026-08-31','22613374','2026-08-05',0,'2026-09-06 21:00:32','2026-09-06 21:00:32'),(1107,279,2,1087,'William Vargas premio tómbola',NULL,NULL,NULL,NULL,949,1.000000,1.000000,10000.00,0.00,10000.00,0.00,10000.00,0.00,'2026-08-31','22613374','2026-08-05',0,'2026-09-06 21:00:32','2026-09-06 21:00:32'),(1108,279,3,921,'Yessy Brenes premio Tómbola',NULL,NULL,NULL,NULL,949,1.000000,1.000000,0.00,10000.00,0.00,10000.00,0.00,10000.00,'2026-08-31','22613375','2026-08-05',0,'2026-09-06 21:00:32','2026-09-06 21:00:32'),(1109,279,4,1087,'Yessy Brenes premio Tómbola',NULL,NULL,NULL,NULL,949,1.000000,1.000000,10000.00,0.00,10000.00,0.00,10000.00,0.00,'2026-08-31','22613375','2026-08-05',0,'2026-09-06 21:00:32','2026-09-06 21:00:32'),(1110,279,5,921,'Franciny Segura premio tómbola',NULL,NULL,NULL,NULL,949,1.000000,1.000000,0.00,5000.00,0.00,5000.00,0.00,5000.00,'2026-08-31','22613376','2026-08-05',0,'2026-09-06 21:00:32','2026-09-06 21:00:32'),(1111,279,6,1087,'Franciny Segura premio tómbola',NULL,NULL,NULL,NULL,949,1.000000,1.000000,5000.00,0.00,5000.00,0.00,5000.00,0.00,'2026-08-31','22613376','2026-08-05',0,'2026-09-06 21:00:32','2026-09-06 21:00:32'),(1112,279,7,921,'Premio día madre \"Hugo Ruiz\"',NULL,NULL,NULL,NULL,949,1.000000,1.000000,0.00,50000.00,0.00,50000.00,0.00,50000.00,'2026-08-31','229654.73','2026-08-10',0,'2026-09-06 21:00:32','2026-09-06 21:00:32'),(1113,279,8,1078,'Premio día madre \"Hugo Ruiz\"',NULL,NULL,NULL,NULL,949,1.000000,1.000000,50000.00,0.00,50000.00,0.00,50000.00,0.00,'2026-08-31','22965473','2026-08-10',0,'2026-09-06 21:00:32','2026-09-06 21:00:32'),(1114,279,9,921,'Ajuste conciliación bancaria',NULL,NULL,NULL,NULL,949,1.000000,1.000000,0.33,0.00,0.33,0.00,0.33,0.00,'2026-08-31','082026','2026-08-31',0,'2026-09-06 21:00:32','2026-09-06 21:00:32'),(1115,279,10,1074,'Ajuste conciliación bancaria',NULL,NULL,NULL,NULL,949,1.000000,1.000000,0.00,0.33,0.00,0.33,0.00,0.33,'2026-08-31','08-2026','2026-08-31',0,'2026-09-06 21:00:32','2026-09-06 21:00:32'),(1116,280,1,921,'William Vargas premio tómbola',NULL,NULL,NULL,NULL,949,1.000000,1.000000,0.00,10000.00,0.00,10000.00,0.00,10000.00,NULL,NULL,NULL,0,'2026-09-06 21:32:17','2026-09-06 21:32:17'),(1117,280,2,1087,'William Vargas premio tómbola',NULL,NULL,NULL,NULL,949,1.000000,1.000000,10000.00,0.00,10000.00,0.00,10000.00,0.00,NULL,NULL,NULL,0,'2026-09-06 21:32:17','2026-09-06 21:32:17'),(1118,280,3,921,'Yessy Brenes premio Tómbola',NULL,NULL,NULL,NULL,949,1.000000,1.000000,0.00,10000.00,0.00,10000.00,0.00,10000.00,NULL,NULL,NULL,0,'2026-09-06 21:32:17','2026-09-06 21:32:17'),(1119,280,4,1087,'Yessy Brenes premio Tómbola',NULL,NULL,NULL,NULL,949,1.000000,1.000000,10000.00,0.00,10000.00,0.00,10000.00,0.00,NULL,NULL,NULL,0,'2026-09-06 21:32:17','2026-09-06 21:32:17'),(1120,280,5,921,'Franciny Segura premio tómbola',NULL,NULL,NULL,NULL,949,1.000000,1.000000,0.00,5000.00,0.00,5000.00,0.00,5000.00,NULL,NULL,NULL,0,'2026-09-06 21:32:17','2026-09-06 21:32:17'),(1121,280,6,1087,'Franciny Segura premio tómbola',NULL,NULL,NULL,NULL,949,1.000000,1.000000,5000.00,0.00,5000.00,0.00,5000.00,0.00,NULL,NULL,NULL,0,'2026-09-06 21:32:17','2026-09-06 21:32:17'),(1122,280,7,921,'Premio día madre \"Hugo Ruiz\"',NULL,NULL,NULL,NULL,949,1.000000,1.000000,0.00,50000.00,0.00,50000.00,0.00,50000.00,NULL,NULL,NULL,0,'2026-09-06 21:32:17','2026-09-06 21:32:17'),(1123,280,8,1078,'Premio día madre \"Hugo Ruiz\"',NULL,NULL,NULL,NULL,949,1.000000,1.000000,50000.00,0.00,50000.00,0.00,50000.00,0.00,NULL,NULL,NULL,0,'2026-09-06 21:32:17','2026-09-06 21:32:17'),(1124,280,9,921,'Ajuste conciliación bancaria',NULL,NULL,NULL,NULL,949,1.000000,1.000000,0.33,0.00,0.33,0.00,0.33,0.00,NULL,NULL,NULL,0,'2026-09-06 21:32:17','2026-09-06 21:32:17'),(1125,280,10,1074,'Ajuste conciliación bancaria',NULL,NULL,NULL,NULL,949,1.000000,1.000000,0.00,0.33,0.00,0.33,0.00,0.33,NULL,NULL,NULL,0,'2026-09-06 21:32:17','2026-09-06 21:32:17'),(1126,281,1,921,'William Vargas premio tómbola',NULL,NULL,NULL,NULL,949,1.000000,1.000000,10000.00,0.00,10000.00,0.00,10000.00,0.00,'2026-08-31','22613374','2026-08-05',0,'2026-09-06 21:32:31','2026-09-06 21:32:31'),(1127,281,2,1087,'William Vargas premio tómbola',NULL,NULL,NULL,NULL,949,1.000000,1.000000,0.00,10000.00,0.00,10000.00,0.00,10000.00,'2026-08-31','22613374','2026-08-05',0,'2026-09-06 21:32:31','2026-09-06 21:32:31'),(1128,281,3,921,'Yessy Brenes premio Tómbola',NULL,NULL,NULL,NULL,949,1.000000,1.000000,10000.00,0.00,10000.00,0.00,10000.00,0.00,'2026-08-31','22613375','2026-08-05',0,'2026-09-06 21:32:31','2026-09-06 21:32:31'),(1129,281,4,1087,'Yessy Brenes premio Tómbola',NULL,NULL,NULL,NULL,949,1.000000,1.000000,0.00,10000.00,0.00,10000.00,0.00,10000.00,'2026-08-31','22613375','2026-08-05',0,'2026-09-06 21:32:31','2026-09-06 21:32:31'),(1130,281,5,921,'Franciny Segura premio tómbola',NULL,NULL,NULL,NULL,949,1.000000,1.000000,5000.00,0.00,5000.00,0.00,5000.00,0.00,'2026-08-31','22613376','2026-08-05',0,'2026-09-06 21:32:31','2026-09-06 21:32:31'),(1131,281,6,1087,'Franciny Segura premio tómbola',NULL,NULL,NULL,NULL,949,1.000000,1.000000,0.00,5000.00,0.00,5000.00,0.00,5000.00,'2026-08-31','22613376','2026-08-05',0,'2026-09-06 21:32:31','2026-09-06 21:32:31'),(1132,281,7,921,'Premio día madre \"Hugo Ruiz\"',NULL,NULL,NULL,NULL,949,1.000000,1.000000,50000.00,0.00,50000.00,0.00,50000.00,0.00,'2026-08-31','229654.73','2026-08-10',0,'2026-09-06 21:32:31','2026-09-06 21:32:31'),(1133,281,8,1078,'Premio día madre \"Hugo Ruiz\"',NULL,NULL,NULL,NULL,949,1.000000,1.000000,0.00,50000.00,0.00,50000.00,0.00,50000.00,'2026-08-31','22965473','2026-08-10',0,'2026-09-06 21:32:32','2026-09-06 21:32:32'),(1134,281,9,921,'Ajuste conciliación bancaria',NULL,NULL,NULL,NULL,949,1.000000,1.000000,0.00,0.33,0.00,0.33,0.00,0.33,'2026-08-31','082026','2026-08-31',0,'2026-09-06 21:32:32','2026-09-06 21:32:32'),(1135,281,10,1074,'Ajuste conciliación bancaria',NULL,NULL,NULL,NULL,949,1.000000,1.000000,0.33,0.00,0.33,0.00,0.33,0.00,'2026-08-31','08-2026','2026-08-31',0,'2026-09-06 21:32:32','2026-09-06 21:32:32'),(1136,282,1,983,NULL,NULL,NULL,NULL,NULL,949,1.000000,1.000000,12611.37,0.00,12611.37,0.00,12611.37,0.00,NULL,NULL,NULL,0,'2026-09-06 21:57:26','2026-09-06 21:57:26'),(1137,282,2,936,NULL,NULL,NULL,NULL,NULL,949,1.000000,1.000000,0.00,12611.37,0.00,12611.37,0.00,12611.37,NULL,NULL,NULL,0,'2026-09-06 21:57:26','2026-09-06 21:57:26'),(1138,283,1,977,'Vanessa Mendoza _ soporte contable agosto 2026_','50626082600070100026000100003010000000030173201449',90,NULL,NULL,949,1.000000,1.000000,0.00,113000.00,0.00,113000.00,0.00,113000.00,'2026-09-25','30','2026-08-26',0,'2026-09-09 01:34:19','2026-09-09 01:34:19'),(1139,283,2,919,'Vanessa Mendoza _ soporte contable agosto 2026_',NULL,NULL,NULL,NULL,949,1.000000,1.000000,113000.00,0.00,113000.00,0.00,113000.00,0.00,'2026-09-26','30','2026-08-26',0,'2026-09-09 01:34:19','2026-09-09 01:34:19'),(1158,284,1,980,'Aplicación cobros rifa dia madre anticipados',NULL,NULL,NULL,NULL,949,1.000000,1.000000,285000.00,0.00,285000.00,0.00,285000.00,0.00,'2026-08-31','082026',NULL,0,'2026-09-09 02:01:41','2026-09-09 02:01:41'),(1159,284,2,1086,'Aplicación cobros rifa dia madre anticipados',NULL,NULL,NULL,NULL,949,1.000000,1.000000,0.00,285000.00,0.00,285000.00,0.00,285000.00,'2026-08-31','082026',NULL,0,'2026-09-09 02:01:41','2026-09-09 02:01:41'),(1160,284,3,925,'BCCR intereses por inversiones',NULL,NULL,NULL,NULL,949,1.000000,1.000000,28000.00,0.00,28000.00,0.00,28000.00,0.00,'2026-08-31','082026',NULL,0,'2026-09-09 02:01:41','2026-09-09 02:01:41'),(1161,284,4,1078,'BCCR intereses por inversiones',NULL,NULL,NULL,NULL,949,1.000000,1.000000,0.00,28000.00,0.00,28000.00,0.00,28000.00,'2026-08-31','082026',NULL,0,'2026-09-09 02:01:41','2026-09-09 02:01:41'),(1162,284,5,930,'BNCR intereses CR...40617',NULL,NULL,NULL,NULL,949,1.000000,1.000000,81160.91,0.00,81160.91,0.00,81160.91,0.00,'2026-08-31','082026',NULL,0,'2026-09-09 02:01:41','2026-09-09 02:01:41'),(1163,284,6,1076,'BNCR intereses CR...40617',NULL,NULL,NULL,NULL,949,1.000000,1.000000,0.00,81160.91,0.00,81160.91,0.00,81160.91,'2026-08-31','082026',NULL,0,'2026-09-09 02:01:41','2026-09-09 02:01:41'),(1164,284,7,930,'BNCR intereses CR...41870',NULL,NULL,NULL,NULL,949,1.000000,1.000000,129965.00,0.00,129965.00,0.00,129965.00,0.00,'2026-08-31','082026',NULL,0,'2026-09-09 02:01:41','2026-09-09 02:01:41'),(1165,284,8,1076,'BNCR intereses CR...41870',NULL,NULL,NULL,NULL,949,1.000000,1.000000,0.00,129965.00,0.00,129965.00,0.00,129965.00,'2026-08-31','082026',NULL,0,'2026-09-09 02:01:41','2026-09-09 02:01:41'),(1166,284,9,1107,'Intereses por captación ahorros agosto 2026',NULL,NULL,NULL,NULL,949,1.000000,1.000000,19439.45,0.00,19439.45,0.00,19439.45,0.00,'2026-08-31','082026',NULL,0,'2026-09-09 02:01:41','2026-09-09 02:01:41'),(1167,284,10,1108,'Intereses por captación ahorros agosto 2026',NULL,NULL,NULL,NULL,949,1.000000,1.000000,77.20,0.00,77.20,0.00,77.20,0.00,'2026-08-31','082026',NULL,0,'2026-09-09 02:01:41','2026-09-09 02:01:41'),(1168,284,11,1007,'Intereses por captación ahorros agosto 2026',NULL,NULL,NULL,NULL,949,1.000000,1.000000,0.00,19439.45,0.00,19439.45,0.00,19439.45,'2026-08-31','082026',NULL,0,'2026-09-09 02:01:41','2026-09-09 02:01:41'),(1169,284,12,1008,'Intereses por captación ahorros agosto 2026',NULL,NULL,NULL,NULL,949,1.000000,1.000000,0.00,77.20,0.00,77.20,0.00,77.20,'2026-08-31','082026',NULL,0,'2026-09-09 02:01:41','2026-09-09 02:01:41'),(1170,284,13,1103,'dep. acumulada agosto 2026',NULL,NULL,NULL,NULL,949,1.000000,1.000000,4253.33,0.00,4253.33,0.00,4253.33,0.00,'2026-08-31','082026',NULL,0,'2026-09-09 02:01:41','2026-09-09 02:01:41'),(1171,284,14,1102,'dep. acumulada agosto 2026',NULL,NULL,NULL,NULL,949,1.000000,1.000000,1710.00,0.00,1710.00,0.00,1710.00,0.00,'2026-08-31','082026',NULL,0,'2026-09-09 02:01:41','2026-09-09 02:01:41'),(1172,284,15,972,'dep. acumulada agosto 2026',NULL,NULL,NULL,NULL,949,1.000000,1.000000,0.00,4253.33,0.00,4253.33,0.00,4253.33,'2026-08-31','082026',NULL,0,'2026-09-09 02:01:41','2026-09-09 02:01:41'),(1173,284,16,969,'dep. acumulada agosto 2026',NULL,NULL,NULL,NULL,949,1.000000,1.000000,0.00,1710.00,0.00,1710.00,0.00,1710.00,'2026-08-31','082026',NULL,0,'2026-09-09 02:01:41','2026-09-09 02:01:41'),(1174,284,17,1087,'Premio Hugo R. rifa día madre',NULL,NULL,NULL,NULL,949,1.000000,1.000000,50000.00,0.00,50000.00,0.00,50000.00,0.00,'2026-08-31','082026',NULL,0,'2026-09-09 02:01:41','2026-09-09 02:01:41'),(1175,284,18,1078,'Premio Hugo R. rifa día madre',NULL,NULL,NULL,NULL,949,1.000000,1.000000,0.00,50000.00,0.00,50000.00,0.00,50000.00,'2026-08-31','082026',NULL,0,'2026-09-09 02:01:41','2026-09-09 02:01:41'),(1176,285,1,929,'BN Fondos rendimiento agosto 2026',NULL,NULL,NULL,NULL,949,1.000000,1.000000,66861.40,0.00,66861.40,0.00,66861.40,0.00,'2026-08-31','082026',NULL,0,'2026-09-09 02:14:45','2026-09-09 02:14:45'),(1177,285,2,1075,'BN Fondos rendimiento agosto 2026',NULL,NULL,NULL,NULL,949,1.000000,1.000000,0.00,66861.40,0.00,66861.40,0.00,66861.40,'2026-08-31','082026',NULL,0,'2026-09-09 02:14:45','2026-09-09 02:14:45'),(1178,286,1,919,NULL,NULL,NULL,NULL,NULL,949,1.000000,1.000000,0.00,113000.00,0.00,113000.00,0.00,113000.00,NULL,NULL,NULL,0,'2026-09-09 02:26:30','2026-09-09 02:26:30'),(1179,286,2,1096,NULL,NULL,NULL,NULL,NULL,949,1.000000,1.000000,113000.00,0.00,113000.00,0.00,113000.00,0.00,NULL,NULL,NULL,0,'2026-09-09 02:26:30','2026-09-09 02:26:30'),(1180,287,1,947,NULL,NULL,NULL,NULL,NULL,949,1.000000,1.000000,44791.11,0.00,44791.11,0.00,44791.11,0.00,NULL,NULL,NULL,0,'2026-09-09 02:59:27','2026-09-09 02:59:27'),(1181,287,2,962,NULL,NULL,NULL,NULL,NULL,949,1.000000,1.000000,0.00,44791.11,0.00,44791.11,0.00,44791.11,NULL,NULL,NULL,0,'2026-09-09 02:59:27','2026-09-09 02:59:27'),(1182,288,1,947,NULL,NULL,NULL,NULL,NULL,949,1.000000,1.000000,0.00,44791.11,0.00,44791.11,0.00,44791.11,NULL,NULL,NULL,0,'2026-09-09 03:05:18','2026-09-09 03:05:18'),(1183,288,2,962,NULL,NULL,NULL,NULL,NULL,949,1.000000,1.000000,44791.11,0.00,44791.11,0.00,44791.11,0.00,NULL,NULL,NULL,0,'2026-09-09 03:05:18','2026-09-09 03:05:18'),(1184,289,1,947,NULL,NULL,NULL,NULL,NULL,949,1.000000,1.000000,31670.00,0.00,31670.00,0.00,31670.00,0.00,NULL,NULL,NULL,0,'2026-09-09 03:05:18','2026-09-09 03:05:18'),(1185,289,2,962,NULL,NULL,NULL,NULL,NULL,949,1.000000,1.000000,0.00,31670.00,0.00,31670.00,0.00,31670.00,NULL,NULL,NULL,0,'2026-09-09 03:05:18','2026-09-09 03:05:18'),(1186,290,1,1115,NULL,NULL,93,NULL,NULL,949,520.000000,1.000000,200.00,0.00,0.38,0.00,0.38,0.00,'2026-02-15',NULL,NULL,0,'2026-09-10 21:11:17','2026-09-10 21:11:17'),(1187,290,2,1116,NULL,NULL,NULL,NULL,NULL,949,520.000000,1.000000,0.00,200.00,0.00,0.38,0.00,0.38,NULL,NULL,NULL,0,'2026-09-10 21:11:17','2026-09-10 21:11:17'),(1264,316,1,1125,'Depósito de los socios',NULL,NULL,NULL,NULL,949,505.250000,1.000000,25000000.00,0.00,49480.46,0.00,49480.46,0.00,NULL,NULL,NULL,0,'2026-09-18 03:45:25','2026-09-18 03:45:25'),(1265,316,2,1239,'Capital social suscrito y pagado',NULL,NULL,NULL,NULL,949,505.250000,1.000000,0.00,25000000.00,0.00,49480.46,0.00,49480.46,NULL,NULL,NULL,0,'2026-09-18 03:45:25','2026-09-18 03:45:25'),(1266,317,1,1175,'Tres estaciones de trabajo',NULL,NULL,NULL,NULL,949,505.250000,1.000000,3000000.00,0.00,5937.65,0.00,5937.65,0.00,NULL,NULL,NULL,0,'2026-09-18 03:45:25','2026-09-18 03:45:25'),(1267,317,2,1154,'IVA soportado 13%',NULL,NULL,NULL,NULL,949,505.250000,1.000000,390000.00,0.00,771.90,0.00,771.90,0.00,NULL,NULL,NULL,0,'2026-09-18 03:45:25','2026-09-18 03:45:25'),(1268,317,3,1196,'Suministros Industriales del Sur',NULL,100,NULL,NULL,949,505.250000,1.000000,0.00,3390000.00,0.00,6709.55,0.00,6709.55,'2026-02-19',NULL,NULL,0,'2026-09-18 03:45:25','2026-09-18 03:45:25'),(1269,318,1,1147,'Mercadería para la venta',NULL,NULL,NULL,NULL,949,508.400000,1.000000,8000000.00,0.00,15735.64,0.00,15735.64,0.00,NULL,NULL,NULL,0,'2026-09-18 03:45:25','2026-09-18 03:45:25'),(1270,318,2,1154,'IVA soportado 13%',NULL,NULL,NULL,NULL,949,508.400000,1.000000,1040000.00,0.00,2045.63,0.00,2045.63,0.00,NULL,NULL,NULL,0,'2026-09-18 03:45:25','2026-09-18 03:45:25'),(1271,318,3,1196,'Suministros Industriales del Sur',NULL,100,NULL,NULL,949,508.400000,1.000000,0.00,9040000.00,0.00,17781.27,0.00,17781.27,'2026-03-12',NULL,NULL,0,'2026-09-18 03:45:25','2026-09-18 03:45:25'),(1272,319,1,1136,'Distribuidora La Central S.A.',NULL,94,NULL,NULL,949,511.750000,1.000000,5650000.00,0.00,11040.55,0.00,11040.55,0.00,'2026-04-04',NULL,NULL,0,'2026-09-18 03:45:25','2026-09-18 03:45:25'),(1273,319,2,1257,'Venta de mercadería gravada 13%',NULL,NULL,46,21,949,511.750000,1.000000,0.00,5000000.00,0.00,9770.40,0.00,9770.40,NULL,NULL,NULL,0,'2026-09-18 03:45:25','2026-09-18 03:45:25'),(1274,319,3,1204,'IVA devengado 13%',NULL,NULL,NULL,NULL,949,511.750000,1.000000,0.00,650000.00,0.00,1270.15,0.00,1270.15,NULL,NULL,NULL,0,'2026-09-18 03:45:25','2026-09-18 03:45:25'),(1275,320,1,1274,'Costo de la venta de marzo',NULL,NULL,46,21,949,511.750000,1.000000,3200000.00,0.00,6253.05,0.00,6253.05,0.00,NULL,NULL,NULL,0,'2026-09-18 03:45:25','2026-09-18 03:45:25'),(1276,320,2,1147,'Salida de inventario',NULL,NULL,NULL,NULL,949,511.750000,1.000000,0.00,3200000.00,0.00,6253.05,0.00,6253.05,NULL,NULL,NULL,0,'2026-09-18 03:45:25','2026-09-18 03:45:25'),(1277,321,1,1125,'Transferencia recibida',NULL,NULL,NULL,NULL,949,509.900000,1.000000,5650000.00,0.00,11080.60,0.00,11080.60,0.00,NULL,NULL,NULL,0,'2026-09-18 03:45:25','2026-09-18 03:45:25'),(1278,321,2,1136,'Cancelación total de la factura',NULL,94,NULL,NULL,949,509.900000,1.000000,0.00,5650000.00,0.00,11080.60,0.00,11080.60,NULL,NULL,NULL,0,'2026-09-18 03:45:25','2026-09-18 03:45:25'),(1279,322,1,1285,'Salarios de abril',NULL,NULL,45,23,949,509.900000,1.000000,1600000.00,0.00,3137.87,0.00,3137.87,0.00,NULL,NULL,NULL,0,'2026-09-18 03:45:25','2026-09-18 03:45:25'),(1280,322,2,1285,'Salarios de abril',NULL,NULL,46,23,949,509.900000,1.000000,1200000.00,0.00,2353.40,0.00,2353.40,0.00,NULL,NULL,NULL,0,'2026-09-18 03:45:25','2026-09-18 03:45:25'),(1281,322,3,1285,'Salarios de abril',NULL,NULL,47,23,949,509.900000,1.000000,1200000.00,0.00,2353.41,0.00,2353.41,0.00,NULL,NULL,NULL,0,'2026-09-18 03:45:25','2026-09-18 03:45:25'),(1282,322,4,1288,'Cargas sociales patronales',NULL,NULL,45,23,949,509.900000,1.000000,424000.00,0.00,831.53,0.00,831.53,0.00,NULL,NULL,NULL,0,'2026-09-18 03:45:25','2026-09-18 03:45:25'),(1283,322,5,1288,'Cargas sociales patronales',NULL,NULL,46,23,949,509.900000,1.000000,318000.00,0.00,623.65,0.00,623.65,0.00,NULL,NULL,NULL,0,'2026-09-18 03:45:25','2026-09-18 03:45:25'),(1284,322,6,1288,'Cargas sociales patronales',NULL,NULL,47,23,949,509.900000,1.000000,318000.00,0.00,623.66,0.00,623.66,0.00,NULL,NULL,NULL,0,'2026-09-18 03:45:25','2026-09-18 03:45:25'),(1285,322,7,1200,'Salarios netos por pagar',NULL,NULL,NULL,NULL,949,509.900000,1.000000,0.00,3650000.00,0.00,7158.27,0.00,7158.27,NULL,NULL,NULL,0,'2026-09-18 03:45:25','2026-09-18 03:45:25'),(1286,322,8,1218,'CCSS obrera y patronal por pagar',NULL,NULL,NULL,NULL,949,509.900000,1.000000,0.00,1410000.00,0.00,2765.25,0.00,2765.25,NULL,NULL,NULL,0,'2026-09-18 03:45:25','2026-09-18 03:45:25'),(1287,323,1,1293,'Alquiler de oficinas',NULL,NULL,45,20,949,513.300000,1.000000,800000.00,0.00,1558.54,0.00,1558.54,0.00,NULL,NULL,NULL,0,'2026-09-18 03:45:25','2026-09-18 03:45:25'),(1288,323,2,1294,'Agua, luz y teléfono',NULL,NULL,45,20,949,513.300000,1.000000,250000.00,0.00,487.04,0.00,487.04,0.00,NULL,NULL,NULL,0,'2026-09-18 03:45:25','2026-09-18 03:45:25'),(1289,323,3,1154,'IVA soportado 13%',NULL,NULL,NULL,NULL,949,513.300000,1.000000,136500.00,0.00,265.93,0.00,265.93,0.00,NULL,NULL,NULL,0,'2026-09-18 03:45:25','2026-09-18 03:45:25'),(1290,323,4,1196,'Transportes Rápidos Mora',NULL,101,NULL,NULL,949,513.300000,1.000000,0.00,1186500.00,0.00,2311.51,0.00,2311.51,'2026-06-14',NULL,NULL,0,'2026-09-18 03:45:25','2026-09-18 03:45:25'),(1291,324,1,1136,'Comercial El Roble S.A.',NULL,95,NULL,NULL,949,516.800000,1.000000,9040000.00,0.00,17492.26,0.00,17492.26,0.00,'2026-07-18',NULL,NULL,0,'2026-09-18 03:45:25','2026-09-18 03:45:25'),(1292,324,2,1257,'Venta de mercadería gravada 13%',NULL,NULL,46,21,949,516.800000,1.000000,0.00,8000000.00,0.00,15479.88,0.00,15479.88,NULL,NULL,NULL,0,'2026-09-18 03:45:25','2026-09-18 03:45:25'),(1293,324,3,1204,'IVA devengado 13%',NULL,NULL,NULL,NULL,949,516.800000,1.000000,0.00,1040000.00,0.00,2012.38,0.00,2012.38,NULL,NULL,NULL,0,'2026-09-18 03:45:25','2026-09-18 03:45:25'),(1294,325,1,1313,'Depreciación de equipo de cómputo',NULL,NULL,45,20,949,516.800000,1.000000,300000.00,0.00,580.50,0.00,580.50,0.00,NULL,NULL,NULL,0,'2026-09-18 03:45:25','2026-09-18 03:45:25'),(1295,325,2,1182,'Depreciación acumulada de equipo de cómputo',NULL,NULL,NULL,NULL,949,516.800000,1.000000,0.00,300000.00,0.00,580.50,0.00,580.50,NULL,NULL,NULL,0,'2026-09-18 03:45:25','2026-09-18 03:45:25'),(1296,326,1,1137,'Northbridge Trading LLC',NULL,98,NULL,NULL,950,518.600000,1.000000,6223200.00,0.00,12000.00,0.00,12000.00,0.00,'2026-09-26',NULL,NULL,0,'2026-09-18 03:45:25','2026-09-18 03:45:25'),(1297,326,2,1261,'Venta de exportación (exenta)',NULL,NULL,46,21,950,518.600000,1.000000,0.00,6223200.00,0.00,12000.00,0.00,12000.00,NULL,NULL,NULL,0,'2026-09-18 03:45:25','2026-09-18 03:45:25'),(1298,327,1,1299,'Asesoría contable y fiscal',NULL,NULL,45,20,949,521.050000,1.000000,1450000.00,0.00,2782.84,0.00,2782.84,0.00,NULL,NULL,NULL,0,'2026-09-18 03:45:26','2026-09-18 03:45:26'),(1299,327,2,1154,'IVA soportado 13%',NULL,NULL,NULL,NULL,949,521.050000,1.000000,188500.00,0.00,361.77,0.00,361.77,0.00,NULL,NULL,NULL,0,'2026-09-18 03:45:26','2026-09-18 03:45:26'),(1300,327,3,1196,'Consultores Asociados Quirós y Cía.',NULL,102,NULL,NULL,949,521.050000,1.000000,0.00,1638500.00,0.00,3144.61,0.00,3144.61,'2026-10-05',NULL,NULL,0,'2026-09-18 03:45:26','2026-09-18 03:45:26'),(1301,328,1,1196,'Cancelación total al proveedor',NULL,100,NULL,NULL,949,521.050000,1.000000,3390000.00,0.00,6506.09,0.00,6506.09,0.00,NULL,NULL,NULL,0,'2026-09-18 03:45:26','2026-09-18 03:45:26'),(1302,328,2,1125,'Transferencia enviada',NULL,NULL,NULL,NULL,949,521.050000,1.000000,0.00,3390000.00,0.00,6506.09,0.00,6506.09,NULL,NULL,NULL,0,'2026-09-18 03:45:26','2026-09-18 03:45:26'),(1345,332,1,1147,'Saldo inicial de ART-0001',NULL,NULL,NULL,NULL,949,505.250000,1.000000,4500000.00,0.00,8906.48,0.00,8906.48,0.00,NULL,NULL,NULL,0,'2026-09-18 03:56:36','2026-09-18 03:56:36'),(1346,332,2,1361,'Saldo inicial de ART-0001',NULL,NULL,NULL,NULL,949,505.250000,1.000000,0.00,4500000.00,0.00,8906.48,0.00,8906.48,NULL,NULL,NULL,0,'2026-09-18 03:56:36','2026-09-18 03:56:36'),(1347,332,3,1147,'Saldo inicial de ART-0002',NULL,NULL,NULL,NULL,949,505.250000,1.000000,1425000.00,0.00,2820.39,0.00,2820.39,0.00,NULL,NULL,NULL,0,'2026-09-18 03:56:36','2026-09-18 03:56:36'),(1348,332,4,1361,'Saldo inicial de ART-0002',NULL,NULL,NULL,NULL,949,505.250000,1.000000,0.00,1425000.00,0.00,2820.39,0.00,2820.39,NULL,NULL,NULL,0,'2026-09-18 03:56:36','2026-09-18 03:56:36'),(1349,332,5,1147,'Saldo inicial de ART-0003',NULL,NULL,NULL,NULL,949,505.250000,1.000000,480000.00,0.00,950.02,0.00,950.02,0.00,NULL,NULL,NULL,0,'2026-09-18 03:56:36','2026-09-18 03:56:36'),(1350,332,6,1361,'Saldo inicial de ART-0003',NULL,NULL,NULL,NULL,949,505.250000,1.000000,0.00,480000.00,0.00,950.02,0.00,950.02,NULL,NULL,NULL,0,'2026-09-18 03:56:36','2026-09-18 03:56:36'),(1351,332,7,1147,'Saldo inicial de ART-0004',NULL,NULL,NULL,NULL,949,505.250000,1.000000,325000.00,0.00,643.25,0.00,643.25,0.00,NULL,NULL,NULL,0,'2026-09-18 03:56:36','2026-09-18 03:56:36'),(1352,332,8,1361,'Saldo inicial de ART-0004',NULL,NULL,NULL,NULL,949,505.250000,1.000000,0.00,325000.00,0.00,643.25,0.00,643.25,NULL,NULL,NULL,0,'2026-09-18 03:56:36','2026-09-18 03:56:36'),(1353,332,9,1147,'Saldo inicial de ART-0005',NULL,NULL,NULL,NULL,949,505.250000,1.000000,1120000.00,0.00,2216.72,0.00,2216.72,0.00,NULL,NULL,NULL,0,'2026-09-18 03:56:36','2026-09-18 03:56:36'),(1354,332,10,1361,'Saldo inicial de ART-0005',NULL,NULL,NULL,NULL,949,505.250000,1.000000,0.00,1120000.00,0.00,2216.72,0.00,2216.72,NULL,NULL,NULL,0,'2026-09-18 03:56:36','2026-09-18 03:56:36'),(1355,332,11,1147,'Saldo inicial de ART-0006',NULL,NULL,NULL,NULL,949,505.250000,1.000000,210000.00,0.00,415.64,0.00,415.64,0.00,NULL,NULL,NULL,0,'2026-09-18 03:56:36','2026-09-18 03:56:36'),(1356,332,12,1361,'Saldo inicial de ART-0006',NULL,NULL,NULL,NULL,949,505.250000,1.000000,0.00,210000.00,0.00,415.64,0.00,415.64,NULL,NULL,NULL,0,'2026-09-18 03:56:36','2026-09-18 03:56:36'),(1357,332,13,1147,'Saldo inicial de ART-0007',NULL,NULL,NULL,NULL,949,505.250000,1.000000,760000.00,0.00,1504.21,0.00,1504.21,0.00,NULL,NULL,NULL,0,'2026-09-18 03:56:36','2026-09-18 03:56:36'),(1358,332,14,1361,'Saldo inicial de ART-0007',NULL,NULL,NULL,NULL,949,505.250000,1.000000,0.00,760000.00,0.00,1504.21,0.00,1504.21,NULL,NULL,NULL,0,'2026-09-18 03:56:36','2026-09-18 03:56:36'),(1359,332,15,1147,'Saldo inicial de ART-0008',NULL,NULL,NULL,NULL,949,505.250000,1.000000,1020000.00,0.00,2018.80,0.00,2018.80,0.00,NULL,NULL,NULL,0,'2026-09-18 03:56:36','2026-09-18 03:56:36'),(1360,332,16,1361,'Saldo inicial de ART-0008',NULL,NULL,NULL,NULL,949,505.250000,1.000000,0.00,1020000.00,0.00,2018.80,0.00,2018.80,NULL,NULL,NULL,0,'2026-09-18 03:56:36','2026-09-18 03:56:36'),(1361,332,17,1147,'Saldo inicial de MAT-0001',NULL,NULL,NULL,NULL,949,505.250000,1.000000,900000.00,0.00,1781.30,0.00,1781.30,0.00,NULL,NULL,NULL,0,'2026-09-18 03:56:36','2026-09-18 03:56:36'),(1362,332,18,1361,'Saldo inicial de MAT-0001',NULL,NULL,NULL,NULL,949,505.250000,1.000000,0.00,900000.00,0.00,1781.30,0.00,1781.30,NULL,NULL,NULL,0,'2026-09-18 03:56:36','2026-09-18 03:56:36'),(1363,332,19,1147,'Saldo inicial de MAT-0002',NULL,NULL,NULL,NULL,949,505.250000,1.000000,504000.00,0.00,997.53,0.00,997.53,0.00,NULL,NULL,NULL,0,'2026-09-18 03:56:36','2026-09-18 03:56:36'),(1364,332,20,1361,'Saldo inicial de MAT-0002',NULL,NULL,NULL,NULL,949,505.250000,1.000000,0.00,504000.00,0.00,997.53,0.00,997.53,NULL,NULL,NULL,0,'2026-09-18 03:56:36','2026-09-18 03:56:36'),(1365,332,21,1147,'Saldo inicial de MAT-0003',NULL,NULL,NULL,NULL,949,505.250000,1.000000,237500.00,0.00,470.06,0.00,470.06,0.00,NULL,NULL,NULL,0,'2026-09-18 03:56:36','2026-09-18 03:56:36'),(1366,332,22,1361,'Saldo inicial de MAT-0003',NULL,NULL,NULL,NULL,949,505.250000,1.000000,0.00,237500.00,0.00,470.06,0.00,470.06,NULL,NULL,NULL,0,'2026-09-18 03:56:36','2026-09-18 03:56:36'),(1367,332,23,1147,'Saldo inicial de PTE-0001',NULL,NULL,NULL,NULL,949,505.250000,1.000000,1260000.00,0.00,2493.81,0.00,2493.81,0.00,NULL,NULL,NULL,0,'2026-09-18 03:56:36','2026-09-18 03:56:36'),(1368,332,24,1361,'Saldo inicial de PTE-0001',NULL,NULL,NULL,NULL,949,505.250000,1.000000,0.00,1260000.00,0.00,2493.81,0.00,2493.81,NULL,NULL,NULL,0,'2026-09-18 03:56:36','2026-09-18 03:56:36'),(1369,332,25,1147,'Saldo inicial de PTE-0002',NULL,NULL,NULL,NULL,949,505.250000,1.000000,1860000.00,0.00,3681.35,0.00,3681.35,0.00,NULL,NULL,NULL,0,'2026-09-18 03:56:36','2026-09-18 03:56:36'),(1370,332,26,1361,'Saldo inicial de PTE-0002',NULL,NULL,NULL,NULL,949,505.250000,1.000000,0.00,1860000.00,0.00,3681.35,0.00,3681.35,NULL,NULL,NULL,0,'2026-09-18 03:56:36','2026-09-18 03:56:36'),(1371,332,27,1150,'Saldo inicial de SUM-0001',NULL,NULL,NULL,NULL,949,505.250000,1.000000,540000.00,0.00,1068.78,0.00,1068.78,0.00,NULL,NULL,NULL,0,'2026-09-18 03:56:36','2026-09-18 03:56:36'),(1372,332,28,1361,'Saldo inicial de SUM-0001',NULL,NULL,NULL,NULL,949,505.250000,1.000000,0.00,540000.00,0.00,1068.78,0.00,1068.78,NULL,NULL,NULL,0,'2026-09-18 03:56:36','2026-09-18 03:56:36'),(1373,332,29,1150,'Saldo inicial de SUM-0002',NULL,NULL,NULL,NULL,949,505.250000,1.000000,308000.00,0.00,609.60,0.00,609.60,0.00,NULL,NULL,NULL,0,'2026-09-18 03:56:36','2026-09-18 03:56:36'),(1374,332,30,1361,'Saldo inicial de SUM-0002',NULL,NULL,NULL,NULL,949,505.250000,1.000000,0.00,308000.00,0.00,609.60,0.00,609.60,NULL,NULL,NULL,0,'2026-09-18 03:56:36','2026-09-18 03:56:36'),(1375,332,31,1150,'Saldo inicial de SUM-0003',NULL,NULL,NULL,NULL,949,505.250000,1.000000,156000.00,0.00,308.76,0.00,308.76,0.00,NULL,NULL,NULL,0,'2026-09-18 03:56:36','2026-09-18 03:56:36'),(1376,332,32,1361,'Saldo inicial de SUM-0003',NULL,NULL,NULL,NULL,949,505.250000,1.000000,0.00,156000.00,0.00,308.76,0.00,308.76,NULL,NULL,NULL,0,'2026-09-18 03:56:36','2026-09-18 03:56:36'),(1377,333,1,1148,'Saldo inicial de ART-0001',NULL,NULL,NULL,NULL,949,505.250000,1.000000,2250000.00,0.00,4453.24,0.00,4453.24,0.00,NULL,NULL,NULL,0,'2026-09-18 03:56:36','2026-09-18 03:56:36'),(1378,333,2,1361,'Saldo inicial de ART-0001',NULL,NULL,NULL,NULL,949,505.250000,1.000000,0.00,2250000.00,0.00,4453.24,0.00,4453.24,NULL,NULL,NULL,0,'2026-09-18 03:56:36','2026-09-18 03:56:36'),(1379,333,3,1148,'Saldo inicial de ART-0005',NULL,NULL,NULL,NULL,949,505.250000,1.000000,420000.00,0.00,831.27,0.00,831.27,0.00,NULL,NULL,NULL,0,'2026-09-18 03:56:36','2026-09-18 03:56:36'),(1380,333,4,1361,'Saldo inicial de ART-0005',NULL,NULL,NULL,NULL,949,505.250000,1.000000,0.00,420000.00,0.00,831.27,0.00,831.27,NULL,NULL,NULL,0,'2026-09-18 03:56:36','2026-09-18 03:56:36'),(1381,334,1,1147,'Saldo inicial de ART-0003',NULL,NULL,NULL,NULL,949,505.250000,1.000000,240000.00,0.00,475.01,0.00,475.01,0.00,NULL,NULL,NULL,0,'2026-09-18 03:56:36','2026-09-18 03:56:36'),(1382,334,2,1361,'Saldo inicial de ART-0003',NULL,NULL,NULL,NULL,949,505.250000,1.000000,0.00,240000.00,0.00,475.01,0.00,475.01,NULL,NULL,NULL,0,'2026-09-18 03:56:36','2026-09-18 03:56:36'),(1383,334,3,1147,'Saldo inicial de ART-0004',NULL,NULL,NULL,NULL,949,505.250000,1.000000,162500.00,0.00,321.62,0.00,321.62,0.00,NULL,NULL,NULL,0,'2026-09-18 03:56:36','2026-09-18 03:56:36'),(1384,334,4,1361,'Saldo inicial de ART-0004',NULL,NULL,NULL,NULL,949,505.250000,1.000000,0.00,162500.00,0.00,321.62,0.00,321.62,NULL,NULL,NULL,0,'2026-09-18 03:56:36','2026-09-18 03:56:36'),(1385,334,5,1147,'Saldo inicial de ART-0006',NULL,NULL,NULL,NULL,949,505.250000,1.000000,105000.00,0.00,207.82,0.00,207.82,0.00,NULL,NULL,NULL,0,'2026-09-18 03:56:36','2026-09-18 03:56:36'),(1386,334,6,1361,'Saldo inicial de ART-0006',NULL,NULL,NULL,NULL,949,505.250000,1.000000,0.00,105000.00,0.00,207.82,0.00,207.82,NULL,NULL,NULL,0,'2026-09-18 03:56:36','2026-09-18 03:56:36'),(1406,340,1,1147,'Mercadería para la venta',NULL,NULL,NULL,NULL,949,508.400000,1.000000,0.00,8000000.00,0.00,15735.64,0.00,15735.64,NULL,NULL,NULL,0,'2026-09-18 04:02:41','2026-09-18 04:02:41'),(1407,340,2,1154,'IVA soportado 13%',NULL,NULL,NULL,NULL,949,508.400000,1.000000,0.00,1040000.00,0.00,2045.63,0.00,2045.63,NULL,NULL,NULL,0,'2026-09-18 04:02:41','2026-09-18 04:02:41'),(1408,340,3,1196,'Suministros Industriales del Sur',NULL,100,NULL,NULL,949,508.400000,1.000000,9040000.00,0.00,17781.27,0.00,17781.27,0.00,'2026-03-12',NULL,NULL,0,'2026-09-18 04:02:41','2026-09-18 04:02:41'),(1409,341,1,1274,'Costo de la venta de marzo',NULL,NULL,46,21,949,511.750000,1.000000,0.00,3200000.00,0.00,6253.05,0.00,6253.05,NULL,NULL,NULL,0,'2026-09-18 04:02:41','2026-09-18 04:02:41'),(1410,341,2,1147,'Salida de inventario',NULL,NULL,NULL,NULL,949,511.750000,1.000000,3200000.00,0.00,6253.05,0.00,6253.05,0.00,NULL,NULL,NULL,0,'2026-09-18 04:02:41','2026-09-18 04:02:41'),(1411,342,1,1147,'Compra de ART-0001',NULL,NULL,NULL,NULL,949,508.400000,1.000000,4500000.00,0.00,8851.30,0.00,8851.30,0.00,NULL,NULL,NULL,0,'2026-09-18 04:02:42','2026-09-18 04:02:42'),(1412,342,2,1358,'Compra de ART-0001',NULL,NULL,NULL,NULL,949,508.400000,1.000000,0.00,4500000.00,0.00,8851.30,0.00,8851.30,NULL,NULL,NULL,0,'2026-09-18 04:02:42','2026-09-18 04:02:42'),(1413,342,3,1147,'Compra de ART-0005',NULL,NULL,NULL,NULL,949,508.400000,1.000000,3500000.00,0.00,6884.34,0.00,6884.34,0.00,NULL,NULL,NULL,0,'2026-09-18 04:02:42','2026-09-18 04:02:42'),(1414,342,4,1358,'Compra de ART-0005',NULL,NULL,NULL,NULL,949,508.400000,1.000000,0.00,3500000.00,0.00,6884.34,0.00,6884.34,NULL,NULL,NULL,0,'2026-09-18 04:02:42','2026-09-18 04:02:42'),(1415,343,1,1358,'Compra de ART-0001',NULL,NULL,NULL,NULL,949,508.400000,1.000000,4500000.00,0.00,8851.30,0.00,8851.30,0.00,'2026-03-12',NULL,NULL,0,'2026-09-18 04:02:42','2026-09-18 04:02:42'),(1416,343,2,1358,'Compra de ART-0005',NULL,NULL,NULL,NULL,949,508.400000,1.000000,3500000.00,0.00,6884.34,0.00,6884.34,0.00,'2026-03-12',NULL,NULL,0,'2026-09-18 04:02:42','2026-09-18 04:02:42'),(1417,343,3,1154,'IVA 13.00%',NULL,NULL,NULL,NULL,949,508.400000,1.000000,1040000.00,0.00,2045.63,0.00,2045.63,0.00,'2026-03-12',NULL,NULL,0,'2026-09-18 04:02:42','2026-09-18 04:02:42'),(1418,343,4,1196,'[PRUEBA] Factura de compra de mercadería',NULL,100,NULL,NULL,949,508.400000,1.000000,0.00,9040000.00,0.00,17781.27,0.00,17781.27,'2026-03-12',NULL,NULL,0,'2026-09-18 04:02:42','2026-09-18 04:02:42'),(1419,344,1,1147,'Costo de venta de ART-0001',NULL,NULL,NULL,NULL,949,506.505304,1.000000,0.00,2700000.00,0.00,5330.65,0.00,5330.65,NULL,NULL,NULL,0,'2026-09-18 04:02:42','2026-09-18 04:02:42'),(1420,344,2,1274,'Costo de venta de ART-0001',NULL,NULL,46,21,949,506.505304,1.000000,2700000.00,0.00,5330.65,0.00,5330.65,0.00,NULL,NULL,NULL,0,'2026-09-18 04:02:42','2026-09-18 04:02:42'),(1421,344,3,1147,'Costo de venta de ART-0002',NULL,NULL,NULL,NULL,949,505.250002,1.000000,0.00,380000.00,0.00,752.10,0.00,752.10,NULL,NULL,NULL,0,'2026-09-18 04:02:42','2026-09-18 04:02:42'),(1422,344,4,1274,'Costo de venta de ART-0002',NULL,NULL,46,21,949,505.250002,1.000000,380000.00,0.00,752.10,0.00,752.10,0.00,NULL,NULL,NULL,0,'2026-09-18 04:02:42','2026-09-18 04:02:42'),(1423,344,5,1147,'Costo de venta de ART-0003',NULL,NULL,NULL,NULL,949,505.250010,1.000000,0.00,120000.00,0.00,237.51,0.00,237.51,NULL,NULL,NULL,0,'2026-09-18 04:02:42','2026-09-18 04:02:42'),(1424,344,6,1274,'Costo de venta de ART-0003',NULL,NULL,46,21,949,505.250010,1.000000,120000.00,0.00,237.51,0.00,237.51,0.00,NULL,NULL,NULL,0,'2026-09-18 04:02:42','2026-09-18 04:02:42'),(1503,360,1,1361,'Saca la apertura de resultados',NULL,NULL,NULL,NULL,949,505.250000,1.000000,18783000.00,0.00,37175.66,0.00,37175.66,0.00,NULL,NULL,NULL,0,'2026-09-18 04:07:24','2026-09-18 04:07:24'),(1504,360,2,1368,'Contrapartida patrimonial de la apertura',NULL,NULL,NULL,NULL,949,505.250000,1.000000,0.00,18783000.00,0.00,37175.66,0.00,37175.66,NULL,NULL,NULL,0,'2026-09-18 04:07:24','2026-09-18 04:07:24'),(1505,361,1,1148,'ART-0003: ALM01 → ALM02',NULL,NULL,NULL,NULL,949,505.250010,1.000000,12000.00,0.00,23.75,0.00,23.75,0.00,NULL,NULL,NULL,0,'2026-09-20 01:55:19','2026-09-20 01:55:19'),(1506,361,2,1147,'ART-0003: ALM01 → ALM02',NULL,NULL,NULL,NULL,949,505.250010,1.000000,0.00,12000.00,0.00,23.75,0.00,23.75,NULL,NULL,NULL,0,'2026-09-20 01:55:19','2026-09-20 01:55:19'),(1507,362,1,1147,'MAT-0001 — Lámina de acero 1,2 mm',NULL,NULL,NULL,NULL,949,505.250110,1.000000,0.00,46800.00,0.00,92.63,0.00,92.63,NULL,NULL,NULL,0,'2026-09-23 03:54:08','2026-09-23 03:54:08'),(1508,362,2,1357,'MAT-0001 — Lámina de acero 1,2 mm',NULL,NULL,NULL,NULL,949,505.250110,1.000000,46800.00,0.00,92.63,0.00,92.63,0.00,NULL,NULL,NULL,0,'2026-09-23 03:54:08','2026-09-23 03:54:08'),(1509,362,3,1147,'MAT-0002 — Pintura electrostática negra',NULL,NULL,NULL,NULL,949,505.250028,1.000000,0.00,12600.00,0.00,24.94,0.00,24.94,NULL,NULL,NULL,0,'2026-09-23 03:54:08','2026-09-23 03:54:08'),(1510,362,4,1357,'MAT-0002 — Pintura electrostática negra',NULL,NULL,NULL,NULL,949,505.250028,1.000000,12600.00,0.00,24.94,0.00,24.94,0.00,NULL,NULL,NULL,0,'2026-09-23 03:54:08','2026-09-23 03:54:08'),(1511,362,5,1147,'MAT-0003 — Tornillería surtida',NULL,NULL,NULL,NULL,949,505.250026,1.000000,0.00,19000.00,0.00,37.61,0.00,37.61,NULL,NULL,NULL,0,'2026-09-23 03:54:08','2026-09-23 03:54:08'),(1512,362,6,1357,'MAT-0003 — Tornillería surtida',NULL,NULL,NULL,NULL,949,505.250026,1.000000,19000.00,0.00,37.61,0.00,37.61,0.00,NULL,NULL,NULL,0,'2026-09-23 03:54:08','2026-09-23 03:54:08'),(1513,363,1,1147,'Recibo de producción — 10 gabinetes terminados',NULL,NULL,NULL,NULL,949,521.050000,1.000000,78400.00,0.00,150.47,0.00,150.47,0.00,NULL,NULL,NULL,0,'2026-09-23 03:54:08','2026-09-23 03:54:08'),(1514,363,2,1357,'Recibo de producción — 10 gabinetes terminados',NULL,NULL,NULL,NULL,949,521.050000,1.000000,0.00,78400.00,0.00,150.47,0.00,150.47,NULL,NULL,NULL,0,'2026-09-23 03:54:08','2026-09-23 03:54:08'),(1515,364,1,1147,'MAT-0001 — Lámina de acero 1,2 mm',NULL,NULL,NULL,NULL,949,505.250110,1.000000,0.00,111240.00,0.00,220.17,0.00,220.17,NULL,NULL,NULL,0,'2026-09-23 03:54:08','2026-09-23 03:54:08'),(1516,364,2,1357,'MAT-0001 — Lámina de acero 1,2 mm',NULL,NULL,NULL,NULL,949,505.250110,1.000000,111240.00,0.00,220.17,0.00,220.17,0.00,NULL,NULL,NULL,0,'2026-09-23 03:54:08','2026-09-23 03:54:08'),(1517,364,3,1147,'MAT-0003 — Tornillería surtida',NULL,NULL,NULL,NULL,949,505.250026,1.000000,0.00,38000.00,0.00,75.21,0.00,75.21,NULL,NULL,NULL,0,'2026-09-23 03:54:08','2026-09-23 03:54:08'),(1518,364,4,1357,'MAT-0003 — Tornillería surtida',NULL,NULL,NULL,NULL,949,505.250026,1.000000,38000.00,0.00,75.21,0.00,75.21,0.00,NULL,NULL,NULL,0,'2026-09-23 03:54:08','2026-09-23 03:54:08'),(1519,365,1,1364,'Cierre — el lote se desechó por falla de soldadura',NULL,NULL,NULL,NULL,949,521.050000,1.000000,149240.00,0.00,286.42,0.00,286.42,0.00,NULL,NULL,NULL,0,'2026-09-23 03:54:08','2026-09-23 03:54:08'),(1520,365,2,1357,'Cierre — el lote se desechó por falla de soldadura',NULL,NULL,NULL,NULL,949,521.050000,1.000000,0.00,149240.00,0.00,286.42,0.00,286.42,NULL,NULL,NULL,0,'2026-09-23 03:54:08','2026-09-23 03:54:08'),(1521,366,1,921,'pagos reparacio',NULL,NULL,NULL,NULL,949,NULL,NULL,0.00,40000.00,0.00,0.00,0.00,0.00,'2026-09-30','fact 330','2026-09-25',0,'2026-09-30 04:26:15','2026-09-30 04:26:15'),(1522,366,2,1096,'pagos',NULL,NULL,NULL,NULL,949,NULL,NULL,40000.00,0.00,0.00,0.00,0.00,0.00,'2026-09-30','fact 330','2026-09-25',0,'2026-09-30 04:26:15','2026-09-30 04:26:15');
/*!40000 ALTER TABLE `journal_details` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `journal_entries`
--

DROP TABLE IF EXISTS `journal_entries`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `journal_entries` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `company_id` bigint unsigned NOT NULL,
  `document_type_id` bigint unsigned NOT NULL,
  `document_number` bigint unsigned DEFAULT NULL,
  `number_series_id` bigint unsigned DEFAULT NULL,
  `series_number` bigint unsigned DEFAULT NULL COMMENT 'número manual asignado dentro de number_series_id; document_number sigue siendo el consecutivo interno automático',
  `document_date` date NOT NULL,
  `posting_date` date NOT NULL,
  `due_date` date DEFAULT NULL,
  `fiscal_period_id` bigint unsigned DEFAULT NULL,
  `description` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `status` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'draft' COMMENT 'draft|posted|voided',
  `reversal_of_id` bigint unsigned DEFAULT NULL,
  `schedule_id` bigint unsigned DEFAULT NULL,
  `source_module` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `business_partner_id` bigint unsigned DEFAULT NULL COMMENT 'FK a business_partners se agrega en la migración de Fase 1 (CxC/CxP)',
  `created_by` bigint unsigned DEFAULT NULL,
  `posted_by` bigint unsigned DEFAULT NULL,
  `posted_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `journal_entries_doc_number_unique` (`company_id`,`document_type_id`,`document_number`),
  KEY `journal_entries_document_type_id_foreign` (`document_type_id`),
  KEY `journal_entries_fiscal_period_id_foreign` (`fiscal_period_id`),
  KEY `journal_entries_reversal_of_id_foreign` (`reversal_of_id`),
  KEY `journal_entries_created_by_foreign` (`created_by`),
  KEY `journal_entries_posted_by_foreign` (`posted_by`),
  KEY `journal_entries_business_partner_id_foreign` (`business_partner_id`),
  KEY `journal_entries_number_series_id_foreign` (`number_series_id`),
  KEY `journal_entries_schedule_id_foreign` (`schedule_id`),
  KEY `journal_entries_company_status_posting_date_index` (`company_id`,`status`,`posting_date`),
  CONSTRAINT `journal_entries_business_partner_id_foreign` FOREIGN KEY (`business_partner_id`) REFERENCES `business_partners` (`id`) ON DELETE SET NULL,
  CONSTRAINT `journal_entries_company_id_foreign` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`) ON DELETE CASCADE,
  CONSTRAINT `journal_entries_created_by_foreign` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `journal_entries_document_type_id_foreign` FOREIGN KEY (`document_type_id`) REFERENCES `document_types` (`id`),
  CONSTRAINT `journal_entries_fiscal_period_id_foreign` FOREIGN KEY (`fiscal_period_id`) REFERENCES `fiscal_periods` (`id`),
  CONSTRAINT `journal_entries_number_series_id_foreign` FOREIGN KEY (`number_series_id`) REFERENCES `document_type_number_series` (`id`) ON DELETE SET NULL,
  CONSTRAINT `journal_entries_posted_by_foreign` FOREIGN KEY (`posted_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `journal_entries_reversal_of_id_foreign` FOREIGN KEY (`reversal_of_id`) REFERENCES `journal_entries` (`id`) ON DELETE SET NULL,
  CONSTRAINT `journal_entries_schedule_id_foreign` FOREIGN KEY (`schedule_id`) REFERENCES `journal_entry_schedules` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=367 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `journal_entries`
--

LOCK TABLES `journal_entries` WRITE;
/*!40000 ALTER TABLE `journal_entries` DISABLE KEYS */;
INSERT INTO `journal_entries` VALUES (275,600,411,1,NULL,NULL,'2026-01-01','2026-01-01',NULL,364,'CARGA INICIAL EMPRESA EN MARCHA','posted',NULL,NULL,'contable',NULL,357,357,'2026-09-03 03:42:43','2026-09-03 03:42:43','2026-09-03 03:42:43'),(276,600,408,1,NULL,NULL,'2026-08-31','2026-08-31',NULL,371,'Deducciones planilla agosto 2026','posted',NULL,NULL,'contable',NULL,357,357,'2026-09-05 22:57:26','2026-09-05 22:45:16','2026-09-05 22:57:26'),(277,600,409,1,NULL,NULL,'2026-08-31','2026-08-31',NULL,371,'Transferencias bancarias agosto 2026','posted',NULL,NULL,'bancos',NULL,357,357,'2026-09-06 01:22:40','2026-09-06 00:35:23','2026-09-06 01:22:40'),(278,600,410,1,NULL,NULL,'2026-08-31','2026-08-31',NULL,371,'Depósistos bancarios en agosto 2026','posted',NULL,NULL,'bancos',NULL,357,357,'2026-09-06 20:23:16','2026-09-06 17:22:16','2026-09-06 20:23:17'),(279,600,409,2,NULL,NULL,'2026-08-31','2026-09-06','2026-08-31',372,'Depósitos bancarios vistos en estdo de cuenta agosto 2026','voided',NULL,NULL,'bancos',NULL,357,357,'2026-09-06 21:00:32','2026-09-06 21:00:32','2026-09-06 21:32:32'),(280,600,409,3,NULL,NULL,'2026-08-31','2026-08-31',NULL,371,'Depósitos bancarios vistos en estdo de cuenta agosto 2026','posted',NULL,NULL,'bancos',NULL,357,357,'2026-09-06 21:32:17','2026-09-06 21:32:17','2026-09-06 21:32:17'),(281,600,409,4,NULL,NULL,'2026-09-06','2026-09-06',NULL,372,'Anulación de TRF-2','posted',279,NULL,'bancos',NULL,357,357,'2026-09-06 21:32:31','2026-09-06 21:32:31','2026-09-06 21:32:31'),(282,600,412,1,NULL,NULL,'2026-08-31','2026-08-31',NULL,371,'Depísito N.10375988 28-08-2026','posted',NULL,NULL,'contable',NULL,357,357,'2026-09-06 21:57:26','2026-09-06 21:57:26','2026-09-06 21:57:26'),(283,600,413,1,NULL,NULL,'2026-08-26','2026-08-26','2026-09-26',371,'Registro de factura N°30 Vanessa Mendoza C. por soporte en contabilidad de agosto 2026','posted',NULL,NULL,'compras',NULL,357,357,'2026-09-09 01:34:19','2026-09-09 01:34:19','2026-09-09 01:34:19'),(284,600,408,2,NULL,NULL,'2026-08-31','2026-08-31','2026-08-31',371,'Registros de eventos especiales en agosto 2026','posted',NULL,NULL,'contable',NULL,357,357,'2026-09-09 02:01:40','2026-09-09 01:55:13','2026-09-09 02:01:41'),(285,600,408,3,NULL,NULL,'2026-08-31','2026-08-31','2026-08-31',371,NULL,'posted',NULL,NULL,'contable',NULL,357,357,'2026-09-09 02:14:45','2026-09-09 02:14:45','2026-09-09 02:14:45'),(286,600,412,2,NULL,NULL,'2026-08-30','2026-08-30',NULL,371,'Vanessa Menodza Calderon servicio agosto 2026','posted',NULL,NULL,'contable',NULL,357,357,'2026-09-09 02:26:30','2026-09-09 02:26:30','2026-09-09 02:26:30'),(287,600,412,3,NULL,NULL,'2026-08-31','2026-08-31',NULL,371,'Deducción planilla agosto 2026','voided',NULL,NULL,'contable',NULL,357,357,'2026-09-09 02:59:27','2026-09-09 02:59:27','2026-09-09 03:05:18'),(288,600,412,4,NULL,NULL,'2026-08-31','2026-08-31',NULL,371,'Anulación — reclasificación ARR-3 movió el monto completo en vez del parcial correcto','posted',287,NULL,'contable',NULL,357,357,'2026-09-09 03:05:17','2026-09-09 03:05:17','2026-09-09 03:05:17'),(289,600,412,5,NULL,NULL,'2026-08-31','2026-08-31',NULL,371,'Deducción planilla agosto 2026 (corrección — reclasificación parcial correcta)','posted',NULL,NULL,'contable',NULL,357,357,'2026-09-09 03:05:18','2026-09-09 03:05:18','2026-09-09 03:05:18'),(290,601,414,1,NULL,NULL,'2026-01-15','2026-01-15',NULL,376,NULL,'posted',NULL,NULL,'contable',NULL,NULL,NULL,'2026-09-10 21:11:17','2026-09-10 21:11:17','2026-09-10 21:11:17'),(316,604,415,1,NULL,NULL,'2026-01-05','2026-01-05',NULL,377,'[PRUEBA] Aporte inicial de capital','posted',NULL,NULL,'contable',NULL,358,358,'2026-09-18 03:45:25','2026-09-18 03:45:25','2026-09-18 03:45:25'),(317,604,418,1,NULL,NULL,'2026-01-20','2026-01-20',NULL,377,'[PRUEBA] Compra de equipo de cómputo','posted',NULL,NULL,'compras',NULL,358,358,'2026-09-18 03:45:25','2026-09-18 03:45:25','2026-09-18 03:45:25'),(318,604,418,2,NULL,NULL,'2026-02-10','2026-02-10',NULL,378,'[PRUEBA] Compra de mercadería','voided',NULL,NULL,'compras',NULL,358,358,'2026-09-18 03:45:25','2026-09-18 03:45:25','2026-09-18 04:02:41'),(319,604,417,1,NULL,NULL,'2026-03-05','2026-03-05',NULL,379,'[PRUEBA] Factura de venta a Distribuidora La Central','posted',NULL,NULL,'ventas',NULL,358,358,'2026-09-18 03:45:25','2026-09-18 03:45:25','2026-09-18 03:45:25'),(320,604,415,2,NULL,NULL,'2026-03-05','2026-03-05',NULL,379,'[PRUEBA] Costo de la mercadería vendida en marzo','voided',NULL,NULL,'contable',NULL,358,358,'2026-09-18 03:45:25','2026-09-18 03:45:25','2026-09-18 04:02:41'),(321,604,419,1,NULL,NULL,'2026-04-02','2026-04-02',NULL,380,'[PRUEBA] Cobro de la factura de venta de marzo','posted',NULL,NULL,'cxc',NULL,358,358,'2026-09-18 03:45:25','2026-09-18 03:45:25','2026-09-18 03:45:25'),(322,604,415,3,NULL,NULL,'2026-04-30','2026-04-30',NULL,380,'[PRUEBA] Planilla de abril','posted',NULL,NULL,'contable',NULL,358,358,'2026-09-18 03:45:25','2026-09-18 03:45:25','2026-09-18 03:45:25'),(323,604,418,3,NULL,NULL,'2026-05-15','2026-05-15',NULL,381,'[PRUEBA] Gastos operativos de mayo','posted',NULL,NULL,'compras',NULL,358,358,'2026-09-18 03:45:25','2026-09-18 03:45:25','2026-09-18 03:45:25'),(324,604,417,2,NULL,NULL,'2026-06-18','2026-06-18',NULL,382,'[PRUEBA] Factura de venta a Comercial El Roble','posted',NULL,NULL,'ventas',NULL,358,358,'2026-09-18 03:45:25','2026-09-18 03:45:25','2026-09-18 03:45:25'),(325,604,415,4,NULL,NULL,'2026-06-30','2026-06-30',NULL,382,'[PRUEBA] Depreciación del primer semestre','posted',NULL,NULL,'contable',NULL,358,358,'2026-09-18 03:45:25','2026-09-18 03:45:25','2026-09-18 03:45:25'),(326,604,417,3,NULL,NULL,'2026-08-12','2026-08-12',NULL,384,'[PRUEBA] Factura de exportación a Northbridge Trading','posted',NULL,NULL,'ventas',NULL,358,358,'2026-09-18 03:45:25','2026-09-18 03:45:25','2026-09-18 03:45:25'),(327,604,418,4,NULL,NULL,'2026-09-05','2026-09-05',NULL,385,'[PRUEBA] Honorarios profesionales de setiembre','posted',NULL,NULL,'compras',NULL,358,358,'2026-09-18 03:45:26','2026-09-18 03:45:26','2026-09-18 03:45:26'),(328,604,420,1,NULL,NULL,'2026-09-12','2026-09-12',NULL,385,'[PRUEBA] Pago de la compra de equipo de cómputo','posted',NULL,NULL,'cxp',NULL,358,358,'2026-09-18 03:45:26','2026-09-18 03:45:26','2026-09-18 03:45:26'),(332,604,423,1,NULL,NULL,'2026-01-10','2026-01-10',NULL,377,'[PRUEBA] Existencias iniciales — almacén principal','posted',NULL,NULL,'inventario',NULL,358,358,'2026-09-18 03:56:36','2026-09-18 03:56:36','2026-09-18 03:56:36'),(333,604,423,2,NULL,NULL,'2026-01-10','2026-01-10',NULL,377,'[PRUEBA] Existencias iniciales — bodega de tránsito','posted',NULL,NULL,'inventario',NULL,358,358,'2026-09-18 03:56:36','2026-09-18 03:56:36','2026-09-18 03:56:36'),(334,604,423,3,NULL,NULL,'2026-01-10','2026-01-10',NULL,377,'[PRUEBA] Existencias iniciales — almacén con ubicaciones','posted',NULL,NULL,'inventario',NULL,358,358,'2026-09-18 03:56:36','2026-09-18 03:56:36','2026-09-18 03:56:36'),(340,604,418,5,NULL,NULL,'2026-02-10','2026-02-10',NULL,378,'[PRUEBA] Anulación — se rehace por el módulo de inventario','posted',318,NULL,'compras',NULL,358,358,'2026-09-18 04:02:41','2026-09-18 04:02:41','2026-09-18 04:02:41'),(341,604,415,5,NULL,NULL,'2026-03-05','2026-03-05',NULL,379,'[PRUEBA] Anulación — se rehace por el módulo de inventario','posted',320,NULL,'contable',NULL,358,358,'2026-09-18 04:02:41','2026-09-18 04:02:41','2026-09-18 04:02:41'),(342,604,426,1,NULL,NULL,'2026-02-10','2026-02-10',NULL,378,'[PRUEBA] Entrada por compra de mercadería','posted',NULL,NULL,'inventario',NULL,358,358,'2026-09-18 04:02:42','2026-09-18 04:02:42','2026-09-18 04:02:42'),(343,604,418,6,NULL,NULL,'2026-02-10','2026-02-10','2026-03-12',378,'[PRUEBA] Factura de compra de mercadería','posted',NULL,NULL,'compras',NULL,358,358,'2026-09-18 04:02:42','2026-09-18 04:02:42','2026-09-18 04:02:42'),(344,604,424,1,NULL,NULL,'2026-03-05','2026-03-05',NULL,379,'[PRUEBA] Salida por venta de marzo','posted',NULL,NULL,'inventario',NULL,358,358,'2026-09-18 04:02:42','2026-09-18 04:02:42','2026-09-18 04:02:42'),(360,604,415,6,NULL,NULL,'2026-01-10','2026-01-10',NULL,377,'[PRUEBA] Reclasificación de la contrapartida de saldos iniciales','posted',NULL,NULL,'contable',NULL,358,358,'2026-09-18 04:07:24','2026-09-18 04:07:24','2026-09-18 04:07:24'),(361,604,425,1,NULL,NULL,'2026-09-20','2026-09-20',NULL,385,'TRASLADO POR VENTA COMPROBADA','posted',NULL,NULL,'inventario',NULL,359,359,'2026-09-20 01:55:19','2026-09-20 01:55:19','2026-09-20 01:55:19'),(362,604,424,2,NULL,NULL,'2026-09-14','2026-09-14',NULL,385,'Emisión a producción — tanda de 10 gabinetes','posted',NULL,NULL,'inventario',NULL,NULL,NULL,'2026-09-23 03:54:07','2026-09-23 03:54:07','2026-09-23 03:54:07'),(363,604,423,4,NULL,NULL,'2026-09-15','2026-09-15',NULL,385,'Recibo de producción — 10 gabinetes terminados','posted',NULL,NULL,'inventario',NULL,NULL,NULL,'2026-09-23 03:54:08','2026-09-23 03:54:08','2026-09-23 03:54:08'),(364,604,424,3,NULL,NULL,'2026-09-18','2026-09-18',NULL,385,'Emisión a producción — tanda de 5 racks','posted',NULL,NULL,'inventario',NULL,NULL,NULL,'2026-09-23 03:54:08','2026-09-23 03:54:08','2026-09-23 03:54:08'),(365,604,423,5,NULL,NULL,'2026-09-19','2026-09-19',NULL,385,'Cierre — el lote se desechó por falla de soldadura','posted',NULL,NULL,'inventario',NULL,NULL,NULL,'2026-09-23 03:54:08','2026-09-23 03:54:08','2026-09-23 03:54:08'),(366,600,408,NULL,NULL,NULL,'2026-09-30','2026-09-30','2026-09-30',NULL,NULL,'draft',NULL,NULL,'contable',NULL,355,NULL,NULL,'2026-09-30 04:26:15','2026-09-30 04:26:15');
/*!40000 ALTER TABLE `journal_entries` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `journal_entry_schedules`
--

DROP TABLE IF EXISTS `journal_entry_schedules`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `journal_entry_schedules` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `company_id` bigint unsigned NOT NULL,
  `document_type_id` bigint unsigned NOT NULL,
  `description` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `lines` json NOT NULL,
  `frequency_type` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'days|months',
  `interval_count` int unsigned NOT NULL,
  `next_run_date` date NOT NULL,
  `expires_at` date DEFAULT NULL,
  `status` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'active' COMMENT 'active|expired|cancelled',
  `created_by` bigint unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `journal_entry_schedules_company_id_foreign` (`company_id`),
  KEY `journal_entry_schedules_document_type_id_foreign` (`document_type_id`),
  KEY `journal_entry_schedules_created_by_foreign` (`created_by`),
  CONSTRAINT `journal_entry_schedules_company_id_foreign` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`) ON DELETE CASCADE,
  CONSTRAINT `journal_entry_schedules_created_by_foreign` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `journal_entry_schedules_document_type_id_foreign` FOREIGN KEY (`document_type_id`) REFERENCES `document_types` (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=14 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `journal_entry_schedules`
--

LOCK TABLES `journal_entry_schedules` WRITE;
/*!40000 ALTER TABLE `journal_entry_schedules` DISABLE KEYS */;
/*!40000 ALTER TABLE `journal_entry_schedules` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `labor_settlement_lines`
--

DROP TABLE IF EXISTS `labor_settlement_lines`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `labor_settlement_lines` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `labor_settlement_id` bigint unsigned NOT NULL,
  `line_number` int unsigned NOT NULL,
  `kind` varchar(30) COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'christmas_bonus|vacation|notice|severance|indemnity|pending_salary|deduction',
  `code` varchar(30) COLLATE utf8mb4_unicode_ci NOT NULL,
  `name` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `detail` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'cómo se llegó al número, para el trabajador',
  `days` decimal(10,4) DEFAULT NULL,
  `daily_rate` decimal(18,2) DEFAULT NULL,
  `amount` decimal(18,2) NOT NULL,
  `subject_to_ccss` tinyint(1) NOT NULL DEFAULT '0',
  `subject_to_income_tax` tinyint(1) NOT NULL DEFAULT '0',
  `account_id` bigint unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `labor_settlement_lines_account_id_foreign` (`account_id`),
  KEY `labor_settlement_lines_labor_settlement_id_kind_index` (`labor_settlement_id`,`kind`),
  CONSTRAINT `labor_settlement_lines_account_id_foreign` FOREIGN KEY (`account_id`) REFERENCES `chart_of_accounts` (`id`) ON DELETE SET NULL,
  CONSTRAINT `labor_settlement_lines_labor_settlement_id_foreign` FOREIGN KEY (`labor_settlement_id`) REFERENCES `labor_settlements` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `labor_settlement_lines`
--

LOCK TABLES `labor_settlement_lines` WRITE;
/*!40000 ALTER TABLE `labor_settlement_lines` DISABLE KEYS */;
/*!40000 ALTER TABLE `labor_settlement_lines` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `labor_settlements`
--

DROP TABLE IF EXISTS `labor_settlements`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `labor_settlements` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `company_id` bigint unsigned NOT NULL,
  `employee_id` bigint unsigned NOT NULL,
  `termination_date` date NOT NULL,
  `reason` varchar(40) COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'la causal decide qué extremos proceden: ver LaborSettlement::REASONS',
  `reason_detail` text COLLATE utf8mb4_unicode_ci COMMENT 'los hechos, para el expediente',
  `average_monthly_salary` decimal(18,2) NOT NULL DEFAULT '0.00' COMMENT 'promedio de los últimos 6 meses: base de preaviso y cesantía',
  `average_daily_salary` decimal(18,2) NOT NULL DEFAULT '0.00',
  `vacation_daily_salary` decimal(18,2) NOT NULL DEFAULT '0.00' COMMENT 'promedio de las últimas 50 semanas: base de vacaciones',
  `christmas_bonus_base` decimal(18,2) NOT NULL DEFAULT '0.00' COMMENT 'salarios del 1 de diciembre a la salida',
  `years_of_service` decimal(8,4) NOT NULL DEFAULT '0.0000',
  `bases_from_history` tinyint(1) NOT NULL DEFAULT '1',
  `history_months_found` smallint unsigned NOT NULL DEFAULT '0',
  `total_gross` decimal(18,2) NOT NULL DEFAULT '0.00',
  `total_ccss` decimal(18,2) NOT NULL DEFAULT '0.00',
  `total_income_tax` decimal(18,2) NOT NULL DEFAULT '0.00',
  `total_other_deductions` decimal(18,2) NOT NULL DEFAULT '0.00',
  `total_net` decimal(18,2) NOT NULL DEFAULT '0.00',
  `status` varchar(20) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'draft' COMMENT 'draft|approved|posted|voided',
  `journal_entry_id` bigint unsigned DEFAULT NULL,
  `document_type_id` bigint unsigned DEFAULT NULL,
  `cost_center_id` bigint unsigned DEFAULT NULL,
  `calculated_at` timestamp NULL DEFAULT NULL,
  `approved_at` timestamp NULL DEFAULT NULL,
  `approved_by` bigint unsigned DEFAULT NULL,
  `created_by` bigint unsigned DEFAULT NULL,
  `notes` text COLLATE utf8mb4_unicode_ci,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `labor_settlements_employee_id_foreign` (`employee_id`),
  KEY `labor_settlements_journal_entry_id_foreign` (`journal_entry_id`),
  KEY `labor_settlements_document_type_id_foreign` (`document_type_id`),
  KEY `labor_settlements_cost_center_id_foreign` (`cost_center_id`),
  KEY `labor_settlements_approved_by_foreign` (`approved_by`),
  KEY `labor_settlements_created_by_foreign` (`created_by`),
  KEY `labor_settlements_company_id_status_index` (`company_id`,`status`),
  KEY `labor_settlements_company_id_employee_id_index` (`company_id`,`employee_id`),
  CONSTRAINT `labor_settlements_approved_by_foreign` FOREIGN KEY (`approved_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `labor_settlements_company_id_foreign` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`) ON DELETE CASCADE,
  CONSTRAINT `labor_settlements_cost_center_id_foreign` FOREIGN KEY (`cost_center_id`) REFERENCES `cost_centers` (`id`) ON DELETE SET NULL,
  CONSTRAINT `labor_settlements_created_by_foreign` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `labor_settlements_document_type_id_foreign` FOREIGN KEY (`document_type_id`) REFERENCES `document_types` (`id`) ON DELETE SET NULL,
  CONSTRAINT `labor_settlements_employee_id_foreign` FOREIGN KEY (`employee_id`) REFERENCES `employees` (`id`),
  CONSTRAINT `labor_settlements_journal_entry_id_foreign` FOREIGN KEY (`journal_entry_id`) REFERENCES `journal_entries` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `labor_settlements`
--

LOCK TABLES `labor_settlements` WRITE;
/*!40000 ALTER TABLE `labor_settlements` DISABLE KEYS */;
/*!40000 ALTER TABLE `labor_settlements` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `landed_cost_allocations`
--

DROP TABLE IF EXISTS `landed_cost_allocations`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `landed_cost_allocations` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `landed_cost_document_id` bigint unsigned NOT NULL,
  `item_id` bigint unsigned NOT NULL,
  `warehouse_id` bigint unsigned NOT NULL,
  `received_quantity` decimal(18,6) NOT NULL COMMENT 'lo que trajo la línea de la recepción',
  `on_hand_quantity` decimal(18,6) NOT NULL COMMENT 'existencia del artículo al momento de repartir',
  `allocated_amount` decimal(18,2) NOT NULL COMMENT 'porción del total que le tocó a esta línea',
  `capitalized_amount` decimal(18,2) NOT NULL,
  `expensed_amount` decimal(18,2) NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `landed_cost_allocations_item_id_foreign` (`item_id`),
  KEY `landed_cost_allocations_warehouse_id_foreign` (`warehouse_id`),
  KEY `landed_cost_allocations_landed_cost_document_id_index` (`landed_cost_document_id`),
  CONSTRAINT `landed_cost_allocations_item_id_foreign` FOREIGN KEY (`item_id`) REFERENCES `items` (`id`),
  CONSTRAINT `landed_cost_allocations_landed_cost_document_id_foreign` FOREIGN KEY (`landed_cost_document_id`) REFERENCES `landed_cost_documents` (`id`) ON DELETE CASCADE,
  CONSTRAINT `landed_cost_allocations_warehouse_id_foreign` FOREIGN KEY (`warehouse_id`) REFERENCES `warehouses` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `landed_cost_allocations`
--

LOCK TABLES `landed_cost_allocations` WRITE;
/*!40000 ALTER TABLE `landed_cost_allocations` DISABLE KEYS */;
/*!40000 ALTER TABLE `landed_cost_allocations` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `landed_cost_documents`
--

DROP TABLE IF EXISTS `landed_cost_documents`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `landed_cost_documents` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `company_id` bigint unsigned NOT NULL,
  `document_type_id` bigint unsigned NOT NULL,
  `journal_entry_id` bigint unsigned NOT NULL,
  `inventory_document_id` bigint unsigned NOT NULL,
  `business_partner_id` bigint unsigned DEFAULT NULL,
  `document_date` date NOT NULL,
  `posting_date` date NOT NULL,
  `amount` decimal(18,2) NOT NULL COMMENT 'total del costo a repartir, en moneda local',
  `capitalized_amount` decimal(18,2) NOT NULL COMMENT 'parte que entró al inventario todavía en existencia',
  `expensed_amount` decimal(18,2) NOT NULL COMMENT 'parte cuya mercancía ya salió: va a diferencia de precio',
  `description` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `status` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'posted' COMMENT 'posted|voided',
  `created_by` bigint unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `landed_cost_documents_document_type_id_foreign` (`document_type_id`),
  KEY `landed_cost_documents_journal_entry_id_foreign` (`journal_entry_id`),
  KEY `landed_cost_documents_inventory_document_id_foreign` (`inventory_document_id`),
  KEY `landed_cost_documents_business_partner_id_foreign` (`business_partner_id`),
  KEY `landed_cost_documents_created_by_foreign` (`created_by`),
  KEY `landed_cost_documents_company_id_posting_date_index` (`company_id`,`posting_date`),
  CONSTRAINT `landed_cost_documents_business_partner_id_foreign` FOREIGN KEY (`business_partner_id`) REFERENCES `business_partners` (`id`),
  CONSTRAINT `landed_cost_documents_company_id_foreign` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`) ON DELETE CASCADE,
  CONSTRAINT `landed_cost_documents_created_by_foreign` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `landed_cost_documents_document_type_id_foreign` FOREIGN KEY (`document_type_id`) REFERENCES `document_types` (`id`),
  CONSTRAINT `landed_cost_documents_inventory_document_id_foreign` FOREIGN KEY (`inventory_document_id`) REFERENCES `inventory_documents` (`id`),
  CONSTRAINT `landed_cost_documents_journal_entry_id_foreign` FOREIGN KEY (`journal_entry_id`) REFERENCES `journal_entries` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `landed_cost_documents`
--

LOCK TABLES `landed_cost_documents` WRITE;
/*!40000 ALTER TABLE `landed_cost_documents` DISABLE KEYS */;
/*!40000 ALTER TABLE `landed_cost_documents` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `license_categories`
--

DROP TABLE IF EXISTS `license_categories`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `license_categories` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'nombre comercial, ej. Básica/Profesional/Corporativa',
  `max_companies` smallint unsigned NOT NULL,
  `max_admins` smallint unsigned NOT NULL DEFAULT '3',
  `max_users` smallint unsigned NOT NULL DEFAULT '10',
  `duration_months` smallint unsigned NOT NULL DEFAULT '12' COMMENT 'duración estándar, para permitir categorías con vigencias distintas a la anual',
  `description` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT '1',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=6 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `license_categories`
--

LOCK TABLES `license_categories` WRITE;
/*!40000 ALTER TABLE `license_categories` DISABLE KEYS */;
INSERT INTO `license_categories` VALUES (4,'Profesional',3,3,10,12,'Plan intermedio de demostración',1,'2026-09-01 01:38:22','2026-09-01 01:38:22'),(5,'PREMIUM',50,10,50,12,'EDICION ESPECIAL',1,'2026-09-01 03:21:37','2026-09-01 03:21:37');
/*!40000 ALTER TABLE `license_categories` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `licenses`
--

DROP TABLE IF EXISTS `licenses`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `licenses` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `code` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'código que el cliente activa, ej. CONTAPP-XXXX-XXXX-XXXX',
  `category_id` bigint unsigned DEFAULT NULL,
  `max_companies` smallint unsigned NOT NULL DEFAULT '1',
  `max_admins` smallint unsigned NOT NULL DEFAULT '3',
  `max_users` smallint unsigned NOT NULL DEFAULT '10',
  `expires_at` date NOT NULL,
  `status` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'active' COMMENT 'active|revoked',
  `notes` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'referencia interna: cliente, convenio, contacto...',
  `issued_by` bigint unsigned DEFAULT NULL,
  `superuser_id` bigint unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `licenses_code_unique` (`code`),
  KEY `licenses_category_id_foreign` (`category_id`),
  KEY `licenses_superuser_id_foreign` (`superuser_id`),
  KEY `licenses_issued_by_new_foreign` (`issued_by`),
  CONSTRAINT `licenses_category_id_foreign` FOREIGN KEY (`category_id`) REFERENCES `license_categories` (`id`) ON DELETE SET NULL,
  CONSTRAINT `licenses_issued_by_new_foreign` FOREIGN KEY (`issued_by`) REFERENCES `propietarios` (`id`) ON DELETE SET NULL,
  CONSTRAINT `licenses_superuser_id_foreign` FOREIGN KEY (`superuser_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=41 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `licenses`
--

LOCK TABLES `licenses` WRITE;
/*!40000 ALTER TABLE `licenses` DISABLE KEYS */;
INSERT INTO `licenses` VALUES (39,'CONTAPP-IEOC-UUJJ-LH4L',5,50,10,50,'2027-09-01','active','ALEXANDER NARANJO BRENES',26,355,'2026-09-01 03:31:17','2026-09-02 02:20:33'),(40,'CONTAPP-LZRA-KOMH-HUM3',4,3,3,10,'2027-09-18','active','Pruebas',26,358,'2026-09-18 02:11:44','2026-09-18 02:18:03');
/*!40000 ALTER TABLE `licenses` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `migrations`
--

DROP TABLE IF EXISTS `migrations`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `migrations` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `migration` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `batch` int NOT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=162 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `migrations`
--

LOCK TABLES `migrations` WRITE;
/*!40000 ALTER TABLE `migrations` DISABLE KEYS */;
INSERT INTO `migrations` VALUES (1,'0001_01_01_000000_create_users_table',1),(2,'0001_01_01_000001_create_cache_table',1),(3,'0001_01_01_000002_create_jobs_table',1),(4,'2026_08_05_032812_create_currencies_table',1),(5,'2026_08_05_032814_create_companies_table',1),(6,'2026_08_05_032816_create_account_mask_configs_table',1),(7,'2026_08_05_032819_add_contapp_fields_to_users_table',1),(8,'2026_08_05_032821_create_company_user_table',1),(9,'2026_08_05_032823_create_roles_table',1),(10,'2026_08_05_032826_create_user_roles_table',1),(11,'2026_08_05_032828_create_modules_table',1),(12,'2026_08_05_032830_create_module_permissions_table',1),(13,'2026_08_05_032833_create_company_modules_table',1),(14,'2026_08_05_032835_create_audit_logs_table',1),(15,'2026_08_05_032838_create_fiscal_years_table',1),(16,'2026_08_05_032840_create_fiscal_periods_table',1),(17,'2026_08_05_032842_create_chart_of_accounts_table',1),(18,'2026_08_05_032845_create_document_types_table',1),(19,'2026_08_05_032847_create_document_type_printing_table',1),(20,'2026_08_05_032849_create_document_type_officers_table',1),(21,'2026_08_05_032852_create_document_type_visible_columns_table',1),(22,'2026_08_05_032854_create_document_type_permissions_table',1),(23,'2026_08_05_032856_create_exchange_rates_table',1),(24,'2026_08_05_032859_create_journal_entries_table',1),(25,'2026_08_05_032901_create_journal_details_table',1),(26,'2026_08_05_035008_create_bp_categories_table',1),(27,'2026_08_05_035011_create_bp_families_table',1),(28,'2026_08_05_035013_create_business_partners_table',1),(29,'2026_08_05_035016_create_bp_open_items_table',1),(30,'2026_08_05_035018_create_bp_payment_applications_table',1),(31,'2026_08_05_035021_add_business_partner_foreign_keys_to_journal_tables',1),(32,'2026_08_05_040114_create_bank_accounts_table',1),(33,'2026_08_05_040117_create_bank_statement_lines_table',1),(34,'2026_08_05_040119_create_bank_reconciliations_table',1),(35,'2026_08_05_040122_create_bank_reconciliation_lines_table',1),(36,'2026_08_05_041419_create_tax_types_table',1),(37,'2026_08_05_041422_create_tax_rates_table',1),(38,'2026_08_05_041424_create_journal_detail_taxes_table',1),(39,'2026_08_05_041427_create_fx_revaluation_runs_table',1),(40,'2026_08_05_041430_create_fx_revaluation_details_table',1),(41,'2026_08_05_041432_create_period_closing_processes_table',1),(42,'2026_08_05_041435_create_opening_balances_table',1),(43,'2026_08_06_020352_add_is_platform_admin_to_users_table',1),(44,'2026_08_06_020355_create_licenses_table',1),(45,'2026_08_06_020358_add_license_id_to_companies_table',1),(46,'2026_08_13_205131_add_contact_fields_to_business_partners_table',1),(47,'2026_08_14_014422_add_special_attributes_to_chart_of_accounts_table',1),(48,'2026_08_16_210621_create_cost_centers_table',1),(49,'2026_08_16_210622_add_cost_center_id_to_journal_details_table',1),(50,'2026_08_16_212515_add_hierarchy_and_validity_to_cost_centers_table',1),(51,'2026_08_17_203659_create_document_type_number_series_table',1),(52,'2026_08_17_203700_add_number_series_to_journal_entries_table',1),(53,'2026_08_17_210617_add_holder_name_to_document_type_number_series_table',1),(54,'2026_08_18_184337_make_journal_entries_draft_friendly',1),(55,'2026_08_19_150000_add_tax_rate_id_to_chart_of_accounts_table',1),(56,'2026_08_20_140000_add_requires_electronic_key_to_document_types_table',1),(57,'2026_08_20_140100_add_electronic_key_to_journal_entries_table',1),(58,'2026_08_20_150000_move_electronic_key_to_journal_details_table',1),(59,'2026_08_22_090000_add_posting_date_and_due_date_to_journal_entries_table',1),(60,'2026_08_23_100000_add_fiscal_credit_fields_to_tax_rates_table',1),(61,'2026_08_24_090000_add_cost_center_id_to_business_partners_table',1),(62,'2026_08_24_100000_add_bp_line_requirement_to_document_types_table',1),(63,'2026_08_24_110000_add_is_opening_type_to_document_types_table',1),(64,'2026_08_24_120000_add_is_reconciliation_type_to_document_types_table',1),(65,'2026_08_24_120001_create_account_reconciliations_table',1),(66,'2026_08_24_130000_add_last_revaluation_rate_to_bp_open_items_table',1),(67,'2026_08_24_130001_add_gain_loss_accounts_to_fx_revaluation_runs_table',1),(68,'2026_08_24_222346_create_journal_entry_schedules_table',1),(69,'2026_08_24_222349_add_schedule_id_to_journal_entries_table',1),(70,'2026_08_25_034211_drop_hierarchy_from_cost_centers_table',1),(71,'2026_08_25_034214_create_cost_allocation_rules_table',1),(72,'2026_08_25_034216_create_cost_allocation_rule_lines_table',1),(73,'2026_08_25_034219_add_cost_allocation_rule_id_to_journal_details_table',1),(74,'2026_08_27_033333_add_status_posting_date_index_to_journal_entries_table',1),(75,'2026_08_27_041432_add_is_closing_type_to_document_types_table',1),(76,'2026_08_28_012837_add_address_to_companies_table',1),(77,'2026_08_28_030507_create_license_categories_table',1),(78,'2026_08_28_030510_add_category_id_to_licenses_table',1),(79,'2026_08_28_031521_create_commercial_profiles_table',1),(80,'2026_08_28_031524_create_commercial_interactions_table',1),(81,'2026_08_28_031527_create_commercial_follow_ups_table',1),(82,'2026_08_29_040000_add_superuser_id_to_licenses_table',1),(83,'2026_08_29_040001_backfill_superuser_id_on_licenses_table',1),(84,'2026_08_30_000000_create_propietarios_table',1),(85,'2026_08_30_000001_backfill_propietarios_from_platform_admin_users',1),(86,'2026_08_30_000002_repoint_issued_by_and_author_id_to_propietarios',1),(87,'2026_08_30_000003_drop_is_platform_admin_from_users_table',1),(88,'2026_08_30_010000_create_saved_reports_table',1),(89,'2026_08_31_120000_add_status_to_company_user_table',1),(90,'2026_08_31_120100_add_admin_user_limits_to_license_categories_table',1),(91,'2026_08_31_120101_add_admin_user_limits_to_licenses_table',1),(92,'2026_09_05_173308_add_reference_document_date_to_journal_details_table',2),(93,'2026_09_06_190000_create_bp_open_item_reconciliations_table',3),(94,'2026_09_07_090000_add_amount_to_account_reconciliation_lines_table',4),(95,'2026_09_07_100000_add_company_id_to_tax_types_and_tax_rates_tables',5),(96,'2026_09_13_100000_create_units_of_measure_table',6),(97,'2026_09_13_100001_create_item_groups_table',6),(98,'2026_09_13_100002_create_warehouses_table',6),(99,'2026_09_13_100003_create_items_table',6),(100,'2026_09_13_100004_create_item_warehouses_table',6),(101,'2026_09_13_110000_create_gl_determinations_table',7),(102,'2026_09_13_110001_create_inventory_documents_table',7),(103,'2026_09_13_110002_create_inventory_document_lines_table',7),(104,'2026_09_13_110003_create_stock_journals_table',7),(105,'2026_09_13_120000_add_purchase_fields_to_inventory_documents_table',8),(106,'2026_09_13_130000_create_landed_cost_documents_table',9),(107,'2026_09_13_130001_create_landed_cost_allocations_table',9),(108,'2026_09_13_130002_allow_revaluation_rows_in_stock_journals_table',9),(109,'2026_09_15_100000_create_production_orders_table',10),(110,'2026_09_15_100001_add_production_order_to_inventory_documents_table',10),(111,'2026_09_15_110000_create_warehouse_bins_table',11),(112,'2026_09_15_110001_create_item_bins_table',11),(113,'2026_09_15_110002_add_bin_to_stock_movement_tables',11),(114,'2026_09_15_120000_add_fiscal_fields_to_master_data',12),(115,'2026_09_15_120001_create_sales_documents_table',12),(116,'2026_09_15_120002_create_sales_document_lines_table',12),(117,'2026_09_15_120003_create_sales_line_taxes_table',12),(118,'2026_09_15_120004_create_sales_payments_and_references_tables',12),(119,'2026_09_15_120005_create_billing_tax_accounts_table',12),(120,'2026_09_15_140000_add_warehouse_transfers',13),(121,'2026_09_19_100000_add_purchase_returns_to_inventory_documents',14),(122,'2026_09_19_110000_add_original_document_to_sales_documents_table',15),(124,'2026_09_19_120000_create_sales_orders_tables',16),(125,'2026_09_20_100000_create_stock_counts_tables',17),(127,'2026_09_20_110000_create_import_cost_documents_tables',18),(128,'2026_09_20_120000_add_import_details_to_inventory_documents',19),(129,'2026_09_20_130000_create_item_lots_tables',20),(130,'2026_09_20_130001_add_lot_to_stock_movement_tables',20),(131,'2026_09_20_140000_create_inventory_write_downs_tables',21),(132,'2026_09_20_150000_create_purchase_orders_tables',22),(133,'2026_09_20_160000_add_reorder_levels_to_item_warehouses',23),(134,'2026_09_20_170000_add_default_reorder_levels_to_items',24),(135,'2026_09_21_120000_create_price_lists_tables',25),(136,'2026_09_21_130000_create_item_serials_table',26),(137,'2026_09_21_140000_create_bills_of_materials_tables',27),(138,'2026_09_21_150000_add_price_list_to_bp_categories',28),(139,'2026_09_21_160000_create_price_override_authorizations_table',29),(140,'2026_09_21_170000_allow_price_overrides_on_sales_orders',30),(141,'2026_09_23_100000_create_payroll_configuration_tables',31),(142,'2026_09_23_110000_create_employees_table',31),(143,'2026_09_23_120000_create_payroll_run_tables',31),(144,'2026_09_23_130000_create_payroll_settings_table',32),(145,'2026_09_23_140000_create_employee_deductions_table',32),(147,'2026_09_23_150000_create_vacation_and_personnel_action_tables',33),(148,'2026_09_23_160000_create_payroll_inputs_table',34),(149,'2026_09_24_100000_create_employee_recurring_inputs_table',35),(150,'2026_09_24_110000_add_income_tax_mode_to_payroll_settings',36),(151,'2026_09_24_120000_create_payroll_period_events_table',37),(152,'2026_09_25_100000_add_sign_to_payroll_concepts',38),(153,'2026_09_25_110000_create_employee_notes_table',39),(154,'2026_09_26_100000_create_departments_and_job_positions_tables',40),(155,'2026_09_26_110000_add_pensioner_to_employees_and_contributions',41),(156,'2026_09_26_120000_add_weekly_salary_divisor_to_employees',42),(157,'2026_09_26_130000_create_labor_settlements_tables',42),(158,'2026_09_26_140000_add_vacation_average_basis_to_payroll_settings',43),(159,'2026_09_26_150000_add_labor_settlement_id_to_vacation_movements',44),(160,'2026_09_29_100000_add_propietario_id_to_audit_logs_table',45),(161,'2026_09_30_100000_add_theme_to_companies_table',45);
/*!40000 ALTER TABLE `migrations` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `module_permissions`
--

DROP TABLE IF EXISTS `module_permissions`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `module_permissions` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `company_id` bigint unsigned NOT NULL,
  `module_id` bigint unsigned NOT NULL,
  `subject_type` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'user|role',
  `subject_id` bigint unsigned NOT NULL,
  `access_level` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'read_write|read|none',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `module_permissions_subject_unique` (`company_id`,`module_id`,`subject_type`,`subject_id`),
  KEY `module_permissions_module_id_foreign` (`module_id`),
  KEY `module_permissions_subject_type_subject_id_index` (`subject_type`,`subject_id`),
  CONSTRAINT `module_permissions_company_id_foreign` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`) ON DELETE CASCADE,
  CONSTRAINT `module_permissions_module_id_foreign` FOREIGN KEY (`module_id`) REFERENCES `modules` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=1470 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `module_permissions`
--

LOCK TABLES `module_permissions` WRITE;
/*!40000 ALTER TABLE `module_permissions` DISABLE KEYS */;
INSERT INTO `module_permissions` VALUES (1442,600,1427,'user',357,'read_write','2026-09-02 04:33:23','2026-09-02 04:33:23'),(1443,600,1428,'user',357,'read_write','2026-09-02 04:33:23','2026-09-02 04:33:23'),(1444,600,1429,'user',357,'read_write','2026-09-02 04:33:23','2026-09-02 04:33:23'),(1445,600,1430,'user',357,'read_write','2026-09-02 04:33:23','2026-09-02 04:33:23'),(1446,600,1431,'user',357,'read_write','2026-09-02 04:33:23','2026-09-02 04:33:23'),(1447,600,1427,'user',356,'read_write','2026-09-02 04:33:53','2026-09-02 04:33:53'),(1448,600,1428,'user',356,'read_write','2026-09-02 04:33:53','2026-09-02 04:33:53'),(1449,600,1429,'user',356,'read_write','2026-09-02 04:33:53','2026-09-02 04:33:53'),(1450,600,1430,'user',356,'read_write','2026-09-02 04:33:53','2026-09-02 04:33:53'),(1451,600,1431,'user',356,'read_write','2026-09-02 04:33:53','2026-09-02 04:33:53'),(1452,603,1427,'user',356,'read_write','2026-09-18 02:21:59','2026-09-18 02:21:59'),(1453,603,1428,'user',356,'read_write','2026-09-18 02:21:59','2026-09-18 02:21:59'),(1454,603,1429,'user',356,'read_write','2026-09-18 02:21:59','2026-09-18 02:21:59'),(1455,603,1430,'user',356,'read_write','2026-09-18 02:21:59','2026-09-18 02:21:59'),(1456,603,1431,'user',356,'read_write','2026-09-18 02:21:59','2026-09-18 02:21:59'),(1457,603,1432,'user',356,'read_write','2026-09-18 02:21:59','2026-09-18 02:21:59'),(1458,604,1427,'user',356,'read_write','2026-09-18 02:24:33','2026-09-18 02:24:33'),(1459,604,1428,'user',356,'read_write','2026-09-18 02:24:33','2026-09-18 02:24:33'),(1460,604,1429,'user',356,'read_write','2026-09-18 02:24:33','2026-09-18 02:24:33'),(1461,604,1430,'user',356,'read_write','2026-09-18 02:24:33','2026-09-18 02:24:33'),(1462,604,1431,'user',356,'read_write','2026-09-18 02:24:33','2026-09-18 02:24:33'),(1463,604,1432,'user',356,'read_write','2026-09-18 02:24:33','2026-09-18 02:24:33'),(1464,604,1427,'user',359,'read_write','2026-09-18 02:51:59','2026-09-18 02:51:59'),(1465,604,1428,'user',359,'read_write','2026-09-18 02:51:59','2026-09-18 02:51:59'),(1466,604,1429,'user',359,'read_write','2026-09-18 02:51:59','2026-09-18 02:51:59'),(1467,604,1430,'user',359,'read_write','2026-09-18 02:51:59','2026-09-18 02:51:59'),(1468,604,1431,'user',359,'read_write','2026-09-18 02:51:59','2026-09-18 02:51:59'),(1469,604,1432,'user',359,'read_write','2026-09-18 02:51:59','2026-09-18 02:51:59');
/*!40000 ALTER TABLE `module_permissions` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `modules`
--

DROP TABLE IF EXISTS `modules`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `modules` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `code` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'ventas, bancos, cxc, contabilidad, planillas...',
  `name` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `modules_code_unique` (`code`)
) ENGINE=InnoDB AUTO_INCREMENT=1435 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `modules`
--

LOCK TABLES `modules` WRITE;
/*!40000 ALTER TABLE `modules` DISABLE KEYS */;
INSERT INTO `modules` VALUES (1427,'accounting','Contabilidad (asientos, catálogo, cierres)','2026-09-01 01:38:20','2026-09-01 01:38:20'),(1428,'business_partners','Socios de negocio y cartera','2026-09-01 01:38:20','2026-09-01 01:38:20'),(1429,'banking','Bancos y conciliaciones','2026-09-01 01:38:20','2026-09-01 01:38:20'),(1430,'tax','Impuestos (IVA)','2026-09-01 01:38:20','2026-09-01 01:38:20'),(1431,'reports','Reportería','2026-09-01 01:38:20','2026-09-01 01:38:20'),(1432,'inventory','Inventario (artículos, almacenes)','2026-09-13 23:32:34','2026-09-13 23:32:34'),(1433,'billing','Facturación electrónica','2026-09-23 04:36:45','2026-09-23 04:36:45'),(1434,'payroll','Planillas (empleados, nómina, acciones de personal)','2026-09-23 04:36:45','2026-09-23 04:36:45');
/*!40000 ALTER TABLE `modules` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `opening_balances`
--

DROP TABLE IF EXISTS `opening_balances`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `opening_balances` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `company_id` bigint unsigned NOT NULL,
  `fiscal_year_id` bigint unsigned NOT NULL,
  `account_id` bigint unsigned NOT NULL,
  `business_partner_id` bigint unsigned DEFAULT NULL,
  `debit_local` decimal(18,2) NOT NULL DEFAULT '0.00',
  `credit_local` decimal(18,2) NOT NULL DEFAULT '0.00',
  `debit_foreign` decimal(18,2) NOT NULL DEFAULT '0.00',
  `credit_foreign` decimal(18,2) NOT NULL DEFAULT '0.00',
  `currency_id` bigint unsigned DEFAULT NULL,
  `imported_by` bigint unsigned DEFAULT NULL,
  `imported_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `opening_balances_company_id_foreign` (`company_id`),
  KEY `opening_balances_fiscal_year_id_foreign` (`fiscal_year_id`),
  KEY `opening_balances_account_id_foreign` (`account_id`),
  KEY `opening_balances_business_partner_id_foreign` (`business_partner_id`),
  KEY `opening_balances_currency_id_foreign` (`currency_id`),
  KEY `opening_balances_imported_by_foreign` (`imported_by`),
  CONSTRAINT `opening_balances_account_id_foreign` FOREIGN KEY (`account_id`) REFERENCES `chart_of_accounts` (`id`),
  CONSTRAINT `opening_balances_business_partner_id_foreign` FOREIGN KEY (`business_partner_id`) REFERENCES `business_partners` (`id`) ON DELETE SET NULL,
  CONSTRAINT `opening_balances_company_id_foreign` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`) ON DELETE CASCADE,
  CONSTRAINT `opening_balances_currency_id_foreign` FOREIGN KEY (`currency_id`) REFERENCES `currencies` (`id`),
  CONSTRAINT `opening_balances_fiscal_year_id_foreign` FOREIGN KEY (`fiscal_year_id`) REFERENCES `fiscal_years` (`id`),
  CONSTRAINT `opening_balances_imported_by_foreign` FOREIGN KEY (`imported_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=3 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `opening_balances`
--

LOCK TABLES `opening_balances` WRITE;
/*!40000 ALTER TABLE `opening_balances` DISABLE KEYS */;
/*!40000 ALTER TABLE `opening_balances` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `password_reset_tokens`
--

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

--
-- Dumping data for table `password_reset_tokens`
--

LOCK TABLES `password_reset_tokens` WRITE;
/*!40000 ALTER TABLE `password_reset_tokens` DISABLE KEYS */;
/*!40000 ALTER TABLE `password_reset_tokens` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `payroll_concepts`
--

DROP TABLE IF EXISTS `payroll_concepts`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `payroll_concepts` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `company_id` bigint unsigned NOT NULL,
  `code` varchar(30) COLLATE utf8mb4_unicode_ci NOT NULL,
  `name` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `type` varchar(20) COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'earning|deduction',
  `sign` tinyint NOT NULL DEFAULT '1' COMMENT '1 suma al devengado, -1 lo resta (incapacidad, ausencias)',
  `affects_ccss` tinyint(1) NOT NULL DEFAULT '1',
  `affects_income_tax` tinyint(1) NOT NULL DEFAULT '1',
  `affects_provisions` tinyint(1) NOT NULL DEFAULT '1',
  `calculation` varchar(20) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'amount',
  `factor` decimal(8,4) DEFAULT NULL COMMENT 'porcentaje, o multiplicador de la hora ordinaria',
  `account_id` bigint unsigned DEFAULT NULL,
  `is_recurring` tinyint(1) NOT NULL DEFAULT '0' COMMENT 'se arrastra a cada planilla hasta que se desactive',
  `status` varchar(20) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'active',
  `legal_basis` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `payroll_concepts_company_id_code_unique` (`company_id`,`code`),
  KEY `payroll_concepts_account_id_foreign` (`account_id`),
  KEY `payroll_concepts_company_id_type_status_index` (`company_id`,`type`,`status`),
  CONSTRAINT `payroll_concepts_account_id_foreign` FOREIGN KEY (`account_id`) REFERENCES `chart_of_accounts` (`id`) ON DELETE SET NULL,
  CONSTRAINT `payroll_concepts_company_id_foreign` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=17 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `payroll_concepts`
--

LOCK TABLES `payroll_concepts` WRITE;
/*!40000 ALTER TABLE `payroll_concepts` DISABLE KEYS */;
INSERT INTO `payroll_concepts` VALUES (1,603,'HE-SIMPLE','Horas extra','earning',1,1,1,1,'hours',1.5000,NULL,0,'active','CT art. 139: la hora extra se paga con un cincuenta por ciento más sobre la ordinaria.','2026-09-24 03:54:40','2026-09-24 03:54:40'),(2,603,'HE-DOBLE','Horas extra dobles','earning',1,1,1,1,'hours',2.0000,NULL,0,'active','Para jornada extraordinaria en día feriado o de descanso, según la política de la empresa.','2026-09-24 03:54:40','2026-09-24 03:54:40'),(3,603,'FERIADO','Feriado laborado','earning',1,1,1,1,'amount',NULL,NULL,0,'active','CT art. 152: el feriado trabajado se paga doble.','2026-09-24 03:54:40','2026-09-24 03:54:40'),(4,603,'COMISION','Comisiones','earning',1,1,1,1,'amount',NULL,NULL,0,'active','Es salario en especie de naturaleza variable: entra completo a la base de todo.','2026-09-24 03:54:40','2026-09-24 03:54:40'),(5,603,'BONO','Bonificación','earning',1,1,1,1,'amount',NULL,NULL,0,'active',NULL,'2026-09-24 03:54:40','2026-09-24 03:54:40'),(6,603,'COMPL-INC','Complemento de incapacidad','earning',1,1,1,1,'amount',NULL,NULL,0,'active','Lo que la empresa paga POR ENCIMA del subsidio: eso sí es salario.','2026-09-24 03:54:40','2026-09-24 03:54:40'),(7,603,'VIATICO','Viáticos y reembolsos','earning',1,0,0,0,'amount',NULL,NULL,0,'active','Reintegro de un gasto hecho por cuenta de la empresa; no es remuneración.','2026-09-24 03:54:40','2026-09-24 03:54:40'),(8,603,'SUBSIDIO','Subsidio por incapacidad','earning',1,0,0,0,'amount',NULL,NULL,0,'active','Lo paga la CCSS o el INS, no el patrono: no es salario.','2026-09-24 03:54:40','2026-09-24 03:54:40'),(9,603,'AGUINALDO','Aguinaldo','earning',1,0,0,0,'amount',NULL,NULL,0,'active','No afecto a cargas sociales ni al impuesto al salario dentro del límite de ley.','2026-09-24 03:54:40','2026-09-24 03:54:40'),(10,603,'ADELANTO','Adelanto de salario','deduction',1,0,0,0,'amount',NULL,NULL,0,'active',NULL,'2026-09-24 03:54:40','2026-09-24 03:54:40'),(11,603,'PRESTAMO','Cuota de préstamo','deduction',1,0,0,0,'amount',NULL,NULL,0,'active',NULL,'2026-09-24 03:54:40','2026-09-24 03:54:40'),(12,603,'ASO-AHORRO','Ahorro asociación solidarista','deduction',1,0,0,0,'percentage',NULL,NULL,0,'active',NULL,'2026-09-24 03:54:40','2026-09-24 03:54:40'),(13,603,'ASO-CUOTA','Cuota asociación solidarista','deduction',1,0,0,0,'percentage',NULL,NULL,0,'active',NULL,'2026-09-24 03:54:40','2026-09-24 03:54:40'),(14,603,'PENSION','Pensión alimentaria','deduction',1,0,0,0,'amount',NULL,NULL,0,'active','Tiene preferencia sobre cualquier otro rebajo voluntario.','2026-09-24 03:54:40','2026-09-24 03:54:40'),(15,603,'EMBARGO','Embargo judicial','deduction',1,0,0,0,'amount',NULL,NULL,0,'active',NULL,'2026-09-24 03:54:40','2026-09-24 03:54:40'),(16,603,'OTRA-DED','Otra deducción','deduction',1,0,0,0,'amount',NULL,NULL,0,'active',NULL,'2026-09-24 03:54:40','2026-09-24 03:54:40');
/*!40000 ALTER TABLE `payroll_concepts` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `payroll_contributions`
--

DROP TABLE IF EXISTS `payroll_contributions`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `payroll_contributions` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `company_id` bigint unsigned NOT NULL,
  `code` varchar(30) COLLATE utf8mb4_unicode_ci NOT NULL,
  `name` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `payer` varchar(20) COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'employee|employer',
  `institution` varchar(30) COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'ccss|ins|ina|imas|banco_popular|fcl|rop|otro',
  `percentage` decimal(8,4) NOT NULL,
  `base` varchar(30) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'ccss_base' COMMENT 'ccss_base|gross',
  `exempt_for_pensioner` tinyint(1) NOT NULL DEFAULT '0' COMMENT 'los componentes de IVM: un pensionado ya no cotiza por ese régimen',
  `ceiling_amount` decimal(18,2) DEFAULT NULL,
  `expense_account_id` bigint unsigned DEFAULT NULL,
  `liability_account_id` bigint unsigned DEFAULT NULL,
  `valid_from` date NOT NULL,
  `valid_to` date DEFAULT NULL,
  `status` varchar(20) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'active',
  `legal_basis` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'norma que lo sustenta, para poder auditarlo',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `payroll_contrib_unique` (`company_id`,`code`,`valid_from`),
  KEY `payroll_contributions_expense_account_id_foreign` (`expense_account_id`),
  KEY `payroll_contributions_liability_account_id_foreign` (`liability_account_id`),
  KEY `payroll_contributions_company_id_payer_status_index` (`company_id`,`payer`,`status`),
  CONSTRAINT `payroll_contributions_company_id_foreign` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`) ON DELETE CASCADE,
  CONSTRAINT `payroll_contributions_expense_account_id_foreign` FOREIGN KEY (`expense_account_id`) REFERENCES `chart_of_accounts` (`id`) ON DELETE SET NULL,
  CONSTRAINT `payroll_contributions_liability_account_id_foreign` FOREIGN KEY (`liability_account_id`) REFERENCES `chart_of_accounts` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=13 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `payroll_contributions`
--

LOCK TABLES `payroll_contributions` WRITE;
/*!40000 ALTER TABLE `payroll_contributions` DISABLE KEYS */;
INSERT INTO `payroll_contributions` VALUES (1,603,'SEM-OBR','CCSS · Enfermedad y Maternidad (obrero)','employee','ccss',5.5000,'ccss',0,NULL,NULL,NULL,'2026-01-01',NULL,'active','Reglamento del Seguro de Salud, CCSS — VERIFICAR vigente','2026-09-24 03:54:40','2026-09-24 03:54:40'),(2,603,'IVM-OBR','CCSS · Invalidez, Vejez y Muerte (obrero)','employee','ccss',4.1700,'ccss',0,NULL,NULL,NULL,'2026-01-01',NULL,'active','Reglamento del Seguro de IVM, CCSS — VERIFICAR vigente','2026-09-24 03:54:40','2026-09-24 03:54:40'),(3,603,'BPDC-OBR','Banco Popular · Aporte obrero','employee','banco_popular',1.0000,'ccss',0,NULL,NULL,NULL,'2026-01-01',NULL,'active','Ley Orgánica del Banco Popular n.º 4351 — VERIFICAR vigente','2026-09-24 03:54:40','2026-09-24 03:54:40'),(4,603,'SEM-PAT','CCSS · Enfermedad y Maternidad (patronal)','employer','ccss',9.2500,'ccss',0,NULL,NULL,NULL,'2026-01-01',NULL,'active','Reglamento del Seguro de Salud, CCSS — VERIFICAR vigente','2026-09-24 03:54:40','2026-09-24 03:54:40'),(5,603,'IVM-PAT','CCSS · Invalidez, Vejez y Muerte (patronal)','employer','ccss',5.4200,'ccss',0,NULL,NULL,NULL,'2026-01-01',NULL,'active','Reglamento del Seguro de IVM, CCSS — VERIFICAR vigente','2026-09-24 03:54:40','2026-09-24 03:54:40'),(6,603,'BPDC-PAT','Banco Popular · Aporte patronal','employer','banco_popular',0.5000,'ccss',0,NULL,NULL,NULL,'2026-01-01',NULL,'active','Ley n.º 4351 — VERIFICAR vigente','2026-09-24 03:54:40','2026-09-24 03:54:40'),(7,603,'ASIG-FAM','Asignaciones Familiares (FODESAF)','employer','otro',5.0000,'ccss',0,NULL,NULL,NULL,'2026-01-01',NULL,'active','Ley de Desarrollo Social y Asignaciones Familiares — VERIFICAR vigente','2026-09-24 03:54:40','2026-09-24 03:54:40'),(8,603,'IMAS','IMAS','employer','imas',0.5000,'ccss',0,NULL,NULL,NULL,'2026-01-01',NULL,'active','Ley n.º 4760 — VERIFICAR vigente','2026-09-24 03:54:40','2026-09-24 03:54:40'),(9,603,'INA','INA','employer','ina',1.5000,'ccss',0,NULL,NULL,NULL,'2026-01-01',NULL,'active','Ley Orgánica del INA n.º 6868 — VERIFICAR vigente','2026-09-24 03:54:40','2026-09-24 03:54:40'),(10,603,'FCL','Fondo de Capitalización Laboral','employer','fcl',1.5000,'ccss',0,NULL,NULL,NULL,'2026-01-01',NULL,'active','Ley de Protección al Trabajador n.º 7983 — VERIFICAR vigente','2026-09-24 03:54:40','2026-09-24 03:54:40'),(11,603,'ROP','Régimen Obligatorio de Pensiones Complementarias','employer','rop',2.0000,'ccss',0,NULL,NULL,NULL,'2026-01-01',NULL,'active','Ley de Protección al Trabajador n.º 7983 — VERIFICAR vigente','2026-09-24 03:54:40','2026-09-24 03:54:40'),(12,603,'INS-RT','INS · Riesgos del Trabajo','employer','ins',0.0000,'ccss',0,NULL,NULL,NULL,'2026-01-01',NULL,'active','Código de Trabajo, Título IV — la tasa la fija la póliza de CADA empresa según su actividad','2026-09-24 03:54:40','2026-09-24 03:54:40');
/*!40000 ALTER TABLE `payroll_contributions` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `payroll_entries`
--

DROP TABLE IF EXISTS `payroll_entries`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `payroll_entries` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `payroll_period_id` bigint unsigned NOT NULL,
  `employee_id` bigint unsigned NOT NULL,
  `cost_center_id` bigint unsigned DEFAULT NULL,
  `days_worked` decimal(8,2) NOT NULL,
  `base_salary` decimal(18,2) NOT NULL COMMENT 'el vigente al calcular, congelado',
  `total_earnings` decimal(18,2) NOT NULL DEFAULT '0.00',
  `ccss_base` decimal(18,2) NOT NULL DEFAULT '0.00' COMMENT 'parte del bruto que sí forma salario para cargas',
  `income_tax_base` decimal(18,2) NOT NULL DEFAULT '0.00',
  `total_employee_contributions` decimal(18,2) NOT NULL DEFAULT '0.00',
  `income_tax` decimal(18,2) NOT NULL DEFAULT '0.00',
  `total_other_deductions` decimal(18,2) NOT NULL DEFAULT '0.00',
  `total_deductions` decimal(18,2) NOT NULL DEFAULT '0.00',
  `net_pay` decimal(18,2) NOT NULL DEFAULT '0.00',
  `total_employer_contributions` decimal(18,2) NOT NULL DEFAULT '0.00',
  `total_provisions` decimal(18,2) NOT NULL DEFAULT '0.00',
  `payment_method` varchar(20) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `bank_account` varchar(34) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `notes` text COLLATE utf8mb4_unicode_ci,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `payroll_entry_unique` (`payroll_period_id`,`employee_id`),
  KEY `payroll_entries_cost_center_id_foreign` (`cost_center_id`),
  KEY `payroll_entries_employee_id_index` (`employee_id`),
  CONSTRAINT `payroll_entries_cost_center_id_foreign` FOREIGN KEY (`cost_center_id`) REFERENCES `cost_centers` (`id`) ON DELETE SET NULL,
  CONSTRAINT `payroll_entries_employee_id_foreign` FOREIGN KEY (`employee_id`) REFERENCES `employees` (`id`),
  CONSTRAINT `payroll_entries_payroll_period_id_foreign` FOREIGN KEY (`payroll_period_id`) REFERENCES `payroll_periods` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `payroll_entries`
--

LOCK TABLES `payroll_entries` WRITE;
/*!40000 ALTER TABLE `payroll_entries` DISABLE KEYS */;
/*!40000 ALTER TABLE `payroll_entries` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `payroll_entry_lines`
--

DROP TABLE IF EXISTS `payroll_entry_lines`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `payroll_entry_lines` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `payroll_entry_id` bigint unsigned NOT NULL,
  `line_number` int unsigned NOT NULL,
  `kind` varchar(30) COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'earning|employee_contribution|income_tax|deduction|employer_contribution|provision',
  `code` varchar(30) COLLATE utf8mb4_unicode_ci NOT NULL,
  `name` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `payroll_concept_id` bigint unsigned DEFAULT NULL,
  `payroll_contribution_id` bigint unsigned DEFAULT NULL,
  `payroll_provision_id` bigint unsigned DEFAULT NULL,
  `base_amount` decimal(18,2) DEFAULT NULL,
  `rate` decimal(8,4) DEFAULT NULL,
  `quantity` decimal(12,4) DEFAULT NULL COMMENT 'horas o días, si aplica',
  `amount` decimal(18,2) NOT NULL,
  `account_id` bigint unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `payroll_entry_lines_payroll_concept_id_foreign` (`payroll_concept_id`),
  KEY `payroll_entry_lines_payroll_contribution_id_foreign` (`payroll_contribution_id`),
  KEY `payroll_entry_lines_payroll_provision_id_foreign` (`payroll_provision_id`),
  KEY `payroll_entry_lines_account_id_foreign` (`account_id`),
  KEY `payroll_entry_lines_payroll_entry_id_kind_index` (`payroll_entry_id`,`kind`),
  CONSTRAINT `payroll_entry_lines_account_id_foreign` FOREIGN KEY (`account_id`) REFERENCES `chart_of_accounts` (`id`) ON DELETE SET NULL,
  CONSTRAINT `payroll_entry_lines_payroll_concept_id_foreign` FOREIGN KEY (`payroll_concept_id`) REFERENCES `payroll_concepts` (`id`) ON DELETE SET NULL,
  CONSTRAINT `payroll_entry_lines_payroll_contribution_id_foreign` FOREIGN KEY (`payroll_contribution_id`) REFERENCES `payroll_contributions` (`id`) ON DELETE SET NULL,
  CONSTRAINT `payroll_entry_lines_payroll_entry_id_foreign` FOREIGN KEY (`payroll_entry_id`) REFERENCES `payroll_entries` (`id`) ON DELETE CASCADE,
  CONSTRAINT `payroll_entry_lines_payroll_provision_id_foreign` FOREIGN KEY (`payroll_provision_id`) REFERENCES `payroll_provisions` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `payroll_entry_lines`
--

LOCK TABLES `payroll_entry_lines` WRITE;
/*!40000 ALTER TABLE `payroll_entry_lines` DISABLE KEYS */;
/*!40000 ALTER TABLE `payroll_entry_lines` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `payroll_inputs`
--

DROP TABLE IF EXISTS `payroll_inputs`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `payroll_inputs` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `company_id` bigint unsigned NOT NULL,
  `payroll_period_id` bigint unsigned NOT NULL,
  `employee_id` bigint unsigned NOT NULL,
  `payroll_concept_id` bigint unsigned NOT NULL,
  `amount` decimal(18,2) DEFAULT NULL,
  `quantity` decimal(12,4) DEFAULT NULL,
  `notes` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'de dónde salió el dato',
  `created_by` bigint unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `payroll_inputs_company_id_foreign` (`company_id`),
  KEY `payroll_inputs_employee_id_foreign` (`employee_id`),
  KEY `payroll_inputs_payroll_concept_id_foreign` (`payroll_concept_id`),
  KEY `payroll_inputs_created_by_foreign` (`created_by`),
  KEY `payroll_inputs_payroll_period_id_employee_id_index` (`payroll_period_id`,`employee_id`),
  CONSTRAINT `payroll_inputs_company_id_foreign` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`) ON DELETE CASCADE,
  CONSTRAINT `payroll_inputs_created_by_foreign` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `payroll_inputs_employee_id_foreign` FOREIGN KEY (`employee_id`) REFERENCES `employees` (`id`) ON DELETE CASCADE,
  CONSTRAINT `payroll_inputs_payroll_concept_id_foreign` FOREIGN KEY (`payroll_concept_id`) REFERENCES `payroll_concepts` (`id`) ON DELETE CASCADE,
  CONSTRAINT `payroll_inputs_payroll_period_id_foreign` FOREIGN KEY (`payroll_period_id`) REFERENCES `payroll_periods` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `payroll_inputs`
--

LOCK TABLES `payroll_inputs` WRITE;
/*!40000 ALTER TABLE `payroll_inputs` DISABLE KEYS */;
/*!40000 ALTER TABLE `payroll_inputs` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `payroll_period_events`
--

DROP TABLE IF EXISTS `payroll_period_events`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `payroll_period_events` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `company_id` bigint unsigned NOT NULL,
  `payroll_period_id` bigint unsigned NOT NULL,
  `event` varchar(30) COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'calculated|approved|posted|reopened|voided|closed',
  `from_status` varchar(20) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `to_status` varchar(20) COLLATE utf8mb4_unicode_ci NOT NULL,
  `reason` text COLLATE utf8mb4_unicode_ci,
  `journal_entry_id` bigint unsigned DEFAULT NULL,
  `created_by` bigint unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `payroll_period_events_company_id_foreign` (`company_id`),
  KEY `payroll_period_events_journal_entry_id_foreign` (`journal_entry_id`),
  KEY `payroll_period_events_created_by_foreign` (`created_by`),
  KEY `payroll_period_events_payroll_period_id_created_at_index` (`payroll_period_id`,`created_at`),
  CONSTRAINT `payroll_period_events_company_id_foreign` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`) ON DELETE CASCADE,
  CONSTRAINT `payroll_period_events_created_by_foreign` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `payroll_period_events_journal_entry_id_foreign` FOREIGN KEY (`journal_entry_id`) REFERENCES `journal_entries` (`id`) ON DELETE SET NULL,
  CONSTRAINT `payroll_period_events_payroll_period_id_foreign` FOREIGN KEY (`payroll_period_id`) REFERENCES `payroll_periods` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `payroll_period_events`
--

LOCK TABLES `payroll_period_events` WRITE;
/*!40000 ALTER TABLE `payroll_period_events` DISABLE KEYS */;
/*!40000 ALTER TABLE `payroll_period_events` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `payroll_periods`
--

DROP TABLE IF EXISTS `payroll_periods`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `payroll_periods` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `company_id` bigint unsigned NOT NULL,
  `year` smallint unsigned NOT NULL,
  `frequency` varchar(20) COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'quincenal|mensual|semanal',
  `number` smallint unsigned NOT NULL COMMENT 'número del período dentro del año',
  `name` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `start_date` date NOT NULL,
  `end_date` date NOT NULL,
  `payment_date` date NOT NULL,
  `status` varchar(20) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'open' COMMENT 'open|calculated|approved|posted|closed',
  `journal_entry_id` bigint unsigned DEFAULT NULL,
  `reversal_journal_entry_id` bigint unsigned DEFAULT NULL,
  `document_type_id` bigint unsigned DEFAULT NULL,
  `calculated_at` timestamp NULL DEFAULT NULL,
  `approved_at` timestamp NULL DEFAULT NULL,
  `approved_by` bigint unsigned DEFAULT NULL,
  `created_by` bigint unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `payroll_period_unique` (`company_id`,`year`,`frequency`,`number`),
  KEY `payroll_periods_journal_entry_id_foreign` (`journal_entry_id`),
  KEY `payroll_periods_document_type_id_foreign` (`document_type_id`),
  KEY `payroll_periods_approved_by_foreign` (`approved_by`),
  KEY `payroll_periods_created_by_foreign` (`created_by`),
  KEY `payroll_periods_company_id_status_index` (`company_id`,`status`),
  KEY `payroll_periods_reversal_journal_entry_id_foreign` (`reversal_journal_entry_id`),
  CONSTRAINT `payroll_periods_approved_by_foreign` FOREIGN KEY (`approved_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `payroll_periods_company_id_foreign` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`) ON DELETE CASCADE,
  CONSTRAINT `payroll_periods_created_by_foreign` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `payroll_periods_document_type_id_foreign` FOREIGN KEY (`document_type_id`) REFERENCES `document_types` (`id`) ON DELETE SET NULL,
  CONSTRAINT `payroll_periods_journal_entry_id_foreign` FOREIGN KEY (`journal_entry_id`) REFERENCES `journal_entries` (`id`) ON DELETE SET NULL,
  CONSTRAINT `payroll_periods_reversal_journal_entry_id_foreign` FOREIGN KEY (`reversal_journal_entry_id`) REFERENCES `journal_entries` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=2 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `payroll_periods`
--

LOCK TABLES `payroll_periods` WRITE;
/*!40000 ALTER TABLE `payroll_periods` DISABLE KEYS */;
INSERT INTO `payroll_periods` VALUES (1,603,2026,'quincenal',1,'Septiembre 2026 · 2.ª quincena','2026-09-16','2026-09-30','2026-09-30','open',NULL,NULL,NULL,NULL,NULL,NULL,358,'2026-09-24 04:05:58','2026-09-24 04:05:58');
/*!40000 ALTER TABLE `payroll_periods` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `payroll_provisions`
--

DROP TABLE IF EXISTS `payroll_provisions`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `payroll_provisions` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `company_id` bigint unsigned NOT NULL,
  `code` varchar(30) COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'aguinaldo|vacaciones|cesantia|preaviso',
  `name` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `percentage` decimal(8,4) NOT NULL COMMENT 'sobre el salario devengado del período',
  `expense_account_id` bigint unsigned DEFAULT NULL,
  `liability_account_id` bigint unsigned DEFAULT NULL,
  `valid_from` date NOT NULL,
  `valid_to` date DEFAULT NULL,
  `status` varchar(20) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'active',
  `legal_basis` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `payroll_provision_unique` (`company_id`,`code`,`valid_from`),
  KEY `payroll_provisions_expense_account_id_foreign` (`expense_account_id`),
  KEY `payroll_provisions_liability_account_id_foreign` (`liability_account_id`),
  CONSTRAINT `payroll_provisions_company_id_foreign` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`) ON DELETE CASCADE,
  CONSTRAINT `payroll_provisions_expense_account_id_foreign` FOREIGN KEY (`expense_account_id`) REFERENCES `chart_of_accounts` (`id`) ON DELETE SET NULL,
  CONSTRAINT `payroll_provisions_liability_account_id_foreign` FOREIGN KEY (`liability_account_id`) REFERENCES `chart_of_accounts` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=5 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `payroll_provisions`
--

LOCK TABLES `payroll_provisions` WRITE;
/*!40000 ALTER TABLE `payroll_provisions` DISABLE KEYS */;
INSERT INTO `payroll_provisions` VALUES (1,603,'aguinaldo','Provisión de aguinaldo',8.3333,NULL,NULL,'2026-01-01',NULL,'active','Ley n.º 2412 y CT art. 611 — un doceavo del salario devengado','2026-09-24 03:54:40','2026-09-24 03:54:40'),(2,603,'vacaciones','Provisión de vacaciones',4.1667,NULL,NULL,'2026-01-01',NULL,'active','Código de Trabajo art. 153 y 156','2026-09-24 03:54:40','2026-09-24 03:54:40'),(3,603,'cesantia','Provisión de cesantía',5.3333,NULL,NULL,'2026-01-01',NULL,'active','Código de Trabajo art. 29 — aproximación; la liquidación real usa la tabla por antigüedad','2026-09-24 03:54:40','2026-09-24 03:54:40'),(4,603,'preaviso','Provisión de preaviso',0.0000,NULL,NULL,'2026-01-01',NULL,'active','Código de Trabajo art. 28 — solo aplica en despido sin causa sin aviso previo','2026-09-24 03:54:40','2026-09-24 03:54:40');
/*!40000 ALTER TABLE `payroll_provisions` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `payroll_settings`
--

DROP TABLE IF EXISTS `payroll_settings`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `payroll_settings` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `company_id` bigint unsigned NOT NULL,
  `salary_expense_account_id` bigint unsigned DEFAULT NULL,
  `net_payable_account_id` bigint unsigned DEFAULT NULL,
  `income_tax_payable_account_id` bigint unsigned DEFAULT NULL,
  `document_type_id` bigint unsigned DEFAULT NULL,
  `vacation_days_per_month` decimal(6,4) NOT NULL DEFAULT '1.0000',
  `vacation_average_basis` varchar(20) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'practice' COMMENT 'practice: 2 semanas si es semanal, 6 meses si no | legal_50_weeks: CT art. 157',
  `max_deduction_percentage` decimal(5,2) NOT NULL DEFAULT '0.00',
  `income_tax_mode` varchar(20) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'accumulated' COMMENT 'accumulated|projected',
  `income_tax_base` varchar(30) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'gross' COMMENT 'gross|net_of_contributions',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `payroll_settings_company_id_unique` (`company_id`),
  KEY `payroll_settings_salary_expense_account_id_foreign` (`salary_expense_account_id`),
  KEY `payroll_settings_net_payable_account_id_foreign` (`net_payable_account_id`),
  KEY `payroll_settings_income_tax_payable_account_id_foreign` (`income_tax_payable_account_id`),
  KEY `payroll_settings_document_type_id_foreign` (`document_type_id`),
  CONSTRAINT `payroll_settings_company_id_foreign` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`) ON DELETE CASCADE,
  CONSTRAINT `payroll_settings_document_type_id_foreign` FOREIGN KEY (`document_type_id`) REFERENCES `document_types` (`id`) ON DELETE SET NULL,
  CONSTRAINT `payroll_settings_income_tax_payable_account_id_foreign` FOREIGN KEY (`income_tax_payable_account_id`) REFERENCES `chart_of_accounts` (`id`) ON DELETE SET NULL,
  CONSTRAINT `payroll_settings_net_payable_account_id_foreign` FOREIGN KEY (`net_payable_account_id`) REFERENCES `chart_of_accounts` (`id`) ON DELETE SET NULL,
  CONSTRAINT `payroll_settings_salary_expense_account_id_foreign` FOREIGN KEY (`salary_expense_account_id`) REFERENCES `chart_of_accounts` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `payroll_settings`
--

LOCK TABLES `payroll_settings` WRITE;
/*!40000 ALTER TABLE `payroll_settings` DISABLE KEYS */;
/*!40000 ALTER TABLE `payroll_settings` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `payroll_tax_brackets`
--

DROP TABLE IF EXISTS `payroll_tax_brackets`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `payroll_tax_brackets` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `company_id` bigint unsigned NOT NULL,
  `valid_from` date NOT NULL,
  `valid_to` date DEFAULT NULL,
  `bracket_number` smallint unsigned NOT NULL,
  `from_amount` decimal(18,2) NOT NULL,
  `to_amount` decimal(18,2) DEFAULT NULL,
  `percentage` decimal(8,4) NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `payroll_bracket_unique` (`company_id`,`valid_from`,`bracket_number`),
  CONSTRAINT `payroll_tax_brackets_company_id_foreign` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=6 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `payroll_tax_brackets`
--

LOCK TABLES `payroll_tax_brackets` WRITE;
/*!40000 ALTER TABLE `payroll_tax_brackets` DISABLE KEYS */;
INSERT INTO `payroll_tax_brackets` VALUES (1,603,'2026-01-01',NULL,1,0.00,929000.00,0.0000,'2026-09-24 03:54:40','2026-09-24 03:54:40'),(2,603,'2026-01-01',NULL,2,929000.00,1363000.00,10.0000,'2026-09-24 03:54:40','2026-09-24 03:54:40'),(3,603,'2026-01-01',NULL,3,1363000.00,2392000.00,15.0000,'2026-09-24 03:54:40','2026-09-24 03:54:40'),(4,603,'2026-01-01',NULL,4,2392000.00,4783000.00,20.0000,'2026-09-24 03:54:40','2026-09-24 03:54:40'),(5,603,'2026-01-01',NULL,5,4783000.00,NULL,25.0000,'2026-09-24 03:54:40','2026-09-24 03:54:40');
/*!40000 ALTER TABLE `payroll_tax_brackets` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `payroll_tax_credits`
--

DROP TABLE IF EXISTS `payroll_tax_credits`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `payroll_tax_credits` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `company_id` bigint unsigned NOT NULL,
  `code` varchar(20) COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'spouse|child',
  `name` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `monthly_amount` decimal(18,2) NOT NULL,
  `valid_from` date NOT NULL,
  `valid_to` date DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `payroll_credit_unique` (`company_id`,`code`,`valid_from`),
  CONSTRAINT `payroll_tax_credits_company_id_foreign` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=3 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `payroll_tax_credits`
--

LOCK TABLES `payroll_tax_credits` WRITE;
/*!40000 ALTER TABLE `payroll_tax_credits` DISABLE KEYS */;
INSERT INTO `payroll_tax_credits` VALUES (1,603,'spouse','Crédito por cónyuge',4000.00,'2026-01-01',NULL,'2026-09-24 03:54:40','2026-09-24 03:54:40'),(2,603,'child','Crédito por hijo',2600.00,'2026-01-01',NULL,'2026-09-24 03:54:40','2026-09-24 03:54:40');
/*!40000 ALTER TABLE `payroll_tax_credits` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `period_closing_processes`
--

DROP TABLE IF EXISTS `period_closing_processes`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `period_closing_processes` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `company_id` bigint unsigned NOT NULL,
  `fiscal_period_id` bigint unsigned DEFAULT NULL,
  `fiscal_year_id` bigint unsigned DEFAULT NULL,
  `type` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'month_close|month_reopen|year_close',
  `document_type_id` bigint unsigned DEFAULT NULL,
  `journal_entry_id` bigint unsigned DEFAULT NULL,
  `executed_by` bigint unsigned DEFAULT NULL,
  `executed_at` timestamp NULL DEFAULT NULL,
  `status` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'completed',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `period_closing_processes_company_id_foreign` (`company_id`),
  KEY `period_closing_processes_fiscal_period_id_foreign` (`fiscal_period_id`),
  KEY `period_closing_processes_fiscal_year_id_foreign` (`fiscal_year_id`),
  KEY `period_closing_processes_document_type_id_foreign` (`document_type_id`),
  KEY `period_closing_processes_journal_entry_id_foreign` (`journal_entry_id`),
  KEY `period_closing_processes_executed_by_foreign` (`executed_by`),
  CONSTRAINT `period_closing_processes_company_id_foreign` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`) ON DELETE CASCADE,
  CONSTRAINT `period_closing_processes_document_type_id_foreign` FOREIGN KEY (`document_type_id`) REFERENCES `document_types` (`id`),
  CONSTRAINT `period_closing_processes_executed_by_foreign` FOREIGN KEY (`executed_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `period_closing_processes_fiscal_period_id_foreign` FOREIGN KEY (`fiscal_period_id`) REFERENCES `fiscal_periods` (`id`),
  CONSTRAINT `period_closing_processes_fiscal_year_id_foreign` FOREIGN KEY (`fiscal_year_id`) REFERENCES `fiscal_years` (`id`),
  CONSTRAINT `period_closing_processes_journal_entry_id_foreign` FOREIGN KEY (`journal_entry_id`) REFERENCES `journal_entries` (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=11 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `period_closing_processes`
--

LOCK TABLES `period_closing_processes` WRITE;
/*!40000 ALTER TABLE `period_closing_processes` DISABLE KEYS */;
/*!40000 ALTER TABLE `period_closing_processes` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `personnel_actions`
--

DROP TABLE IF EXISTS `personnel_actions`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `personnel_actions` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `company_id` bigint unsigned NOT NULL,
  `employee_id` bigint unsigned NOT NULL,
  `action_type` varchar(40) COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'hire|salary_change|position_change|cost_center_change|journey_change|suspension|reinstatement|termination',
  `effective_date` date NOT NULL,
  `previous_value` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `new_value` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `field` varchar(40) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'campo de la ficha que cambia',
  `reason` text COLLATE utf8mb4_unicode_ci,
  `status` varchar(20) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'draft' COMMENT 'draft|approved|applied|cancelled',
  `requested_by` bigint unsigned DEFAULT NULL,
  `approved_by` bigint unsigned DEFAULT NULL,
  `approved_at` timestamp NULL DEFAULT NULL,
  `applied_at` timestamp NULL DEFAULT NULL,
  `notes` text COLLATE utf8mb4_unicode_ci,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `personnel_actions_employee_id_foreign` (`employee_id`),
  KEY `personnel_actions_requested_by_foreign` (`requested_by`),
  KEY `personnel_actions_approved_by_foreign` (`approved_by`),
  KEY `personnel_actions_company_id_employee_id_effective_date_index` (`company_id`,`employee_id`,`effective_date`),
  KEY `personnel_actions_company_id_status_index` (`company_id`,`status`),
  CONSTRAINT `personnel_actions_approved_by_foreign` FOREIGN KEY (`approved_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `personnel_actions_company_id_foreign` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`) ON DELETE CASCADE,
  CONSTRAINT `personnel_actions_employee_id_foreign` FOREIGN KEY (`employee_id`) REFERENCES `employees` (`id`) ON DELETE CASCADE,
  CONSTRAINT `personnel_actions_requested_by_foreign` FOREIGN KEY (`requested_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `personnel_actions`
--

LOCK TABLES `personnel_actions` WRITE;
/*!40000 ALTER TABLE `personnel_actions` DISABLE KEYS */;
/*!40000 ALTER TABLE `personnel_actions` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `price_list_items`
--

DROP TABLE IF EXISTS `price_list_items`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `price_list_items` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `price_list_id` bigint unsigned NOT NULL,
  `item_id` bigint unsigned NOT NULL,
  `unit_price` decimal(18,5) NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `price_list_items_price_list_id_item_id_unique` (`price_list_id`,`item_id`),
  KEY `price_list_items_item_id_foreign` (`item_id`),
  CONSTRAINT `price_list_items_item_id_foreign` FOREIGN KEY (`item_id`) REFERENCES `items` (`id`),
  CONSTRAINT `price_list_items_price_list_id_foreign` FOREIGN KEY (`price_list_id`) REFERENCES `price_lists` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `price_list_items`
--

LOCK TABLES `price_list_items` WRITE;
/*!40000 ALTER TABLE `price_list_items` DISABLE KEYS */;
/*!40000 ALTER TABLE `price_list_items` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `price_lists`
--

DROP TABLE IF EXISTS `price_lists`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `price_lists` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `company_id` bigint unsigned NOT NULL,
  `code` varchar(20) COLLATE utf8mb4_unicode_ci NOT NULL,
  `name` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `currency_id` bigint unsigned NOT NULL,
  `prices_include_tax` tinyint(1) NOT NULL DEFAULT '0',
  `valid_from` date DEFAULT NULL,
  `valid_to` date DEFAULT NULL,
  `is_default` tinyint(1) NOT NULL DEFAULT '0',
  `status` varchar(20) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'active',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `price_lists_company_id_code_unique` (`company_id`,`code`),
  KEY `price_lists_currency_id_foreign` (`currency_id`),
  KEY `price_lists_company_id_status_index` (`company_id`,`status`),
  CONSTRAINT `price_lists_company_id_foreign` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`) ON DELETE CASCADE,
  CONSTRAINT `price_lists_currency_id_foreign` FOREIGN KEY (`currency_id`) REFERENCES `currencies` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `price_lists`
--

LOCK TABLES `price_lists` WRITE;
/*!40000 ALTER TABLE `price_lists` DISABLE KEYS */;
/*!40000 ALTER TABLE `price_lists` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `price_override_authorizations`
--

DROP TABLE IF EXISTS `price_override_authorizations`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `price_override_authorizations` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `company_id` bigint unsigned NOT NULL,
  `sales_document_id` bigint unsigned DEFAULT NULL,
  `sales_order_id` bigint unsigned DEFAULT NULL,
  `sales_document_line_id` bigint unsigned DEFAULT NULL,
  `sales_order_line_id` bigint unsigned DEFAULT NULL,
  `item_id` bigint unsigned DEFAULT NULL,
  `price_list_id` bigint unsigned DEFAULT NULL,
  `price_list_code` varchar(20) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `list_unit_price` decimal(18,5) NOT NULL,
  `invoiced_unit_price` decimal(18,5) NOT NULL,
  `difference` decimal(18,5) NOT NULL COMMENT 'facturado - lista; negativo = se vendió más barato',
  `requested_by` bigint unsigned DEFAULT NULL,
  `authorized_by` bigint unsigned NOT NULL,
  `reason` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `price_override_authorizations_sales_document_id_foreign` (`sales_document_id`),
  KEY `price_override_authorizations_sales_document_line_id_foreign` (`sales_document_line_id`),
  KEY `price_override_authorizations_item_id_foreign` (`item_id`),
  KEY `price_override_authorizations_price_list_id_foreign` (`price_list_id`),
  KEY `price_override_authorizations_requested_by_foreign` (`requested_by`),
  KEY `price_override_authorizations_authorized_by_foreign` (`authorized_by`),
  KEY `price_override_authorizations_company_id_created_at_index` (`company_id`,`created_at`),
  KEY `price_override_authorizations_company_id_authorized_by_index` (`company_id`,`authorized_by`),
  KEY `price_override_authorizations_sales_order_line_id_foreign` (`sales_order_line_id`),
  KEY `price_override_order_item_index` (`sales_order_id`,`item_id`),
  CONSTRAINT `price_override_authorizations_authorized_by_foreign` FOREIGN KEY (`authorized_by`) REFERENCES `users` (`id`),
  CONSTRAINT `price_override_authorizations_company_id_foreign` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`) ON DELETE CASCADE,
  CONSTRAINT `price_override_authorizations_item_id_foreign` FOREIGN KEY (`item_id`) REFERENCES `items` (`id`) ON DELETE SET NULL,
  CONSTRAINT `price_override_authorizations_price_list_id_foreign` FOREIGN KEY (`price_list_id`) REFERENCES `price_lists` (`id`) ON DELETE SET NULL,
  CONSTRAINT `price_override_authorizations_requested_by_foreign` FOREIGN KEY (`requested_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `price_override_authorizations_sales_document_id_foreign` FOREIGN KEY (`sales_document_id`) REFERENCES `sales_documents` (`id`) ON DELETE CASCADE,
  CONSTRAINT `price_override_authorizations_sales_document_line_id_foreign` FOREIGN KEY (`sales_document_line_id`) REFERENCES `sales_document_lines` (`id`) ON DELETE SET NULL,
  CONSTRAINT `price_override_authorizations_sales_order_id_foreign` FOREIGN KEY (`sales_order_id`) REFERENCES `sales_orders` (`id`) ON DELETE CASCADE,
  CONSTRAINT `price_override_authorizations_sales_order_line_id_foreign` FOREIGN KEY (`sales_order_line_id`) REFERENCES `sales_order_lines` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `price_override_authorizations`
--

LOCK TABLES `price_override_authorizations` WRITE;
/*!40000 ALTER TABLE `price_override_authorizations` DISABLE KEYS */;
/*!40000 ALTER TABLE `price_override_authorizations` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `production_orders`
--

DROP TABLE IF EXISTS `production_orders`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `production_orders` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `company_id` bigint unsigned NOT NULL,
  `item_id` bigint unsigned NOT NULL,
  `bill_of_material_id` bigint unsigned DEFAULT NULL,
  `warehouse_id` bigint unsigned NOT NULL,
  `planned_quantity` decimal(18,6) NOT NULL,
  `produced_quantity` decimal(18,6) NOT NULL DEFAULT '0.000000',
  `order_date` date NOT NULL,
  `description` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `status` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'open' COMMENT 'open|closed',
  `variance_journal_entry_id` bigint unsigned DEFAULT NULL,
  `closed_at` timestamp NULL DEFAULT NULL,
  `created_by` bigint unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `production_orders_item_id_foreign` (`item_id`),
  KEY `production_orders_warehouse_id_foreign` (`warehouse_id`),
  KEY `production_orders_variance_journal_entry_id_foreign` (`variance_journal_entry_id`),
  KEY `production_orders_created_by_foreign` (`created_by`),
  KEY `production_orders_company_id_status_index` (`company_id`,`status`),
  KEY `production_orders_bill_of_material_id_foreign` (`bill_of_material_id`),
  CONSTRAINT `production_orders_bill_of_material_id_foreign` FOREIGN KEY (`bill_of_material_id`) REFERENCES `bills_of_materials` (`id`) ON DELETE SET NULL,
  CONSTRAINT `production_orders_company_id_foreign` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`) ON DELETE CASCADE,
  CONSTRAINT `production_orders_created_by_foreign` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `production_orders_item_id_foreign` FOREIGN KEY (`item_id`) REFERENCES `items` (`id`),
  CONSTRAINT `production_orders_variance_journal_entry_id_foreign` FOREIGN KEY (`variance_journal_entry_id`) REFERENCES `journal_entries` (`id`) ON DELETE SET NULL,
  CONSTRAINT `production_orders_warehouse_id_foreign` FOREIGN KEY (`warehouse_id`) REFERENCES `warehouses` (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=5 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `production_orders`
--

LOCK TABLES `production_orders` WRITE;
/*!40000 ALTER TABLE `production_orders` DISABLE KEYS */;
INSERT INTO `production_orders` VALUES (1,604,15,NULL,3,5.000000,0.000000,'2026-09-21','resmas','open',NULL,NULL,359,'2026-09-21 03:59:10','2026-09-21 03:59:10'),(2,604,13,1,3,30.000000,0.000000,'2026-09-23','EJEMPLO 1 — lista para emitir: probá el botón \"Emitir materia prima\"','open',NULL,NULL,NULL,'2026-09-23 03:54:07','2026-09-23 03:54:07'),(3,604,13,1,3,10.000000,10.000000,'2026-09-13','EJEMPLO 2 — ciclo completo: emitida, recibida y cerrada sin desviación','closed',NULL,'2026-09-23 03:54:08',NULL,'2026-09-23 03:54:07','2026-09-23 03:54:08'),(4,604,14,3,3,5.000000,0.000000,'2026-09-17','EJEMPLO 3 — tanda fallida: se cerró sin producto y el costo fue a desviación','closed',365,'2026-09-23 03:54:08',NULL,'2026-09-23 03:54:08','2026-09-23 03:54:08');
/*!40000 ALTER TABLE `production_orders` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `propietarios`
--

DROP TABLE IF EXISTS `propietarios`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `propietarios` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `email` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `password` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `remember_token` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `propietarios_email_unique` (`email`)
) ENGINE=InnoDB AUTO_INCREMENT=27 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `propietarios`
--

LOCK TABLES `propietarios` WRITE;
/*!40000 ALTER TABLE `propietarios` DISABLE KEYS */;
INSERT INTO `propietarios` VALUES (26,'NCode Digital','alexandernaranjobrenes@gmail.com','$2y$12$5ciF5Pdd9pwitV/OotH6Le204c8Mdyai/I/lecqFexXYjY..pVOaq',NULL,'2026-09-01 02:23:56','2026-09-01 02:23:56');
/*!40000 ALTER TABLE `propietarios` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `purchase_order_lines`
--

DROP TABLE IF EXISTS `purchase_order_lines`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `purchase_order_lines` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `purchase_order_id` bigint unsigned NOT NULL,
  `line_number` int unsigned NOT NULL,
  `item_id` bigint unsigned NOT NULL,
  `warehouse_id` bigint unsigned NOT NULL,
  `quantity` decimal(18,6) NOT NULL,
  `quantity_received` decimal(18,6) NOT NULL DEFAULT '0.000000' COMMENT 'lo ya recibido; el pendiente vivo es quantity - quantity_received',
  `unit_cost_local` decimal(18,6) NOT NULL DEFAULT '0.000000' COMMENT 'costo pactado, informativo: la recepción manda',
  `description` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `purchase_order_lines_purchase_order_id_foreign` (`purchase_order_id`),
  KEY `purchase_order_lines_warehouse_id_foreign` (`warehouse_id`),
  KEY `purchase_order_lines_item_id_warehouse_id_index` (`item_id`,`warehouse_id`),
  CONSTRAINT `purchase_order_lines_item_id_foreign` FOREIGN KEY (`item_id`) REFERENCES `items` (`id`),
  CONSTRAINT `purchase_order_lines_purchase_order_id_foreign` FOREIGN KEY (`purchase_order_id`) REFERENCES `purchase_orders` (`id`) ON DELETE CASCADE,
  CONSTRAINT `purchase_order_lines_warehouse_id_foreign` FOREIGN KEY (`warehouse_id`) REFERENCES `warehouses` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `purchase_order_lines`
--

LOCK TABLES `purchase_order_lines` WRITE;
/*!40000 ALTER TABLE `purchase_order_lines` DISABLE KEYS */;
/*!40000 ALTER TABLE `purchase_order_lines` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `purchase_orders`
--

DROP TABLE IF EXISTS `purchase_orders`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `purchase_orders` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `company_id` bigint unsigned NOT NULL,
  `number` varchar(20) COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'consecutivo interno por compañía; no es fiscal',
  `business_partner_id` bigint unsigned NOT NULL,
  `order_date` date NOT NULL,
  `expected_date` date DEFAULT NULL,
  `status` varchar(20) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'open' COMMENT 'open|partially_received|received|cancelled|closed',
  `description` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_by` bigint unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `purchase_orders_company_id_number_unique` (`company_id`,`number`),
  KEY `purchase_orders_business_partner_id_foreign` (`business_partner_id`),
  KEY `purchase_orders_created_by_foreign` (`created_by`),
  KEY `purchase_orders_company_id_status_index` (`company_id`,`status`),
  CONSTRAINT `purchase_orders_business_partner_id_foreign` FOREIGN KEY (`business_partner_id`) REFERENCES `business_partners` (`id`),
  CONSTRAINT `purchase_orders_company_id_foreign` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`) ON DELETE CASCADE,
  CONSTRAINT `purchase_orders_created_by_foreign` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `purchase_orders`
--

LOCK TABLES `purchase_orders` WRITE;
/*!40000 ALTER TABLE `purchase_orders` DISABLE KEYS */;
/*!40000 ALTER TABLE `purchase_orders` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `roles`
--

DROP TABLE IF EXISTS `roles`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `roles` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `company_id` bigint unsigned DEFAULT NULL,
  `name` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `type` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'super_admin|admin|user',
  `can_grant_permissions` tinyint(1) NOT NULL DEFAULT '0' COMMENT 'delega la facultad de otorgar derechos, nunca delete',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `roles_company_id_foreign` (`company_id`),
  CONSTRAINT `roles_company_id_foreign` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=18 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `roles`
--

LOCK TABLES `roles` WRITE;
/*!40000 ALTER TABLE `roles` DISABLE KEYS */;
INSERT INTO `roles` VALUES (16,NULL,'Administrador','admin',1,'2026-09-02 04:09:59','2026-09-02 04:09:59'),(17,NULL,'Usuario','user',0,'2026-09-02 04:12:15','2026-09-02 04:12:15');
/*!40000 ALTER TABLE `roles` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `sales_document_lines`
--

DROP TABLE IF EXISTS `sales_document_lines`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `sales_document_lines` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `sales_document_id` bigint unsigned NOT NULL,
  `line_number` int unsigned NOT NULL,
  `item_id` bigint unsigned DEFAULT NULL,
  `warehouse_id` bigint unsigned DEFAULT NULL,
  `warehouse_bin_id` bigint unsigned DEFAULT NULL,
  `item_code` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'código comercial',
  `cabys_code` varchar(13) COLLATE utf8mb4_unicode_ci NOT NULL,
  `description` varchar(200) COLLATE utf8mb4_unicode_ci NOT NULL,
  `unit_code` varchar(15) COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'Nota 15',
  `is_service` tinyint(1) NOT NULL DEFAULT '0' COMMENT 'separa servicios de mercancías en el resumen',
  `quantity` decimal(16,3) NOT NULL,
  `unit_price` decimal(18,5) NOT NULL,
  `total_amount` decimal(18,5) NOT NULL COMMENT 'cantidad × precio unitario',
  `discount_code` varchar(2) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'Nota 20',
  `discount_reason` varchar(80) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'obligatorio si el código es 99',
  `discount_amount` decimal(18,5) NOT NULL DEFAULT '0.00000',
  `subtotal` decimal(18,5) NOT NULL COMMENT 'total_amount − discount_amount',
  `tax_amount` decimal(18,5) NOT NULL DEFAULT '0.00000',
  `exonerated_amount` decimal(18,5) NOT NULL DEFAULT '0.00000',
  `line_total` decimal(18,5) NOT NULL COMMENT 'subtotal + impuesto neto de exoneración',
  `vin_or_serial` varchar(17) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'obligatorio si el CAByS es vehículo, aeronave o embarcación',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `sales_document_lines_item_id_foreign` (`item_id`),
  KEY `sales_document_lines_warehouse_id_foreign` (`warehouse_id`),
  KEY `sales_document_lines_warehouse_bin_id_foreign` (`warehouse_bin_id`),
  KEY `sales_document_lines_sales_document_id_line_number_index` (`sales_document_id`,`line_number`),
  CONSTRAINT `sales_document_lines_item_id_foreign` FOREIGN KEY (`item_id`) REFERENCES `items` (`id`) ON DELETE SET NULL,
  CONSTRAINT `sales_document_lines_sales_document_id_foreign` FOREIGN KEY (`sales_document_id`) REFERENCES `sales_documents` (`id`) ON DELETE CASCADE,
  CONSTRAINT `sales_document_lines_warehouse_bin_id_foreign` FOREIGN KEY (`warehouse_bin_id`) REFERENCES `warehouse_bins` (`id`) ON DELETE SET NULL,
  CONSTRAINT `sales_document_lines_warehouse_id_foreign` FOREIGN KEY (`warehouse_id`) REFERENCES `warehouses` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `sales_document_lines`
--

LOCK TABLES `sales_document_lines` WRITE;
/*!40000 ALTER TABLE `sales_document_lines` DISABLE KEYS */;
/*!40000 ALTER TABLE `sales_document_lines` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `sales_documents`
--

DROP TABLE IF EXISTS `sales_documents`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `sales_documents` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `company_id` bigint unsigned NOT NULL,
  `document_type_id` bigint unsigned NOT NULL,
  `fiscal_document_type` varchar(2) COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'Nota 3: 01 FE, 02 ND, 03 NC, 04 TE, 08 FEC, 09 FEE, 10 REP',
  `situation` varchar(1) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '1' COMMENT '1 normal, 2 contingencia, 3 sin internet',
  `branch` varchar(3) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '001',
  `terminal` varchar(5) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '00001',
  `consecutive` varchar(20) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'consecutivo fiscal de 20 dígitos',
  `clave` varchar(50) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'clave numérica de 50 dígitos',
  `security_code` varchar(8) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'dígitos 43-50 de la clave',
  `emitter_activity_code` varchar(6) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `receiver_activity_code` varchar(6) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `business_partner_id` bigint unsigned DEFAULT NULL,
  `currency_id` bigint unsigned NOT NULL,
  `exchange_rate` decimal(18,5) NOT NULL DEFAULT '1.00000' COMMENT '1.00000 cuando la moneda es la local',
  `sale_condition` varchar(2) COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'Nota 5',
  `credit_term_days` smallint unsigned DEFAULT NULL COMMENT 'obligatorio en condiciones a crédito',
  `document_date` date NOT NULL,
  `posting_date` date NOT NULL COMMENT 'fecha rectora del asiento',
  `due_date` date DEFAULT NULL,
  `total_taxed_services` decimal(18,5) NOT NULL DEFAULT '0.00000',
  `total_exempt_services` decimal(18,5) NOT NULL DEFAULT '0.00000',
  `total_exonerated_services` decimal(18,5) NOT NULL DEFAULT '0.00000',
  `total_no_subject_services` decimal(18,5) NOT NULL DEFAULT '0.00000',
  `total_taxed_goods` decimal(18,5) NOT NULL DEFAULT '0.00000',
  `total_exempt_goods` decimal(18,5) NOT NULL DEFAULT '0.00000',
  `total_exonerated_goods` decimal(18,5) NOT NULL DEFAULT '0.00000',
  `total_no_subject_goods` decimal(18,5) NOT NULL DEFAULT '0.00000',
  `total_sale` decimal(18,5) NOT NULL DEFAULT '0.00000',
  `total_discounts` decimal(18,5) NOT NULL DEFAULT '0.00000',
  `total_net_sale` decimal(18,5) NOT NULL DEFAULT '0.00000',
  `total_tax` decimal(18,5) NOT NULL DEFAULT '0.00000',
  `total_document` decimal(18,5) NOT NULL DEFAULT '0.00000',
  `notes` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `status` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'draft' COMMENT 'draft|posted|voided — el ciclo fiscal (firmado/enviado/aceptado) llega con el XML',
  `journal_entry_id` bigint unsigned DEFAULT NULL,
  `inventory_document_id` bigint unsigned DEFAULT NULL,
  `original_sales_document_id` bigint unsigned DEFAULT NULL,
  `sales_order_id` bigint unsigned DEFAULT NULL,
  `created_by` bigint unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `sales_documents_clave_unique` (`company_id`,`clave`),
  UNIQUE KEY `sales_documents_consecutive_unique` (`company_id`,`consecutive`),
  KEY `sales_documents_document_type_id_foreign` (`document_type_id`),
  KEY `sales_documents_business_partner_id_foreign` (`business_partner_id`),
  KEY `sales_documents_currency_id_foreign` (`currency_id`),
  KEY `sales_documents_journal_entry_id_foreign` (`journal_entry_id`),
  KEY `sales_documents_inventory_document_id_foreign` (`inventory_document_id`),
  KEY `sales_documents_created_by_foreign` (`created_by`),
  KEY `sales_documents_company_id_posting_date_index` (`company_id`,`posting_date`),
  KEY `sales_documents_original_sales_document_id_foreign` (`original_sales_document_id`),
  KEY `sales_documents_sales_order_id_foreign` (`sales_order_id`),
  CONSTRAINT `sales_documents_business_partner_id_foreign` FOREIGN KEY (`business_partner_id`) REFERENCES `business_partners` (`id`) ON DELETE SET NULL,
  CONSTRAINT `sales_documents_company_id_foreign` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`) ON DELETE CASCADE,
  CONSTRAINT `sales_documents_created_by_foreign` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `sales_documents_currency_id_foreign` FOREIGN KEY (`currency_id`) REFERENCES `currencies` (`id`),
  CONSTRAINT `sales_documents_document_type_id_foreign` FOREIGN KEY (`document_type_id`) REFERENCES `document_types` (`id`),
  CONSTRAINT `sales_documents_inventory_document_id_foreign` FOREIGN KEY (`inventory_document_id`) REFERENCES `inventory_documents` (`id`) ON DELETE SET NULL,
  CONSTRAINT `sales_documents_journal_entry_id_foreign` FOREIGN KEY (`journal_entry_id`) REFERENCES `journal_entries` (`id`) ON DELETE SET NULL,
  CONSTRAINT `sales_documents_original_sales_document_id_foreign` FOREIGN KEY (`original_sales_document_id`) REFERENCES `sales_documents` (`id`) ON DELETE SET NULL,
  CONSTRAINT `sales_documents_sales_order_id_foreign` FOREIGN KEY (`sales_order_id`) REFERENCES `sales_orders` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `sales_documents`
--

LOCK TABLES `sales_documents` WRITE;
/*!40000 ALTER TABLE `sales_documents` DISABLE KEYS */;
/*!40000 ALTER TABLE `sales_documents` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `sales_line_taxes`
--

DROP TABLE IF EXISTS `sales_line_taxes`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `sales_line_taxes` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `sales_document_line_id` bigint unsigned NOT NULL,
  `tax_code` varchar(2) COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'Nota 8: 01 IVA, 02 selectivo, 07 cálculo especial...',
  `iva_rate_code` varchar(2) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'Nota 8.1; solo cuando tax_code es de IVA',
  `rate_percentage` decimal(5,2) NOT NULL,
  `taxable_base` decimal(18,5) NOT NULL,
  `amount` decimal(18,5) NOT NULL COMMENT 'base × tarifa, antes de exoneración',
  `exoneration_document_type` varchar(2) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'Nota 10.1',
  `exoneration_document_number` varchar(40) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `exoneration_article` varchar(10) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `exoneration_clause` varchar(10) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `exoneration_institution` varchar(2) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'Nota 23',
  `exoneration_date` date DEFAULT NULL,
  `exonerated_percentage` decimal(5,2) DEFAULT NULL,
  `exonerated_amount` decimal(18,5) NOT NULL DEFAULT '0.00000',
  `net_amount` decimal(18,5) NOT NULL COMMENT 'amount − exonerated_amount: lo que de verdad se cobra',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `sales_line_taxes_sales_document_line_id_index` (`sales_document_line_id`),
  CONSTRAINT `sales_line_taxes_sales_document_line_id_foreign` FOREIGN KEY (`sales_document_line_id`) REFERENCES `sales_document_lines` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `sales_line_taxes`
--

LOCK TABLES `sales_line_taxes` WRITE;
/*!40000 ALTER TABLE `sales_line_taxes` DISABLE KEYS */;
/*!40000 ALTER TABLE `sales_line_taxes` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `sales_order_lines`
--

DROP TABLE IF EXISTS `sales_order_lines`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `sales_order_lines` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `sales_order_id` bigint unsigned NOT NULL,
  `line_number` int unsigned NOT NULL,
  `item_id` bigint unsigned NOT NULL,
  `warehouse_id` bigint unsigned NOT NULL,
  `quantity` decimal(18,6) NOT NULL,
  `quantity_invoiced` decimal(18,6) NOT NULL DEFAULT '0.000000' COMMENT 'lo ya facturado; la reserva viva es quantity - quantity_invoiced',
  `unit_price` decimal(18,5) NOT NULL DEFAULT '0.00000' COMMENT 'precio pactado, informativo: la factura manda',
  `description` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `sales_order_lines_sales_order_id_foreign` (`sales_order_id`),
  KEY `sales_order_lines_warehouse_id_foreign` (`warehouse_id`),
  KEY `sales_order_lines_item_id_warehouse_id_index` (`item_id`,`warehouse_id`),
  CONSTRAINT `sales_order_lines_item_id_foreign` FOREIGN KEY (`item_id`) REFERENCES `items` (`id`),
  CONSTRAINT `sales_order_lines_sales_order_id_foreign` FOREIGN KEY (`sales_order_id`) REFERENCES `sales_orders` (`id`) ON DELETE CASCADE,
  CONSTRAINT `sales_order_lines_warehouse_id_foreign` FOREIGN KEY (`warehouse_id`) REFERENCES `warehouses` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `sales_order_lines`
--

LOCK TABLES `sales_order_lines` WRITE;
/*!40000 ALTER TABLE `sales_order_lines` DISABLE KEYS */;
/*!40000 ALTER TABLE `sales_order_lines` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `sales_orders`
--

DROP TABLE IF EXISTS `sales_orders`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `sales_orders` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `company_id` bigint unsigned NOT NULL,
  `number` varchar(20) COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'consecutivo interno por compañía; no es fiscal',
  `business_partner_id` bigint unsigned NOT NULL,
  `order_date` date NOT NULL,
  `delivery_date` date DEFAULT NULL,
  `status` varchar(20) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'open' COMMENT 'open|invoiced|cancelled',
  `description` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_by` bigint unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `sales_orders_company_id_number_unique` (`company_id`,`number`),
  KEY `sales_orders_business_partner_id_foreign` (`business_partner_id`),
  KEY `sales_orders_created_by_foreign` (`created_by`),
  KEY `sales_orders_company_id_status_index` (`company_id`,`status`),
  CONSTRAINT `sales_orders_business_partner_id_foreign` FOREIGN KEY (`business_partner_id`) REFERENCES `business_partners` (`id`),
  CONSTRAINT `sales_orders_company_id_foreign` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`) ON DELETE CASCADE,
  CONSTRAINT `sales_orders_created_by_foreign` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `sales_orders`
--

LOCK TABLES `sales_orders` WRITE;
/*!40000 ALTER TABLE `sales_orders` DISABLE KEYS */;
/*!40000 ALTER TABLE `sales_orders` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `sales_payments`
--

DROP TABLE IF EXISTS `sales_payments`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `sales_payments` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `sales_document_id` bigint unsigned NOT NULL,
  `method_code` varchar(2) COLLATE utf8mb4_unicode_ci NOT NULL,
  `amount` decimal(18,5) NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `sales_payments_sales_document_id_index` (`sales_document_id`),
  CONSTRAINT `sales_payments_sales_document_id_foreign` FOREIGN KEY (`sales_document_id`) REFERENCES `sales_documents` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `sales_payments`
--

LOCK TABLES `sales_payments` WRITE;
/*!40000 ALTER TABLE `sales_payments` DISABLE KEYS */;
/*!40000 ALTER TABLE `sales_payments` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `sales_references`
--

DROP TABLE IF EXISTS `sales_references`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `sales_references` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `sales_document_id` bigint unsigned NOT NULL,
  `document_type` varchar(2) COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'Nota 10',
  `number` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'clave de 50 dígitos o número del documento físico',
  `issued_at` date DEFAULT NULL,
  `reason_code` varchar(2) COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'Nota 9',
  `reason` varchar(180) COLLATE utf8mb4_unicode_ci NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `sales_references_sales_document_id_index` (`sales_document_id`),
  CONSTRAINT `sales_references_sales_document_id_foreign` FOREIGN KEY (`sales_document_id`) REFERENCES `sales_documents` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `sales_references`
--

LOCK TABLES `sales_references` WRITE;
/*!40000 ALTER TABLE `sales_references` DISABLE KEYS */;
/*!40000 ALTER TABLE `sales_references` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `saved_reports`
--

DROP TABLE IF EXISTS `saved_reports`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `saved_reports` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `company_id` bigint unsigned NOT NULL,
  `user_id` bigint unsigned NOT NULL,
  `report_code` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `name` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `parameters` json NOT NULL,
  `is_shared` tinyint(1) NOT NULL DEFAULT '0',
  `last_run_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `saved_reports_user_id_foreign` (`user_id`),
  KEY `saved_reports_company_id_report_code_index` (`company_id`,`report_code`),
  CONSTRAINT `saved_reports_company_id_foreign` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`) ON DELETE CASCADE,
  CONSTRAINT `saved_reports_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=6 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `saved_reports`
--

LOCK TABLES `saved_reports` WRITE;
/*!40000 ALTER TABLE `saved_reports` DISABLE KEYS */;
/*!40000 ALTER TABLE `saved_reports` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `sessions`
--

DROP TABLE IF EXISTS `sessions`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `sessions` (
  `id` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `user_id` bigint unsigned DEFAULT NULL,
  `ip_address` varchar(45) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `user_agent` text COLLATE utf8mb4_unicode_ci,
  `payload` longtext COLLATE utf8mb4_unicode_ci NOT NULL,
  `last_activity` int NOT NULL,
  PRIMARY KEY (`id`),
  KEY `sessions_user_id_index` (`user_id`),
  KEY `sessions_last_activity_index` (`last_activity`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `sessions`
--

LOCK TABLES `sessions` WRITE;
/*!40000 ALTER TABLE `sessions` DISABLE KEYS */;
INSERT INTO `sessions` VALUES ('1QWJ3wnc7fwnfaCTMu6XScXwEbHtt88ZlfTFui1E',NULL,'172.18.0.1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/154.0.0.0 Safari/537.36','eyJfdG9rZW4iOiJFTnozWjZXekNFeGdoWkNYaTBCRFFnZ3Bxd0YwYXhqUTR2c3lyYTNGIiwiX2ZsYXNoIjp7Im9sZCI6W10sIm5ldyI6W119fQ==',1790742946),('qQajDZXLttTdpDRBR0OwuJTTXdIaxq2l3zm7hpEj',NULL,'172.18.0.1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/154.0.0.0 Safari/537.36','eyJfdG9rZW4iOiI1cERCdFE4REdpb1RTS0Q1SnZ3MzZUb1NqMDV4emtLVmFZTWN6ZmlkIiwiX3ByZXZpb3VzIjp7InVybCI6Imh0dHA6XC9cL2xvY2FsaG9zdDo4MDAwXC9iYWNrb2ZmaWNlXC9sb2dpbiIsInJvdXRlIjoiYmFja29mZmljZS5sb2dpbiJ9LCJfZmxhc2giOnsib2xkIjpbXSwibmV3IjpbXX19',1790983961),('zUEmJpgsRXq70snWZ4mqNk45qWS9XhofJtJ7kqFe',NULL,'172.18.0.1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/154.0.0.0 Safari/537.36','eyJfdG9rZW4iOiJPYnk4ckpqTDFPQ3luUGdXVGxLNFB0UHhldnE3d2RLZjVOTTlJZ01wIiwiX2ZsYXNoIjp7Im9sZCI6W10sIm5ldyI6W119fQ==',1790820345);
/*!40000 ALTER TABLE `sessions` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `stock_count_lines`
--

DROP TABLE IF EXISTS `stock_count_lines`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `stock_count_lines` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `stock_count_id` bigint unsigned NOT NULL,
  `line_number` int unsigned NOT NULL,
  `item_id` bigint unsigned NOT NULL,
  `warehouse_id` bigint unsigned NOT NULL,
  `warehouse_bin_id` bigint unsigned DEFAULT NULL,
  `theoretical_quantity` decimal(18,6) NOT NULL COMMENT 'lo que el kardex decía a la fecha de corte; congelado al abrir',
  `counted_quantity` decimal(18,6) DEFAULT NULL COMMENT 'lo que se contó; null mientras la línea siga sin contar',
  `unit_cost_local` decimal(18,6) NOT NULL DEFAULT '0.000000' COMMENT 'costo promedio al abrir, para valorar la diferencia en la hoja',
  `description` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `stock_count_lines_item_id_foreign` (`item_id`),
  KEY `stock_count_lines_warehouse_id_foreign` (`warehouse_id`),
  KEY `stock_count_lines_warehouse_bin_id_foreign` (`warehouse_bin_id`),
  KEY `stock_count_lines_stock_count_id_line_number_index` (`stock_count_id`,`line_number`),
  CONSTRAINT `stock_count_lines_item_id_foreign` FOREIGN KEY (`item_id`) REFERENCES `items` (`id`),
  CONSTRAINT `stock_count_lines_stock_count_id_foreign` FOREIGN KEY (`stock_count_id`) REFERENCES `stock_counts` (`id`) ON DELETE CASCADE,
  CONSTRAINT `stock_count_lines_warehouse_bin_id_foreign` FOREIGN KEY (`warehouse_bin_id`) REFERENCES `warehouse_bins` (`id`) ON DELETE SET NULL,
  CONSTRAINT `stock_count_lines_warehouse_id_foreign` FOREIGN KEY (`warehouse_id`) REFERENCES `warehouses` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `stock_count_lines`
--

LOCK TABLES `stock_count_lines` WRITE;
/*!40000 ALTER TABLE `stock_count_lines` DISABLE KEYS */;
/*!40000 ALTER TABLE `stock_count_lines` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `stock_counts`
--

DROP TABLE IF EXISTS `stock_counts`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `stock_counts` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `company_id` bigint unsigned NOT NULL,
  `number` varchar(20) COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'consecutivo interno por compañía; no es fiscal',
  `document_type_id` bigint unsigned NOT NULL,
  `cutoff_date` date NOT NULL COMMENT 'fecha a la que se congeló la existencia teórica',
  `warehouse_id` bigint unsigned NOT NULL,
  `item_group_id` bigint unsigned DEFAULT NULL,
  `blind` tinyint(1) NOT NULL DEFAULT '0' COMMENT 'conteo a ciegas: la hoja impresa oculta la existencia teórica',
  `status` varchar(20) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'open' COMMENT 'open|posted|cancelled',
  `inventory_document_id` bigint unsigned DEFAULT NULL,
  `description` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_by` bigint unsigned DEFAULT NULL,
  `posted_by` bigint unsigned DEFAULT NULL,
  `posted_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `stock_counts_company_id_number_unique` (`company_id`,`number`),
  KEY `stock_counts_document_type_id_foreign` (`document_type_id`),
  KEY `stock_counts_warehouse_id_foreign` (`warehouse_id`),
  KEY `stock_counts_item_group_id_foreign` (`item_group_id`),
  KEY `stock_counts_inventory_document_id_foreign` (`inventory_document_id`),
  KEY `stock_counts_created_by_foreign` (`created_by`),
  KEY `stock_counts_posted_by_foreign` (`posted_by`),
  KEY `stock_counts_company_id_status_index` (`company_id`,`status`),
  CONSTRAINT `stock_counts_company_id_foreign` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`) ON DELETE CASCADE,
  CONSTRAINT `stock_counts_created_by_foreign` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `stock_counts_document_type_id_foreign` FOREIGN KEY (`document_type_id`) REFERENCES `document_types` (`id`),
  CONSTRAINT `stock_counts_inventory_document_id_foreign` FOREIGN KEY (`inventory_document_id`) REFERENCES `inventory_documents` (`id`) ON DELETE SET NULL,
  CONSTRAINT `stock_counts_item_group_id_foreign` FOREIGN KEY (`item_group_id`) REFERENCES `item_groups` (`id`) ON DELETE SET NULL,
  CONSTRAINT `stock_counts_posted_by_foreign` FOREIGN KEY (`posted_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `stock_counts_warehouse_id_foreign` FOREIGN KEY (`warehouse_id`) REFERENCES `warehouses` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `stock_counts`
--

LOCK TABLES `stock_counts` WRITE;
/*!40000 ALTER TABLE `stock_counts` DISABLE KEYS */;
/*!40000 ALTER TABLE `stock_counts` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `stock_journals`
--

DROP TABLE IF EXISTS `stock_journals`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `stock_journals` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `company_id` bigint unsigned NOT NULL,
  `item_id` bigint unsigned NOT NULL,
  `warehouse_id` bigint unsigned NOT NULL,
  `warehouse_bin_id` bigint unsigned DEFAULT NULL,
  `item_lot_id` bigint unsigned DEFAULT NULL,
  `inventory_document_line_id` bigint unsigned DEFAULT NULL,
  `landed_cost_allocation_id` bigint unsigned DEFAULT NULL,
  `journal_entry_id` bigint unsigned DEFAULT NULL,
  `posting_date` date NOT NULL,
  `direction` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'in|out — el signo lo da esta columna, quantity siempre es positiva',
  `quantity` decimal(18,6) NOT NULL,
  `unit_cost_local` decimal(18,6) NOT NULL,
  `unit_cost_foreign` decimal(18,6) NOT NULL,
  `total_cost_local` decimal(18,2) NOT NULL COMMENT 'ya redondeado: es el monto exacto que fue al asiento',
  `total_cost_foreign` decimal(18,2) NOT NULL,
  `balance_quantity` decimal(18,6) NOT NULL COMMENT 'existencia del almacén después de este movimiento — foto de auditoría, no fuente de verdad',
  `avg_cost_local_after` decimal(18,6) NOT NULL COMMENT 'promedio global del artículo después de este movimiento',
  `avg_cost_foreign_after` decimal(18,6) NOT NULL,
  `reversal_of_id` bigint unsigned DEFAULT NULL,
  `created_by` bigint unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `stock_journals_item_id_foreign` (`item_id`),
  KEY `stock_journals_warehouse_id_foreign` (`warehouse_id`),
  KEY `stock_journals_inventory_document_line_id_foreign` (`inventory_document_line_id`),
  KEY `stock_journals_reversal_of_id_foreign` (`reversal_of_id`),
  KEY `stock_journals_created_by_foreign` (`created_by`),
  KEY `stock_journals_item_warehouse_date_index` (`company_id`,`item_id`,`warehouse_id`,`posting_date`),
  KEY `stock_journals_journal_entry_id_index` (`journal_entry_id`),
  KEY `stock_journals_landed_cost_allocation_id_foreign` (`landed_cost_allocation_id`),
  KEY `stock_journals_warehouse_bin_id_foreign` (`warehouse_bin_id`),
  KEY `stock_journals_item_lot_id_posting_date_index` (`item_lot_id`,`posting_date`),
  CONSTRAINT `stock_journals_company_id_foreign` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`) ON DELETE CASCADE,
  CONSTRAINT `stock_journals_created_by_foreign` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `stock_journals_inventory_document_line_id_foreign` FOREIGN KEY (`inventory_document_line_id`) REFERENCES `inventory_document_lines` (`id`),
  CONSTRAINT `stock_journals_item_id_foreign` FOREIGN KEY (`item_id`) REFERENCES `items` (`id`),
  CONSTRAINT `stock_journals_item_lot_id_foreign` FOREIGN KEY (`item_lot_id`) REFERENCES `item_lots` (`id`) ON DELETE SET NULL,
  CONSTRAINT `stock_journals_journal_entry_id_foreign` FOREIGN KEY (`journal_entry_id`) REFERENCES `journal_entries` (`id`),
  CONSTRAINT `stock_journals_landed_cost_allocation_id_foreign` FOREIGN KEY (`landed_cost_allocation_id`) REFERENCES `landed_cost_allocations` (`id`) ON DELETE CASCADE,
  CONSTRAINT `stock_journals_reversal_of_id_foreign` FOREIGN KEY (`reversal_of_id`) REFERENCES `stock_journals` (`id`) ON DELETE SET NULL,
  CONSTRAINT `stock_journals_warehouse_bin_id_foreign` FOREIGN KEY (`warehouse_bin_id`) REFERENCES `warehouse_bins` (`id`) ON DELETE SET NULL,
  CONSTRAINT `stock_journals_warehouse_id_foreign` FOREIGN KEY (`warehouse_id`) REFERENCES `warehouses` (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=82 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `stock_journals`
--

LOCK TABLES `stock_journals` WRITE;
/*!40000 ALTER TABLE `stock_journals` DISABLE KEYS */;
INSERT INTO `stock_journals` VALUES (22,604,2,3,NULL,NULL,22,NULL,332,'2026-01-10','in',10.000000,450000.000000,890.648193,4500000.00,8906.48,10.000000,450000.000000,890.648193,NULL,358,'2026-09-18 03:56:36'),(23,604,3,3,NULL,NULL,23,NULL,332,'2026-01-10','in',15.000000,95000.000000,188.025729,1425000.00,2820.39,15.000000,95000.000000,188.025729,NULL,358,'2026-09-18 03:56:36'),(24,604,4,3,NULL,NULL,24,NULL,332,'2026-01-10','in',40.000000,12000.000000,23.750618,480000.00,950.02,40.000000,12000.000000,23.750618,NULL,358,'2026-09-18 03:56:36'),(25,604,5,3,NULL,NULL,25,NULL,332,'2026-01-10','in',50.000000,6500.000000,12.864918,325000.00,643.25,50.000000,6500.000000,12.864918,NULL,358,'2026-09-18 03:56:36'),(26,604,6,3,NULL,NULL,26,NULL,332,'2026-01-10','in',8.000000,140000.000000,277.090549,1120000.00,2216.72,8.000000,140000.000000,277.090549,NULL,358,'2026-09-18 03:56:36'),(27,604,7,3,NULL,NULL,27,NULL,332,'2026-01-10','in',60.000000,3500.000000,6.927263,210000.00,415.64,60.000000,3500.000000,6.927263,NULL,358,'2026-09-18 03:56:36'),(28,604,8,3,NULL,NULL,28,NULL,332,'2026-01-10','in',20.000000,38000.000000,75.210291,760000.00,1504.21,20.000000,38000.000000,75.210291,NULL,358,'2026-09-18 03:56:36'),(29,604,9,3,NULL,NULL,29,NULL,332,'2026-01-10','in',12.000000,85000.000000,168.233547,1020000.00,2018.80,12.000000,85000.000000,168.233547,NULL,358,'2026-09-18 03:56:36'),(30,604,10,3,NULL,NULL,30,NULL,332,'2026-01-10','in',500.000000,1800.000000,3.562592,900000.00,1781.30,500.000000,1800.000000,3.562592,NULL,358,'2026-09-18 03:56:36'),(31,604,11,3,NULL,NULL,31,NULL,332,'2026-01-10','in',120.000000,4200.000000,8.312716,504000.00,997.53,120.000000,4200.000000,8.312716,NULL,358,'2026-09-18 03:56:36'),(32,604,12,3,NULL,NULL,32,NULL,332,'2026-01-10','in',25.000000,9500.000000,18.802572,237500.00,470.06,25.000000,9500.000000,18.802572,NULL,358,'2026-09-18 03:56:36'),(33,604,13,3,NULL,NULL,33,NULL,332,'2026-01-10','in',6.000000,210000.000000,415.635823,1260000.00,2493.81,6.000000,210000.000000,415.635823,NULL,358,'2026-09-18 03:56:36'),(34,604,14,3,NULL,NULL,34,NULL,332,'2026-01-10','in',3.000000,620000.000000,1227.115289,1860000.00,3681.35,3.000000,620000.000000,1227.115289,NULL,358,'2026-09-18 03:56:36'),(35,604,15,3,NULL,NULL,35,NULL,332,'2026-01-10','in',30.000000,18000.000000,35.625927,540000.00,1068.78,30.000000,18000.000000,35.625927,NULL,358,'2026-09-18 03:56:36'),(36,604,16,3,NULL,NULL,36,NULL,332,'2026-01-10','in',14.000000,22000.000000,43.542800,308000.00,609.60,14.000000,22000.000000,43.542800,NULL,358,'2026-09-18 03:56:36'),(37,604,17,3,NULL,NULL,37,NULL,332,'2026-01-10','in',20.000000,7800.000000,15.437902,156000.00,308.76,20.000000,7800.000000,15.437902,NULL,358,'2026-09-18 03:56:36'),(38,604,2,4,NULL,NULL,38,NULL,333,'2026-01-10','in',5.000000,450000.000000,890.648193,2250000.00,4453.24,5.000000,450000.000000,890.648193,NULL,358,'2026-09-18 03:56:36'),(39,604,6,4,NULL,NULL,39,NULL,333,'2026-01-10','in',3.000000,140000.000000,277.090549,420000.00,831.27,3.000000,140000.000000,277.090549,NULL,358,'2026-09-18 03:56:36'),(40,604,4,5,1,NULL,40,NULL,334,'2026-01-10','in',20.000000,12000.000000,23.750618,240000.00,475.01,20.000000,12000.000000,23.750618,NULL,358,'2026-09-18 03:56:36'),(41,604,5,5,2,NULL,41,NULL,334,'2026-01-10','in',25.000000,6500.000000,12.864918,162500.00,321.62,25.000000,6500.000000,12.864918,NULL,358,'2026-09-18 03:56:36'),(42,604,7,5,4,NULL,42,NULL,334,'2026-01-10','in',30.000000,3500.000000,6.927263,105000.00,207.82,30.000000,3500.000000,6.927263,NULL,358,'2026-09-18 03:56:36'),(48,604,2,3,NULL,NULL,48,NULL,342,'2026-02-10','in',10.000000,450000.000000,885.129819,4500000.00,8851.30,20.000000,450000.000000,888.440843,NULL,358,'2026-09-18 04:02:42'),(49,604,6,3,NULL,NULL,49,NULL,342,'2026-02-10','in',25.000000,140000.000000,275.373721,3500000.00,6884.34,33.000000,140000.000000,275.898307,NULL,358,'2026-09-18 04:02:42'),(50,604,2,3,NULL,NULL,50,NULL,344,'2026-03-05','out',6.000000,450000.000000,888.440843,2700000.00,5330.65,14.000000,450000.000000,888.440843,NULL,358,'2026-09-18 04:02:42'),(51,604,3,3,NULL,NULL,51,NULL,344,'2026-03-05','out',4.000000,95000.000000,188.025729,380000.00,752.10,11.000000,95000.000000,188.025729,NULL,358,'2026-09-18 04:02:42'),(52,604,4,3,NULL,NULL,52,NULL,344,'2026-03-05','out',10.000000,12000.000000,23.750618,120000.00,237.51,30.000000,12000.000000,23.750618,NULL,358,'2026-09-18 04:02:42'),(74,604,4,3,NULL,NULL,74,NULL,361,'2026-09-20','out',1.000000,12000.000000,23.750618,12000.00,23.75,29.000000,12000.000000,23.750618,NULL,359,'2026-09-20 01:55:19'),(75,604,4,4,NULL,NULL,74,NULL,361,'2026-09-20','in',1.000000,12000.000000,23.750618,12000.00,23.75,1.000000,12000.000000,23.750618,NULL,359,'2026-09-20 01:55:19'),(76,604,10,3,NULL,NULL,75,NULL,362,'2026-09-14','out',26.000000,1800.000000,3.562592,46800.00,92.63,474.000000,1800.000000,3.562592,NULL,NULL,'2026-09-23 03:54:08'),(77,604,11,3,NULL,NULL,76,NULL,362,'2026-09-14','out',3.000000,4200.000000,8.312716,12600.00,24.94,117.000000,4200.000000,8.312716,NULL,NULL,'2026-09-23 03:54:08'),(78,604,12,3,NULL,NULL,77,NULL,362,'2026-09-14','out',2.000000,9500.000000,18.802572,19000.00,37.61,23.000000,9500.000000,18.802572,NULL,NULL,'2026-09-23 03:54:08'),(79,604,13,3,NULL,NULL,78,NULL,363,'2026-09-15','in',10.000000,7840.000000,15.046540,78400.00,150.47,16.000000,83650.000000,165.267521,NULL,NULL,'2026-09-23 03:54:08'),(80,604,10,3,NULL,NULL,79,NULL,364,'2026-09-18','out',61.800000,1800.000000,3.562592,111240.00,220.17,412.200000,1800.000000,3.562592,NULL,NULL,'2026-09-23 03:54:08'),(81,604,12,3,NULL,NULL,80,NULL,364,'2026-09-18','out',4.000000,9500.000000,18.802572,38000.00,75.21,19.000000,9500.000000,18.802572,NULL,NULL,'2026-09-23 03:54:08');
/*!40000 ALTER TABLE `stock_journals` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `tax_rates`
--

DROP TABLE IF EXISTS `tax_rates`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `tax_rates` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `company_id` bigint unsigned DEFAULT NULL,
  `tax_type_id` bigint unsigned NOT NULL,
  `code` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `name` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `percentage` decimal(5,2) NOT NULL,
  `grants_fiscal_credit` tinyint(1) NOT NULL DEFAULT '0',
  `fiscal_credit_note` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `effective_from` date NOT NULL,
  `effective_to` date DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `tax_rates_tax_type_id_foreign` (`tax_type_id`),
  KEY `tax_rates_company_id_foreign` (`company_id`),
  CONSTRAINT `tax_rates_company_id_foreign` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`) ON DELETE CASCADE,
  CONSTRAINT `tax_rates_tax_type_id_foreign` FOREIGN KEY (`tax_type_id`) REFERENCES `tax_types` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=30 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `tax_rates`
--

LOCK TABLES `tax_rates` WRITE;
/*!40000 ALTER TABLE `tax_rates` DISABLE KEYS */;
INSERT INTO `tax_rates` VALUES (24,NULL,28,'IVA-13','IVA tarifa general 13%',13.00,1,NULL,'2019-07-01',NULL,'2026-09-01 01:38:24','2026-09-07 01:10:56'),(25,NULL,28,'IVA-4','IVA tarifa reducida 4%',4.00,1,NULL,'2019-07-01',NULL,'2026-09-07 01:10:56','2026-09-07 01:10:56'),(26,NULL,28,'IVA-2','IVA tarifa reducida 2%',2.00,1,NULL,'2019-07-01',NULL,'2026-09-07 01:10:56','2026-09-07 01:10:56'),(27,NULL,28,'IVA-1','IVA tarifa reducida 1%',1.00,1,NULL,'2019-07-01',NULL,'2026-09-07 01:10:56','2026-09-07 01:10:56'),(28,NULL,28,'IVA-0','IVA exento 0%',0.00,0,'Bienes y servicios exentos por ley — no genera crédito fiscal.','2019-07-01',NULL,'2026-09-07 01:10:56','2026-09-07 01:10:56');
/*!40000 ALTER TABLE `tax_rates` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `tax_types`
--

DROP TABLE IF EXISTS `tax_types`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `tax_types` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `company_id` bigint unsigned DEFAULT NULL,
  `code` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'IVA, RENTA...',
  `name` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `tax_types_company_id_foreign` (`company_id`),
  CONSTRAINT `tax_types_company_id_foreign` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=30 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `tax_types`
--

LOCK TABLES `tax_types` WRITE;
/*!40000 ALTER TABLE `tax_types` DISABLE KEYS */;
INSERT INTO `tax_types` VALUES (28,NULL,'IVA','Impuesto al Valor Agregado','2026-09-01 01:38:24','2026-09-01 01:38:24');
/*!40000 ALTER TABLE `tax_types` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `units_of_measure`
--

DROP TABLE IF EXISTS `units_of_measure`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `units_of_measure` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `company_id` bigint unsigned NOT NULL,
  `code` varchar(20) COLLATE utf8mb4_unicode_ci NOT NULL,
  `name` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `decimals` tinyint unsigned NOT NULL DEFAULT '2' COMMENT 'decimales admitidos en cantidad; 0 = unidad indivisible',
  `status` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'active' COMMENT 'active|inactive',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `units_of_measure_company_id_code_unique` (`company_id`,`code`),
  CONSTRAINT `units_of_measure_company_id_foreign` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=10 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `units_of_measure`
--

LOCK TABLES `units_of_measure` WRITE;
/*!40000 ALTER TABLE `units_of_measure` DISABLE KEYS */;
INSERT INTO `units_of_measure` VALUES (3,604,'UND','Unidad',0,'active','2026-09-18 03:54:05','2026-09-18 03:54:05'),(4,604,'CAJ','Caja',0,'active','2026-09-18 03:54:05','2026-09-18 03:54:05'),(5,604,'KG','Kilogramo',3,'active','2026-09-18 03:54:05','2026-09-18 03:54:05'),(6,604,'LT','Litro',3,'active','2026-09-18 03:54:05','2026-09-18 03:54:05'),(7,604,'MT','Metro',2,'active','2026-09-18 03:54:05','2026-09-18 03:54:05'),(8,604,'HRA','Hora',2,'active','2026-09-18 03:54:05','2026-09-18 03:54:05'),(9,604,'SRV','Servicio',0,'active','2026-09-18 03:54:05','2026-09-18 03:54:05');
/*!40000 ALTER TABLE `units_of_measure` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `user_roles`
--

DROP TABLE IF EXISTS `user_roles`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `user_roles` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `user_id` bigint unsigned NOT NULL,
  `role_id` bigint unsigned NOT NULL,
  `company_id` bigint unsigned NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `user_roles_user_id_role_id_company_id_unique` (`user_id`,`role_id`,`company_id`),
  KEY `user_roles_role_id_foreign` (`role_id`),
  KEY `user_roles_company_id_foreign` (`company_id`),
  CONSTRAINT `user_roles_company_id_foreign` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`) ON DELETE CASCADE,
  CONSTRAINT `user_roles_role_id_foreign` FOREIGN KEY (`role_id`) REFERENCES `roles` (`id`) ON DELETE CASCADE,
  CONSTRAINT `user_roles_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=23 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `user_roles`
--

LOCK TABLES `user_roles` WRITE;
/*!40000 ALTER TABLE `user_roles` DISABLE KEYS */;
INSERT INTO `user_roles` VALUES (18,356,16,600,'2026-09-02 04:09:59','2026-09-02 04:09:59'),(19,357,17,600,'2026-09-02 04:12:15','2026-09-02 04:12:15'),(20,356,16,603,'2026-09-18 02:21:59','2026-09-18 02:21:59'),(21,356,16,604,'2026-09-18 02:24:33','2026-09-18 02:24:33'),(22,359,17,604,'2026-09-18 02:51:59','2026-09-18 02:51:59');
/*!40000 ALTER TABLE `user_roles` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `users`
--

DROP TABLE IF EXISTS `users`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `users` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `email` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `email_verified_at` timestamp NULL DEFAULT NULL,
  `password` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `is_super_admin` tinyint(1) NOT NULL DEFAULT '0' COMMENT 'global, no editable por UI normal',
  `default_company_id` bigint unsigned DEFAULT NULL,
  `status` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'active',
  `remember_token` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `users_email_unique` (`email`),
  KEY `users_default_company_id_foreign` (`default_company_id`),
  CONSTRAINT `users_default_company_id_foreign` FOREIGN KEY (`default_company_id`) REFERENCES `companies` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=360 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `users`
--

LOCK TABLES `users` WRITE;
/*!40000 ALTER TABLE `users` DISABLE KEYS */;
INSERT INTO `users` VALUES (355,'IANB','alexn72@hotmail.com',NULL,'$2y$12$RfPzYJlEznLyqrOXhenuT.cHl3IP521xDUkq2bquJmaHS9RtLodDC',1,599,'active',NULL,'2026-09-02 02:20:33','2026-09-02 02:20:33'),(356,'Administrador sistema','alexandernaranjobrenes@gmail.com',NULL,'$2y$12$qzeHpbr1VO0C2LzfmgWACuoszNP8R3aAVwNcHBcWKClC0lrhbRbp2',0,600,'active',NULL,'2026-09-02 04:09:59','2026-09-02 04:09:59'),(357,'ALEX','vanessamendoza3@hotmail.com',NULL,'$2y$12$UXUG/GUpsO7Arh2ji/NWVe3yxT8iIwi0aChyKwWl.wqhO1DUv1VZq',0,600,'active',NULL,'2026-09-02 04:12:15','2026-09-02 04:12:15'),(358,'KevinNM','kevinnm770@gmail.com',NULL,'$2y$12$QQnAJaR5CvDcDgU2ZXX7MOY4Im9ywF0wNeKfs92AN.ZoH8vVeFLlC',1,603,'active',NULL,'2026-09-18 02:18:03','2026-09-18 02:18:03'),(359,'USUARIO','info@contapp.com',NULL,'$2y$12$.A3t06GWKSnvYtg6MExAU.8kV4uaiaHXHk6PW4HcET.Oa.xQ4iPd6',0,604,'active',NULL,'2026-09-18 02:51:59','2026-09-18 02:51:59');
/*!40000 ALTER TABLE `users` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `vacation_movements`
--

DROP TABLE IF EXISTS `vacation_movements`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `vacation_movements` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `company_id` bigint unsigned NOT NULL,
  `employee_id` bigint unsigned NOT NULL,
  `type` varchar(20) COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'accrual|taken|paid|adjustment',
  `movement_date` date NOT NULL,
  `days` decimal(10,4) NOT NULL,
  `from_date` date DEFAULT NULL,
  `to_date` date DEFAULT NULL,
  `payroll_period_id` bigint unsigned DEFAULT NULL,
  `payroll_entry_id` bigint unsigned DEFAULT NULL,
  `labor_settlement_id` bigint unsigned DEFAULT NULL,
  `amount` decimal(18,2) DEFAULT NULL COMMENT 'monto pagado, si se pagaron',
  `notes` text COLLATE utf8mb4_unicode_ci,
  `created_by` bigint unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `vacation_accrual_unique` (`employee_id`,`payroll_period_id`,`type`),
  KEY `vacation_movements_payroll_period_id_foreign` (`payroll_period_id`),
  KEY `vacation_movements_payroll_entry_id_foreign` (`payroll_entry_id`),
  KEY `vacation_movements_created_by_foreign` (`created_by`),
  KEY `vacation_movements_company_id_employee_id_movement_date_index` (`company_id`,`employee_id`,`movement_date`),
  KEY `vacation_movements_labor_settlement_id_foreign` (`labor_settlement_id`),
  CONSTRAINT `vacation_movements_company_id_foreign` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`) ON DELETE CASCADE,
  CONSTRAINT `vacation_movements_created_by_foreign` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `vacation_movements_employee_id_foreign` FOREIGN KEY (`employee_id`) REFERENCES `employees` (`id`) ON DELETE CASCADE,
  CONSTRAINT `vacation_movements_labor_settlement_id_foreign` FOREIGN KEY (`labor_settlement_id`) REFERENCES `labor_settlements` (`id`) ON DELETE SET NULL,
  CONSTRAINT `vacation_movements_payroll_entry_id_foreign` FOREIGN KEY (`payroll_entry_id`) REFERENCES `payroll_entries` (`id`) ON DELETE SET NULL,
  CONSTRAINT `vacation_movements_payroll_period_id_foreign` FOREIGN KEY (`payroll_period_id`) REFERENCES `payroll_periods` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `vacation_movements`
--

LOCK TABLES `vacation_movements` WRITE;
/*!40000 ALTER TABLE `vacation_movements` DISABLE KEYS */;
/*!40000 ALTER TABLE `vacation_movements` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `warehouse_bins`
--

DROP TABLE IF EXISTS `warehouse_bins`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `warehouse_bins` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `warehouse_id` bigint unsigned NOT NULL,
  `code` varchar(30) COLLATE utf8mb4_unicode_ci NOT NULL,
  `name` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `status` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'active' COMMENT 'active|inactive',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `warehouse_bins_warehouse_id_code_unique` (`warehouse_id`,`code`),
  CONSTRAINT `warehouse_bins_warehouse_id_foreign` FOREIGN KEY (`warehouse_id`) REFERENCES `warehouses` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=6 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `warehouse_bins`
--

LOCK TABLES `warehouse_bins` WRITE;
/*!40000 ALTER TABLE `warehouse_bins` DISABLE KEYS */;
INSERT INTO `warehouse_bins` VALUES (1,5,'A-01-01','Pasillo A, estante 1, nivel 1','active','2026-09-18 03:54:05','2026-09-18 03:54:05'),(2,5,'A-01-02','Pasillo A, estante 1, nivel 2','active','2026-09-18 03:54:05','2026-09-18 03:54:05'),(3,5,'A-02-01','Pasillo A, estante 2, nivel 1','active','2026-09-18 03:54:05','2026-09-18 03:54:05'),(4,5,'B-01-01','Pasillo B, estante 1, nivel 1','active','2026-09-18 03:54:05','2026-09-18 03:54:05'),(5,5,'REC-01','Área de recepción','active','2026-09-18 03:54:05','2026-09-18 03:54:05');
/*!40000 ALTER TABLE `warehouse_bins` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `warehouses`
--

DROP TABLE IF EXISTS `warehouses`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `warehouses` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `company_id` bigint unsigned NOT NULL,
  `code` varchar(20) COLLATE utf8mb4_unicode_ci NOT NULL,
  `name` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `address` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `is_default` tinyint(1) NOT NULL DEFAULT '0',
  `uses_bins` tinyint(1) NOT NULL DEFAULT '0',
  `status` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'active' COMMENT 'active|inactive',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `warehouses_company_id_code_unique` (`company_id`,`code`),
  CONSTRAINT `warehouses_company_id_foreign` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=6 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `warehouses`
--

LOCK TABLES `warehouses` WRITE;
/*!40000 ALTER TABLE `warehouses` DISABLE KEYS */;
INSERT INTO `warehouses` VALUES (3,604,'ALM01','Almacén principal','San José, oficinas centrales',1,0,'active','2026-09-18 03:54:05','2026-09-18 03:54:05'),(4,604,'ALM02','Bodega de tránsito','Zona franca, Alajuela',0,0,'active','2026-09-18 03:54:05','2026-09-18 03:54:05'),(5,604,'ALM03','Almacén con ubicaciones','Cartago, centro de distribución',0,1,'active','2026-09-18 03:54:05','2026-09-18 03:54:05');
/*!40000 ALTER TABLE `warehouses` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Dumping routines for database 'bdcontapp'
--
/*!40103 SET TIME_ZONE=@OLD_TIME_ZONE */;

/*!40101 SET SQL_MODE=@OLD_SQL_MODE */;
/*!40014 SET FOREIGN_KEY_CHECKS=@OLD_FOREIGN_KEY_CHECKS */;
/*!40014 SET UNIQUE_CHECKS=@OLD_UNIQUE_CHECKS */;
/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
/*!40111 SET SQL_NOTES=@OLD_SQL_NOTES */;

-- Dump completed on 2026-10-02 23:33:29
