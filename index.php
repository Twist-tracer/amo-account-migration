<?php
ini_set('max_execution_time', 0);
require_once 'AccountMigrate.php';

$config = require_once 'config.php';
AccountMigrate::run($config)->move();
