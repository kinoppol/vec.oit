-- 011 · Seed demo statuses and evidences for school 1
INSERT INTO `school_indicator_status` (`school_id`,`indicator_id`,`status`) VALUES
(1,1,'done'),(1,2,'done'),(1,3,'done'),(1,4,'done'),(1,5,'inprogress'),
(1,6,'done'),(1,7,'done'),(1,8,'inprogress'),(1,9,'done'),(1,10,'done'),
(1,11,'done'),(1,12,'done'),(1,14,'inprogress'),(1,15,'done'),
(1,21,'done'),(1,22,'done'),(1,25,'done'),(1,28,'inprogress');

INSERT INTO `school_indicator_status` (`school_id`,`indicator_id`,`status`)
SELECT 1,`id`,'done' FROM `indicators` WHERE `id` BETWEEN 34 AND 66;

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

INSERT INTO `evidences` (`school_id`,`indicator_id`,`type`,`title`,`url`)
SELECT 1,`id`,'link',CONCAT('หลักฐาน ',`code`,' ปีงบประมาณ 2567'),
       CONCAT('https://www.nakhonluang.ac.th/oit/2567/',`code`)
FROM `indicators` WHERE `id` BETWEEN 34 AND 66;
