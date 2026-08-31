<?php
require_once __DIR__ . '/../includes/functions.php';
requireLogin();

if (isSuperAdmin()) {
    header('Location: management.php');
} else {
    header('Location: ../select-module.php');
}
exit;
