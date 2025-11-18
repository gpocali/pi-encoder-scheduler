-- --------------------------------------------------------
-- Graphic Scheduler Database Schema
--
-- This script creates all necessary tables, relationships,
-- and indexes for the application.
-- --------------------------------------------------------

-- Set session variables for compatibility and UTC timezone
SET NAMES utf8mb4;
SET time_zone = 'Eastern Standard Time';
SET default_storage_engine = InnoDB;

-- --------------------------------------------------------
-- Drop tables in reverse order of creation
-- (to avoid foreign key constraint errors)
-- --------------------------------------------------------

DROP TABLE IF EXISTS `default_assets`;
DROP TABLE IF EXISTS `events`;
DROP TABLE IF EXISTS `assets`;
DROP TABLE IF EXISTS `tags`;
DROP TABLE IF EXISTS `users`;

-- --------------------------------------------------------
-- Table structure for `users`
-- --------------------------------------------------------
CREATE TABLE `users` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `username` varchar(50) NOT NULL,
  `password_hash` varchar(255) NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `idx_username` (`username`)
) COMMENT='Stores admin user login information.';

-- --------------------------------------------------------
-- Table structure for `tags`
-- --------------------------------------------------------
CREATE TABLE `tags` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `tag_name` varchar(100) NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `idx_tag_name` (`tag_name`)
) COMMENT='Stores the names of output channels (e.g., "Lower Third").';

-- --------------------------------------------------------
-- Table structure for `assets`
-- --------------------------------------------------------
CREATE TABLE `assets` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `filename_disk` varchar(255) NOT NULL COMMENT 'Unique, safe filename stored on the server',
  `filename_original` varchar(255) NOT NULL COMMENT 'Original filename from user upload',
  `mime_type` varchar(100) NOT NULL,
  `uploaded_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`)
) COMMENT='Stores metadata for all uploaded graphic files.';

-- --------------------------------------------------------
-- Table structure for `events`
-- --------------------------------------------------------
CREATE TABLE `events` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `event_name` varchar(255) NOT NULL,
  `start_time` datetime NOT NULL COMMENT 'Event start time in UTC',
  `end_time` datetime NOT NULL COMMENT 'Event end time in UTC',
  `asset_id` int(11) NOT NULL,
  `tag_id` int(11) NOT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_asset_id` (`asset_id`),
  KEY `idx_tag_id` (`tag_id`),
  KEY `idx_event_times` (`start_time`,`end_time`) COMMENT 'Index for fast time-based lookups',
  
  -- Foreign Key Constraints
  CONSTRAINT `fk_event_asset` FOREIGN KEY (`asset_id`) REFERENCES `assets` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE,
  CONSTRAINT `fk_event_tag` FOREIGN KEY (`tag_id`) REFERENCES `tags` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE
) COMMENT='The main schedule of events.';

-- --------------------------------------------------------
-- Table structure for `default_assets`
-- --------------------------------------------------------
CREATE TABLE `default_assets` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `tag_id` int(11) NOT NULL COMMENT 'The tag this default applies to',
  `asset_id` int(11) NOT NULL COMMENT 'The asset to show by default',
  PRIMARY KEY (`id`),
  UNIQUE KEY `idx_tag_id` (`tag_id`) COMMENT 'Ensures only one default per tag',
  KEY `idx_asset_id` (`asset_id`),
  
  -- Foreign Key Constraints
  CONSTRAINT `fk_default_asset` FOREIGN KEY (`asset_id`) REFERENCES `assets` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE,
  CONSTRAINT `fk_default_tag` FOREIGN KEY (`tag_id`) REFERENCES `tags` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE
) COMMENT='Stores the default asset for each tag.';