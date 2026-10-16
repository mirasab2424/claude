<?php
// Хеш начального пароля админки (/admin). Сам пароль в репозитории не хранится.
// После смены пароля в админке новый хеш лежит в storage/admin.json и имеет приоритет.
return [
    'admin_password_hash' => '$2y$10$ae.0EbcsJSFGuNPb1GuujOfK.BJYqGXFU8Q23Fd7lJ9eFf2.4Yolu',
];
