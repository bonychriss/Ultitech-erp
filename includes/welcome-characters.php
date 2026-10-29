<?php

declare(strict_types=1);

/**
 * Welcome-email illustrations and the company business field that picks one.
 * Both values live in the tenant system_settings table.
 */

/**
 * @return array<string, array{label:string,file:string,group:string}>
 */
function manageUsersWelcomeCharacters(): array
{
    return [
        'wave' => ['label' => 'Peace sign', 'file' => 'welcome-email-wave.png', 'group' => 'General'],
        'man' => ['label' => 'Waving hello', 'file' => 'welcome-email-man.png', 'group' => 'General'],
        'laptop' => ['label' => 'Thumbs up + laptop', 'file' => 'welcome-email-laptop.png', 'group' => 'General'],
        'highfive' => ['label' => 'High five', 'file' => 'welcome-email-highfive.png', 'group' => 'General'],
        'key' => ['label' => 'Handing the key', 'file' => 'welcome-email-key.png', 'group' => 'General'],
        'bot' => ['label' => 'Friendly bot', 'file' => 'welcome-email-bot.png', 'group' => 'General'],
        'spares' => ['label' => 'Spares mechanic', 'file' => 'welcome-email-spares.png', 'group' => 'Industry'],
        'truck' => ['label' => 'Truck driver', 'file' => 'welcome-email-truck.png', 'group' => 'Industry'],
        'ppe' => ['label' => 'PPE / safety', 'file' => 'welcome-email-ppe.png', 'group' => 'Industry'],
        'food' => ['label' => 'Food / chef', 'file' => 'welcome-email-food.png', 'group' => 'Industry'],
        'logistics' => ['label' => 'Warehouse / logistics', 'file' => 'welcome-email-logistics.png', 'group' => 'Industry'],
        'delivery' => ['label' => 'Delivery courier', 'file' => 'welcome-email-delivery.png', 'group' => 'Industry'],
        'none' => ['label' => 'No illustration', 'file' => '', 'group' => 'Other'],
    ];
}

/**
 * Company business fields; each one maps to its matching welcome character.
 *
 * @return array<string, array{label:string,description:string,character:string}>
 */
function companyBusinessFields(): array
{
    return [
        'general' => ['label' => 'General trading / office', 'description' => 'Mixed trading, services and office work.', 'character' => 'wave'],
        'spares' => ['label' => 'Spares & auto parts', 'description' => 'Vehicle and machinery spare parts.', 'character' => 'spares'],
        'trucks' => ['label' => 'Trucks & transport', 'description' => 'Haulage, fleets and transport services.', 'character' => 'truck'],
        'ppe' => ['label' => 'PPE & safety', 'description' => 'Protective equipment and safety supplies.', 'character' => 'ppe'],
        'food' => ['label' => 'Food & beverage', 'description' => 'Restaurants, catering and food supply.', 'character' => 'food'],
        'logistics' => ['label' => 'Logistics & warehousing', 'description' => 'Storage, stock handling and distribution.', 'character' => 'logistics'],
        'courier' => ['label' => 'Courier & delivery', 'description' => 'Parcel and last-mile delivery.', 'character' => 'delivery'],
        'technology' => ['label' => 'Technology & IT', 'description' => 'Software, IT services and electronics.', 'character' => 'bot'],
    ];
}

function welcomeCharactersSettingGet(string $key): string
{
    global $pdo;
    if (!($pdo instanceof PDO)) {
        return '';
    }
    try {
        $stmt = $pdo->prepare('SELECT setting_value FROM system_settings WHERE setting_key = ? LIMIT 1');
        $stmt->execute([$key]);

        return trim((string) ($stmt->fetchColumn() ?: ''));
    } catch (Throwable $e) {
        return '';
    }
}

function welcomeCharactersSettingSet(string $key, string $value): bool
{
    global $pdo;
    if (!($pdo instanceof PDO)) {
        return false;
    }
    try {
        $stmt = $pdo->prepare(
            'INSERT INTO system_settings (setting_key, setting_value) VALUES (?, ?)
             ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)'
        );

        return $stmt->execute([$key, $value]);
    } catch (Throwable $e) {
        error_log('welcomeCharactersSettingSet: ' . $e->getMessage());

        return false;
    }
}

function companyBusinessField(): string
{
    $key = welcomeCharactersSettingGet('company_business_field');

    return array_key_exists($key, companyBusinessFields()) ? $key : '';
}

/**
 * Saving a field also switches the welcome email to that field's character.
 */
function saveCompanyBusinessField(string $key): bool
{
    $fields = companyBusinessFields();
    if (!array_key_exists($key, $fields)) {
        return false;
    }

    return welcomeCharactersSettingSet('company_business_field', $key)
        && welcomeCharactersSettingSet('welcome_email_character', $fields[$key]['character']);
}

/**
 * Explicit choice from the email preview wins; otherwise the company field's character; otherwise 'wave'.
 */
function manageUsersWelcomeCharacter(): string
{
    $characters = manageUsersWelcomeCharacters();
    $key = welcomeCharactersSettingGet('welcome_email_character');
    if (array_key_exists($key, $characters)) {
        return $key;
    }
    $field = companyBusinessField();
    if ($field !== '') {
        return companyBusinessFields()[$field]['character'];
    }

    return 'wave';
}

function manageUsersSaveWelcomeCharacter(string $key): bool
{
    if (!array_key_exists($key, manageUsersWelcomeCharacters())) {
        return false;
    }

    return welcomeCharactersSettingSet('welcome_email_character', $key);
}

/**
 * Site-relative URL of a character image ('' for none / missing file).
 */
function welcomeCharacterImagePath(string $key): string
{
    $file = manageUsersWelcomeCharacters()[$key]['file'] ?? '';
    if ($file === '' || !is_file(dirname(__DIR__) . '/assets/images/' . $file)) {
        return '';
    }

    return function_exists('app_url') ? app_url('/assets/images/' . $file) : '/assets/images/' . $file;
}
