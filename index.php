<?php
ini_set('max_execution_time', 0); // ����� ���������� ������������
ini_set('memory_limit', 536870912); // �������� 512�� (���������� ��� ����� ���������)
require_once 'AccountMigrate.php';

$config = require_once 'config.php';
AccountMigrate::run($config)->move();
