-- phpMyAdmin SQL Dump
-- version 5.2.1
-- https://www.phpmyadmin.net/
--
-- Host: 127.0.0.1
-- Generation Time: Oct 11, 2026 at 03:25 PM
-- Server version: 10.4.32-MariaDB
-- PHP Version: 8.2.12

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";


/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;

--
-- Database: `purple_bamboo_db`
--

-- --------------------------------------------------------

--
-- Table structure for table `activities`
--

CREATE TABLE `activities` (
  `activity_id` int(11) NOT NULL,
  `activity_name` varchar(100) NOT NULL,
  `category` enum('pool','outdoor','indoor','wellness','other') DEFAULT 'pool',
  `description` text DEFAULT NULL,
  `image` varchar(255) DEFAULT NULL,
  `price` decimal(10,2) NOT NULL,
  `duration_minutes` int(11) DEFAULT NULL,
  `capacity` int(11) DEFAULT 10,
  `activity_type` enum('full_day','time_based') DEFAULT 'time_based',
  `monitoring_start_time` time NOT NULL,
  `monitoring_end_time` time NOT NULL,
  `max_slots_per_session` int(11) DEFAULT 10,
  `activity_image` varchar(255) DEFAULT NULL,
  `status` varchar(50) DEFAULT 'available',
  `is_available` tinyint(1) DEFAULT 1,
  `maintenance_notes` varchar(255) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `duration` varchar(50) DEFAULT '1 Hour',
  `max_pax` int(11) DEFAULT 2,
  `total_units` int(11) DEFAULT 6,
  `available_units` int(11) DEFAULT 4,
  `equipment` text DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `activities`
--

INSERT INTO `activities` (`activity_id`, `activity_name`, `category`, `description`, `image`, `price`, `duration_minutes`, `capacity`, `activity_type`, `monitoring_start_time`, `monitoring_end_time`, `max_slots_per_session`, `activity_image`, `status`, `is_available`, `maintenance_notes`, `created_at`, `duration`, `max_pax`, `total_units`, `available_units`, `equipment`) VALUES
(1, 'Main Infinity Pool Pass', 'pool', 'Access to crystal-clear infinity pool with lounge chairs and kids pool zone.', 'https://images.unsplash.com/photo-1576013551627-0cc20b96c2a7?auto=format&fit=crop&w=600&q=80', 350.00, 480, 10, 'time_based', '08:00:00', '20:00:00', 30, 'https://images.unsplash.com/photo-1576013551627-0cc20b96c2a7?auto=format&fit=crop&w=600&q=80', 'available', 1, NULL, '2026-09-16 17:35:02', '1 Hour', 2, 6, 4, NULL),
(2, 'Clear-Bottom Kayak & Paddle Board', 'outdoor', 'Transparent kayak equipment for scenic pool and lagoon exploration.', 'https://images.unsplash.com/photo-1544551763-46a013bb70d5?auto=format&fit=crop&w=600&q=80', 500.00, 60, 10, 'time_based', '08:00:00', '17:00:00', 10, 'https://images.unsplash.com/photo-1544551763-46a013bb70d5?auto=format&fit=crop&w=600&q=80', 'available', 1, NULL, '2026-09-16 17:35:02', '1 Hour', 2, 6, 4, '[{\"name\":\"Kayak\",\"qty\":6,\"icon\":\"fa-sailboat\"},{\"name\":\"Paddle\",\"qty\":6,\"icon\":\"fa-water\"},{\"name\":\"Life Jacket\",\"qty\":10,\"icon\":\"fa-vest\"}]'),
(3, 'Traditional Bamboo Herbal Massage', 'wellness', 'Relaxing traditional Hilot massage using heated bamboo sticks and coconut oil.', 'https://images.unsplash.com/photo-1544161515-4ab6ce6db874?auto=format&fit=crop&w=600&q=80', 1200.00, 60, 10, 'time_based', '09:00:00', '21:00:00', 5, 'https://images.unsplash.com/photo-1544161515-4ab6ce6db874?auto=format&fit=crop&w=600&q=80', 'available', 1, NULL, '2026-09-16 17:35:02', '1 Hour', 2, 6, 4, NULL);

-- --------------------------------------------------------

--
-- Table structure for table `activity_logs`
--

CREATE TABLE `activity_logs` (
  `log_id` int(11) NOT NULL,
  `user_id` int(11) DEFAULT NULL,
  `action_type` varchar(50) NOT NULL,
  `description` text NOT NULL,
  `ip_address` varchar(45) DEFAULT NULL,
  `timestamp` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `activity_reservations`
--

CREATE TABLE `activity_reservations` (
  `reservation_id` int(11) NOT NULL,
  `booking_id` int(11) NOT NULL,
  `activity_id` int(11) NOT NULL,
  `scheduled_date` date NOT NULL,
  `scheduled_time` time NOT NULL,
  `num_participants` int(11) NOT NULL DEFAULT 1,
  `subtotal` decimal(10,2) NOT NULL,
  `status` enum('scheduled','completed','cancelled') DEFAULT 'scheduled'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `addon_reservations`
--

CREATE TABLE `addon_reservations` (
  `id` int(11) NOT NULL,
  `reservation_id` varchar(50) NOT NULL,
  `activity_id` int(11) NOT NULL,
  `activity_date` date NOT NULL,
  `start_time` time DEFAULT NULL,
  `end_time` time DEFAULT NULL,
  `quantity` int(11) DEFAULT 1,
  `created_at` datetime DEFAULT current_timestamp(),
  `price` decimal(10,2) DEFAULT NULL,
  `total_price` decimal(10,2) DEFAULT NULL,
  `status` varchar(50) DEFAULT 'confirmed'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `addon_reservations`
--

INSERT INTO `addon_reservations` (`id`, `reservation_id`, `activity_id`, `activity_date`, `start_time`, `end_time`, `quantity`, `created_at`, `price`, `total_price`, `status`) VALUES
(1, 'PBR-2026-1082', 1, '2026-10-07', '15:33:00', '23:33:00', 1, '2026-10-07 00:10:36', NULL, NULL, 'confirmed'),
(3, 'PBR-2026-4906', 3, '2026-10-13', '09:00:00', '10:00:00', 1, '2026-10-09 20:13:01', NULL, NULL, 'confirmed');

-- --------------------------------------------------------

--
-- Table structure for table `admins`
--

CREATE TABLE `admins` (
  `id` int(11) NOT NULL,
  `profile_id` varchar(50) DEFAULT NULL,
  `first_name` varchar(50) NOT NULL,
  `middle_name` varchar(50) DEFAULT NULL,
  `last_name` varchar(50) NOT NULL,
  `suffix` varchar(20) DEFAULT NULL,
  `email` varchar(100) NOT NULL,
  `phone` varchar(50) NOT NULL,
  `password_hash` varchar(255) NOT NULL,
  `role` varchar(20) DEFAULT 'admin',
  `status` varchar(20) NOT NULL DEFAULT 'active',
  `profile_picture` varchar(255) DEFAULT NULL,
  `created_at` datetime DEFAULT current_timestamp(),
  `block_reason` varchar(255) DEFAULT NULL,
  `blocked_at` datetime DEFAULT NULL,
  `blocked_by` varchar(100) DEFAULT NULL,
  `reset_otp` varchar(10) DEFAULT NULL,
  `reset_expires` datetime DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `admins`
--

INSERT INTO `admins` (`id`, `profile_id`, `first_name`, `middle_name`, `last_name`, `suffix`, `email`, `phone`, `password_hash`, `role`, `status`, `profile_picture`, `created_at`, `block_reason`, `blocked_at`, `blocked_by`, `reset_otp`, `reset_expires`) VALUES
(1, 'PBR-ADM-00001', 'Super', '', 'Admin', '', 'admin@purplebamboo.com', '09566978644', '$2y$10$C90p9JNF6iC25trxb0oNJuRT/vG.dVr6zwp1D8em935XMk5.a6H8C', 'admin', 'active', NULL, '2026-09-17 03:35:01', NULL, NULL, NULL, NULL, NULL),
(4, 'PBR-ADM-00004', 'Kent Harold', 'Delios', 'Rollo', '', 'khunaguero355@gmail.com', '09852970273', '$2y$10$QvHuFAEayVWUhHIQcomideo4RdTxL31pLrFrvK9lpVt.n9e14j6d.', 'admin', 'active', NULL, '2026-10-06 17:39:47', NULL, NULL, NULL, NULL, NULL),
(5, 'PBR-ADM-00005', 'Mia Franz', 'Oquin', 'Quidez', '', 'aimznarf@gmail.com', '09999999999', '$2y$12$RTdnqiug9XaD5RKNT4rMNOZW7P7lvb9SUXMMKf1k.Tdu4Fe7rKXfq', 'admin', 'active', NULL, '2026-10-06 14:38:26', NULL, NULL, NULL, NULL, NULL),
(6, 'PBR-ADM-00006', 'Stefanie', 'Natividad', 'Gomez', '', 'stefaniegomez335@gmail.com', '09511761195', '$2y$10$Pdc2I4LAJaXdmzVvsZthkuxge5l08/ohn3xUD.HXZJ1cKkgyjXV2G', 'admin', 'active', NULL, '2026-10-06 14:40:41', NULL, NULL, NULL, NULL, NULL);

-- --------------------------------------------------------

--
-- Table structure for table `amenities`
--

CREATE TABLE `amenities` (
  `amenity_id` int(11) NOT NULL,
  `amenity_name` varchar(100) NOT NULL,
  `description` text DEFAULT NULL,
  `price` decimal(10,2) DEFAULT 0.00,
  `icon_class` varchar(50) DEFAULT 'fa-solid fa-check'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `bookings`
--

CREATE TABLE `bookings` (
  `booking_id` int(11) NOT NULL,
  `booking_reference` varchar(50) DEFAULT NULL,
  `guest_id` int(11) DEFAULT NULL,
  `kubo_id` int(11) DEFAULT NULL,
  `check_in_date` date DEFAULT NULL,
  `check_out_date` date DEFAULT NULL,
  `num_guests` int(11) NOT NULL DEFAULT 1,
  `total_amount` decimal(10,2) NOT NULL,
  `payment_status` varchar(50) DEFAULT 'proof_submitted',
  `booking_status` varchar(50) DEFAULT 'pending',
  `notes` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `payment_proof` varchar(255) DEFAULT NULL,
  `id` varchar(50) DEFAULT NULL,
  `guest_name` varchar(100) DEFAULT NULL,
  `guest_email` varchar(100) DEFAULT NULL,
  `guest_phone` varchar(50) DEFAULT NULL,
  `type` varchar(20) DEFAULT 'house',
  `item_id` int(11) DEFAULT 1,
  `item_name` varchar(100) DEFAULT NULL,
  `check_in` datetime DEFAULT NULL,
  `check_out` datetime DEFAULT NULL,
  `time_slot` varchar(50) DEFAULT NULL,
  `start_time` time DEFAULT NULL,
  `end_time` time DEFAULT NULL,
  `pax` int(11) DEFAULT 1,
  `gcash_ref` varchar(50) DEFAULT NULL,
  `special_notes` text DEFAULT NULL,
  `profile_id` varchar(50) DEFAULT NULL,
  `user_id` int(11) DEFAULT NULL,
  `guest_list` text DEFAULT NULL,
  `rejection_reason` text DEFAULT NULL,
  `rebook_deadline` datetime DEFAULT NULL,
  `is_forfeited` tinyint(1) DEFAULT 0,
  `resched_pending` tinyint(1) DEFAULT 0,
  `resched_check_in` datetime DEFAULT NULL,
  `resched_check_out` datetime DEFAULT NULL,
  `resched_time_slot` varchar(50) DEFAULT NULL,
  `resched_reason` text DEFAULT NULL,
  `resched_created_at` datetime DEFAULT NULL,
  `resched_count` int(11) DEFAULT 0,
  `rescheduled_from` varchar(100) DEFAULT NULL,
  `resched_reject_reason` text DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `bookings`
--

INSERT INTO `bookings` (`booking_id`, `booking_reference`, `guest_id`, `kubo_id`, `check_in_date`, `check_out_date`, `num_guests`, `total_amount`, `payment_status`, `booking_status`, `notes`, `created_at`, `updated_at`, `payment_proof`, `id`, `guest_name`, `guest_email`, `guest_phone`, `type`, `item_id`, `item_name`, `check_in`, `check_out`, `time_slot`, `start_time`, `end_time`, `pax`, `gcash_ref`, `special_notes`, `profile_id`, `user_id`, `guest_list`, `rejection_reason`, `rebook_deadline`, `is_forfeited`, `resched_pending`, `resched_check_in`, `resched_check_out`, `resched_time_slot`, `resched_reason`, `resched_created_at`, `resched_count`, `rescheduled_from`, `resched_reject_reason`) VALUES
(1, NULL, NULL, NULL, NULL, NULL, 5, 7350.00, 'verified', 'checked_out', NULL, '2026-10-06 10:10:36', '2026-10-09 10:30:07', 'uploads/receipts/receipt_PBR-2026-1082_1791303052.png', 'PBR-2026-1082', 'Kent Harold Delios Rollo', 'rollokentharold@gmail.com', '0985 297 0273', 'house', 1, 'Mahogany Bamboo Kubo #1', '2026-10-07 14:00:00', '2026-10-09 12:00:00', '2 Night(s) Stay (2:00 PM - 12:00 PM)', NULL, NULL, 5, '11111111111111111111', '[Pool & Activities Add-ons]:\n• Main Infinity Pool Pass (1 Pax) - ₱350.00 [Scheduled: Oct 07, 2026 at 3:33 PM]', 'PBR-GST-00215', 215, 'mori amber', NULL, NULL, 0, 0, NULL, NULL, NULL, NULL, NULL, 0, NULL, NULL),
(2, NULL, NULL, NULL, NULL, NULL, 15, 17400.00, 'unpaid', 'cancelled', NULL, '2026-10-06 22:09:38', '2026-10-06 19:13:42', 'uploads/receipts/sample_receipt.jpg', 'PBR-2026-3427', 'Registered Guest', 'deinlauvix@gmail.com', '0951 176 1195', 'house', 3, 'Royal Purple Pavilion #3', '2026-10-07 14:00:00', '2026-10-10 12:00:00', '3 Night(s) Stay (2:00 PM - 12:00 PM)', NULL, NULL, 15, NULL, 'Cancelled: Guest requested cancellation', 'PBR-GST-00216', 216, 'dein\r\nclyde\r\nclaude', NULL, NULL, 0, 0, NULL, NULL, NULL, NULL, NULL, 0, NULL, NULL),
(3, NULL, NULL, NULL, NULL, NULL, 2, 7000.00, 'verified', 'checked_out', NULL, '2026-10-06 22:17:03', '2026-10-09 18:15:04', 'uploads/receipts/sample_receipt.jpg', 'PBR-2026-9460', 'Clyde Dekumado', 'guest@purplebamboo.com', '0912 390 0876', 'house', 1, 'Beach Kubo', '2026-10-06 13:00:00', '2026-10-08 12:00:00', '2 Nights Stay (In: 1:00 PM | Out: 12:00 PM)', NULL, NULL, 2, 'WALK-IN-CASH', 'Manual Walk-In / Front Desk Reservation', NULL, NULL, '', NULL, NULL, 0, 0, NULL, NULL, NULL, NULL, NULL, 0, NULL, NULL),
(4, NULL, NULL, NULL, NULL, NULL, 5, 3500.00, 'verified', 'approved', NULL, '2026-10-09 20:57:56', '2026-10-09 18:15:14', NULL, 'PBR-2026-2401', 'Registered Guest', 'clydedekumado@gmail.com', '0912 390 8454', 'house', 1, 'Mahogany Bamboo Kubo #1', '2026-10-10 14:00:00', '2026-10-11 12:00:00', '1 Night(s) Stay (2:00 PM - 12:00 PM)', NULL, NULL, 5, NULL, '', 'PBR-GST-00221', 221, 'clyde\r\ndein', NULL, NULL, 0, 0, NULL, NULL, NULL, NULL, NULL, 0, NULL, NULL),
(5, NULL, NULL, NULL, NULL, NULL, 5, 29200.00, 'verified', 'confirmed', NULL, '2026-10-10 06:12:12', '2026-10-10 03:21:05', 'uploads/receipts/receipt_PBR-2026-4906_1791602071.png', 'PBR-2026-4906', 'Tifanny Franz Kento', 'kentotifanny@gmail.com', '0931 005 7627', 'house', 1, 'Mahogany Bamboo Kubo #1', '2026-10-29 00:00:00', '2026-11-06 00:00:00', 'Morning (08:00 AM - 12:00 PM)', NULL, NULL, 5, '8734928328472', '[Pool & Activities Add-ons]:\n• Traditional Bamboo Herbal Massage (1 Pax) - ₱1,200.00 [Scheduled: Oct 13, 2026 at 9:00 AM]\n[Reschedule Request (Attempt 1 of 2)] Requested new dates: Oct 29, 2026 to Nov 06, 2026 (Awaiting Admin Verification on Oct 09, 2026 11:16 PM)\n[Timeline] Oct 09, 2026 11:16 PM | Reschedule requested (Attempt 1 of 2) | Oct 13–21 → Oct 29–6\n[Audit] Rescheduled (Attempt 1 of 2) on Oct 09, 2026 11:21 PM by Mia Franz Oquin Quidez: Dates: Oct 13, 2026 2:00 PM to Oct 21, 2026 12:00 PM -> Oct 29, 2026 12:00 AM to Nov 06, 2026 12:00 AM | Reason: Admin verified & confirmed reschedule request:\n[Timeline] Oct 9, 2026 · 11:21 PM | Reschedule approved | By: Mia Franz Oquin Quidez', 'PBR-GST-00220', 220, 'jzdhish\r\nkjodjOSV\r\nlnvdosho\r\nlkvjofi', NULL, NULL, 0, 0, NULL, NULL, NULL, NULL, NULL, 1, 'Oct 13–21, 2026', NULL),
(6, NULL, NULL, NULL, NULL, NULL, 4, 6300.00, 'rejected', 'pending', NULL, '2026-10-10 08:33:48', '2026-10-10 05:36:37', 'uploads/receipts/receipt_PBR-2026-5949_1791610494.jpg', 'PBR-2026-5949', 'Tifanny Franz Kento', 'kentotifanny@gmail.com', '0931 005 7627', 'house', 4, 'House Kubo', '2026-10-10 13:50:00', '2026-10-19 12:00:00', '9 Night(s) Stay (1:50 PM - 12:00 PM)', NULL, NULL, 4, '3344454575655', '', 'PBR-GST-00220', 220, 'nando', 'Fake or tampered receipt', NULL, 0, 0, NULL, NULL, NULL, NULL, NULL, 0, NULL, NULL),
(7, NULL, NULL, NULL, NULL, NULL, 2, 15400.00, 'proof_submitted', 'pending', NULL, '2026-10-10 07:28:00', '2026-10-11 10:39:21', 'uploads/receipts/receipt_PBR-2026-1548_1791715161.png', 'PBR-2026-1548', 'Garp D Monkey', 'fme090909@gmail.com', '0912 121 2124', 'house', 2, 'Orchid Honeymoon Kubo #2', '2026-10-12 21:53:00', '2026-10-19 12:00:00', '7 Night(s) Stay (9:53 PM - 12:00 PM)', NULL, NULL, 2, '2323232343434', '', 'PBR-GST-00217', 217, 'dffdfdsf\nfdfdsfd', '', NULL, 0, 0, NULL, NULL, NULL, NULL, NULL, 0, NULL, NULL),
(8, NULL, NULL, NULL, NULL, NULL, 1, 3500.00, 'proof_submitted', 'pending', NULL, '2026-10-10 09:47:56', '2026-10-10 15:48:09', 'uploads/receipts/receipt_PBR-2026-2388_1791647289.png', 'PBR-2026-2388', 'Garp D Monkey', 'fme090909@gmail.com', '0912 121 2124', 'house', 1, 'Mahogany Bamboo Kubo #1', '2026-10-13 21:50:00', '2026-10-14 12:00:00', '1 Night(s) Stay (9:50 PM - 12:00 PM)', NULL, NULL, 1, '1221323324234', '', 'PBR-GST-00217', 217, 'Garp D Monkey', '', NULL, 0, 0, NULL, NULL, NULL, NULL, NULL, 0, NULL, NULL),
(9, NULL, NULL, NULL, NULL, NULL, 1, 5800.00, 'proof_submitted', 'pending', NULL, '2026-10-10 10:40:49', '2026-10-10 16:44:01', 'uploads/receipts/receipt_PBR-2026-4853_1791650641.png', 'PBR-2026-4853', 'Garp D Monkey', 'fme090909@gmail.com', '0912 121 2124', 'house', 3, 'Royal Purple Pavilion #3', '2026-10-29 14:00:00', '2026-10-30 12:00:00', '1 Night(s) Stay (2:00 PM - 12:00 PM)', NULL, NULL, 1, '1231322334433', '', 'PBR-GST-00217', 217, 'Garp D Monkey', '', NULL, 0, 0, NULL, NULL, NULL, NULL, NULL, 0, NULL, NULL);

-- --------------------------------------------------------

--
-- Table structure for table `booking_archive`
--

CREATE TABLE `booking_archive` (
  `id` varchar(50) NOT NULL,
  `guest_name` varchar(100) NOT NULL,
  `guest_email` varchar(100) NOT NULL,
  `guest_phone` varchar(50) NOT NULL,
  `type` varchar(20) DEFAULT 'house',
  `item_id` int(11) DEFAULT 1,
  `item_name` varchar(100) NOT NULL,
  `check_in` date NOT NULL,
  `check_out` date DEFAULT NULL,
  `time_slot` varchar(50) DEFAULT NULL,
  `pax` int(11) DEFAULT 1,
  `total_amount` decimal(10,2) NOT NULL,
  `gcash_ref` varchar(50) DEFAULT NULL,
  `booking_status` varchar(30) DEFAULT 'completed',
  `archived_at` datetime DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `dining_menu`
--

CREATE TABLE `dining_menu` (
  `id` int(11) NOT NULL,
  `item_name` varchar(100) NOT NULL,
  `category` varchar(50) NOT NULL,
  `price` decimal(10,2) NOT NULL,
  `description` text DEFAULT NULL,
  `image` varchar(255) DEFAULT NULL,
  `allergens` varchar(255) DEFAULT NULL,
  `is_available` tinyint(1) DEFAULT 1
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `dining_menu`
--

INSERT INTO `dining_menu` (`id`, `item_name`, `category`, `price`, `description`, `image`, `allergens`, `is_available`) VALUES
(1, 'Sizzling Garlic Butter Tiger Prawns', 'Main Dishes', 680.00, 'Fresh tiger prawns sauteed in rich garlic butter sauce, chili flakes, and fresh scallions.', 'https://images.unsplash.com/photo-1565680018434-b513d5e5fd47?auto=format&fit=crop&w=600&q=80', 'Seafood / Shellfish, Dairy', 1),
(2, 'Purple Bamboo Special Grilled Chicken Inasal', 'Main Dishes', 450.00, 'Charcoal-grilled marinated chicken leg with annatto lemongrass oil and soy dip.', 'https://images.unsplash.com/photo-1598515214211-89d3c73ae83b?auto=format&fit=crop&w=600&q=80', 'Soy', 1),
(3, 'Creamy Peanut Kare-Kare Tender Beef', 'Main Dishes', 580.00, 'Traditional Filipino stew cooked in rich toasted peanut & sesame sauce with native vegetables.', 'https://images.unsplash.com/photo-1546069901-ba9599a7e63c?auto=format&fit=crop&w=600&q=80', 'Nuts / Peanuts, Seafood / Shellfish', 1),
(4, 'Crispy Calamares with Dip', 'Appetizers', 340.00, 'Deep-fried squid rings served with spicy garlic mayo and tartar sauce.', 'https://images.unsplash.com/photo-1599487488170-d11ec9c172f0?auto=format&fit=crop&w=600&q=80', 'Seafood / Shellfish, Eggs, Gluten', 1),
(5, 'Fresh Tropical Dragonfruit & Mango Smoothie', 'Drinks & Cocktails', 180.00, 'Blended fresh mangoes, dragonfruit, coconut milk, and shaved ice.', 'https://images.unsplash.com/photo-1553530666-ba11a7da3888?auto=format&fit=crop&w=600&q=80', 'Dairy-Free, Vegan', 1),
(6, 'Purple Velvet Ube Cream Shake', 'Drinks & Cocktails', 220.00, 'Signature purple yam thick milkshake topped with whipped cream, macapuno, and caramel.', 'https://images.unsplash.com/photo-1572490122747-3968b75cc699?auto=format&fit=crop&w=600&q=80', 'Dairy', 1),
(7, 'Bamboo Coconut Halo-Halo Supreme', 'Desserts & Snacks', 250.00, 'Shaved ice with sweet beans, nata de coco, ube halaya, leche flan, and scoop of ube ice cream.', 'https://images.unsplash.com/photo-1563805042-7684c019e1cb?auto=format&fit=crop&w=600&q=80', 'Dairy, Eggs', 1),
(8, 'Signature Poolside Mai Tai Cocktail', 'Drinks & Cocktails', 320.00, 'Dark rum, orange curaçao, almond syrup, and fresh lime juice served in bamboo tumbler.', 'https://images.unsplash.com/photo-1514362545857-3bc16c4c7d1b?auto=format&fit=crop&w=600&q=80', 'Nuts / Peanuts', 1);

-- --------------------------------------------------------

--
-- Table structure for table `dining_orders`
--

CREATE TABLE `dining_orders` (
  `id` varchar(50) NOT NULL,
  `user_id` int(11) DEFAULT NULL,
  `guest_name` varchar(100) NOT NULL,
  `guest_email` varchar(100) NOT NULL,
  `guest_phone` varchar(50) NOT NULL,
  `kubo_location` varchar(100) NOT NULL,
  `order_items_json` text NOT NULL,
  `total_amount` decimal(10,2) NOT NULL,
  `guest_allergies` varchar(255) DEFAULT NULL,
  `allergy_notes` text DEFAULT NULL,
  `requested_serve_time` varchar(50) DEFAULT 'ASAP (15-20 Mins)',
  `order_status` varchar(50) DEFAULT 'pending',
  `kitchen_notes` text DEFAULT NULL,
  `created_at` datetime DEFAULT current_timestamp(),
  `prepared_at` datetime DEFAULT NULL,
  `served_at` datetime DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `dining_orders`
--

INSERT INTO `dining_orders` (`id`, `user_id`, `guest_name`, `guest_email`, `guest_phone`, `kubo_location`, `order_items_json`, `total_amount`, `guest_allergies`, `allergy_notes`, `requested_serve_time`, `order_status`, `kitchen_notes`, `created_at`, `prepared_at`, `served_at`) VALUES
('PBR-FOOD-2026-2061', 217, 'Garp D Monkey', 'fme090909@gmail.com', '09121212124', 'Beach Kubo', '[{\"id\":2,\"name\":\"Purple Bamboo Special Grilled Chicken Inasal\",\"price\":450,\"allergens\":\"Soy\",\"qty\":1}]', 450.00, '', '', 'ASAP (15-20 Mins)', 'pending', '', '2026-10-10 21:23:33', NULL, NULL),
('PBR-FOOD-2026-4028', NULL, 'Kent Harold Delios Rollo', 'rollokentharold@gmail.com', '09171234567', 'Mahogany Bamboo Kubo #1', '[{\"id\":1,\"name\":\"Sizzling Garlic Butter Tiger Prawns\",\"price\":680,\"qty\":2},{\"id\":6,\"name\":\"Purple Velvet Ube Cream Shake\",\"price\":220,\"qty\":2}]', 1800.00, 'Seafood / Shellfish, Dairy', 'Please make sure food is prepared with separate tongs', 'ASAP (15-20 Mins)', 'served', '', '2026-10-10 20:52:52', '2026-10-10 21:07:49', '2026-10-10 21:08:00'),
('PBR-FOOD-2026-5908', 222, 'AV', 'nightmarefox50@gmail.com', '09221212212', 'Beach Kubo', '[{\"id\":1,\"name\":\"Sizzling Garlic Butter Tiger Prawns\",\"price\":680,\"allergens\":\"Seafood \\/ Shellfish, Dairy\",\"qty\":1}]', 680.00, '', '', 'ASAP (15-20 Mins)', 'pending', '', '2026-10-10 20:47:51', NULL, NULL),
('PBR-FOOD-2026-6023', 217, 'Garp D Monkey', 'fme090909@gmail.com', '09121212124', 'Beach Kubo', '[{\"id\":2,\"name\":\"Purple Bamboo Special Grilled Chicken Inasal\",\"price\":450,\"allergens\":\"Soy\",\"qty\":1}]', 450.00, '', '', 'ASAP (15-20 Mins)', 'accepted', '', '2026-10-10 21:23:11', NULL, NULL),
('PBR-FOOD-2026-9761', NULL, 'Kent Harold Delios Rollo', 'rollokentharold@gmail.com', '09171234567', 'Mahogany Bamboo Kubo #1', '[{\"id\":1,\"name\":\"Sizzling Garlic Butter Tiger Prawns\",\"price\":680,\"qty\":2},{\"id\":6,\"name\":\"Purple Velvet Ube Cream Shake\",\"price\":220,\"qty\":2}]', 1800.00, 'Seafood / Shellfish, Dairy', 'Please make sure food is prepared with separate tongs', 'ASAP (15-20 Mins)', 'pending', 'Guest is in Kubo #1', '2026-10-10 20:52:51', NULL, NULL);

-- --------------------------------------------------------

--
-- Table structure for table `gcash_payments`
--

CREATE TABLE `gcash_payments` (
  `payment_id` int(11) NOT NULL,
  `booking_id` int(11) NOT NULL,
  `reference_number` varchar(50) NOT NULL,
  `account_name` varchar(100) NOT NULL,
  `account_number` varchar(20) NOT NULL,
  `amount_paid` decimal(10,2) NOT NULL,
  `proof_image` varchar(255) NOT NULL,
  `verification_status` enum('pending','approved','rejected') DEFAULT 'pending',
  `rejection_reason` text DEFAULT NULL,
  `verified_by` int(11) DEFAULT NULL,
  `verified_at` datetime DEFAULT NULL,
  `submitted_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `house_categories`
--

CREATE TABLE `house_categories` (
  `category_id` int(11) NOT NULL,
  `category_name` varchar(150) NOT NULL,
  `description` text DEFAULT NULL,
  `base_price` decimal(10,2) NOT NULL,
  `max_capacity` int(11) NOT NULL,
  `category_image` varchar(255) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `house_categories`
--

INSERT INTO `house_categories` (`category_id`, `category_name`, `description`, `base_price`, `max_capacity`, `category_image`) VALUES
(1, 'Basic Sea View Room', '6sqm\r\n1 bedroom\r\n1 Double Bed\r\nFree Wifi\r\nSleeps 2', 1500.00, 2, NULL),
(2, 'Honeymoon Pool Condo', '25sqm\r\n1 bedroom\r\n1 Queen Bed\r\nFree Wifi\r\nSleeps 2', 2500.00, 2, NULL),
(3, 'Family Sea View Room', '44 sqm\r\n2 bedrooms\r\n1 Queen Bed, 1 Large Twin Bunk Bed and 1 Twin Bunf Bed\r\nFree Wifi\r\nSleeps 6', 3000.00, 6, NULL),
(4, 'Presidential Ocean House', '102 sqm\r\n2 bedrooms\r\n1 King Bed and 2 Double Bunk beds\r\nFree Wifi', 5000.00, 8, NULL);

-- --------------------------------------------------------

--
-- Table structure for table `house_kubos`
--

CREATE TABLE `house_kubos` (
  `kubo_id` int(11) NOT NULL,
  `category_id` int(11) NOT NULL,
  `kubo_name_number` varchar(50) NOT NULL,
  `description` text DEFAULT NULL,
  `price_per_night` decimal(10,2) NOT NULL,
  `capacity` int(11) NOT NULL,
  `status` varchar(50) NOT NULL DEFAULT 'available',
  `primary_image` varchar(255) DEFAULT NULL,
  `badges` varchar(255) DEFAULT NULL,
  `renovation_notes` text DEFAULT NULL,
  `renovation_start` date DEFAULT NULL,
  `renovation_end` date DEFAULT NULL,
  `available_units` int(11) NOT NULL DEFAULT 5
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `house_kubos`
--

INSERT INTO `house_kubos` (`kubo_id`, `category_id`, `kubo_name_number`, `description`, `price_per_night`, `capacity`, `status`, `primary_image`, `badges`, `renovation_notes`, `renovation_start`, `renovation_end`, `available_units`) VALUES
(1, 3, 'Mahogany Bamboo Kubo #1', 'Spacious air-conditioned Kubo with private veranda, refrigerator, and 6-person capacity.', 3500.00, 5, 'available', 'https://images.unsplash.com/photo-1590490360182-c33d57733427?auto=format&fit=crop&w=600&q=80', 'Free WiFi, Aircon', NULL, NULL, NULL, 5),
(2, 2, 'Orchid Honeymoon Kubo #2', 'Cozy bamboo pavilion steps away from the main infinity pool. Romantic queen bed setup.', 2200.00, 2, 'available', 'https://images.unsplash.com/photo-1582719478250-c89cae4dc85b?auto=format&fit=crop&w=600&q=80', 'Poolside, Smart TV', NULL, NULL, NULL, 5),
(3, 4, 'Royal Purple Pavilion #3', '2-Storey bamboo villa equipped with private grilling station, sound system, and up to 15 guests capacity.', 5800.00, 15, 'available', 'https://images.unsplash.com/photo-1618773928121-c32242e63f39?auto=format&fit=crop&w=600&q=80', 'Up to 15 Pax, BBQ Grill', NULL, NULL, NULL, 5),
(4, 1, 'House Kubo', 'Cozy bamboo cottage with a relaxing atmosphere and beautiful sea views.', 700.00, 4, 'available', 'uploads/kubos/kubo_4_1791602774.jpg', 'Free WiFi, Aircon', NULL, NULL, NULL, 1);

-- --------------------------------------------------------

--
-- Table structure for table `kubo_amenities`
--

CREATE TABLE `kubo_amenities` (
  `kubo_id` int(11) NOT NULL,
  `amenity_id` int(11) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `notifications`
--

CREATE TABLE `notifications` (
  `notification_id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `title` varchar(100) NOT NULL,
  `message` text NOT NULL,
  `is_read` tinyint(1) DEFAULT 0,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `reviews`
--

CREATE TABLE `reviews` (
  `review_id` int(11) NOT NULL,
  `booking_id` varchar(50) DEFAULT NULL,
  `user_name` varchar(100) DEFAULT NULL,
  `guest` varchar(100) DEFAULT NULL,
  `guest_id` int(11) DEFAULT NULL,
  `profile_id` varchar(50) DEFAULT NULL,
  `item` varchar(100) DEFAULT NULL,
  `rating` int(11) DEFAULT 5,
  `comment` text DEFAULT NULL,
  `staff_reply` text DEFAULT NULL,
  `staff_reply_by` varchar(100) DEFAULT NULL,
  `staff_reply_at` varchar(50) DEFAULT NULL,
  `is_approved` tinyint(1) DEFAULT 1,
  `is_published` tinyint(1) DEFAULT 1,
  `created_at` datetime DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `sent_emails`
--

CREATE TABLE `sent_emails` (
  `id` int(11) NOT NULL,
  `ref_id` varchar(50) DEFAULT NULL,
  `to_email` varchar(100) NOT NULL,
  `subject` varchar(255) NOT NULL,
  `sent_status` varchar(50) DEFAULT 'delivered',
  `error_message` text DEFAULT NULL,
  `sent_at` datetime DEFAULT current_timestamp(),
  `meta_json` text DEFAULT NULL,
  `html_file` varchar(255) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `sent_emails`
--

INSERT INTO `sent_emails` (`id`, `ref_id`, `to_email`, `subject`, `sent_status`, `error_message`, `sent_at`, `meta_json`, `html_file`) VALUES
(1, 'ADM-PBRADM00001-1791295934', 'rollokent@gmail.com', '🔐 Your Administrator Account Credentials - Purple Bamboo Resort', 'logged_locally', 'PHPMailer failed to send staff credentials email.', '2026-10-06 22:12:17', '{\"staff_name\":\"Rollo, Kent Harold Delios\",\"profile_id\":\"PBR-ADM-00001\",\"action_type\":\"created\",\"role\":\"admin\"}', 'data/emails/ADM-PBRADM00001-1791295934.html'),
(2, 'ADM-PBRADM00207-1791297015', 'admin@purplebamboo.com', '🔐 Your Administrator Password Has Been Updated - Purple Bamboo Resort', 'logged_locally', 'PHPMailer failed to send staff credentials email.', '2026-10-06 22:30:17', '{\"staff_name\":\"Estrada, Mia Franz Quidez\",\"profile_id\":\"PBR-ADM-00207\",\"action_type\":\"updated\",\"role\":\"admin\"}', 'data/emails/ADM-PBRADM00207-1791297015.html'),
(3, 'ADM-PBRADM00003-1791298288', 'rollokentharold@gmail.com', '🔐 Your Administrator Account Credentials - Purple Bamboo Resort', 'logged_locally', 'PHPMailer failed to send staff credentials email.', '2026-10-06 22:51:31', '{\"staff_name\":\"Rollo, Kent harold\",\"profile_id\":\"PBR-ADM-00003\",\"action_type\":\"created\",\"role\":\"admin\"}', 'data/emails/ADM-PBRADM00003-1791298288.html'),
(4, 'ADM-PBRADM00004-1791301187', 'Khunaguero355@gmail.com', '🔐 Your Administrator Account Credentials - Purple Bamboo Resort', 'delivered', NULL, '2026-10-06 23:39:51', '{\"staff_name\":\"Rollo, Kent harold\",\"profile_id\":\"PBR-ADM-00004\",\"action_type\":\"created\",\"role\":\"admin\"}', 'data/emails/ADM-PBRADM00004-1791301187.html'),
(5, 'ADM-PBRADM00004-1791302237', 'khunaguero355@gmail.com', '🔐 Your Administrator Password Has Been Updated - Purple Bamboo Resort', 'delivered', NULL, '2026-10-06 23:57:22', '{\"staff_name\":\"Rollo, Kent harold\",\"profile_id\":\"PBR-ADM-00004\",\"action_type\":\"updated\",\"role\":\"admin\"}', 'data/emails/ADM-PBRADM00004-1791302237.html'),
(6, 'STF-PBRSTF00001-1791302547', 'aimznarf@gmail.com', '🔑 Your Staff Account Credentials - Purple Bamboo Resort', 'delivered', NULL, '2026-10-07 00:02:32', '{\"staff_name\":\"Quidez, Mia Franz\",\"profile_id\":\"PBR-STF-00001\",\"action_type\":\"created\",\"role\":\"staff\"}', 'data/emails/STF-PBRSTF00001-1791302547.html'),
(7, 'PBR-2026-1082', 'rollokentharold@gmail.com', '🌿 Booking Confirmation & Schedule - [PBR-2026-1082] | Purple Bamboo Resort', 'delivered', NULL, '2026-10-07 00:10:40', '{\"guest_name\":\"Kent Harold Delios Rollo\",\"item_name\":\"Mahogany Bamboo Kubo #1\",\"booked_at\":\"Tuesday, October 6, 2026 at 6:10 PM\",\"check_in\":\"Wednesday, October 7, 2026 at 2:00 PM\",\"check_out\":\"Friday, October 9, 2026 at 12:00 PM (Noon)\",\"total_amount\":7350}', 'data/emails/PBR-2026-1082.html'),
(8, 'ADM-PBRADM00005-1791311906', 'aimznarf@gmail.com', '🔐 Your Administrator Account Credentials - Purple Bamboo Resort', 'delivered', NULL, '2026-10-06 11:38:27', '{\"staff_name\":\"Quidez, Mia Franz Oquin\",\"profile_id\":\"PBR-ADM-00005\",\"action_type\":\"created\",\"role\":\"admin\"}', 'data/emails/ADM-PBRADM00005-1791311906.html'),
(9, 'ADM-PBRADM00006-1791312041', 'Stefaniegomez335@gmail.com', '🔐 Your Administrator Account Credentials - Purple Bamboo Resort', 'delivered', NULL, '2026-10-06 11:40:45', '{\"staff_name\":\"Gomez, Stefanie Natividad\",\"profile_id\":\"PBR-ADM-00006\",\"action_type\":\"created\",\"role\":\"admin\"}', 'data/emails/ADM-PBRADM00006-1791312041.html'),
(10, 'PBR-2026-3427', 'deinlauvix@gmail.com', '🌿 Booking Confirmation & Schedule - [PBR-2026-3427] | Purple Bamboo Resort', 'delivered', NULL, '2026-10-06 12:09:40', '{\"guest_name\":\"Registered Guest\",\"item_name\":\"Royal Purple Pavilion #3\",\"booked_at\":\"Tuesday, October 6, 2026 at 3:09 PM\",\"check_in\":\"Wednesday, October 7, 2026 at 2:00 PM\",\"check_out\":\"Saturday, October 10, 2026 at 12:00 PM (Noon)\",\"total_amount\":17400}', 'data/emails/PBR-2026-3427.html'),
(11, 'PBR-2026-9460', 'guest@purplebamboo.com', '🌿 Booking Confirmation & Schedule - [PBR-2026-9460] | Purple Bamboo Resort', 'delivered', NULL, '2026-10-06 12:17:06', '{\"guest_name\":\"Clyde Dekumado\",\"item_name\":\"Beach Kubo\",\"booked_at\":\"Tuesday, October 6, 2026 at 3:17 PM\",\"check_in\":\"Tuesday, October 6, 2026 at 2:00 PM\",\"check_out\":\"Thursday, October 8, 2026 at 12:00 PM (Noon)\",\"total_amount\":7000}', 'data/emails/PBR-2026-9460.html'),
(12, 'PBR-2026-2401', 'clydedekumado@gmail.com', '🌿 Booking Confirmation & Schedule - [PBR-2026-2401] | Purple Bamboo Resort', 'delivered', NULL, '2026-10-09 11:15:15', '{\"guest_name\":\"Registered Guest\",\"item_name\":\"Mahogany Bamboo Kubo #1\",\"booked_at\":\"Friday, October 9, 2026 at 1:57 PM\",\"check_in\":\"Saturday, October 10, 2026 at 2:00 PM\",\"check_out\":\"Sunday, October 11, 2026 at 12:00 PM (Noon)\",\"total_amount\":3500}', 'data/emails/PBR-2026-2401.html'),
(13, 'PBR-2026-4906', 'kentotifanny@gmail.com', '🌿 Booking Confirmation & Schedule - [PBR-2026-4906] | Purple Bamboo Resort', 'delivered', NULL, '2026-10-09 20:20:26', '{\"guest_name\":\"Tifanny Franz Kento\",\"item_name\":\"Mahogany Bamboo Kubo #1\",\"booked_at\":\"Friday, October 9, 2026 at 11:12 PM\",\"check_in\":\"Tuesday, October 13, 2026 at 2:00 PM\",\"check_out\":\"Wednesday, October 21, 2026 at 12:00 PM (Noon)\",\"total_amount\":29200}', 'data/emails/PBR-2026-4906.html');

-- --------------------------------------------------------

--
-- Table structure for table `site_content`
--

CREATE TABLE `site_content` (
  `content_key` varchar(50) NOT NULL,
  `content_value` longtext NOT NULL,
  `updated_by` varchar(100) DEFAULT NULL,
  `updated_at` datetime DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `site_content`
--

INSERT INTO `site_content` (`content_key`, `content_value`, `updated_by`, `updated_at`) VALUES
('homepage', '{\"resort_logo\":\"uploads\\/branding\\/resort_logo_20261010_011618_6ac9ca22cadc1.jpg\",\"resort_brand_title\":\"PURPLE BAMBOO\",\"resort_brand_subtitle\":\"BEACH RESORT\",\"resort_name\":\"The Blessed Purple Bamboo Beach Resort\",\"hero_badge\":\"Exclusive Tropical Sanctuary\",\"hero_title\":\"Welcome to Purple Bamboo Resort\",\"hero_subtitle\":\"Experience serene kubos, infinity pools, and tropical bliss In Telbang, Alaminos City, Pangasinan\",\"hero_btn1_text\":\"Book Your Stay\",\"hero_btn1_url\":\"browse_kubos.php\",\"hero_btn2_text\":\"Check Availability\",\"hero_btn2_url\":\"check_availability.php\",\"hero_pill1\":\"Verified Resort Booking\",\"hero_pill2\":\"Crystal Clear Pools\",\"hero_pill3\":\"5-Star Rated Stays\",\"features\":[{\"icon\":\"fa-house-chimney\",\"color\":\"#7c3aed\",\"title\":\"Luxury Private Kubos\",\"desc\":\"Air-conditioned authentic bamboo kubos.\"},{\"icon\":\"fa-person-swimming\",\"color\":\"#7c3aed\",\"title\":\"Swimming Pools\",\"desc\":\"Adult and kiddie swimming pools.\"},{\"icon\":\"fa-water\",\"color\":\"#059669\",\"title\":\"Calm Natural Ambience\",\"desc\":\"Lush tropical bamboo scenery.\"},{\"icon\":\"fa-spa\",\"color\":\"#e11d48\",\"title\":\"Events & Gatherings\",\"desc\":\"Ideal venue for parties and family reunions.\"}],\"kubos_section_title\":\"Our Exclusive Accommodations\",\"kubos_section_subtitle\":\"Select from our traditional bamboo kubos.\",\"activities_section_title\":\"Resort Amenities & Relaxation\",\"activities_section_subtitle\":\"Enjoy our refreshing swimming pools.\",\"reviews_section_title\":\"What Our Guests Say\",\"reviews_section_subtitle\":\"Real experiences and reviews from verified visitors.\",\"guarantee_badge\":\"Instant GCash Booking\",\"guarantee_title\":\"Book Your Stay with Instant GCash Verification\",\"guarantee_subtitle\":\"Get an instant booking reference number upon payment.\",\"guarantee_btn_text\":\"Check Dates & Reserve Now\",\"guarantee_btn_url\":\"check_availability.php\",\"resort_location\":\"Telbang, Alaminos City, Pangasinan\",\"resort_phone\":\"0956 697 8679\",\"gcash_name\":\"PURPLE BAMBOO RESORT\",\"gcash_number\":\"0956 697 8679\",\"gcash_qr_image\":\"assets\\/gcash_qr.jpg\",\"gcash_show_on_home\":true,\"gcash_instructions\":\"1. Open GCash or banking app.\\n2. Tap \'Scan QR\' and scan the official QR code.\\n3. Input reservation amount.\\n4. Save screenshot of receipt.\",\"gcash_card_title\":\"Official GCash Payment Channel\",\"gcash_card_subtitle\":\"Scan with GCash or InstaPay for Instant Reservation Verification\",\"announcement_enabled\":false,\"announcement_text\":\"\",\"announcement_link\":\"\",\"updated_at\":\"2026-10-10 01:16:18\",\"updated_by\":\"Mia Franz Oquin Quidez\",\"resort_email\":\"info@purplebambooresort.com\"}', 'Mia Franz Oquin Quidez', '2026-10-09 22:16:18');

-- --------------------------------------------------------

--
-- Table structure for table `site_settings`
--

CREATE TABLE `site_settings` (
  `setting_key` varchar(100) NOT NULL,
  `setting_value` text DEFAULT NULL,
  `updated_at` datetime DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `staff`
--

CREATE TABLE `staff` (
  `id` int(11) NOT NULL,
  `profile_id` varchar(50) DEFAULT NULL,
  `first_name` varchar(50) NOT NULL,
  `middle_name` varchar(50) DEFAULT NULL,
  `last_name` varchar(50) NOT NULL,
  `suffix` varchar(20) DEFAULT NULL,
  `email` varchar(100) NOT NULL,
  `phone` varchar(50) NOT NULL,
  `password_hash` varchar(255) NOT NULL,
  `role` varchar(20) DEFAULT 'staff',
  `status` varchar(20) NOT NULL DEFAULT 'active',
  `department` varchar(100) DEFAULT 'Front Desk',
  `position` varchar(100) DEFAULT 'Staff Member',
  `profile_picture` varchar(255) DEFAULT NULL,
  `created_at` datetime DEFAULT current_timestamp(),
  `block_reason` varchar(255) DEFAULT NULL,
  `blocked_at` datetime DEFAULT NULL,
  `blocked_by` varchar(100) DEFAULT NULL,
  `reset_otp` varchar(10) DEFAULT NULL,
  `reset_expires` datetime DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `system_settings`
--

CREATE TABLE `system_settings` (
  `setting_id` int(11) NOT NULL,
  `setting_key` varchar(50) NOT NULL,
  `setting_value` text NOT NULL,
  `setting_description` varchar(255) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `system_settings`
--

INSERT INTO `system_settings` (`setting_id`, `setting_key`, `setting_value`, `setting_description`) VALUES
(1, 'resort_name', 'Purple Bamboo Resort', 'Name of the resort'),
(2, 'gcash_number', '09171234567', 'Resort GCash mobile number'),
(3, 'gcash_account_name', 'Purple Bamboo Resort', 'GCash registered account name'),
(4, 'max_advance_booking_days', '90', 'Days in advance guests can reserve');

-- --------------------------------------------------------

--
-- Table structure for table `users`
--

CREATE TABLE `users` (
  `user_id` int(11) NOT NULL,
  `id` int(11) DEFAULT NULL,
  `profile_id` varchar(50) DEFAULT NULL,
  `full_name` varchar(100) NOT NULL,
  `first_name` varchar(50) DEFAULT NULL,
  `middle_name` varchar(50) DEFAULT NULL,
  `last_name` varchar(50) DEFAULT NULL,
  `suffix` varchar(20) DEFAULT NULL,
  `email` varchar(100) NOT NULL,
  `phone` varchar(20) NOT NULL,
  `password_hash` varchar(255) NOT NULL,
  `role` enum('admin','staff','guest') NOT NULL DEFAULT 'guest',
  `status` varchar(20) NOT NULL DEFAULT 'active',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `reset_token` varchar(255) DEFAULT NULL,
  `reset_token_expires` datetime DEFAULT NULL,
  `emergency_contact_name` varchar(100) DEFAULT NULL,
  `emergency_contact_relationship` varchar(50) DEFAULT NULL,
  `emergency_contact_phone` varchar(50) DEFAULT NULL,
  `emergency_contact_alt_phone` varchar(50) DEFAULT NULL,
  `id_type` varchar(50) DEFAULT NULL,
  `id_number` varchar(100) DEFAULT NULL,
  `id_image` varchar(255) DEFAULT NULL,
  `profile_picture` varchar(255) DEFAULT NULL,
  `health_conditions` varchar(255) DEFAULT NULL,
  `health_info` text DEFAULT NULL,
  `is_blocked` tinyint(1) DEFAULT 0,
  `block_reason` varchar(255) DEFAULT NULL,
  `blocked_at` datetime DEFAULT NULL,
  `blocked_by` varchar(100) DEFAULT NULL,
  `gcash_name` varchar(100) DEFAULT NULL,
  `reset_otp` varchar(10) DEFAULT NULL,
  `reset_expires` datetime DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `users`
--

INSERT INTO `users` (`user_id`, `id`, `profile_id`, `full_name`, `first_name`, `middle_name`, `last_name`, `suffix`, `email`, `phone`, `password_hash`, `role`, `status`, `created_at`, `updated_at`, `reset_token`, `reset_token_expires`, `emergency_contact_name`, `emergency_contact_relationship`, `emergency_contact_phone`, `emergency_contact_alt_phone`, `id_type`, `id_number`, `id_image`, `profile_picture`, `health_conditions`, `health_info`, `is_blocked`, `block_reason`, `blocked_at`, `blocked_by`, `gcash_name`, `reset_otp`, `reset_expires`) VALUES
(215, NULL, NULL, '', 'Kent Harold', 'Delios', 'Rollo', '', 'rollokentharold@gmail.com', '09852970273', '$2y$10$iYSqsTtBtGfhtaKlHgUyi.f67B66zQywGjOb4adQBpqasRcV5Wlyy', 'guest', 'active', '2026-10-06 15:58:08', '2026-10-06 15:58:08', NULL, NULL, 'Kent harold Rollo', 'Parent', '09852970273', '', '', '', '', '', '', '', 0, NULL, NULL, NULL, NULL, NULL, NULL),
(216, NULL, NULL, '', 'Dein', '', 'Lauvix', '', 'deinlauvix@gmail.com', '09511761195', '$2y$10$NXTcsK7WpyKAR93FQoXxPuHFfYky368Dfm6EmAzqiIyC2UpqqG9uC', 'guest', 'active', '2026-10-06 19:07:07', '2026-10-06 19:07:07', NULL, NULL, 'Clyde Dekumado', 'Friend', '09123456789', '', '', '', '', '', 'Asthma, Food / Drug Allergies', '', 0, NULL, NULL, NULL, NULL, NULL, NULL),
(217, NULL, NULL, '', 'Garp', 'D', 'Monkey', '', 'fme090909@gmail.com', '09121212124', '$2y$10$vD7BfzPAmb8reXcgHsB2ouzi70UHVhEZtCH8r6.bbomhI/N1toIuy', 'guest', 'active', '2026-10-07 07:25:14', '2026-10-07 07:25:14', NULL, NULL, 'Garp D Monkey', 'Child', '09121212124', '', '', '', '', '', '', '', 0, NULL, NULL, NULL, NULL, NULL, NULL),
(218, NULL, NULL, '', 'Guest', 'Test', 'Pro', 'Jr.', 'guest4@gmail.com', '09143124123', '$2y$10$d/Ut685mcqSPNJvNne9eA.jihVKoJ1Zd../W.MivMJyDLxB/qT5ra', 'guest', 'active', '2026-10-07 14:20:29', '2026-10-07 14:20:29', NULL, NULL, 'GuestFriend', 'Friend', '09412342114', '09412451325', '', '', '', '', 'Food / Drug Allergies', '', 0, NULL, NULL, NULL, NULL, NULL, NULL),
(219, NULL, NULL, '', 'Gladyvelle', '', 'Rosete', '', 'gladyvellebalisalisarosete@gmail.com', '09517355861', '$2y$10$AtgJDK4al.cKk4RrN7H3gOA8udiiPd.zZFshi8dbGBmt6xZayrZ9u', 'guest', 'active', '2026-10-08 04:53:09', '2026-10-08 04:53:09', NULL, NULL, 'Claire Angelic Rosete', 'Sibling', '09694349238', '', '', '', '', '', 'Food / Drug Allergies', '', 0, NULL, NULL, NULL, NULL, NULL, NULL),
(220, NULL, NULL, '', 'Tifanny', 'Franz', 'Kento', '', 'kentotifanny@gmail.com', '09310057627', '$2y$10$h7Q1uxNOnGET/vbcgc8jzOxYO1x.ZO5RL9XBQnjk2eRGaOFQLhebO', 'guest', 'active', '2026-10-09 02:49:13', '2026-10-09 02:49:13', NULL, NULL, 'Sophia Kento', 'Guardian', '09310057627', '', '', '', '', '', '', '', 0, NULL, NULL, NULL, NULL, NULL, NULL),
(221, NULL, NULL, '', 'clyde', '', 'dekumado', '', 'clydedekumado@gmail.com', '09123908454', '$2y$10$sKPix2hrZk9F/Hhz95.hge2ZD/AtmD2f/nDMuivQmnvXqtq.Lqv4y', 'guest', 'active', '2026-10-09 17:29:53', '2026-10-09 17:29:53', NULL, NULL, 'dein lauvix', 'Friend', '09456501235', '', '', '', '', '', 'Asthma, Food / Drug Allergies', '', 0, NULL, NULL, NULL, NULL, NULL, NULL),
(222, NULL, NULL, '', 'Luffy', 'Dawn', 'Monkey', '', 'nightmarefox50@gmail.com', '09221212212', '$2y$10$kI58qL8P.o26DAZpyqMtleg/I7nPxdxdDDlgL5LUoS6nWANFdrina', 'guest', 'active', '2026-10-10 12:33:51', '2026-10-10 12:33:51', NULL, NULL, 'Garp D Monkey', 'Parent', '09121212124', '09908676756', '', '', '', '', '', '', 0, NULL, NULL, NULL, NULL, NULL, NULL);

--
-- Indexes for dumped tables
--

--
-- Indexes for table `activities`
--
ALTER TABLE `activities`
  ADD PRIMARY KEY (`activity_id`);

--
-- Indexes for table `activity_logs`
--
ALTER TABLE `activity_logs`
  ADD PRIMARY KEY (`log_id`),
  ADD KEY `user_id` (`user_id`);

--
-- Indexes for table `activity_reservations`
--
ALTER TABLE `activity_reservations`
  ADD PRIMARY KEY (`reservation_id`),
  ADD KEY `booking_id` (`booking_id`),
  ADD KEY `activity_id` (`activity_id`);

--
-- Indexes for table `addon_reservations`
--
ALTER TABLE `addon_reservations`
  ADD PRIMARY KEY (`id`),
  ADD KEY `reservation_id` (`reservation_id`),
  ADD KEY `activity_id` (`activity_id`);

--
-- Indexes for table `admins`
--
ALTER TABLE `admins`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `email` (`email`);

--
-- Indexes for table `amenities`
--
ALTER TABLE `amenities`
  ADD PRIMARY KEY (`amenity_id`);

--
-- Indexes for table `bookings`
--
ALTER TABLE `bookings`
  ADD PRIMARY KEY (`booking_id`),
  ADD UNIQUE KEY `booking_reference` (`booking_reference`),
  ADD KEY `guest_id` (`guest_id`),
  ADD KEY `kubo_id` (`kubo_id`);

--
-- Indexes for table `booking_archive`
--
ALTER TABLE `booking_archive`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `dining_menu`
--
ALTER TABLE `dining_menu`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `dining_orders`
--
ALTER TABLE `dining_orders`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `gcash_payments`
--
ALTER TABLE `gcash_payments`
  ADD PRIMARY KEY (`payment_id`),
  ADD KEY `booking_id` (`booking_id`),
  ADD KEY `verified_by` (`verified_by`);

--
-- Indexes for table `house_categories`
--
ALTER TABLE `house_categories`
  ADD PRIMARY KEY (`category_id`);

--
-- Indexes for table `house_kubos`
--
ALTER TABLE `house_kubos`
  ADD PRIMARY KEY (`kubo_id`),
  ADD KEY `category_id` (`category_id`);

--
-- Indexes for table `kubo_amenities`
--
ALTER TABLE `kubo_amenities`
  ADD PRIMARY KEY (`kubo_id`,`amenity_id`),
  ADD KEY `amenity_id` (`amenity_id`);

--
-- Indexes for table `notifications`
--
ALTER TABLE `notifications`
  ADD PRIMARY KEY (`notification_id`),
  ADD KEY `user_id` (`user_id`);

--
-- Indexes for table `reviews`
--
ALTER TABLE `reviews`
  ADD PRIMARY KEY (`review_id`);

--
-- Indexes for table `sent_emails`
--
ALTER TABLE `sent_emails`
  ADD PRIMARY KEY (`id`),
  ADD KEY `ref_id` (`ref_id`),
  ADD KEY `to_email` (`to_email`);

--
-- Indexes for table `site_content`
--
ALTER TABLE `site_content`
  ADD PRIMARY KEY (`content_key`);

--
-- Indexes for table `site_settings`
--
ALTER TABLE `site_settings`
  ADD PRIMARY KEY (`setting_key`);

--
-- Indexes for table `staff`
--
ALTER TABLE `staff`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `email` (`email`);

--
-- Indexes for table `system_settings`
--
ALTER TABLE `system_settings`
  ADD PRIMARY KEY (`setting_id`),
  ADD UNIQUE KEY `setting_key` (`setting_key`);

--
-- Indexes for table `users`
--
ALTER TABLE `users`
  ADD PRIMARY KEY (`user_id`),
  ADD UNIQUE KEY `email` (`email`);

--
-- AUTO_INCREMENT for dumped tables
--

--
-- AUTO_INCREMENT for table `activities`
--
ALTER TABLE `activities`
  MODIFY `activity_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=4;

--
-- AUTO_INCREMENT for table `activity_logs`
--
ALTER TABLE `activity_logs`
  MODIFY `log_id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `activity_reservations`
--
ALTER TABLE `activity_reservations`
  MODIFY `reservation_id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `addon_reservations`
--
ALTER TABLE `addon_reservations`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=4;

--
-- AUTO_INCREMENT for table `admins`
--
ALTER TABLE `admins`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=7;

--
-- AUTO_INCREMENT for table `amenities`
--
ALTER TABLE `amenities`
  MODIFY `amenity_id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `bookings`
--
ALTER TABLE `bookings`
  MODIFY `booking_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=10;

--
-- AUTO_INCREMENT for table `dining_menu`
--
ALTER TABLE `dining_menu`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=9;

--
-- AUTO_INCREMENT for table `gcash_payments`
--
ALTER TABLE `gcash_payments`
  MODIFY `payment_id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `house_categories`
--
ALTER TABLE `house_categories`
  MODIFY `category_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=5;

--
-- AUTO_INCREMENT for table `house_kubos`
--
ALTER TABLE `house_kubos`
  MODIFY `kubo_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=5;

--
-- AUTO_INCREMENT for table `notifications`
--
ALTER TABLE `notifications`
  MODIFY `notification_id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `reviews`
--
ALTER TABLE `reviews`
  MODIFY `review_id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `sent_emails`
--
ALTER TABLE `sent_emails`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=14;

--
-- AUTO_INCREMENT for table `staff`
--
ALTER TABLE `staff`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT for table `system_settings`
--
ALTER TABLE `system_settings`
  MODIFY `setting_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=5;

--
-- AUTO_INCREMENT for table `users`
--
ALTER TABLE `users`
  MODIFY `user_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=223;

--
-- Constraints for dumped tables
--

--
-- Constraints for table `activity_logs`
--
ALTER TABLE `activity_logs`
  ADD CONSTRAINT `activity_logs_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`user_id`) ON DELETE SET NULL;

--
-- Constraints for table `activity_reservations`
--
ALTER TABLE `activity_reservations`
  ADD CONSTRAINT `activity_reservations_ibfk_1` FOREIGN KEY (`booking_id`) REFERENCES `bookings` (`booking_id`) ON DELETE CASCADE,
  ADD CONSTRAINT `activity_reservations_ibfk_2` FOREIGN KEY (`activity_id`) REFERENCES `activities` (`activity_id`) ON DELETE CASCADE;

--
-- Constraints for table `bookings`
--
ALTER TABLE `bookings`
  ADD CONSTRAINT `bookings_ibfk_1` FOREIGN KEY (`guest_id`) REFERENCES `users` (`user_id`) ON DELETE CASCADE,
  ADD CONSTRAINT `bookings_ibfk_2` FOREIGN KEY (`kubo_id`) REFERENCES `house_kubos` (`kubo_id`) ON DELETE CASCADE;

--
-- Constraints for table `gcash_payments`
--
ALTER TABLE `gcash_payments`
  ADD CONSTRAINT `gcash_payments_ibfk_1` FOREIGN KEY (`booking_id`) REFERENCES `bookings` (`booking_id`) ON DELETE CASCADE,
  ADD CONSTRAINT `gcash_payments_ibfk_2` FOREIGN KEY (`verified_by`) REFERENCES `users` (`user_id`) ON DELETE SET NULL;

--
-- Constraints for table `house_kubos`
--
ALTER TABLE `house_kubos`
  ADD CONSTRAINT `house_kubos_ibfk_1` FOREIGN KEY (`category_id`) REFERENCES `house_categories` (`category_id`) ON DELETE CASCADE;

--
-- Constraints for table `kubo_amenities`
--
ALTER TABLE `kubo_amenities`
  ADD CONSTRAINT `kubo_amenities_ibfk_1` FOREIGN KEY (`kubo_id`) REFERENCES `house_kubos` (`kubo_id`) ON DELETE CASCADE,
  ADD CONSTRAINT `kubo_amenities_ibfk_2` FOREIGN KEY (`amenity_id`) REFERENCES `amenities` (`amenity_id`) ON DELETE CASCADE;

--
-- Constraints for table `notifications`
--
ALTER TABLE `notifications`
  ADD CONSTRAINT `notifications_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`user_id`) ON DELETE CASCADE;
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
