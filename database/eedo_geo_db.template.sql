-- Clean template for Geo Tagging project
-- Scope: login + administrator/staff + barangay/purok/precinct

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";

CREATE TABLE IF NOT EXISTS `users` (
  `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
  `name` varchar(255) NOT NULL,
  `username` varchar(255) DEFAULT NULL,
  `designation` varchar(255) DEFAULT NULL,
  `email` varchar(255) DEFAULT NULL,
  `email_verified_at` timestamp NULL DEFAULT NULL,
  `password` varchar(255) NOT NULL,
  `role` enum('administrator','staff') NOT NULL DEFAULT 'staff',
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `must_change_password` tinyint(1) NOT NULL DEFAULT 0,
  `barangay_scope` enum('ALL','SPECIFIC') NOT NULL DEFAULT 'ALL',
  `remember_token` varchar(100) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `users_username_unique` (`username`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `password_resets` (
  `email` varchar(255) NOT NULL,
  `token` varchar(255) NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  KEY `password_resets_email_index` (`email`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `failed_jobs` (
  `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
  `uuid` varchar(255) NOT NULL,
  `connection` text NOT NULL,
  `queue` text NOT NULL,
  `payload` longtext NOT NULL,
  `exception` longtext NOT NULL,
  `failed_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `failed_jobs_uuid_unique` (`uuid`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `personal_access_tokens` (
  `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
  `tokenable_type` varchar(255) NOT NULL,
  `tokenable_id` bigint(20) UNSIGNED NOT NULL,
  `name` varchar(255) NOT NULL,
  `token` varchar(64) NOT NULL,
  `abilities` text DEFAULT NULL,
  `last_used_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `personal_access_tokens_token_unique` (`token`),
  KEY `personal_access_tokens_tokenable_type_tokenable_id_index` (`tokenable_type`,`tokenable_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `permissions` (
  `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
  `code` varchar(100) NOT NULL,
  `label` varchar(150) NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `permissions_code_unique` (`code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `bow_tbl_barangays` (
  `barangay_id` int(11) NOT NULL AUTO_INCREMENT,
  `barangay_name` varchar(150) NOT NULL,
  `status` enum('ACTIVE','INACTIVE') DEFAULT 'ACTIVE',
  `created_at` datetime DEFAULT NULL,
  `updated_at` datetime DEFAULT NULL,
  PRIMARY KEY (`barangay_id`),
  UNIQUE KEY `uq_barangay_name` (`barangay_name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `bow_tbl_puroks` (
  `purok_id` int(11) NOT NULL AUTO_INCREMENT,
  `barangay_id` int(11) NOT NULL,
  `purok_name` varchar(150) NOT NULL,
  `status` enum('ACTIVE','INACTIVE') DEFAULT 'ACTIVE',
  `created_at` datetime DEFAULT NULL,
  `updated_at` datetime DEFAULT NULL,
  PRIMARY KEY (`purok_id`),
  UNIQUE KEY `uq_barangay_purok` (`barangay_id`,`purok_name`),
  CONSTRAINT `fk_purok_barangay` FOREIGN KEY (`barangay_id`) REFERENCES `bow_tbl_barangays` (`barangay_id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `bow_tbl_precincts` (
  `precinct_id` int(11) NOT NULL AUTO_INCREMENT,
  `purok_id` int(11) NOT NULL,
  `precinct_name` varchar(150) NOT NULL,
  `status` enum('ACTIVE','INACTIVE') DEFAULT 'ACTIVE',
  `created_at` datetime DEFAULT NULL,
  `updated_at` datetime DEFAULT NULL,
  PRIMARY KEY (`precinct_id`),
  UNIQUE KEY `uq_purok_precinct` (`purok_id`,`precinct_name`),
  CONSTRAINT `fk_precinct_purok` FOREIGN KEY (`purok_id`) REFERENCES `bow_tbl_puroks` (`purok_id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `user_permissions` (
  `user_id` bigint(20) UNSIGNED NOT NULL,
  `permission_id` bigint(20) UNSIGNED NOT NULL,
  PRIMARY KEY (`user_id`,`permission_id`),
  KEY `fk_user_permissions_permission` (`permission_id`),
  CONSTRAINT `fk_user_permissions_permission` FOREIGN KEY (`permission_id`) REFERENCES `permissions` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_user_permissions_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `user_barangays` (
  `user_id` bigint(20) UNSIGNED NOT NULL,
  `barangay_id` int(11) NOT NULL,
  PRIMARY KEY (`user_id`,`barangay_id`),
  KEY `fk_user_barangays_barangay` (`barangay_id`),
  CONSTRAINT `fk_user_barangays_barangay` FOREIGN KEY (`barangay_id`) REFERENCES `bow_tbl_barangays` (`barangay_id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_user_barangays_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

DELETE FROM `user_permissions`;
DELETE FROM `user_barangays`;
DELETE FROM `personal_access_tokens`;
DELETE FROM `permissions`;
DELETE FROM `bow_tbl_precincts`;
DELETE FROM `bow_tbl_puroks`;
DELETE FROM `bow_tbl_barangays`;
DELETE FROM `users`;

INSERT INTO `permissions` (`id`, `code`, `label`) VALUES
(1, 'bow.manage_geo', 'Manage Barangay, Purok, and Precinct'),
(2, 'bow.view_geo', 'View Barangay, Purok, and Precinct');

INSERT INTO `users` (`id`, `name`, `username`, `designation`, `email`, `password`, `role`, `is_active`, `must_change_password`, `barangay_scope`, `created_at`, `updated_at`) VALUES
(1, 'System Administrator', 'administrator', 'Administrator', 'administrator', '$2y$10$ZvK9YSfAeWPP2e3EdKTMFOHsc9cmIElCwAxob4f2EbKw8Hd2Th07y', 'administrator', 1, 0, 'ALL', NOW(), NOW()),
(2, 'Staff Account', 'staff', 'Staff', 'staff', '$2y$10$IfyELTp1.1cTZp1K/KUHXuVClyVcihIFMebgvqOVvgItsc3JIoV82', 'staff', 1, 0, 'ALL', NOW(), NOW());

INSERT INTO `user_permissions` (`user_id`, `permission_id`) VALUES
(1, 1),
(1, 2),
(2, 2);

INSERT INTO `bow_tbl_barangays` (`barangay_id`, `barangay_name`, `status`, `created_at`, `updated_at`) VALUES
(1, 'SAMPLE BARANGAY', 'ACTIVE', NOW(), NOW());

INSERT INTO `bow_tbl_puroks` (`purok_id`, `barangay_id`, `purok_name`, `status`, `created_at`, `updated_at`) VALUES
(1, 1, 'SAMPLE PUROK', 'ACTIVE', NOW(), NOW());

INSERT INTO `bow_tbl_precincts` (`precinct_id`, `purok_id`, `precinct_name`, `status`, `created_at`, `updated_at`) VALUES
(1, 1, 'SAMPLE PRECINCT', 'ACTIVE', NOW(), NOW());

COMMIT;
