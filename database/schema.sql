-- ============================================================
-- ระบบเปิดเผยข้อมูลสาธารณะ (OIT) — Database Schema + Seed
-- MariaDB 10+ / MySQL 5.7+
-- Default login password for all seed users: "password"
-- ============================================================

CREATE DATABASE IF NOT EXISTS `vec_oit`
  DEFAULT CHARACTER SET utf8mb4
  DEFAULT COLLATE utf8mb4_unicode_ci;

USE `vec_oit`;

SET NAMES 'utf8mb4';
SET FOREIGN_KEY_CHECKS = 0;

-- ============================================================
-- TABLES
-- ============================================================

CREATE TABLE IF NOT EXISTS `schools` (
  `id`          INT UNSIGNED AUTO_INCREMENT,
  `name`        VARCHAR(200)  NOT NULL,
  `code`        VARCHAR(20)   DEFAULT NULL,
  `province`    VARCHAR(100)  NOT NULL DEFAULT '',
  `slug`        VARCHAR(120)  NOT NULL,
  `website`     VARCHAR(500)  DEFAULT NULL,
  `emblem_path` VARCHAR(300)  DEFAULT NULL,
  `status`      ENUM('pending','active','inactive') NOT NULL DEFAULT 'pending',
  `created_at`  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_slug` (`slug`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `users` (
  `id`             INT UNSIGNED AUTO_INCREMENT,
  `school_id`      INT UNSIGNED DEFAULT NULL,
  `national_id`    VARCHAR(13)  NOT NULL,
  `password_hash`  VARCHAR(255) NOT NULL,
  `full_name`      VARCHAR(200) NOT NULL,
  `role`           ENUM('user','schooladmin','centraladmin') NOT NULL DEFAULT 'user',
  `status`         ENUM('active','disabled','pending') NOT NULL DEFAULT 'pending',
  `must_change_pw` TINYINT(1)   NOT NULL DEFAULT 1,
  `last_login`     TIMESTAMP    DEFAULT NULL,
  `created_at`     TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_national_id` (`national_id`),
  CONSTRAINT `fk_users_school` FOREIGN KEY (`school_id`) REFERENCES `schools` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `fiscal_years` (
  `id`         INT UNSIGNED AUTO_INCREMENT,
  `year_code`  VARCHAR(10)  NOT NULL,
  `label`      VARCHAR(100) NOT NULL,
  `is_active`  TINYINT(1)   NOT NULL DEFAULT 0,
  `created_at` TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_year_code` (`year_code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `indicator_sections` (
  `id`             INT UNSIGNED AUTO_INCREMENT,
  `fiscal_year_id` INT UNSIGNED NOT NULL,
  `code`           VARCHAR(10)  NOT NULL,
  `title`          VARCHAR(300) NOT NULL,
  `sort_order`     TINYINT UNSIGNED NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  CONSTRAINT `fk_sec_year` FOREIGN KEY (`fiscal_year_id`) REFERENCES `fiscal_years` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `indicator_subsections` (
  `id`         INT UNSIGNED AUTO_INCREMENT,
  `section_id` INT UNSIGNED NOT NULL,
  `code`       VARCHAR(10)  NOT NULL,
  `title`      VARCHAR(300) NOT NULL,
  `sort_order` TINYINT UNSIGNED NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  CONSTRAINT `fk_sub_sec` FOREIGN KEY (`section_id`) REFERENCES `indicator_sections` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `indicators` (
  `id`            INT UNSIGNED AUTO_INCREMENT,
  `subsection_id` INT UNSIGNED NOT NULL,
  `code`          VARCHAR(10)  NOT NULL,
  `title`         VARCHAR(300) NOT NULL,
  `criteria`      TEXT         DEFAULT NULL,
  `sort_order`    SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  CONSTRAINT `fk_ind_sub` FOREIGN KEY (`subsection_id`) REFERENCES `indicator_subsections` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `school_indicator_status` (
  `id`           INT UNSIGNED AUTO_INCREMENT,
  `school_id`    INT UNSIGNED NOT NULL,
  `indicator_id` INT UNSIGNED NOT NULL,
  `status`       ENUM('pending','inprogress','done') NOT NULL DEFAULT 'pending',
  `note`         TEXT          DEFAULT NULL,
  `updated_by`   INT UNSIGNED DEFAULT NULL,
  `updated_at`   TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_school_ind` (`school_id`, `indicator_id`),
  CONSTRAINT `fk_sis_school` FOREIGN KEY (`school_id`) REFERENCES `schools` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_sis_ind`    FOREIGN KEY (`indicator_id`) REFERENCES `indicators` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `evidences` (
  `id`           INT UNSIGNED AUTO_INCREMENT,
  `school_id`    INT UNSIGNED NOT NULL,
  `indicator_id` INT UNSIGNED NOT NULL,
  `type`         ENUM('link','file','image','text') NOT NULL DEFAULT 'link',
  `title`        VARCHAR(300)  NOT NULL,
  `url`          VARCHAR(1000) DEFAULT NULL,
  `file_path`    VARCHAR(300)  DEFAULT NULL,
  `note`         TEXT          DEFAULT NULL,
  `created_by`   INT UNSIGNED  DEFAULT NULL,
  `created_at`   TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  CONSTRAINT `fk_ev_school` FOREIGN KEY (`school_id`) REFERENCES `schools` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_ev_ind`    FOREIGN KEY (`indicator_id`) REFERENCES `indicators` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET FOREIGN_KEY_CHECKS = 1;

-- ============================================================
-- SEED: Fiscal Years
-- ============================================================
INSERT INTO `fiscal_years` (`id`,`year_code`,`label`,`is_active`) VALUES
(1,'2568','ปีงบประมาณ พ.ศ. 2568',1),
(2,'2567','ปีงบประมาณ พ.ศ. 2567',0);

-- ============================================================
-- SEED: Schools
-- ============================================================
INSERT INTO `schools` (`id`,`name`,`province`,`slug`,`status`) VALUES
(1,'วิทยาลัยเทคนิคนครหลวง','กรุงเทพมหานคร','nakhonluang','active'),
(2,'วิทยาลัยอาชีวศึกษาเชียงใหม่','เชียงใหม่','chiangmai-vec','active'),
(3,'วิทยาลัยการอาชีพหัวหิน','ประจวบคีรีขันธ์','huahin-ic','pending'),
(4,'วิทยาลัยเทคนิคขอนแก่น','ขอนแก่น','khonkaen-tc','pending');

-- ============================================================
-- SEED: Users  (password = "password")
-- ============================================================
INSERT INTO `users` (`id`,`school_id`,`national_id`,`password_hash`,`full_name`,`role`,`status`,`must_change_pw`) VALUES
(1,NULL,'0000000000001','$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi','ผู้ดูแลส่วนกลาง สอศ.','centraladmin','active',0),
(2,1,'1100700123456','$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi','นายสมชาย ใจดี','schooladmin','active',0),
(3,1,'1100700234567','$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi','นางสาวสุดา รักเรียน','user','active',0),
(4,1,'1100700345678','$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi','นายวิชัย พากเพียร','user','pending',1),
(5,1,'1100700456789','$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi','นางมาลี ตั้งใจ','user','active',0);

-- ============================================================
-- SEED: Indicator Sections
-- ============================================================
INSERT INTO `indicator_sections` (`id`,`fiscal_year_id`,`code`,`title`,`sort_order`) VALUES
(1,1,'9','ส่วนที่ 1 · การเปิดเผยข้อมูล',1),
(2,1,'10','ส่วนที่ 2 · การป้องกันการทุจริต',2),
(3,2,'9','ส่วนที่ 1 · การเปิดเผยข้อมูล',1),
(4,2,'10','ส่วนที่ 2 · การป้องกันการทุจริต',2);

-- ============================================================
-- SEED: Indicator Subsections (2568: 1-7; 2567: 8-14)
-- ============================================================
INSERT INTO `indicator_subsections` (`id`,`section_id`,`code`,`title`,`sort_order`) VALUES
(1,1,'9.1','9.1 ข้อมูลพื้นฐาน',1),
(2,1,'9.2','9.2 การบริหารงานและการใช้จ่ายงบประมาณ',2),
(3,1,'9.3','9.3 การจัดซื้อจัดจ้าง',3),
(4,1,'9.4','9.4 การบริหารและพัฒนาทรัพยากรบุคคล',4),
(5,1,'9.5','9.5 การส่งเสริมความโปร่งใส',5),
(6,2,'10.1','10.1 นโยบาย No Gift Policy',1),
(7,2,'10.2','10.2 มาตรการส่งเสริมคุณธรรมและความโปร่งใส',2),
(8,3,'9.1','9.1 ข้อมูลพื้นฐาน',1),
(9,3,'9.2','9.2 การบริหารงานและการใช้จ่ายงบประมาณ',2),
(10,3,'9.3','9.3 การจัดซื้อจัดจ้าง',3),
(11,3,'9.4','9.4 การบริหารและพัฒนาทรัพยากรบุคคล',4),
(12,3,'9.5','9.5 การส่งเสริมความโปร่งใส',5),
(13,4,'10.1','10.1 นโยบาย No Gift Policy',1),
(14,4,'10.2','10.2 มาตรการส่งเสริมคุณธรรมและความโปร่งใส',2);

-- ============================================================
-- SEED: Indicators  (2568: 1-33;  2567: 34-66)
-- ============================================================
INSERT INTO `indicators` (`id`,`subsection_id`,`code`,`title`,`criteria`,`sort_order`) VALUES
-- 9.1
(1,1,'O1','โครงสร้างหน่วยงาน','แสดงแผนผังโครงสร้างการแบ่งส่วนราชการของสถานศึกษา',1),
(2,1,'O2','ข้อมูลผู้บริหาร','แสดงรายชื่อ ตำแหน่ง และภาพถ่ายผู้บริหารสถานศึกษา',2),
(3,1,'O3','อำนาจหน้าที่','แสดงข้อมูลหน้าที่และอำนาจของสถานศึกษาตามที่กฎหมายกำหนด',3),
(4,1,'O4','ข้อมูลการติดต่อ','แสดงที่อยู่ เบอร์โทรศัพท์ อีเมล และแผนที่ตั้งของสถานศึกษา',4),
(5,1,'O5','ข่าวประชาสัมพันธ์','แสดงข้อมูลข่าวสารที่เกี่ยวข้องกับการดำเนินงานในปีงบประมาณ',5),
(6,1,'O6','Q&A','แสดงช่องทางที่บุคคลภายนอกสามารถสอบถามข้อมูลและได้รับคำตอบ',6),
-- 9.2
(7,2,'O7','แผนพัฒนาสถานศึกษา','แสดงแผนการดำเนินงานตามยุทธศาสตร์/แผนพัฒนาสถานศึกษา',1),
(8,2,'O8','แผนและความก้าวหน้าการดำเนินงานประจำปี','แสดงความก้าวหน้าการดำเนินงานตามแผนปฏิบัติราชการประจำปี',2),
(9,2,'O9','รายงานผลการดำเนินงานประจำปี','แสดงผลการดำเนินงานตามแผนปฏิบัติราชการประจำปีที่ผ่านมา',3),
(10,2,'O10','คู่มือหรือแนวทางการปฏิบัติงาน','แสดงคู่มือหรือแนวทางการปฏิบัติงานของบุคลากร',4),
(11,2,'O11','คู่มือหรือแนวทางการให้บริการ','แสดงคู่มือการให้บริการสำหรับผู้รับบริการหรือผู้มาติดต่อ',5),
(12,2,'O12','ข้อมูลสถิติการให้บริการ','แสดงข้อมูลสถิติการให้บริการตามภารกิจของสถานศึกษา',6),
(13,2,'O13','รายงานการกำกับติดตามการใช้จ่ายงบประมาณประจำปี','แสดงผลการใช้จ่ายงบประมาณรายไตรมาส',7),
-- 9.3
(14,3,'O14','แผนการจัดซื้อจัดจ้างหรือแผนการจัดหาพัสดุ','แสดงแผนการจัดซื้อจัดจ้างประจำปี',1),
(15,3,'O15','ประกาศต่าง ๆ เกี่ยวกับการจัดซื้อจัดจ้าง','แสดงประกาศจัดซื้อจัดจ้างตามที่หน่วยงานต้องดำเนินการ',2),
(16,3,'O16','สรุปผลการจัดซื้อจัดจ้างรายเดือน','แสดงสรุปผลการจัดซื้อจัดจ้าง (แบบ สขร.1) รายเดือน',3),
(17,3,'O17','รายงานผลการจัดซื้อจัดจ้างประจำปี','แสดงผลการจัดซื้อจัดจ้างและการวิเคราะห์ผลประจำปี',4),
-- 9.4
(18,4,'O18','นโยบายการบริหารทรัพยากรบุคคล','แสดงนโยบายการบริหารและพัฒนาทรัพยากรบุคคล',1),
(19,4,'O19','หลักเกณฑ์การบริหารและพัฒนาทรัพยากรบุคคล','แสดงหลักเกณฑ์การสรรหา บรรจุ แต่งตั้ง พัฒนา ประเมินผล',2),
(20,4,'O20','รายงานผลการบริหารและพัฒนาทรัพยากรบุคคลประจำปี','แสดงผลการบริหารและพัฒนาทรัพยากรบุคคลที่ผ่านมา',3),
-- 9.5
(21,5,'O21','แนวปฏิบัติการจัดการเรื่องร้องเรียนการทุจริต','แสดงแนวปฏิบัติการจัดการเรื่องร้องเรียนและประพฤติมิชอบ',1),
(22,5,'O22','ช่องทางแจ้งเรื่องร้องเรียนการทุจริต','แสดงช่องทางออนไลน์สำหรับแจ้งเรื่องร้องเรียน',2),
(23,5,'O23','ข้อมูลเชิงสถิติเรื่องร้องเรียนการทุจริตประจำปี','แสดงข้อมูลสถิติเรื่องร้องเรียนการทุจริตและประพฤติมิชอบ',3),
(24,5,'O24','การเปิดโอกาสให้มีส่วนร่วม','แสดงการเปิดโอกาสให้ผู้มีส่วนได้ส่วนเสียมีส่วนร่วม',4),
-- 10.1
(25,6,'O25','ประกาศเจตนารมณ์นโยบาย No Gift Policy','แสดงประกาศเจตนารมณ์ไม่รับของขวัญและของกำนัล',1),
(26,6,'O26','การสร้างวัฒนธรรม No Gift Policy','แสดงการดำเนินกิจกรรมเสริมสร้างวัฒนธรรมองค์กร',2),
(27,6,'O27','รายงานผลตามนโยบาย No Gift Policy','แสดงผลการดำเนินการตามนโยบายในรอบปี',3),
(28,6,'O28','การประเมินความเสี่ยงการทุจริต','แสดงผลการประเมินความเสี่ยงการทุจริตในประเด็นที่เกี่ยวข้อง',4),
(29,6,'O29','รายงานผลการดำเนินการเพื่อจัดการความเสี่ยงการทุจริต','แสดงผลการดำเนินการตามมาตรการจัดการความเสี่ยง',5),
-- 10.2
(30,7,'O30','แผนปฏิบัติการป้องกันการทุจริต','แสดงแผนปฏิบัติการป้องกันการทุจริตประจำปี',1),
(31,7,'O31','รายงานผลการดำเนินการป้องกันการทุจริตประจำปี','แสดงผลการดำเนินงานตามแผนป้องกันการทุจริต',2),
(32,7,'O32','มาตรการส่งเสริมคุณธรรมและความโปร่งใสภายในสถานศึกษา','แสดงการวิเคราะห์ผลการประเมินและมาตรการที่กำหนด',3),
(33,7,'O33','การดำเนินการตามมาตรการส่งเสริมคุณธรรมและความโปร่งใส','แสดงผลการดำเนินการตามมาตรการที่กำหนด',4),
-- Year 2567: subsections 8-14, indicators 34-66
(34,8,'O1','โครงสร้างหน่วยงาน','แสดงแผนผังโครงสร้างการแบ่งส่วนราชการของสถานศึกษา',1),
(35,8,'O2','ข้อมูลผู้บริหาร','แสดงรายชื่อ ตำแหน่ง และภาพถ่ายผู้บริหารสถานศึกษา',2),
(36,8,'O3','อำนาจหน้าที่','แสดงข้อมูลหน้าที่และอำนาจของสถานศึกษาตามที่กฎหมายกำหนด',3),
(37,8,'O4','ข้อมูลการติดต่อ','แสดงที่อยู่ เบอร์โทรศัพท์ อีเมล และแผนที่ตั้งของสถานศึกษา',4),
(38,8,'O5','ข่าวประชาสัมพันธ์','แสดงข้อมูลข่าวสารที่เกี่ยวข้องกับการดำเนินงานในปีงบประมาณ',5),
(39,8,'O6','Q&A','แสดงช่องทางที่บุคคลภายนอกสามารถสอบถามข้อมูลและได้รับคำตอบ',6),
(40,9,'O7','แผนพัฒนาสถานศึกษา','แสดงแผนการดำเนินงานตามยุทธศาสตร์/แผนพัฒนาสถานศึกษา',1),
(41,9,'O8','แผนและความก้าวหน้าการดำเนินงานประจำปี','แสดงความก้าวหน้าการดำเนินงานตามแผนปฏิบัติราชการประจำปี',2),
(42,9,'O9','รายงานผลการดำเนินงานประจำปี','แสดงผลการดำเนินงานตามแผนปฏิบัติราชการประจำปีที่ผ่านมา',3),
(43,9,'O10','คู่มือหรือแนวทางการปฏิบัติงาน','แสดงคู่มือหรือแนวทางการปฏิบัติงานของบุคลากร',4),
(44,9,'O11','คู่มือหรือแนวทางการให้บริการ','แสดงคู่มือการให้บริการสำหรับผู้รับบริการหรือผู้มาติดต่อ',5),
(45,9,'O12','ข้อมูลสถิติการให้บริการ','แสดงข้อมูลสถิติการให้บริการตามภารกิจของสถานศึกษา',6),
(46,9,'O13','รายงานการกำกับติดตามการใช้จ่ายงบประมาณประจำปี','แสดงผลการใช้จ่ายงบประมาณรายไตรมาส',7),
(47,10,'O14','แผนการจัดซื้อจัดจ้างหรือแผนการจัดหาพัสดุ','แสดงแผนการจัดซื้อจัดจ้างประจำปี',1),
(48,10,'O15','ประกาศต่าง ๆ เกี่ยวกับการจัดซื้อจัดจ้าง','แสดงประกาศจัดซื้อจัดจ้างตามที่หน่วยงานต้องดำเนินการ',2),
(49,10,'O16','สรุปผลการจัดซื้อจัดจ้างรายเดือน','แสดงสรุปผลการจัดซื้อจัดจ้าง (แบบ สขร.1) รายเดือน',3),
(50,10,'O17','รายงานผลการจัดซื้อจัดจ้างประจำปี','แสดงผลการจัดซื้อจัดจ้างและการวิเคราะห์ผลประจำปี',4),
(51,11,'O18','นโยบายการบริหารทรัพยากรบุคคล','แสดงนโยบายการบริหารและพัฒนาทรัพยากรบุคคล',1),
(52,11,'O19','หลักเกณฑ์การบริหารและพัฒนาทรัพยากรบุคคล','แสดงหลักเกณฑ์การสรรหา บรรจุ แต่งตั้ง พัฒนา ประเมินผล',2),
(53,11,'O20','รายงานผลการบริหารและพัฒนาทรัพยากรบุคคลประจำปี','แสดงผลการบริหารและพัฒนาทรัพยากรบุคคลที่ผ่านมา',3),
(54,12,'O21','แนวปฏิบัติการจัดการเรื่องร้องเรียนการทุจริต','แสดงแนวปฏิบัติการจัดการเรื่องร้องเรียนและประพฤติมิชอบ',1),
(55,12,'O22','ช่องทางแจ้งเรื่องร้องเรียนการทุจริต','แสดงช่องทางออนไลน์สำหรับแจ้งเรื่องร้องเรียน',2),
(56,12,'O23','ข้อมูลเชิงสถิติเรื่องร้องเรียนการทุจริตประจำปี','แสดงข้อมูลสถิติเรื่องร้องเรียนการทุจริตและประพฤติมิชอบ',3),
(57,12,'O24','การเปิดโอกาสให้มีส่วนร่วม','แสดงการเปิดโอกาสให้ผู้มีส่วนได้ส่วนเสียมีส่วนร่วม',4),
(58,13,'O25','ประกาศเจตนารมณ์นโยบาย No Gift Policy','แสดงประกาศเจตนารมณ์ไม่รับของขวัญและของกำนัล',1),
(59,13,'O26','การสร้างวัฒนธรรม No Gift Policy','แสดงการดำเนินกิจกรรมเสริมสร้างวัฒนธรรมองค์กร',2),
(60,13,'O27','รายงานผลตามนโยบาย No Gift Policy','แสดงผลการดำเนินการตามนโยบายในรอบปี',3),
(61,13,'O28','การประเมินความเสี่ยงการทุจริต','แสดงผลการประเมินความเสี่ยงการทุจริตในประเด็นที่เกี่ยวข้อง',4),
(62,13,'O29','รายงานผลการดำเนินการเพื่อจัดการความเสี่ยงการทุจริต','แสดงผลการดำเนินการตามมาตรการจัดการความเสี่ยง',5),
(63,14,'O30','แผนปฏิบัติการป้องกันการทุจริต','แสดงแผนปฏิบัติการป้องกันการทุจริตประจำปี',1),
(64,14,'O31','รายงานผลการดำเนินการป้องกันการทุจริตประจำปี','แสดงผลการดำเนินงานตามแผนป้องกันการทุจริต',2),
(65,14,'O32','มาตรการส่งเสริมคุณธรรมและความโปร่งใสภายในสถานศึกษา','แสดงการวิเคราะห์ผลการประเมินและมาตรการที่กำหนด',3),
(66,14,'O33','การดำเนินการตามมาตรการส่งเสริมคุณธรรมและความโปร่งใส','แสดงผลการดำเนินการตามมาตรการที่กำหนด',4);

-- ============================================================
-- SEED: Demo Statuses — School 1, Year 2568
-- ============================================================
INSERT INTO `school_indicator_status` (`school_id`,`indicator_id`,`status`) VALUES
(1,1,'done'),(1,2,'done'),(1,3,'done'),(1,4,'done'),(1,5,'inprogress'),
(1,6,'done'),(1,7,'done'),(1,8,'inprogress'),(1,9,'done'),(1,10,'done'),
(1,11,'done'),(1,12,'done'),(1,14,'inprogress'),(1,15,'done'),
(1,21,'done'),(1,22,'done'),(1,25,'done'),(1,28,'inprogress');

-- School 1, Year 2567 — all done
INSERT INTO `school_indicator_status` (`school_id`,`indicator_id`,`status`)
SELECT 1,`id`,'done' FROM `indicators` WHERE `id` BETWEEN 34 AND 66;

-- ============================================================
-- SEED: Demo Evidences — School 1, Year 2568
-- ============================================================
INSERT INTO `evidences` (`school_id`,`indicator_id`,`type`,`title`,`url`,`note`) VALUES
(1,1,'link','แผนผังโครงสร้างการบริหารงาน','https://www.nakhonluang.ac.th/oit/structure',NULL),
(1,2,'link','ทำเนียบผู้บริหาร','https://www.nakhonluang.ac.th/oit/executives',NULL),
(1,3,'link','อำนาจหน้าที่ตามกฎหมาย','https://www.nakhonluang.ac.th/oit/authority',NULL),
(1,4,'link','ที่ตั้งและช่องทางติดต่อ','https://www.nakhonluang.ac.th/oit/contact',NULL),
(1,4,'text','ที่อยู่สถานศึกษา',NULL,'เลขที่ 199 ถ.เจ้าฟ้า แขวงพระบรมมหาราชวัง เขตพระนคร กรุงเทพฯ 10200 โทร 0-2222-xxxx'),
(1,5,'link','ข่าวประชาสัมพันธ์ปี 2568','https://www.nakhonluang.ac.th/oit/news',NULL),
(1,6,'link','กระดานถาม-ตอบ (Q&A)','https://www.nakhonluang.ac.th/oit/qa',NULL),
(1,7,'link','แผนพัฒนาสถานศึกษา 2566-2570','https://www.nakhonluang.ac.th/oit/plan.pdf',NULL),
(1,8,'link','รายงานความก้าวหน้ารอบ 6 เดือน','https://www.nakhonluang.ac.th/oit/progress',NULL),
(1,9,'link','รายงานผลการดำเนินงานประจำปี 2567','https://www.nakhonluang.ac.th/oit/report.pdf',NULL),
(1,10,'link','คู่มือการปฏิบัติงานบุคลากร','https://www.nakhonluang.ac.th/oit/manual-staff',NULL),
(1,11,'link','คู่มือการให้บริการนักเรียนนักศึกษา','https://www.nakhonluang.ac.th/oit/manual-service',NULL),
(1,12,'link','สถิติการให้บริการ ปี 2568','https://www.nakhonluang.ac.th/oit/stats',NULL),
(1,14,'link','แผนการจัดซื้อจัดจ้าง 2568','https://www.nakhonluang.ac.th/oit/procurement-plan',NULL),
(1,15,'link','ประกาศจัดซื้อจัดจ้าง','https://www.nakhonluang.ac.th/oit/procurement',NULL),
(1,21,'link','แนวปฏิบัติการจัดการเรื่องร้องเรียน','https://www.nakhonluang.ac.th/oit/complaint-guide',NULL),
(1,22,'link','ระบบรับเรื่องร้องเรียนออนไลน์','https://www.nakhonluang.ac.th/oit/complaint',NULL),
(1,22,'text','ช่องทางเพิ่มเติม',NULL,'กล่องรับเรื่องร้องเรียนหน้าห้องอำนวยการ และอีเมล complaint@nakhonluang.ac.th'),
(1,25,'link','หน้าประกาศนโยบาย No Gift Policy','https://www.nakhonluang.ac.th/oit/nogift',NULL),
(1,28,'text','สรุปการประเมินความเสี่ยง',NULL,'อยู่ระหว่างรวบรวมผลการประเมินความเสี่ยงการทุจริตจากทุกฝ่ายงาน');

-- School 1, Year 2567 — one link per indicator
INSERT INTO `evidences` (`school_id`,`indicator_id`,`type`,`title`,`url`)
SELECT 1,`id`,'link',CONCAT('หลักฐาน ',`code`,' ปีงบประมาณ 2567'),
       CONCAT('https://www.nakhonluang.ac.th/oit/2567/',`code`)
FROM `indicators` WHERE `id` BETWEEN 34 AND 66;
