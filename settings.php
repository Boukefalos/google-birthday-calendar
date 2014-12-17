<?php
// Google client
define('CLIENT_ID', '');
define('CLIENT_SECRET', '');
define('REDIRECT_URI', sprintf('http://%s%s', $_SERVER['HTTP_HOST'], $_SERVER['PHP_SELF']));

// Calendar preferences
define('CONTACTS_CALENDAR_ID', '#contacts@group.v.calendar.google.com');
define('TARGET_CALENDAR_SUMMARY', 'Birthdays');
define('CALENDAR_BIRTHDAY_NAME', 'birthday');
define('CONTACTS_USER_EMAIL', 'default');
define('GOOGLE_DATE_FORMAT', 'Y-m-d');