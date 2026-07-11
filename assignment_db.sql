-- phpMyAdmin SQL Dump
-- version 4.9.0.1
-- https://www.phpmyadmin.net/
--
-- Host: sql300.byetcluster.com
-- Generation Time: Jul 10, 2026 at 09:33 AM
-- Server version: 11.4.12-MariaDB
-- PHP Version: 7.2.22

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
SET AUTOCOMMIT = 0;
START TRANSACTION;
SET time_zone = "+00:00";


/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;

--
-- Database: `if0_42363609_assignment_db`
--

-- --------------------------------------------------------

--
-- Table structure for table `assignments`
--

CREATE TABLE `assignments` (
  `assignment_id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `title` varchar(255) NOT NULL,
  `subject` varchar(100) NOT NULL,
  `assignment_content` text DEFAULT NULL,
  `upload_file` varchar(255) DEFAULT NULL,
  `submission_date` datetime DEFAULT current_timestamp(),
  `status` enum('Pending','Checking','Completed') DEFAULT 'Pending',
  `payment_status` enum('unpaid','paid') NOT NULL DEFAULT 'unpaid',
  `payment_date` datetime DEFAULT NULL,
  `payment_amount` decimal(10,2) DEFAULT 0.00,
  `transaction_id` varchar(255) DEFAULT '',
  `payment_method` varchar(100) DEFAULT '',
  `price` decimal(10,2) NOT NULL DEFAULT 15.00,
  `qr_generated` tinyint(1) DEFAULT 0,
  `verification_code` varchar(64) DEFAULT NULL,
  `file_name` varchar(255) DEFAULT NULL,
  `file_path` varchar(255) DEFAULT NULL,
  `processed_file` varchar(255) DEFAULT NULL,
  `ai_score` int(11) DEFAULT NULL,
  `ai_feedback` text DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `assignments`
--

INSERT INTO `assignments` (`assignment_id`, `user_id`, `title`, `subject`, `assignment_content`, `upload_file`, `submission_date`, `status`, `payment_status`, `payment_date`, `payment_amount`, `transaction_id`, `payment_method`, `price`, `qr_generated`, `verification_code`, `file_name`, `file_path`, `processed_file`, `ai_score`, `ai_feedback`) VALUES
(2, 2, 'IML513', 'Instructional Design and Materials', 'Do report based on the game and quiz , and check my AI score', 'uploads/assignments/assign_2_6a40c7186d8a96.82058846.pdf', '2026-06-28 15:02:48', 'Completed', 'paid', '2026-07-04 13:35:29', '10.00', 'TP2607040677633464', 'ToyyibPay', '10.00', 0, NULL, NULL, NULL, NULL, NULL, NULL),
(3, 2, 'Problem Solving 2', 'System', 'Checking AI score report', 'uploads/assignments/assign_2_6a4124a9d3c167.95909092.pdf', '2026-06-28 21:42:01', 'Completed', 'paid', '2026-07-04 13:29:53', '10.00', 'TP2607044590597454', 'ToyyibPay', '15.00', 0, NULL, NULL, NULL, NULL, NULL, NULL),
(4, 2, 'IMS560', 'PROBLEM SOLVING 1', 'CODING AND CHECK AI SCORE', 'uploads/assignments/assign_2_6a412814b36024.47381629.pdf', '2026-06-28 21:56:36', 'Pending', 'unpaid', NULL, '0.00', '', '', '15.00', 0, NULL, NULL, NULL, NULL, NULL, NULL),
(5, 2, 'php', 'sains', 'ksdjkjskjdk', 'uploads/assignments/assign_2_6a45dd7a4a0af7.64803288.pdf', '2026-07-02 11:39:38', 'Completed', 'paid', '2026-07-04 10:19:35', '10.00', 'TP2607043161034994', 'ToyyibPay', '15.00', 0, NULL, NULL, NULL, NULL, NULL, NULL),
(6, 2, 'PROJECT MANAGEMENT', 'IMS565', 'gjeg', 'uploads/assignments/assign_2_6a4775ff8e3e91.87298538.pdf', '2026-07-03 16:42:39', 'Pending', 'paid', '2026-07-04 09:50:51', '10.00', 'TP2607044249613788', 'ToyyibPay', '15.00', 0, NULL, NULL, NULL, NULL, NULL, NULL),
(8, 4, 'IMS565', 'Time Management', 'Introduction Summary', 'uploads/assignments/assign_4_6a48779c1e8014.29518292.pdf', '2026-07-04 11:01:48', 'Completed', 'unpaid', NULL, '0.00', '', '', '15.00', 0, NULL, NULL, NULL, 'reports/report_8.php', 0, 'The assignment appears to be predominantly human-written content.\nStructural patterns, vocabulary usage, and sentence variability are consistent with natural writing.\n\nContent Statistics: 16327 words, 740 sentences, 1 paragraphs.\n17 academic keywords identified and highlighted in the report.'),
(9, 8, 'LCC402', 'English', 'Turnitin my english', 'assign_8_6a49c421d8fec9.09383636.pdf', '2026-07-05 10:40:33', 'Completed', 'paid', '2026-07-05 04:43:18', '5.00', 'TP2607054575389037', 'ToyyibPay', '15.00', 0, NULL, NULL, 'uploads/assignments/assign_8_6a49c421d8fec9.09383636.pdf', 'reports/report_9.html', 2, 'The assignment appears to be predominantly human-written content.\nStructural patterns, vocabulary usage, and sentence variability are consistent with natural writing.\n\nContent Statistics: 45689 words, 736 sentences, 1 paragraphs.\n2 academic keywords identified and highlighted in the report.'),
(10, 8, 'IMS560', 'ADVANCED WEB DESIGN', 'REPORT OF THE AIRASIA WEBSITE', 'assign_8_6a4d00f3e688c2.82588983.pdf', '2026-07-07 21:36:51', 'Completed', 'unpaid', NULL, '0.00', '', '', '15.00', 0, NULL, NULL, 'uploads/assignments/assign_8_6a4d00f3e688c2.82588983.pdf', 'reports/report_10.html', 22, 'The assignment shows moderate indicators that may suggest AI-assisted writing.\nSome patterns (transition word frequency, sentence uniformity) warrant closer review.\n\nContent Statistics: 30156 words, 2059 sentences, 1 paragraphs.\nDetected 16 AI-typical transition/filler word usage(s).\n25 academic keywords identified and highlighted in the report.'),
(11, 9, 'IMS511', 'DATA ANALYTICS', 'Data report', 'assign_9_6a4d1390e227c5.42975592.pdf', '2026-07-07 22:56:16', 'Completed', 'unpaid', NULL, '0.00', '', '', '15.00', 0, NULL, NULL, 'uploads/assignments/assign_9_6a4d1390e227c5.42975592.pdf', 'reports/report_11.html', 6, 'The assignment appears to be predominantly human-written content.\nStructural patterns, vocabulary usage, and sentence variability are consistent with natural writing.\n\nContent Statistics: 23 words, 4 sentences, 2 paragraphs.\nWarning: Very short content may affect analysis reliability.'),
(12, 9, 'IMS564', 'USER EXPERIENCE DESIGN', 'ABOUT UI AND UX', 'assign_9_6a4d13a8349227.32112903.docx', '2026-07-07 22:56:40', 'Completed', 'unpaid', NULL, '0.00', '', '', '15.00', 0, NULL, NULL, 'uploads/assignments/assign_9_6a4d13a8349227.32112903.docx', 'reports/report_12.html', 25, 'The assignment shows moderate indicators that may suggest AI-assisted writing.\nSome patterns (transition word frequency, sentence uniformity) warrant closer review.\n\nContent Statistics: 4108 words, 356 sentences, 26 paragraphs.\nDetected 21 AI-typical transition/filler word usage(s).\n21 academic keywords identified and highlighted in the report.'),
(13, 9, 'IMS560', 'ADVANCED DESIGN', 'TURNITIN', 'assign_9_6a4d1808390309.55432206.pdf', '2026-07-07 23:15:20', 'Completed', 'unpaid', NULL, '0.00', '', '', '15.00', 0, NULL, NULL, 'uploads/assignments/assign_9_6a4d1808390309.55432206.pdf', 'reports/report_13.html', 26, 'The assignment shows moderate indicators that may suggest AI-assisted writing.\nSome patterns (transition word frequency, sentence uniformity) warrant closer review.\n\nContent Statistics: 19145 words, 20 sentences, 3 paragraphs.\nNo personal pronouns found — this is uncommon in student writing.'),
(14, 8, 'begw', 'e', 'g', 'assign_8_6a4d203b16fda7.78956603.pdf', '2026-07-07 23:50:19', 'Completed', 'unpaid', NULL, '0.00', '', '', '15.00', 0, NULL, NULL, 'uploads/assignments/assign_8_6a4d203b16fda7.78956603.pdf', 'reports/report_14.html', 2, 'The assignment appears to be predominantly human-written content.\nStructural patterns, vocabulary usage, and sentence variability are consistent with natural writing.\n\nContent Statistics: 32513 words, 121 sentences, 1 paragraphs.'),
(15, 9, 'A threat to the environement', 'Environment', 'Essay AI Detector', 'assign_9_6a4db82aecb0a3.22182648.pdf', '2026-07-08 10:38:34', 'Completed', 'paid', '2026-07-08 12:14:39', '10.00', 'TP2607083747083203', 'ToyyibPay', '15.00', 0, NULL, NULL, 'uploads/assignments/assign_9_6a4db82aecb0a3.22182648.pdf', 'reports/report_15.html', 2, 'The assignment appears to be predominantly human-written content.\nStructural patterns, vocabulary usage, and sentence variability are consistent with natural writing.\n\nContent Statistics: 2003 words, 1 sentences, 1 paragraphs.'),
(16, 9, 'A threat to the environement', 'Environment', 'Essay', 'assign_9_6a4dbab0e81b86.66396674.docx', '2026-07-08 10:49:20', 'Completed', 'paid', '2026-07-08 11:42:29', '10.00', 'TP2607080314032599', 'ToyyibPay', '15.00', 0, NULL, NULL, 'uploads/assignments/assign_9_6a4dbab0e81b86.66396674.docx', 'reports/report_16.html', 44, 'The assignment shows moderate indicators that may suggest AI-assisted writing.\nSome patterns (transition word frequency, sentence uniformity) warrant closer review.\n\nContent Statistics: 356 words, 22 sentences, 1 paragraphs.\nDetected 1 AI-typical transition/filler word usage(s).\nNo personal pronouns found — this is uncommon in student writing.\n1 academic keywords identified and highlighted in the report.'),
(17, 9, 'egf', 'geeg', 'eg', 'assign_9_6a4e33e7ca6c56.04128697.docx', '2026-07-08 19:26:31', 'Completed', 'unpaid', NULL, '0.00', '', '', '15.00', 0, NULL, NULL, 'uploads/assignments/assign_9_6a4e33e7ca6c56.04128697.docx', 'reports/report_17.html', 44, 'The assignment shows moderate indicators that may suggest AI-assisted writing.\nSome patterns (transition word frequency, sentence uniformity) warrant closer review.\n\nContent Statistics: 356 words, 22 sentences, 1 paragraphs.\nDetected 1 AI-typical transition/filler word usage(s).\nNo personal pronouns found — this is uncommon in student writing.\n1 academic keywords identified and highlighted in the report.'),
(18, 9, 'asd', 'asd', 'asdgfdhjkhngbfvdcsx', 'assign_9_6a4f245cc33c66.59162396.docx', '2026-07-08 21:32:28', 'Pending', 'unpaid', NULL, '0.00', '', '', '15.00', 0, NULL, NULL, 'uploads/assignments/assign_9_6a4f245cc33c66.59162396.docx', NULL, NULL, NULL),
(19, 9, 'IML254', 'INTRODUCTION TO WEB CONTENT DEVELOPMENT (IML254)', 'CORPOARTE WEBSITE', 'assign_9_6a4f2be1ca2fb8.34595687.docx', '2026-07-08 22:04:34', 'Completed', 'paid', '2026-07-09 01:10:37', '5.00', 'TP2607091430647924', 'ToyyibPay', '15.00', 0, NULL, NULL, 'uploads/assignments/assign_9_6a4f2be1ca2fb8.34595687.docx', 'reports/report_19.html', 19, 'The assignment appears to be predominantly human-written content.\nStructural patterns, vocabulary usage, and sentence variability are consistent with natural writing.\n\nContent Statistics: 3388 words, 195 sentences, 3 paragraphs.\nDetected 12 AI-typical transition/filler word usage(s).\n20 academic keywords identified and highlighted in the report.'),
(20, 2, 'dhdjd', 'hddjdj', 'hdjdjd', 'assign_2_6a50e07e2fba85.20448068.pdf', '2026-07-10 05:07:26', 'Pending', 'unpaid', NULL, '0.00', '', '', '15.00', 0, NULL, NULL, 'uploads/assignments/assign_2_6a50e07e2fba85.20448068.pdf', NULL, NULL, NULL),
(21, 9, 'IMS566', 'IMS566', 'System requirements and ERD', 'assign_9_6a50ee10a80d64.89876813.docx', '2026-07-10 06:05:20', 'Completed', 'paid', '2026-07-10 09:07:00', '5.00', 'TP2607100345352935', 'ToyyibPay', '15.00', 0, NULL, NULL, 'uploads/assignments/assign_9_6a50ee10a80d64.89876813.docx', 'reports/report_21.html', 15, 'The assignment appears to be predominantly human-written content.\nStructural patterns, vocabulary usage, and sentence variability are consistent with natural writing.\n\nContent Statistics: 299 words, 20 sentences, 1 paragraphs.\n3 academic keywords identified and highlighted in the report.');

-- --------------------------------------------------------

--
-- Table structure for table `assignment_reviews`
--

CREATE TABLE `assignment_reviews` (
  `review_id` int(11) NOT NULL,
  `assignment_id` int(11) NOT NULL,
  `admin_id` int(11) NOT NULL,
  `marks` int(11) DEFAULT NULL,
  `comment` text DEFAULT NULL,
  `reviewed_file` varchar(255) DEFAULT NULL,
  `reviewed_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `created_at` datetime DEFAULT current_timestamp(),
  `ai_score` decimal(5,2) DEFAULT NULL,
  `similarity` decimal(5,2) DEFAULT NULL,
  `verification_code` varchar(50) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `assignment_reviews`
--

INSERT INTO `assignment_reviews` (`review_id`, `assignment_id`, `admin_id`, `marks`, `comment`, `reviewed_file`, `reviewed_at`, `created_at`, `ai_score`, `similarity`, `verification_code`) VALUES
(1, 2, 3, 45, 'So bad', '20260628_095501_admin_kss_v7i6_10636_6a40d3551a506.pdf', '2026-06-28 07:55:01', '2026-06-28 15:55:01', NULL, NULL, NULL),
(3, 3, 3, 90, 'Higher AI', '20260628_155137_advantages-and-disadvantages-of-online-learning_6a4126e99ad82.pdf', '2026-06-28 13:51:37', '2026-06-28 21:51:37', '25.00', '9.00', 'AIC-6D74EC-4C3D90-FDC713'),
(4, 5, 3, 34, 'rgjnht', '20260703_091606_565_6a4761b6d3bc4.pdf', '2026-07-03 07:16:06', '2026-07-03 15:16:06', '35.00', '34.00', 'AIC-54E0CC-301D41-5A67D4'),
(5, 9, 3, 98, 'That\'s quite good', '20260705_100259_AI_Assignment_Report_assign_8_6a49c421d8fec9_09383636_6a4a0fb3df7b3.pdf', '2026-07-05 08:02:59', '2026-07-05 16:02:59', '2.00', '0.00', 'AIC-EF0676-7D4E6F-BB6ABB'),
(6, 12, 3, 76, '', '20260707_171151_AI_Assignment_Report_assign_9_6a4d13a8349227_32112903_docx_6a4d17372b529.pdf', '2026-07-07 15:05:58', '2026-07-07 23:05:58', '25.00', '2.00', 'AIC-A4F056-3AD569-14A524'),
(7, 16, 3, 65, '', '20260708_052003_Assignment_16_AI_Report_6a4dc1e3ca36f.pdf', '2026-07-08 03:20:03', '2026-07-08 11:20:03', '44.00', '1.00', 'AIC-43E745-00F7A1-BE43B3'),
(8, 15, 3, 22, '', '20260708_121227_Assignment_16_AI_Report_6a4e228b625b9.pdf', '2026-07-08 10:12:27', '2026-07-08 18:12:27', '23.00', '23.00', 'AIC-13CFB1-055ED6-5F93B2'),
(9, 17, 3, 98, '', '20260708_140004_A_Threat_to_the_Environment_6a4e3bc4b779f.pdf', '2026-07-08 12:00:04', '2026-07-08 20:00:04', '9.00', '9.00', 'CERT-2026-00002'),
(10, 8, 3, 2, '', '20260708_141739_A_Threat_to_the_Environment_6a4e3fe359009.pdf', '2026-07-08 12:17:39', '2026-07-08 20:17:39', '22.00', '2.00', 'CERT-2026-00003'),
(11, 19, 3, NULL, '', '20260709_011340_Assignment_19_AI_Report_6a4f2e048b0a9.pdf', '2026-07-09 05:13:40', '2026-07-08 22:13:40', '14.00', '0.00', 'CERT-2026-00004'),
(12, 21, 3, 80, 'blablablabla', '20260710_091230_Assignment_21_AI_Report_6a50efbe44f99.pdf', '2026-07-10 13:12:30', '2026-07-10 06:12:30', '15.00', '2.00', 'CERT-2026-00005');

-- --------------------------------------------------------

--
-- Table structure for table `certificates`
--

CREATE TABLE `certificates` (
  `certificate_id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `assignment_id` int(11) NOT NULL,
  `certificate_code` varchar(30) NOT NULL,
  `student_name` varchar(255) NOT NULL,
  `assignment_title` varchar(255) NOT NULL,
  `ai_score` decimal(5,2) DEFAULT NULL,
  `issued_date` date NOT NULL,
  `certificate_file` varchar(255) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `certificates`
--

INSERT INTO `certificates` (`certificate_id`, `user_id`, `assignment_id`, `certificate_code`, `student_name`, `assignment_title`, `ai_score`, `issued_date`, `certificate_file`, `created_at`) VALUES
(1, 9, 16, 'CERT-2026-00001', 'sakinah', 'A threat to the environement', '44.00', '2026-07-08', NULL, '2026-07-08 11:43:58'),
(2, 9, 17, 'CERT-2026-00002', 'sakinah', 'egf', '9.00', '2026-07-08', NULL, '2026-07-08 12:00:04'),
(3, 4, 8, 'CERT-2026-00003', 'John Kim', 'IMS565', '22.00', '2026-07-08', NULL, '2026-07-08 12:17:39'),
(4, 9, 19, 'CERT-2026-00004', 'sakinah', 'IML254', '14.00', '2026-07-09', 'uploads/certificates/CERT-2026-00004.png', '2026-07-09 05:13:41'),
(5, 9, 21, 'CERT-2026-00005', 'sakinah', 'IMS566', '15.00', '2026-07-10', 'uploads/certificates/CERT-2026-00005.png', '2026-07-10 13:12:30');

-- --------------------------------------------------------

--
-- Table structure for table `contacts`
--

CREATE TABLE `contacts` (
  `contact_id` int(11) NOT NULL,
  `name` varchar(100) DEFAULT NULL,
  `email` varchar(100) DEFAULT NULL,
  `message` text DEFAULT NULL,
  `created_at` datetime DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `contacts`
--

INSERT INTO `contacts` (`contact_id`, `name`, `email`, `message`, `created_at`) VALUES
(1, 'Arif', 'arif02@gmail.com', 'Subject: ENGLISH\n\nI want more plan', '2026-06-26 09:56:27'),
(5, 'John Kim', 'john90@gmail.com', 'Subject: PROBLEM SOLVING\n\nCan u check my coding and fix the design also?', '2026-06-28 14:27:31'),
(7, 'Sakinah', 'ainifarhana866@gmail.com', 'Subject: Computer Science\n\nCan u make turnitin this', '2026-07-07 22:26:24'),
(8, 'Sakinah', 'ainifarhana866@gmail.com', 'Subject: Computer Science\n\nCan u make turnitin this', '2026-07-07 22:26:26'),
(9, 'FSADSFBDV', 'GDGDGCS@gmail.com', 'Subject: BFDSDFGFD\n\nDGFDRDFDSDXCVCVC', '2026-07-08 20:08:14'),
(10, 'Kinah', 'sakinahcomel@gmail.com', 'Subject: ABC\n\nABCDEFGHIJKLMNOPQ', '2026-07-08 21:31:48'),
(11, 'hana', 'hana@gmail.com', 'Subject: english\n\nvery amazing', '2026-07-10 05:02:02');

-- --------------------------------------------------------

--
-- Table structure for table `notifications`
--

CREATE TABLE `notifications` (
  `notification_id` int(11) NOT NULL,
  `user_id` int(11) DEFAULT NULL,
  `message` text DEFAULT NULL,
  `is_read` tinyint(1) DEFAULT 0,
  `created_at` datetime DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `notifications`
--

INSERT INTO `notifications` (`notification_id`, `user_id`, `message`, `is_read`, `created_at`) VALUES
(1, 9, 'Your assignment \"IMS566\" was submitted successfully and is now Pending.', 1, '2026-07-10 06:05:20'),
(2, NULL, 'New assignment \"IMS566\" was submitted and is waiting for checking.', 1, '2026-07-10 06:05:20'),
(3, 9, 'AI analysis for your assignment \"IMS566\" has been completed. Your result is ready to view.', 1, '2026-07-10 06:09:42'),
(4, 9, 'A review has been added for your assignment \"IMS566\". The assignment is completed.', 1, '2026-07-10 06:12:30');

-- --------------------------------------------------------

--
-- Table structure for table `payments`
--

CREATE TABLE `payments` (
  `payment_id` int(11) NOT NULL,
  `invoice_number` varchar(20) DEFAULT NULL,
  `user_id` int(11) DEFAULT NULL,
  `plan_id` int(11) DEFAULT NULL,
  `assignment_id` int(11) DEFAULT NULL,
  `amount` decimal(10,2) NOT NULL,
  `payment_method` varchar(50) DEFAULT NULL,
  `voucher_code` varchar(50) DEFAULT NULL,
  `discount_amount` decimal(10,2) DEFAULT 0.00,
  `total_paid` decimal(10,2) NOT NULL,
  `payment_status` enum('Pending','Paid','Failed') DEFAULT 'Pending',
  `created_at` datetime DEFAULT current_timestamp(),
  `updated_at` datetime DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `payments`
--

INSERT INTO `payments` (`payment_id`, `invoice_number`, `user_id`, `plan_id`, `assignment_id`, `amount`, `payment_method`, `voucher_code`, `discount_amount`, `total_paid`, `payment_status`, `created_at`, `updated_at`) VALUES
(1, NULL, 2, NULL, NULL, '15.00', '0', '', '0.00', '15.00', 'Paid', '2026-06-28 17:09:39', '2026-06-29 20:00:33');

-- --------------------------------------------------------

--
-- Table structure for table `payment_transactions`
--

CREATE TABLE `payment_transactions` (
  `id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `order_id` varchar(120) NOT NULL,
  `type` enum('plan','assignment') NOT NULL,
  `reference_id` int(11) NOT NULL,
  `item_name` varchar(255) DEFAULT '',
  `item_desc` varchar(500) DEFAULT '',
  `original_amount` decimal(10,2) NOT NULL DEFAULT 0.00,
  `discount_amount` decimal(10,2) NOT NULL DEFAULT 0.00,
  `final_amount` decimal(10,2) NOT NULL DEFAULT 0.00,
  `voucher_code` varchar(100) DEFAULT '',
  `toyyibpay_billcode` varchar(100) DEFAULT '',
  `status` enum('pending','paid','failed','expired') DEFAULT 'pending',
  `toyyibpay_transaction_id` varchar(255) DEFAULT '',
  `toyyibpay_payment_method` varchar(100) DEFAULT '',
  `paid_at` datetime DEFAULT NULL,
  `receipt_file` varchar(255) DEFAULT NULL,
  `created_at` datetime DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `payment_transactions`
--

INSERT INTO `payment_transactions` (`id`, `user_id`, `order_id`, `type`, `reference_id`, `item_name`, `item_desc`, `original_amount`, `discount_amount`, `final_amount`, `voucher_code`, `toyyibpay_billcode`, `status`, `toyyibpay_transaction_id`, `toyyibpay_payment_method`, `paid_at`, `receipt_file`, `created_at`) VALUES
(9, 4, 'ASG_4_8_1783137321', 'assignment', 8, 'IMS565', 'Assignment check — Time Management', '10.00', '0.00', '10.00', '', 'bgbzgfz6', 'pending', '', '', NULL, NULL, '2026-07-04 11:55:22'),
(10, 2, 'ASG_2_4_1783139485', 'assignment', 4, 'IMS560', 'Assignment check — PROBLEM SOLVING 1', '10.00', '0.00', '10.00', '', 'jpd8w84d', 'failed', '', '', NULL, NULL, '2026-07-04 12:31:25'),
(12, 2, 'ASG_2_6_1783151423', 'assignment', 6, 'PROJECT MANAGEMENT', 'Assignment check — IMS565', '10.00', '0.00', '10.00', '', 'ksdkvwgz', 'paid', 'TP2607044249613788', '', '2026-07-04 09:50:51', NULL, '2026-07-04 15:50:24'),
(13, 2, 'ASG_2_5_1783153137', 'assignment', 5, 'php', 'Assignment check — sains', '10.00', '0.00', '10.00', '', '4qitxu77', 'paid', 'TP2607043161034994', '', '2026-07-04 10:19:35', 'receipts/receipt_ASG_2_5_1783153137.html', '2026-07-04 16:18:58'),
(14, 2, 'ASG_2_3_1783164569', 'assignment', 3, 'Problem Solving 2', 'Assignment check — System', '10.00', '0.00', '10.00', '', '3fu183zr', 'paid', 'TP2607044590597454', '', '2026-07-04 13:29:53', 'receipts/receipt_ASG_2_3_1783164569.html', '2026-07-04 19:29:30'),
(15, 2, 'ASG_2_2_1783164882', 'assignment', 2, 'IML513', 'Assignment check — Instructional Design and Materials', '10.00', '0.00', '10.00', '', 'zjrh2azr', 'paid', 'TP2607040677633464', '', '2026-07-04 13:35:29', 'receipts/receipt_ASG_2_2_1783164882.html', '2026-07-04 19:34:43'),
(16, 8, 'PLN_8_1_1783171660', 'plan', 1, 'Basic Plan', 'Subscription — Basic Plan (1 Months)', '9.90', '0.00', '9.90', '', 'ivy1x7io', 'paid', 'TP2607040310618426', 'Online Payment', '2026-07-04 15:28:17', NULL, '2026-07-04 21:27:40'),
(17, 8, 'ASG_8_9_1783219371', 'assignment', 9, 'LCC402', 'Assignment check — English', '10.00', '5.00', '5.00', '', '9yotzfvl', 'paid', 'TP2607054575389037', '', '2026-07-05 04:43:18', 'receipts/receipt_ASG_8_9_1783219371.html', '2026-07-05 10:42:51'),
(18, 9, 'ASG_9_16_1783503722', 'assignment', 16, 'A threat to the environement', 'Assignment check — Environment', '10.00', '0.00', '10.00', '', 'c57o7qhx', 'paid', 'TP2607080314032599', '', '2026-07-08 11:42:29', 'receipts/receipt_ASG_9_16_1783503722.html', '2026-07-08 17:42:02'),
(19, 9, 'ASG_9_15_1783505654', 'assignment', 15, 'A threat to the environement', 'Assignment check — Environment', '10.00', '0.00', '10.00', '', 'tp5qunrw', 'paid', 'TP2607083747083203', '', '2026-07-08 12:14:39', 'receipts/receipt_ASG_9_15_1783505654.html', '2026-07-08 18:14:14'),
(20, 9, 'ASG_9_19_1783573751', 'assignment', 19, 'IML254', 'Assignment check â€” INTRODUCTION TO WEB CONTENT DEVELOPMENT (IML254)', '10.00', '5.00', '5.00', 'SAVE2', '1xsiac0z', 'paid', 'TP2607091430647924', '', '2026-07-09 01:10:37', 'receipts/receipt_ASG_9_19_1783573751.html', '2026-07-08 22:09:12'),
(21, 2, 'PLN_2_2_1783685135', 'plan', 2, 'Standard Plan', 'Subscription â€” Standard Plan (4 months)', '29.90', '5.00', '24.90', 'SAVE2', '4opbxzgc', 'paid', 'TP2607104768677295', 'Online Payment', '2026-07-10 08:05:59', NULL, '2026-07-10 05:05:36'),
(22, 9, 'ASG_9_21_1783688743', 'assignment', 21, 'IMS566', 'Assignment check â€” IMS566', '10.00', '5.00', '5.00', 'SAVE2', 'aictim89', 'paid', 'TP2607100345352935', '', '2026-07-10 09:07:00', 'receipts/receipt_ASG_9_21_1783688743.html', '2026-07-10 06:05:44');

-- --------------------------------------------------------

--
-- Table structure for table `plag_ai`
--

CREATE TABLE `plag_ai` (
  `result_id` int(11) NOT NULL,
  `assignment_id` int(11) NOT NULL,
  `ai_score` decimal(5,2) DEFAULT NULL,
  `similarity_score` decimal(5,2) DEFAULT NULL,
  `suggestions` text DEFAULT NULL,
  `admin_feedback` text DEFAULT NULL,
  `report_file` varchar(255) DEFAULT NULL,
  `checked_date` datetime DEFAULT current_timestamp(),
  `status` enum('Pending','Approved','Revision Required') DEFAULT 'Pending'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `plans`
--

CREATE TABLE `plans` (
  `plan_id` int(11) NOT NULL,
  `plan_name` varchar(100) NOT NULL,
  `description` text NOT NULL,
  `price` decimal(10,2) NOT NULL,
  `assignment_discount` decimal(5,2) DEFAULT 0.00,
  `duration` varchar(50) DEFAULT NULL,
  `features` text DEFAULT NULL,
  `status` enum('Active','Inactive') DEFAULT 'Active',
  `created_at` datetime DEFAULT current_timestamp(),
  `plan_image` varchar(255) DEFAULT NULL,
  `badge` varchar(50) DEFAULT NULL,
  `button_text` varchar(50) DEFAULT 'Subscribe'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `plans`
--

INSERT INTO `plans` (`plan_id`, `plan_name`, `description`, `price`, `assignment_discount`, `duration`, `features`, `status`, `created_at`, `plan_image`, `badge`, `button_text`) VALUES
(1, 'Basic Plan', 'Perfect for students who need essential AI assignment checking tools. Upload assignments, detect AI-generated content, receive basic reports, and track submission status easily at an affordable price.', '9.90', '50.00', '1 Months', 'AI Content Detection, Assignment Upload, Basic Analysis Report, Submission Tracking', 'Active', '2026-06-24 13:21:00', 'plan_1782278460_7733.png', 'Started', 'Subscribe'),
(2, 'Standard Plan', 'The Standard Plan is designed for students who need essential AI-powered assignment checking features at an affordable price. It provides accurate analysis of your assignments, helping you improve content quality, detect similarity, and ensure academic integrity before submission. This plan is suitable for regular academic use with reliable performance and fast processing.', '29.90', '50.00', '4 months', 'AI Assignment Checking, Plagiarism / Similarity Detection, File upload support up to 30MB per file, Standard processing speed, Downloadable basic PDF report, Secure storage of submitted assignments', 'Active', '2026-07-03 16:07:35', 'plan_1783066055_6197.png', 'Popular', 'Subscribe'),
(3, 'Premium Plan', 'The Premium Plan is the most advanced package in the AI Assignment Checker system, designed for students who require high-accuracy analysis, deeper insights, and professional-grade reporting. It uses enhanced AI processing to deliver detailed evaluation of assignments, helping users significantly improve quality, originality, and academic performance. This plan is ideal for final-year students and heavy users who need complete and reliable academic support.', '59.90', '50.00', '1 Year', 'Advanced AI Assignment Checking with high-accuracy model, Deep plagiarism and similarity detection system, Detailed AI scoring breakdown, File upload support up to 100MB per file, High-speed priority processing queue, Full professional PDF report with detailed analytics, QR code verification system for secure assignment validation', 'Active', '2026-07-03 16:08:56', 'plan_1783066136_8107.png', 'Premium', 'Subscribe');

-- --------------------------------------------------------

--
-- Table structure for table `testimonials`
--

CREATE TABLE `testimonials` (
  `testimonial_id` int(11) NOT NULL,
  `name` varchar(100) NOT NULL,
  `feedback` text NOT NULL,
  `rating` int(1) DEFAULT 5,
  `avatar` varchar(255) DEFAULT 'default.png',
  `created_at` datetime DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `testimonials`
--

INSERT INTO `testimonials` (`testimonial_id`, `name`, `feedback`, `rating`, `avatar`, `created_at`) VALUES
(1, 'Atikah Zanal', 'So bad', 1, 'Bob', '2026-06-21 21:11:49'),
(8, 'Amirul Hakimi', 'THIS WEBSITEEE IS THE BESTTT', 5, 'Felix', '2026-06-26 01:09:09'),
(9, 'Hasya', 'Fast respond', 5, 'Felix', '2026-06-26 01:33:08'),
(10, 'Caca', 'This is so amazing', 5, 'Jack', '2026-06-28 14:25:02'),
(11, 'Haimi', 'Very Good', 3, 'Bob', '2026-07-07 19:43:40'),
(13, 'Alisya', 'THIS WAS AMAZING WEBSITE', 5, 'Annie', '2026-07-07 19:55:52'),
(14, 'HANA', 'AMAZING', 5, 'Cathy', '2026-07-10 05:03:04'),
(15, 'HANA', 'AMAZING', 5, 'Jack', '2026-07-10 05:03:11'),
(16, 'HANA', 'AMAZING', 5, 'Bob', '2026-07-10 05:03:18');

-- --------------------------------------------------------

--
-- Table structure for table `users`
--

CREATE TABLE `users` (
  `user_id` int(20) NOT NULL,
  `name` varchar(100) NOT NULL,
  `email` varchar(100) NOT NULL,
  `password` varchar(200) NOT NULL,
  `user_type` enum('user','admin') NOT NULL DEFAULT 'user',
  `avatar` varchar(255) DEFAULT 'default.png',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `plan_id` int(11) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `users`
--

INSERT INTO `users` (`user_id`, `name`, `email`, `password`, `user_type`, `avatar`, `created_at`, `plan_id`) VALUES
(2, 'Arif', 'arif02@gmail.com', '$2y$10$vqvlDJRwm5sydxuWqbrNdODa8Z5sMnNhoqvw5v1.zlAxvRwSt5jhK', 'user', 'Annie', '2026-06-18 12:43:41', NULL),
(3, 'Eda Shahira', 'eda99@gmail.com', '$2y$10$2ktayo7qXdjKRgRo4wwhM.dxlZaQmLB.mG6hKdyWFk2rfhF1Cx6Qe', 'admin', 'Annie', '2026-06-18 12:43:41', NULL),
(4, 'John Kim', 'john90@gmail.com', '$2y$10$zIQrF5g.nuKB9f/CAhHNa.gO2/l5FE5jMSqZxLSlOT0DuYYLNBcvK', 'user', 'Annie', '2026-06-19 09:23:50', NULL),
(6, 'Ebnu Qasrin', 'ebnu90@gmail.com', '$2y$10$i.D1dHrXh72GBfXa7Xg.aOy/tAKp5meTpobPENW3hXXZIl3PSMDo2', 'admin', 'Bob', '2026-06-19 11:10:42', NULL),
(8, 'Atikah Zanal', 'nuratikahmohdzanalabidin@gmail.com', '$2y$10$3P3IdxeIMgqibSGcOesMse4C6xFbSHfHfha5RDrIznZzqCZSgAjAC', 'user', 'Felix', '2026-07-04 11:42:25', NULL),
(9, 'sakinah', 'sakinahcomel@gmail.com', '$2y$10$jO1//xZB0FvxZE208XijAeGYK7FZwMd2axzxSGWYdf8SP0m6MnhYi', 'user', 'Cathy', '2026-07-07 14:18:31', NULL),
(10, 'haimin', 'haimin@gmail.com', '$2y$10$tdUx0bOC71MpyOvH/BxeI.cSldiqEdvrTzV7lbuPDC0bRJPvKdGPC', 'user', 'Bob', '2026-07-08 12:38:42', NULL);

-- --------------------------------------------------------

--
-- Table structure for table `user_subscriptions`
--

CREATE TABLE `user_subscriptions` (
  `subscription_id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `plan_id` int(11) NOT NULL,
  `payment_id` int(11) NOT NULL,
  `start_date` datetime NOT NULL,
  `end_date` datetime NOT NULL,
  `status` enum('Active','Expired','Cancelled') DEFAULT 'Active',
  `created_at` datetime DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `user_subscriptions`
--

INSERT INTO `user_subscriptions` (`subscription_id`, `user_id`, `plan_id`, `payment_id`, `start_date`, `end_date`, `status`, `created_at`) VALUES
(2, 8, 1, 16, '2026-07-05 10:34:07', '2026-08-05 04:34:07', 'Active', '2026-07-05 10:34:07'),
(3, 2, 2, 21, '2026-07-10 05:05:59', '2026-08-10 08:05:59', 'Active', '2026-07-10 05:05:59');

-- --------------------------------------------------------

--
-- Table structure for table `vouchers`
--

CREATE TABLE `vouchers` (
  `voucher_id` int(11) NOT NULL,
  `code` varchar(50) NOT NULL,
  `discount_amount` decimal(10,2) NOT NULL,
  `min_amount` decimal(10,2) DEFAULT 0.00,
  `expiry_date` date DEFAULT NULL,
  `status` enum('Active','Inactive','Expired') DEFAULT 'Active'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `vouchers`
--

INSERT INTO `vouchers` (`voucher_id`, `code`, `discount_amount`, `min_amount`, `expiry_date`, `status`) VALUES
(1, 'SAVE10', '15.00', '20.00', '2026-06-30', 'Active'),
(2, 'SAVE2', '5.00', '2.00', '2026-07-10', 'Active'),
(3, 'SAVE15', '15.00', '20.00', '2026-07-31', 'Active');

--
-- Indexes for dumped tables
--

--
-- Indexes for table `assignments`
--
ALTER TABLE `assignments`
  ADD PRIMARY KEY (`assignment_id`),
  ADD UNIQUE KEY `verification_code` (`verification_code`),
  ADD KEY `user_id` (`user_id`);

--
-- Indexes for table `assignment_reviews`
--
ALTER TABLE `assignment_reviews`
  ADD PRIMARY KEY (`review_id`),
  ADD UNIQUE KEY `verification_code` (`verification_code`),
  ADD KEY `assignment_id` (`assignment_id`);

--
-- Indexes for table `certificates`
--
ALTER TABLE `certificates`
  ADD PRIMARY KEY (`certificate_id`),
  ADD UNIQUE KEY `uq_certificate_code` (`certificate_code`),
  ADD KEY `idx_assignment_id` (`assignment_id`),
  ADD KEY `idx_user_id` (`user_id`);

--
-- Indexes for table `contacts`
--
ALTER TABLE `contacts`
  ADD PRIMARY KEY (`contact_id`);

--
-- Indexes for table `notifications`
--
ALTER TABLE `notifications`
  ADD PRIMARY KEY (`notification_id`),
  ADD KEY `user_id` (`user_id`);

--
-- Indexes for table `payments`
--
ALTER TABLE `payments`
  ADD PRIMARY KEY (`payment_id`),
  ADD UNIQUE KEY `invoice_number` (`invoice_number`),
  ADD KEY `user_id` (`user_id`),
  ADD KEY `plan_id` (`plan_id`);

--
-- Indexes for table `payment_transactions`
--
ALTER TABLE `payment_transactions`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `order_id` (`order_id`),
  ADD KEY `idx_user` (`user_id`),
  ADD KEY `idx_order` (`order_id`),
  ADD KEY `idx_status` (`status`);

--
-- Indexes for table `plans`
--
ALTER TABLE `plans`
  ADD PRIMARY KEY (`plan_id`);

--
-- Indexes for table `testimonials`
--
ALTER TABLE `testimonials`
  ADD PRIMARY KEY (`testimonial_id`);

--
-- Indexes for table `users`
--
ALTER TABLE `users`
  ADD PRIMARY KEY (`user_id`);

--
-- Indexes for table `user_subscriptions`
--
ALTER TABLE `user_subscriptions`
  ADD PRIMARY KEY (`subscription_id`),
  ADD KEY `user_id` (`user_id`),
  ADD KEY `plan_id` (`plan_id`),
  ADD KEY `payment_id` (`payment_id`);

--
-- Indexes for table `vouchers`
--
ALTER TABLE `vouchers`
  ADD PRIMARY KEY (`voucher_id`),
  ADD UNIQUE KEY `code` (`code`);

--
-- AUTO_INCREMENT for dumped tables
--

--
-- AUTO_INCREMENT for table `assignments`
--
ALTER TABLE `assignments`
  MODIFY `assignment_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=22;

--
-- AUTO_INCREMENT for table `assignment_reviews`
--
ALTER TABLE `assignment_reviews`
  MODIFY `review_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=13;

--
-- AUTO_INCREMENT for table `certificates`
--
ALTER TABLE `certificates`
  MODIFY `certificate_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=6;

--
-- AUTO_INCREMENT for table `contacts`
--
ALTER TABLE `contacts`
  MODIFY `contact_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=12;

--
-- AUTO_INCREMENT for table `notifications`
--
ALTER TABLE `notifications`
  MODIFY `notification_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=5;

--
-- AUTO_INCREMENT for table `payments`
--
ALTER TABLE `payments`
  MODIFY `payment_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT for table `payment_transactions`
--
ALTER TABLE `payment_transactions`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=23;

--
-- AUTO_INCREMENT for table `plans`
--
ALTER TABLE `plans`
  MODIFY `plan_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=4;

--
-- AUTO_INCREMENT for table `testimonials`
--
ALTER TABLE `testimonials`
  MODIFY `testimonial_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=17;

--
-- AUTO_INCREMENT for table `users`
--
ALTER TABLE `users`
  MODIFY `user_id` int(20) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=11;

--
-- AUTO_INCREMENT for table `user_subscriptions`
--
ALTER TABLE `user_subscriptions`
  MODIFY `subscription_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=4;

--
-- AUTO_INCREMENT for table `vouchers`
--
ALTER TABLE `vouchers`
  MODIFY `voucher_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=4;

--
-- Constraints for dumped tables
--

--
-- Constraints for table `assignments`
--
ALTER TABLE `assignments`
  ADD CONSTRAINT `assignments_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`user_id`);

--
-- Constraints for table `assignment_reviews`
--
ALTER TABLE `assignment_reviews`
  ADD CONSTRAINT `assignment_reviews_ibfk_1` FOREIGN KEY (`assignment_id`) REFERENCES `assignments` (`assignment_id`);

--
-- Constraints for table `certificates`
--
ALTER TABLE `certificates`
  ADD CONSTRAINT `fk_certificates_assignment` FOREIGN KEY (`assignment_id`) REFERENCES `assignments` (`assignment_id`),
  ADD CONSTRAINT `fk_certificates_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`user_id`);

--
-- Constraints for table `notifications`
--
ALTER TABLE `notifications`
  ADD CONSTRAINT `notifications_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`user_id`);

--
-- Constraints for table `payments`
--
ALTER TABLE `payments`
  ADD CONSTRAINT `payments_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`user_id`),
  ADD CONSTRAINT `payments_ibfk_2` FOREIGN KEY (`plan_id`) REFERENCES `plans` (`plan_id`);

--
-- Constraints for table `user_subscriptions`
--
ALTER TABLE `user_subscriptions`
  ADD CONSTRAINT `user_subscriptions_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`user_id`) ON DELETE CASCADE,
  ADD CONSTRAINT `user_subscriptions_ibfk_2` FOREIGN KEY (`plan_id`) REFERENCES `plans` (`plan_id`) ON DELETE CASCADE,
  ADD CONSTRAINT `user_subscriptions_ibfk_3` FOREIGN KEY (`payment_id`) REFERENCES `payment_transactions` (`id`) ON DELETE CASCADE;
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
