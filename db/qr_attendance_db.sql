-- phpMyAdmin SQL Dump
-- version 5.2.1
-- https://www.phpmyadmin.net/
--
-- Host: 127.0.0.1
-- Generation Time: Feb 17, 2026 at 12:30 PM
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
-- Database: `qr_attendance_db`
--

-- --------------------------------------------------------

--
-- Table structure for table `password_resets`
--

CREATE TABLE `password_resets` (
  `id` int(11) NOT NULL,
  `email` varchar(100) NOT NULL,
  `token` varchar(64) NOT NULL,
  `expires_at` datetime NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `tbl_system_settings`
--

CREATE TABLE `tbl_system_settings` (
  `setting_key` varchar(100) NOT NULL,
  `setting_value` varchar(255) NOT NULL,
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `tbl_system_settings`
--

INSERT INTO `tbl_system_settings` (`setting_key`, `setting_value`, `updated_at`) VALUES
('component_selection_enabled', '1', '2026-05-21 11:03:22');

-- --------------------------------------------------------

--
-- Table structure for table `tbl_admin_sections`
--

CREATE TABLE `tbl_admin_sections` (
  `admin_section_id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `course_section` varchar(255) NOT NULL,
  `assigned_by` int(11) DEFAULT NULL,
  `assigned_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `tbl_admin_sections`
--

INSERT INTO `tbl_admin_sections` (`admin_section_id`, `user_id`, `course_section`, `assigned_by`, `assigned_at`) VALUES
(5, 2, 'CWTS 1A', 1, '2026-02-11 08:01:05'),
(6, 4, 'LTS 1A', 1, '2026-02-11 08:01:36'),
(7, 5, 'Alpha 1st', 1, '2026-02-11 08:02:02'),
(8, 4, 'CWTS M', 1, '2026-02-15 02:10:19'),
(9, 6, 'CWTS J', 1, '2026-02-15 10:52:11'),
(10, 5, 'alpha 2nd', 1, '2026-02-16 02:02:05');

-- --------------------------------------------------------

--
-- Table structure for table `tbl_attendance`
--

CREATE TABLE `tbl_attendance` (
  `tbl_attendance_id` int(11) NOT NULL,
  `tbl_student_id` int(11) NOT NULL,
  `time_in` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `notes` text DEFAULT NULL,
  `status` varchar(50) DEFAULT 'On Time',
  `late_email_sent` tinyint(1) NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci;

--
-- Dumping data for table `tbl_attendance`
--

INSERT INTO `tbl_attendance` (`tbl_attendance_id`, `tbl_student_id`, `time_in`, `notes`, `status`) VALUES
(16, 91, '2026-02-16 04:40:55', NULL, 'Late'),
(17, 109, '2026-02-17 03:16:00', NULL, 'Late');

-- --------------------------------------------------------

--
-- Table structure for table `tbl_attendance_archive`
--

CREATE TABLE `tbl_attendance_archive` (
  `tbl_attendance_archive_id` int(11) NOT NULL,
  `tbl_attendance_id` int(11) NOT NULL,
  `tbl_student_id` int(11) NOT NULL,
  `time_in` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `status` varchar(50) DEFAULT NULL,
  `archived_date` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `tbl_attendance_archive`
--

INSERT INTO `tbl_attendance_archive` (`tbl_attendance_archive_id`, `tbl_attendance_id`, `tbl_student_id`, `time_in`, `archived_date`) VALUES
(1, 2, 1, '2024-03-13 00:45:37', '2026-02-10 00:23:44'),
(2, 4, 1, '2026-02-11 03:11:00', '2026-02-11 03:16:25'),
(3, 5, 4, '2026-02-11 03:11:00', '2026-02-11 03:16:25'),
(4, 6, 2, '2026-02-11 03:12:00', '2026-02-11 03:16:25'),
(5, 8, 5, '2026-02-10 23:44:00', '2026-02-12 01:08:13'),
(6, 7, 1, '2026-02-11 05:36:00', '2026-02-12 01:08:13'),
(7, 12, 9, '2026-02-15 02:32:00', '2026-02-15 02:33:59'),
(8, 11, 10, '2026-02-15 02:33:23', '2026-02-15 02:33:59'),
(9, 10, 12, '2026-02-15 02:33:14', '2026-02-15 02:33:59'),
(10, 15, 91, '2026-02-16 02:05:42', '2026-02-16 02:08:26');

-- --------------------------------------------------------

--
-- Table structure for table `tbl_landing_sections`
--

CREATE TABLE `tbl_landing_sections` (
  `section_key` varchar(60) NOT NULL,
  `kicker` varchar(150) DEFAULT NULL,
  `title` varchar(255) NOT NULL,
  `body` text DEFAULT NULL,
  `payload` longtext DEFAULT NULL,
  `updated_by` int(11) DEFAULT NULL,
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `tbl_landing_staff`
--

CREATE TABLE `tbl_landing_staff` (
  `landing_staff_id` int(11) NOT NULL,
  `full_name` varchar(150) NOT NULL,
  `position_title` varchar(150) NOT NULL,
  `program` varchar(30) NOT NULL DEFAULT 'NSTP',
  `group_label` varchar(100) NOT NULL DEFAULT 'NSTP Office',
  `photo_path` varchar(255) DEFAULT NULL,
  `sort_order` int(11) NOT NULL DEFAULT 0,
  `is_visible` tinyint(1) NOT NULL DEFAULT 1,
  `created_by` int(11) DEFAULT NULL,
  `updated_by` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `tbl_student`
--

CREATE TABLE `tbl_student` (
  `tbl_student_id` int(11) NOT NULL,
  `user_id` int(11) DEFAULT NULL,
  `student_number` varchar(10) DEFAULT NULL,
  `student_name` varchar(255) NOT NULL,
  `original_section` varchar(255) DEFAULT NULL,
  `course_section` varchar(255) NOT NULL,
  `generated_code` varchar(255) NOT NULL,
  `qr_code` varchar(255) DEFAULT NULL,
  `created_by` int(11) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `tbl_public_student_registrations`
--

CREATE TABLE `tbl_public_student_registrations` (
  `registration_id` int(11) NOT NULL,
  `form_id` int(11) DEFAULT NULL,
  `user_id` int(11) DEFAULT NULL,
  `registrant_role` varchar(20) NOT NULL DEFAULT 'student',
  `last_name` varchar(100) NOT NULL,
  `extension_name` varchar(30) DEFAULT NULL,
  `first_name` varchar(100) NOT NULL,
  `middle_name` varchar(100) NOT NULL,
  `place_of_birth` varchar(255) NOT NULL,
  `date_of_birth` date NOT NULL,
  `religion` varchar(120) NOT NULL DEFAULT 'N/A',
  `email` varchar(150) NOT NULL,
  `province` varchar(120) NOT NULL,
  `city_municipality` varchar(120) NOT NULL,
  `barangay` varchar(120) NOT NULL,
  `street` varchar(180) NOT NULL,
  `house_no` varchar(80) NOT NULL,
  `student_number` varchar(10) DEFAULT NULL,
  `college` varchar(150) NOT NULL,
  `course` varchar(150) NOT NULL,
  `major` varchar(120) NOT NULL DEFAULT 'N/A',
  `year_section` varchar(40) NOT NULL,
  `component` varchar(20) DEFAULT NULL,
  `shirt_size` varchar(30) DEFAULT NULL,
  `formal_picture` varchar(255) NOT NULL,
  `email_sent` tinyint(1) NOT NULL DEFAULT 0,
  `status` varchar(40) NOT NULL DEFAULT 'submitted',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `tbl_public_registration_forms`
--

CREATE TABLE `tbl_public_registration_forms` (
  `form_id` int(11) NOT NULL,
  `form_title` varchar(150) NOT NULL,
  `form_slug` varchar(80) NOT NULL,
  `registration_role` varchar(20) NOT NULL DEFAULT 'student',
  `field_config` text NOT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_by` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `tbl_announcements`
--

CREATE TABLE `tbl_announcements` (
  `announcement_id` int(11) NOT NULL,
  `title` varchar(180) NOT NULL,
  `body` text NOT NULL,
  `scope_program` varchar(20) DEFAULT NULL,
  `created_by` int(11) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `tbl_notifications`
--

CREATE TABLE `tbl_notifications` (
  `notification_id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `type` varchar(40) NOT NULL,
  `title` varchar(180) NOT NULL,
  `message` text NOT NULL,
  `related_table` varchar(80) DEFAULT NULL,
  `related_id` int(11) DEFAULT NULL,
  `emailed` tinyint(1) NOT NULL DEFAULT 0,
  `is_read` tinyint(1) NOT NULL DEFAULT 0,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `read_at` datetime DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `tbl_student`
--

INSERT INTO `tbl_student` (`tbl_student_id`, `user_id`, `student_name`, `original_section`, `course_section`, `generated_code`, `qr_code`, `created_by`) VALUES
(88, NULL, 'Olet, Jan Andre', 'BSIT 1A', 'LTS 1A', 'STU_6991a38db04c1_4226', NULL, 4),
(89, NULL, 'Lagunilla, Steven', 'BSIT 1A', 'LTS 1A', 'STU_6991a38db8a14_5433', NULL, 4),
(90, NULL, 'Rivera, Ivan', 'BSIT 1A', 'LTS 1A', 'STU_6991a38dba7b1_4629', NULL, 4),
(91, NULL, 'Juan, Jonelle', 'BSIT 1A', 'LTS 1A', 'STU_6991a38dbb642_7850', NULL, 4),
(92, NULL, 'jamo', 'BSIT 1A', 'CWTS M', 'oiRMW6pVVf', NULL, 4),
(98, NULL, 'Olet, Jan Andre', 'BSIT 1A', 'Alpha 1st', 'STU_6993d49091bd3_5106', NULL, 5),
(99, NULL, 'Lagunilla, Steven', 'BSIT 1A', 'Alpha 1st', 'STU_6993d49092ab7_9994', NULL, 5),
(100, NULL, 'Juan, Jonelle', 'BSIT 1A', 'Alpha 1st', 'STU_6993d4909396e_4368', NULL, 5),
(101, NULL, 'Tudla, Janel', 'BSIT 1A', 'Alpha 1st', 'STU_6993d49095293_3941', NULL, 5),
(102, NULL, 'Castillo, Wencel', 'BSIT 1A', 'Alpha 1st', 'STU_6993d4909606c_7674', NULL, 5),
(103, NULL, 'Dela Cruz, Rizza', 'BSIT 1A', 'Alpha 1st', 'STU_6993d49096c0c_8086', NULL, 5),
(104, NULL, 'Balbin, Jenard', 'BSIT 1B', 'Alpha 1st', 'STU_6993d490976e7_9686', NULL, 5),
(105, NULL, 'Santos, Joshua', 'BSIT 1B', 'Alpha 1st', 'STU_6993d490981a3_8412', NULL, 5),
(106, NULL, 'Mandapat, Karl', 'BSIT 1B', 'Alpha 1st', 'STU_6993d490990d5_3234', NULL, 5),
(107, NULL, 'Hago, Kenneth', 'BSIT 1B', 'Alpha 1st', 'STU_6993d49099bbe_9034', NULL, 5),
(108, NULL, 'Cachero, Gebie', 'BSGE 1', 'Alpha 1st', 'STU_6993d4909a842_5982', NULL, 5),
(109, NULL, 'Aduca, Jennifer', 'BSGE 1', 'Alpha 1st', 'STU_6993d4909b38f_4521', NULL, 5),
(110, NULL, 'Tomas, Soony boy', 'BSGE 1', 'Alpha 1st', 'STU_6993d4909c07d_9196', NULL, 5),
(111, NULL, 'Manio, Natahniel', 'BSABE', 'Alpha 1st', 'STU_6993d4909cf80_8571', NULL, 5),
(112, NULL, 'Dela Cruz, Alexis', 'BSABE', 'Alpha 1st', 'STU_6993d4909dca5_3021', NULL, 5);

-- --------------------------------------------------------

--
-- Table structure for table `tbl_users`
--

CREATE TABLE `tbl_users` (
  `user_id` int(11) NOT NULL,
  `username` varchar(50) NOT NULL,
  `email` varchar(100) NOT NULL,
  `password_hash` varchar(255) NOT NULL,
  `full_name` varchar(100) NOT NULL,
  `role` enum('student','facilitator','coordinator','super_admin') NOT NULL DEFAULT 'student',
  `program` varchar(20) DEFAULT NULL,
  `shirt_size` varchar(30) DEFAULT NULL,
  `assigned_section` varchar(255) DEFAULT NULL,
  `profile_picture` varchar(255) DEFAULT NULL,
  `last_password_change` timestamp NULL DEFAULT NULL,
  `first_login_at` datetime DEFAULT NULL,
  `last_login_at` datetime DEFAULT NULL,
  `login_count` int(10) UNSIGNED NOT NULL DEFAULT 0,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `created_by` int(11) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `tbl_users`
--

INSERT INTO `tbl_users` (`user_id`, `username`, `email`, `password_hash`, `full_name`, `role`, `program`, `assigned_section`, `profile_picture`, `last_password_change`, `created_at`, `updated_at`, `created_by`) VALUES
(1, 'admin', 'admin@example.com', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'System Administrator', 'super_admin', NULL, NULL, NULL, NULL, '2026-02-09 08:30:37', '2026-02-10 02:29:22', NULL),
(2, 'CWTS', 'client2@gmail.com', '$2y$10$Wt.4NYHkw3yoU0qRjBZXEO6FfKEmSeOBf6q7k5kqcwBRSXrz4mbnW', 'CWTS', 'facilitator', 'CWTS', 'CWTS 1A', 'uploads/profile_pictures/profile_2_1770874919.png', NULL, '2026-02-10 02:21:10', '2026-02-12 05:41:59', NULL),
(4, 'LTS', 'yejiolet@gmail.com', '$2y$10$7CH7EKUxHFC9xm56f0GJ.e47eRmchlL4B8c56bXxXxXfgjoqIDRV2', 'LTS', 'facilitator', 'LTS', 'CWTS M', NULL, '2026-02-16 00:53:37', '2026-02-11 01:15:04', '2026-02-16 00:53:37', 1),
(5, 'ROTC1', 'client3@gmail.com', '$2y$10$Bie4mf9QUFmo1p5cTxOmq.LIo7E2Y1c7FXyXlatFMaBy46JaXhrSO', 'ROTC1', 'facilitator', 'ROTC', 'Alpha 1st', NULL, NULL, '2026-02-11 01:21:07', '2026-02-15 02:07:51', 1),
(6, 'FACI2', 'FAci2@gmail.com', '$2y$10$lS3jeI0/ftSIDM5DsEE3pO1cSp1Yccmx4f.kTA6tY.r0/1AtZDWE6', 'FACI 2', 'facilitator', NULL, NULL, 'uploads/profile_pictures/profile_6_1771300654.png', NULL, '2026-02-15 02:08:14', '2026-02-17 03:57:34', 1),
(7, 'cwts_coordinator', 'cwts.coordinator@tau-nstp.local', '$2y$10$Z/9ftZEDDWl3jlXh93b/oO07nLJAg3ZSvLfT.CXSVOid/VWAKW512', 'CWTS Coordinator', 'coordinator', 'CWTS', NULL, NULL, NULL, '2026-05-21 09:38:30', '2026-05-21 09:38:30', 1),
(8, 'lts_coordinator', 'lts.coordinator@tau-nstp.local', '$2y$10$u4AmUiUnyGtXuj2z.1RxRee9tBJsJGI28gUo4TNJHFUHMjThNGlpC', 'LTS Coordinator', 'coordinator', 'LTS', NULL, NULL, NULL, '2026-05-21 09:38:30', '2026-05-21 09:38:30', 1),
(9, 'rotc_coordinator', 'rotc.coordinator@tau-nstp.local', '$2y$10$pVXNxzvVf.mI6WJN60dS.O5BXJSWIZq048tMsmadHBokhzsUXu1P2', 'ROTC Coordinator', 'coordinator', 'ROTC', NULL, NULL, NULL, '2026-05-21 09:38:31', '2026-05-21 09:38:31', 1);

--
-- Indexes for dumped tables
--

--
-- Indexes for table `password_resets`
--
ALTER TABLE `password_resets`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_token` (`token`),
  ADD KEY `idx_email` (`email`);

--
-- Indexes for table `tbl_admin_sections`
--
ALTER TABLE `tbl_admin_sections`
  ADD PRIMARY KEY (`admin_section_id`),
  ADD UNIQUE KEY `unique_admin_section` (`user_id`,`course_section`),
  ADD KEY `idx_user_id` (`user_id`),
  ADD KEY `idx_course_section` (`course_section`);

--
-- Indexes for table `tbl_attendance`
--
ALTER TABLE `tbl_attendance`
  ADD PRIMARY KEY (`tbl_attendance_id`);

--
-- Indexes for table `tbl_attendance_archive`
--
ALTER TABLE `tbl_attendance_archive`
  ADD PRIMARY KEY (`tbl_attendance_archive_id`),
  ADD KEY `idx_student_id` (`tbl_student_id`),
  ADD KEY `idx_time_in` (`time_in`),
  ADD KEY `idx_archived_date` (`archived_date`);

--
-- Indexes for table `tbl_student`
--
ALTER TABLE `tbl_student`
  ADD PRIMARY KEY (`tbl_student_id`),
  ADD UNIQUE KEY `qr_code` (`qr_code`),
  ADD KEY `idx_student_user_id` (`user_id`),
  ADD KEY `idx_created_by` (`created_by`),
  ADD KEY `idx_student_created_by` (`created_by`),
  ADD KEY `idx_student_course_section` (`course_section`);

--
-- Indexes for table `tbl_public_student_registrations`
--
ALTER TABLE `tbl_public_student_registrations`
  ADD PRIMARY KEY (`registration_id`),
  ADD KEY `idx_form_id` (`form_id`),
  ADD KEY `idx_email` (`email`),
  ADD KEY `idx_course_year` (`college`,`course`,`year_section`),
  ADD KEY `idx_status` (`status`),
  ADD KEY `idx_created_at` (`created_at`),
  ADD KEY `idx_public_reg_student_status` (`student_number`,`registrant_role`,`status`),
  ADD KEY `idx_public_reg_list` (`registrant_role`,`status`,`component`,`created_at`),
  ADD KEY `idx_public_reg_user` (`user_id`);

--
-- Indexes for table `tbl_public_registration_forms`
--
ALTER TABLE `tbl_public_registration_forms`
  ADD PRIMARY KEY (`form_id`),
  ADD UNIQUE KEY `unique_form_slug` (`form_slug`),
  ADD KEY `idx_is_active` (`is_active`),
  ADD KEY `idx_created_at` (`created_at`);

--
-- Indexes for table `tbl_announcements`
--
ALTER TABLE `tbl_announcements`
  ADD PRIMARY KEY (`announcement_id`),
  ADD KEY `idx_scope_created` (`scope_program`,`created_at`),
  ADD KEY `idx_created_by` (`created_by`);

--
-- Indexes for table `tbl_notifications`
--
ALTER TABLE `tbl_notifications`
  ADD PRIMARY KEY (`notification_id`),
  ADD UNIQUE KEY `unique_user_type_related` (`user_id`,`type`,`related_table`,`related_id`),
  ADD KEY `idx_user_read_created` (`user_id`,`is_read`,`created_at`),
  ADD KEY `idx_related` (`related_table`,`related_id`);

--
-- Indexes for table `tbl_system_settings`
--
ALTER TABLE `tbl_system_settings`
  ADD PRIMARY KEY (`setting_key`);

--
-- Indexes for table `tbl_landing_staff`
--
ALTER TABLE `tbl_landing_staff`
  ADD PRIMARY KEY (`landing_staff_id`),
  ADD KEY `idx_visible_order` (`is_visible`,`sort_order`),
  ADD KEY `idx_program` (`program`);

--
-- Indexes for table `tbl_landing_sections`
--
ALTER TABLE `tbl_landing_sections`
  ADD PRIMARY KEY (`section_key`);

--
-- Indexes for table `tbl_users`
--
ALTER TABLE `tbl_users`
  ADD PRIMARY KEY (`user_id`),
  ADD UNIQUE KEY `username` (`username`),
  ADD UNIQUE KEY `email` (`email`),
  ADD KEY `idx_users_role_program` (`role`,`program`),
  ADD KEY `idx_users_last_login` (`role`,`last_login_at`);

--
-- AUTO_INCREMENT for dumped tables
--

--
-- AUTO_INCREMENT for table `password_resets`
--
ALTER TABLE `password_resets`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT for table `tbl_admin_sections`
--
ALTER TABLE `tbl_admin_sections`
  MODIFY `admin_section_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=11;

--
-- AUTO_INCREMENT for table `tbl_attendance`
--
ALTER TABLE `tbl_attendance`
  MODIFY `tbl_attendance_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=18;

--
-- AUTO_INCREMENT for table `tbl_attendance_archive`
--
ALTER TABLE `tbl_attendance_archive`
  MODIFY `tbl_attendance_archive_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=11;

--
-- AUTO_INCREMENT for table `tbl_student`
--
ALTER TABLE `tbl_student`
  MODIFY `tbl_student_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=113;

--
-- AUTO_INCREMENT for table `tbl_public_student_registrations`
--
ALTER TABLE `tbl_public_student_registrations`
  MODIFY `registration_id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `tbl_public_registration_forms`
--
ALTER TABLE `tbl_public_registration_forms`
  MODIFY `form_id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `tbl_announcements`
--
ALTER TABLE `tbl_announcements`
  MODIFY `announcement_id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `tbl_notifications`
--
ALTER TABLE `tbl_notifications`
  MODIFY `notification_id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `tbl_landing_staff`
--
ALTER TABLE `tbl_landing_staff`
  MODIFY `landing_staff_id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `tbl_users`
--
ALTER TABLE `tbl_users`
  MODIFY `user_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=10;

--
-- Constraints for dumped tables
--

--
-- Constraints for table `tbl_student`
--
ALTER TABLE `tbl_student`
  ADD CONSTRAINT `fk_student_created_by` FOREIGN KEY (`created_by`) REFERENCES `tbl_users` (`user_id`) ON DELETE SET NULL;
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
