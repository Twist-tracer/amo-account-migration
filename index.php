<?php
ini_set('max_execution_time', 0); // Время выполнения неограничено
ini_set('memory_limit', 536870912); // Выделяем 512мб (необходимо для карты сущностей)
require_once 'AccountMigrate.php';

$config = require_once 'config.php';
AccountMigrate::run($config)->move();
