<?php
/**
 * Shared filter bar for analytics pages.
 * Expects: $filters, $departments, $employees, $missionCategories (optional), $showWeekFilter (optional)
 */
$showWeekFilter = $showWeekFilter ?? false;
$showStatusFilter = $showStatusFilter ?? false;
$showModuleFilter = $showModuleFilter ?? false;
$missionCategories = $missionCategories ?? [];
$baseUrl = basename($_SERVER['PHP_SELF'] ?? 'index.php');
?>
<form method="get" class="da-filters" id="analyticsFilters">
    <input type="hidden" name="module" value="analytics">

    <div class="da-filter-group">
        <label for="start_date">From</label>
        <input type="date" id="start_date" name="start_date" value="<?= htmlspecialchars($filters['start_date']) ?>">
    </div>

    <div class="da-filter-group">
        <label for="end_date">To</label>
        <input type="date" id="end_date" name="end_date" value="<?= htmlspecialchars($filters['end_date']) ?>">
    </div>

    <?php if ($showWeekFilter): ?>
    <div class="da-filter-group">
        <label for="week_start">Week</label>
        <input type="date" id="week_start" name="week_start" value="<?= htmlspecialchars(analytics_week_start($filters)) ?>">
    </div>
    <?php endif; ?>

    <div class="da-filter-group">
        <label for="department">Department</label>
        <select id="department" name="department">
            <option value="">All departments</option>
            <?php foreach ($departments as $dept): ?>
                <option value="<?= htmlspecialchars($dept) ?>" <?= $filters['department'] === $dept ? 'selected' : '' ?>>
                    <?= htmlspecialchars($dept) ?>
                </option>
            <?php endforeach; ?>
        </select>
    </div>

    <div class="da-filter-group">
        <label for="employee">Employee</label>
        <select id="employee" name="employee">
            <option value="0">All employees</option>
            <?php foreach ($employees as $emp): ?>
                <option value="<?= (int) $emp['id'] ?>" <?= $filters['employee'] === (int) $emp['id'] ? 'selected' : '' ?>>
                    <?= htmlspecialchars($emp['full_name']) ?>
                </option>
            <?php endforeach; ?>
        </select>
    </div>

    <?php if ($showModuleFilter): ?>
    <div class="da-filter-group">
        <label for="module_cat">Module</label>
        <select id="module_cat" name="module">
            <option value="">All modules</option>
            <?php foreach ($missionCategories as $cat): ?>
                <option value="<?= htmlspecialchars($cat) ?>" <?= $filters['module'] === $cat ? 'selected' : '' ?>>
                    <?= htmlspecialchars($cat) ?>
                </option>
            <?php endforeach; ?>
        </select>
    </div>
    <?php endif; ?>

    <?php if ($showStatusFilter): ?>
    <div class="da-filter-group">
        <label for="status">Status</label>
        <select id="status" name="status">
            <option value="">All statuses</option>
            <?php foreach (['Completed', 'In Progress', 'Pending', 'Delayed'] as $st): ?>
                <option value="<?= $st ?>" <?= $filters['status'] === $st ? 'selected' : '' ?>><?= $st ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <?php endif; ?>

    <div class="da-filter-actions">
        <button type="submit" class="da-btn da-btn-primary"><i class="bi bi-funnel"></i> Apply</button>
        <a href="<?= htmlspecialchars($baseUrl) ?>?module=analytics" class="da-btn da-btn-ghost">Reset</a>
    </div>
</form>
