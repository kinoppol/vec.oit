-- 009 · Seed base data: fiscal years, schools, users (password = "password")
INSERT INTO `fiscal_years` (`id`,`year_code`,`label`,`is_active`) VALUES
(1,'2568','ปีงบประมาณ พ.ศ. 2568',1),
(2,'2567','ปีงบประมาณ พ.ศ. 2567',0);

INSERT INTO `schools` (`id`,`name`,`province`,`slug`,`status`) VALUES
(1,'วิทยาลัยเทคนิคนครหลวง','กรุงเทพมหานคร','nakhonluang','active'),
(2,'วิทยาลัยอาชีวศึกษาเชียงใหม่','เชียงใหม่','chiangmai-vec','active'),
(3,'วิทยาลัยการอาชีพหัวหิน','ประจวบคีรีขันธ์','huahin-ic','pending'),
(4,'วิทยาลัยเทคนิคขอนแก่น','ขอนแก่น','khonkaen-tc','pending');

INSERT INTO `users` (`id`,`school_id`,`national_id`,`password_hash`,`full_name`,`role`,`status`,`must_change_pw`) VALUES
(1,NULL,'0000000000001','$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi','ผู้ดูแลส่วนกลาง สอศ.','centraladmin','active',0),
(2,1,'1100700123456','$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi','นายสมชาย ใจดี','schooladmin','active',0),
(3,1,'1100700234567','$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi','นางสาวสุดา รักเรียน','user','active',0),
(4,1,'1100700345678','$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi','นายวิชัย พากเพียร','user','pending',1),
(5,1,'1100700456789','$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi','นางมาลี ตั้งใจ','user','active',0);
