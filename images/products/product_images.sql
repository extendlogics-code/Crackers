-- phpMyAdmin SQL Dump
-- version 5.2.2
-- https://www.phpmyadmin.net/
--
-- Host: 127.0.0.1:3306
-- Generation Time: Sep 22, 2025 at 12:12 PM
-- Server version: 11.8.3-MariaDB-log
-- PHP Version: 7.2.34

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";


/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;

--
-- Database: `u265933834_crackers`
--

-- --------------------------------------------------------

--
-- Table structure for table `product_images`
--

CREATE TABLE `product_images` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `product_id` bigint(20) UNSIGNED NOT NULL,
  `path` varchar(500) NOT NULL,
  `is_primary` tinyint(1) NOT NULL DEFAULT 0,
  `created_at` timestamp NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `product_images`
--

INSERT INTO `product_images` (`id`, `product_id`, `path`, `is_primary`, `created_at`) VALUES
(1, 2, 'images/products/SC001/20250921_130512_fff63bbc.webp', 1, '2025-09-21 13:05:12'),
(2, 4, 'images/products/SC-003/20250921_131557_79fac256.webp', 1, '2025-09-21 13:15:57'),
(3, 5, 'images/products/SC-004/20250921_131740_38fdbccb.webp', 1, '2025-09-21 13:17:40'),
(4, 6, 'images/products/SC-005/20250921_131952_cd0f7623.webp', 1, '2025-09-21 13:19:52'),
(5, 7, 'images/products/SC-006/20250921_134424_c4af760f.webp', 1, '2025-09-21 13:44:24'),
(6, 2, 'images/products/SC-001/20250921_140732_5ad7406d.webp', 0, '2025-09-21 14:07:32'),
(7, 8, 'images/products/SC-007/20250921_140849_5f1e7c28.webp', 1, '2025-09-21 14:08:49'),
(8, 9, 'images/products/SC-008/20250921_142915_cc933fc6.webp', 1, '2025-09-21 14:29:15'),
(9, 10, 'images/products/PB-001/20250921_143211_9665d0ec.webp', 1, '2025-09-21 14:32:11'),
(10, 11, 'images/products/PB-002/20250921_143316_f8dbe5f4.webp', 1, '2025-09-21 14:33:16'),
(11, 12, 'images/products/PB-003/20250921_143418_f3fbf468.webp', 1, '2025-09-21 14:34:18'),
(12, 20, 'images/products/GW-001/20250921_144129_adab49fd.webp', 1, '2025-09-21 14:41:29'),
(13, 21, 'images/products/GW-002/20250921_144227_a6806b70.webp', 1, '2025-09-21 14:42:27'),
(14, 22, 'images/products/GW-003/20250921_144321_58c7be51.webp', 1, '2025-09-21 14:43:21'),
(15, 25, 'images/products/GW-006/20250921_144532_ee5f638c.webp', 1, '2025-09-21 14:45:32'),
(16, 26, 'images/products/GW-007/20250921_144800_20e6170a.webp', 1, '2025-09-21 14:48:00'),
(17, 27, 'images/products/BC-001/20250921_144857_9db081a3.webp', 1, '2025-09-21 14:48:57'),
(18, 28, 'images/products/TW-001/20250921_151950_61339bcf.webp', 1, '2025-09-21 15:19:50'),
(19, 29, 'images/products/TW-002/20250921_152310_d2e28684.webp', 1, '2025-09-21 15:23:10'),
(20, 30, 'images/products/GC-001/20250921_152422_d9e37c8a.webp', 1, '2025-09-21 15:24:22'),
(21, 31, 'images/products/GC-002/20250921_152525_cb7915ff.webp', 1, '2025-09-21 15:25:25'),
(22, 32, 'images/products/GC-003/20250921_152704_f99a9bac.webp', 1, '2025-09-21 15:27:04'),
(23, 33, 'images/products/GC-004/20250921_152753_9ebce55d.webp', 1, '2025-09-21 15:27:53'),
(24, 34, 'images/products/GC-005/20250921_152855_cbcccc4b.webp', 1, '2025-09-21 15:28:55'),
(25, 35, 'images/products/CG-006/20250921_152955_988f6bde.webp', 1, '2025-09-21 15:29:55'),
(26, 36, 'images/products/CG-007/20250921_153056_020a54cd.webp', 1, '2025-09-21 15:30:56'),
(27, 37, 'images/products/GC-008/20250921_153150_36d1a532.webp', 1, '2025-09-21 15:31:50'),
(28, 38, 'images/products/FP-001/20250921_153251_53a4e85c.webp', 1, '2025-09-21 15:32:51'),
(29, 39, 'images/products/FP-002/20250921_153402_459eb895.webp', 1, '2025-09-21 15:34:02'),
(30, 40, 'images/products/FP-003/20250921_153500_4a01c359.webp', 1, '2025-09-21 15:35:00'),
(31, 41, 'images/products/FP-004/20250921_153550_7fc6be5d.webp', 1, '2025-09-21 15:35:50'),
(32, 42, 'images/products/FP-005/20250921_153652_2430dfc1.webp', 1, '2025-09-21 15:36:52'),
(33, 43, 'images/products/FP-006/20250921_153741_a838f440.webp', 1, '2025-09-21 15:37:41'),
(34, 44, 'images/products/FP-007/20250921_153838_346cea09.webp', 1, '2025-09-21 15:38:38'),
(35, 45, 'images/products/RB-001/20250921_153946_c7e11bc6.webp', 1, '2025-09-21 15:39:46'),
(36, 46, 'images/products/RB-002/20250921_154102_5eb539a6.webp', 1, '2025-09-21 15:41:02'),
(37, 47, 'images/products/EB-001/20250921_154237_c5a34762.webp', 1, '2025-09-21 15:42:37'),
(38, 48, 'images/products/EB-002/20250921_154353_17f4b4ab.webp', 1, '2025-09-21 15:43:53'),
(39, 49, 'images/products/EB-003/20250921_154446_3e132e90.webp', 1, '2025-09-21 15:44:46'),
(40, 52, 'images/products/EB-006/20250921_154618_bd6ed03b.webp', 1, '2025-09-21 15:46:18'),
(41, 53, 'images/products/FSI-001/20250921_154731_8bc0dbd8.webp', 1, '2025-09-21 15:47:31'),
(42, 54, 'images/products/FSI-002/20250921_154814_8c955935.webp', 1, '2025-09-21 15:48:14'),
(43, 55, 'images/products/FSI-003/20250921_154859_fa2ef103.webp', 1, '2025-09-21 15:48:59'),
(44, 56, 'images/products/FSI-004/20250921_154948_25cf5cd2.webp', 1, '2025-09-21 15:49:48'),
(45, 57, 'images/products/FSI-005/20250921_155038_8dbc933f.webp', 1, '2025-09-21 15:50:38'),
(46, 58, 'images/products/FSI-006/20250921_155128_388f393f.webp', 1, '2025-09-21 15:51:28'),
(47, 59, 'images/products/FSI-007/20250921_155209_d203b568.webp', 1, '2025-09-21 15:52:09'),
(48, 60, 'images/products/FSI-008/20250921_155337_f7f90cbe.webp', 1, '2025-09-21 15:53:37'),
(49, 61, 'images/products/FSI-009/20250921_155432_323452e6.webp', 1, '2025-09-21 15:54:32'),
(50, 62, 'images/products/FSI-010/20250921_155524_8e443241.webp', 1, '2025-09-21 15:55:24'),
(51, 63, 'images/products/FSI-011/20250921_155609_93477cbe.webp', 1, '2025-09-21 15:56:09'),
(52, 64, 'images/products/FSI-012/20250921_155655_2a8e8331.webp', 1, '2025-09-21 15:56:55'),
(53, 65, 'images/products/FSI-013/20250921_155741_767ee887.webp', 1, '2025-09-21 15:57:41'),
(54, 66, 'images/products/FSI-014/20250921_155824_0b30f6ff.webp', 1, '2025-09-21 15:58:24'),
(55, 67, 'images/products/FSI-015/20250921_155911_4f2bcc80.webp', 1, '2025-09-21 15:59:11'),
(56, 68, 'images/products/FSI-016/20250921_155954_66d98d5e.webp', 1, '2025-09-21 15:59:54'),
(57, 69, 'images/products/FSI-017/20250921_160035_fda9e30e.webp', 1, '2025-09-21 16:00:35'),
(58, 70, 'images/products/FSI-018/20250921_160114_fe9d06bd.webp', 1, '2025-09-21 16:01:14'),
(59, 71, 'images/products/FSI-019/20250921_160214_ef99e411.webp', 1, '2025-09-21 16:02:14'),
(60, 72, 'images/products/FSI-020/20250921_160252_7da3bcce.webp', 1, '2025-09-21 16:02:52'),
(61, 73, 'images/products/FSI-021/20250921_160334_e52a9b09.webp', 1, '2025-09-21 16:03:34'),
(62, 74, 'images/products/FSI-022/20250921_160413_ccee1b6c.webp', 1, '2025-09-21 16:04:13'),
(63, 75, 'images/products/FSI-023/20250921_160504_3d968895.webp', 1, '2025-09-21 16:05:04'),
(64, 76, 'images/products/FSI-024/20250921_160549_4c537684.webp', 1, '2025-09-21 16:05:49'),
(65, 77, 'images/products/FSI-025/20250921_160632_7b67e173.webp', 1, '2025-09-21 16:06:32'),
(66, 78, 'images/products/FSI-026/20250921_160729_95573c18.webp', 1, '2025-09-21 16:07:29'),
(67, 79, 'images/products/FSI-027/20250921_160831_e5cf72c7.webp', 1, '2025-09-21 16:08:31'),
(68, 80, 'images/products/FSI-028/20250921_160924_5ee9c199.webp', 1, '2025-09-21 16:09:24'),
(69, 81, 'images/products/FSI-029/20250921_161009_05859424.webp', 1, '2025-09-21 16:10:09'),
(70, 82, 'images/products/FSI-030/20250921_161101_f3263747.webp', 1, '2025-09-21 16:11:01'),
(71, 83, 'images/products/FSI-031/20250921_161150_987a93f2.webp', 1, '2025-09-21 16:11:50'),
(72, 84, 'images/products/FSI-032/20250921_161239_ae4c0497.webp', 1, '2025-09-21 16:12:39'),
(73, 86, 'images/products/RP-001/20250921_161405_bedfd4bc.webp', 1, '2025-09-21 16:14:05'),
(74, 87, 'images/products/RP-002/20250921_161451_df2fe545.webp', 1, '2025-09-21 16:14:51'),
(75, 88, 'images/products/RP-003/20250921_161545_dba0fcbe.webp', 1, '2025-09-21 16:15:45'),
(76, 89, 'images/products/RP-004/20250921_161656_eb91fb46.webp', 1, '2025-09-21 16:16:56'),
(77, 90, 'images/products/RP-005/20250921_161802_8a1ba2ca.webp', 1, '2025-09-21 16:18:02'),
(78, 91, 'images/products/RP-006/20250921_161904_dac140b6.webp', 1, '2025-09-21 16:19:04'),
(79, 93, 'images/products/CM-002/20250921_162047_d7de0203.webp', 1, '2025-09-21 16:20:47'),
(80, 94, 'images/products/BI-001/20250921_162256_64843599.webp', 1, '2025-09-21 16:22:56'),
(81, 95, 'images/products/BI-002/20250921_162344_17f3cd4e.webp', 1, '2025-09-21 16:23:44'),
(82, 96, 'images/products/BI-003/20250921_162425_8084de02.webp', 1, '2025-09-21 16:24:25'),
(83, 97, 'images/products/BI-004/20250921_162523_dd74ebbf.webp', 1, '2025-09-21 16:25:23'),
(84, 98, 'images/products/BI-005/20250921_162605_4a710562.webp', 1, '2025-09-21 16:26:05'),
(85, 99, 'images/products/BI-006/20250921_162658_0d495d75.webp', 1, '2025-09-21 16:26:58'),
(86, 100, 'images/products/BI-007/20250921_162746_b77da905.webp', 1, '2025-09-21 16:27:46'),
(87, 101, 'images/products/BI-008/20250921_162833_161fa2fa.webp', 1, '2025-09-21 16:28:33'),
(88, 102, 'images/products/BI-009/20250921_162931_2e077e5c.webp', 1, '2025-09-21 16:29:31'),
(89, 103, 'images/products/BI-010/20250921_163026_08e4bca9.webp', 1, '2025-09-21 16:30:26'),
(90, 104, 'images/products/BI-011/20250921_163113_fe50d288.webp', 1, '2025-09-21 16:31:13'),
(91, 105, 'images/products/HR-001/20250921_163217_b1bd6de0.webp', 1, '2025-09-21 16:32:17'),
(92, 106, 'images/products/HR-002/20250921_163317_8bc2ab5a.webp', 1, '2025-09-21 16:33:17'),
(93, 107, 'images/products/SS-001/20250921_163419_5818a372.webp', 1, '2025-09-21 16:34:19'),
(94, 108, 'images/products/SS-002/20250921_163656_1dc48077.webp', 1, '2025-09-21 16:36:56'),
(95, 109, 'images/products/SS-003/20250921_163814_5f58ee29.webp', 1, '2025-09-21 16:38:14'),
(96, 110, 'images/products/SS-004/20250921_163923_92ff2c42.webp', 1, '2025-09-21 16:39:23'),
(97, 111, 'images/products/SS-005/20250921_164036_6ef71da6.webp', 1, '2025-09-21 16:40:36'),
(98, 119, 'images/products/CMSs-009/20250921_170239_0fd81de2.webp', 1, '2025-09-21 17:02:39'),
(99, 120, 'images/products/CMSS-010/20250921_170347_0c689dcd.webp', 1, '2025-09-21 17:03:47'),
(100, 121, 'images/products/CMSS-011/20250921_170443_41e8ef09.webp', 1, '2025-09-21 17:04:43'),
(101, 122, 'images/products/CMSS-012/20250921_170653_691bd983.webp', 1, '2025-09-21 17:06:53'),
(102, 123, 'images/products/CMSS-013/20250921_170750_b7218dd0.webp', 1, '2025-09-21 17:07:50'),
(103, 124, 'images/products/SPFSS-001/20250921_170911_fd401491.webp', 1, '2025-09-21 17:09:11'),
(104, 125, 'images/products/SPFSS-002/20250921_171007_0126cba8.webp', 1, '2025-09-21 17:10:07'),
(105, 126, 'images/products/SPFSS-003/20250921_172316_27a01352.webp', 1, '2025-09-21 17:23:16'),
(106, 126, 'images/products/SPFSS-003/20250921_172441_603e5521.webp', 0, '2025-09-21 17:24:41'),
(107, 127, 'images/products/SPFSS-004/20250921_174428_2709d904.webp', 1, '2025-09-21 17:44:28');

--
-- Indexes for dumped tables
--

--
-- Indexes for table `product_images`
--
ALTER TABLE `product_images`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_product` (`product_id`);

--
-- AUTO_INCREMENT for dumped tables
--

--
-- AUTO_INCREMENT for table `product_images`
--
ALTER TABLE `product_images`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=108;

--
-- Constraints for dumped tables
--

--
-- Constraints for table `product_images`
--
ALTER TABLE `product_images`
  ADD CONSTRAINT `fk_images_product` FOREIGN KEY (`product_id`) REFERENCES `products` (`id`) ON DELETE CASCADE ON UPDATE CASCADE;
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
