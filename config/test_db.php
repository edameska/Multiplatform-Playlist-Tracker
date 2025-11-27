<?php
$db = require __DIR__ . '/db.php';
// test database!
$db['dsn'] = 'mysql:host=localhost;dbname=yii2basic_test';

return $db;
