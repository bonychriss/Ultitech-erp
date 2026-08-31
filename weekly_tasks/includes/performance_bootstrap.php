<?php
/**
 * Shared bootstrap for Performance module pages.
 */
require_once __DIR__ . '/../../includes/functions.php';
requireLogin();
ensureWeeklyTasksSchema();

$wmHelpers = __DIR__ . '/../../todo/includes/weekly_mission_helpers.php';
if (is_file($wmHelpers)) {
    require_once $wmHelpers;
    if (isset($GLOBALS['pdo']) && function_exists('wm_ensure_tables')) {
        wm_ensure_tables($GLOBALS['pdo']);
    }
}

global $pdo;

$viewerId = (int) ($_SESSION['user_id'] ?? 0);
$viewerName = (string) ($_SESSION['full_name'] ?? 'User');
$viewerRole = (string) ($_SESSION['role'] ?? 'employee');
$viewerDept = (string) ($_SESSION['department'] ?? '');
$isAdmin = in_array(strtolower($viewerRole), ['admin', 'administrator', 'superadmin', 'super_admin', 'company_admin'], true)
    || (function_exists('isAdmin') && isAdmin());

$weekOffset = isset($_GET['week']) ? (int) $_GET['week'] : (isset($_GET['week_offset']) ? (int) $_GET['week_offset'] : 0);

// Align week with To-Do Weekly Mission (Africa/Dar_es_Salaam Monday�Sunday).
if (function_exists('wm_get_week_bounds')) {
    $wmBounds = wm_get_week_bounds();
    if ($weekOffset !== 0 && function_exists('wm_shift_week')) {
        $wmBounds = wm_shift_week($wmBounds['week_start'], $weekOffset);
    }
    $weekStartDate = $wmBounds['week_start'];
    $mondayObj = new DateTime($weekStartDate);
    $sundayObj = new DateTime($wmBounds['week_end']);
} else {
    $mondayObj = new DateTime();
    $mondayObj->setISODate((int) date('o'), (int) date('W'));
    if ($weekOffset !== 0) {
        $mondayObj->modify($weekOffset . ' week');
    }
    $weekStartDate = $mondayObj->format('Y-m-d');
    $sundayObj = (clone $mondayObj)->modify('+6 days');
}
$weekDisplayShort = $mondayObj->format('j') . ' - ' . $sundayObj->format('j M Y');
$weekDisplayLabel = 'This Week (' . $weekDisplayShort . ')';
// Match mockup label format: "This Week (2 - 8 Jun 2026)"
if ($weekOffset === 0) {
    $weekDisplayLabel = 'This Week (' . $mondayObj->format('j') . ' - ' . $sundayObj->format('j M Y') . ')';
}
if ($weekOffset !== 0) {
    $weekDisplayLabel = $mondayObj->format('j M') . ' - ' . $sundayObj->format('j M Y');
}

$prevWeek = $weekOffset - 1;
$nextWeek = $weekOffset + 1;
$isCurrentWeek = ($weekOffset === 0);

$perfBase = function_exists('app_url') ? rtrim(app_url('/weekly_tasks'), '/') : '../weekly_tasks';
$perfCss = function_exists('app_url') ? app_url('/assets/css/performance-module.css') : '../assets/css/performance-module.css';
$modulesLink = function_exists('company_url') ? company_url('select-module') : '../select-module.php';
$analyticsUrl = function_exists('company_url') ? company_url('modules/analytics/performance') . '?module=analytics' : '../modules/analytics/performance.php?module=analytics';

require_once __DIR__ . '/performance_helpers.php';
