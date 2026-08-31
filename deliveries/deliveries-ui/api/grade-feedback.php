<?php

declare(strict_types=1);

/**
 * AI grading of customer review feedback text.
 */
require_once __DIR__ . '/../../config/database.php';
require_once dirname(__DIR__, 3) . '/includes/functions.php';
require_once dirname(__DIR__, 3) . '/includes/ai_helpers.php';

requireLogin();

header('Content-Type: application/json; charset=utf-8');

/**
 * @param mixed $value
 */
function deliveries_grade_sanitize_text($value): string
{
    $text = trim((string) $value);
    if ($text === '') {
        return '';
    }
    $text = str_replace("\u{FFFD}", '', $text);
    return trim(preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $text) ?? $text);
}

/**
 * @param array<string,mixed> $row
 * @return array{id:int,score:int,letter:string,label:string,note:string}
 */
function deliveries_grade_fallback_row(array $row): array
{
    $id = (int) ($row['id'] ?? 0);
    $rating = max(0, min(5, (int) ($row['rating'] ?? 0)));
    $feedback = deliveries_grade_sanitize_text($row['feedback'] ?? '');

    if ($feedback === '') {
        $score = $rating > 0 ? (int) round(($rating / 5) * 100) : 0;
        return [
            'id' => $id,
            'score' => $score,
            'letter' => deliveries_grade_score_to_letter($score),
            'label' => 'Rating only',
            'note' => 'No written feedback provided.',
        ];
    }

    $score = $rating > 0 ? (int) round(($rating / 5) * 100) : 70;
    return [
        'id' => $id,
        'score' => $score,
        'letter' => deliveries_grade_score_to_letter($score),
        'label' => 'Ungraded',
        'note' => 'AI grading unavailable.',
    ];
}

function deliveries_grade_score_to_letter(int $score): string
{
    if ($score >= 97) {
        return 'A+';
    }
    if ($score >= 93) {
        return 'A';
    }
    if ($score >= 90) {
        return 'A-';
    }
    if ($score >= 87) {
        return 'B+';
    }
    if ($score >= 83) {
        return 'B';
    }
    if ($score >= 80) {
        return 'B-';
    }
    if ($score >= 77) {
        return 'C+';
    }
    if ($score >= 73) {
        return 'C';
    }
    if ($score >= 70) {
        return 'C-';
    }
    if ($score >= 60) {
        return 'D';
    }
    return 'F';
}

/**
 * @param array<int,array<string,mixed>> $reviews
 * @return list<array{id:int,score:int,letter:string,label:string,note:string}>
 */
function deliveries_grade_normalize_ai_rows(array $reviews, array $parsed): array
{
    $byId = [];
    foreach ($parsed as $row) {
        if (!is_array($row)) {
            continue;
        }
        $id = (int) ($row['id'] ?? 0);
        if ($id <= 0) {
            continue;
        }
        $score = max(0, min(100, (int) round((float) ($row['score'] ?? 0))));
        $letter = trim((string) ($row['letter'] ?? ''));
        if ($letter === '') {
            $letter = deliveries_grade_score_to_letter($score);
        }
        $byId[$id] = [
            'id' => $id,
            'score' => $score,
            'letter' => $letter,
            'label' => trim((string) ($row['label'] ?? 'Graded')) ?: 'Graded',
            'note' => trim((string) ($row['note'] ?? '')) ?: 'Graded from feedback.',
        ];
    }

    $out = [];
    foreach ($reviews as $review) {
        $id = (int) ($review['id'] ?? 0);
        $out[] = $byId[$id] ?? deliveries_grade_fallback_row($review);
    }

    return $out;
}

try {
    $input = json_decode(file_get_contents('php://input'), true) ?: [];
    $rows = is_array($input['reviews'] ?? null) ? $input['reviews'] : [];
    if ($rows === []) {
        echo json_encode(['ok' => true, 'grades' => [], 'viaAi' => false]);
        exit;
    }

    $reviews = [];
    foreach (array_slice($rows, 0, 50) as $row) {
        if (!is_array($row)) {
            continue;
        }
        $id = (int) ($row['id'] ?? 0);
        if ($id <= 0) {
            continue;
        }
        $reviews[] = [
            'id' => $id,
            'feedback' => deliveries_grade_sanitize_text($row['feedback'] ?? ''),
            'rating' => max(0, min(5, (int) ($row['rating'] ?? 0))),
        ];
    }

    if ($reviews === []) {
        echo json_encode(['ok' => true, 'grades' => [], 'viaAi' => false]);
        exit;
    }

    $settings = function_exists('ai_fetch_settings_row') ? ai_fetch_settings_row() : null;
    if (!$settings || !(int) ($settings['is_enabled'] ?? 0)) {
        $fallback = array_map('deliveries_grade_fallback_row', $reviews);
        echo json_encode([
            'ok' => true,
            'grades' => $fallback,
            'viaAi' => false,
            'note' => 'AI is not configured. Showing rating-based estimates.',
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $companyId = function_exists('currentCompanyId') ? (int) currentCompanyId() : 0;
    if ($companyId <= 0) {
        $companyId = (int) ($_SESSION['company_id'] ?? 0);
    }
    $userId = (int) ($_SESSION['user_id'] ?? 0);

    if (function_exists('ai_check_company_limit') && $companyId > 0) {
        $limit = ai_check_company_limit($companyId);
        if (empty($limit['allowed'])) {
            $fallback = array_map('deliveries_grade_fallback_row', $reviews);
            echo json_encode([
                'ok' => true,
                'grades' => $fallback,
                'viaAi' => false,
                'note' => 'Daily AI limit reached. Showing rating-based estimates.',
            ], JSON_UNESCAPED_UNICODE);
            exit;
        }
    }

    $payload = json_encode($reviews, JSON_UNESCAPED_UNICODE);
    $system = 'You grade customer delivery feedback for a business quality dashboard. '
        . 'For each review, analyze the feedback text (tone, clarity, sentiment, usefulness). '
        . 'If feedback is empty, grade from star rating only and set label to "Rating only". '
        . 'Return ONLY valid JSON (no markdown) as an object with key "grades" containing an array. '
        . 'Each item must include: id (integer), score (0-100 integer), letter (A+, A, A-, B+, B, B-, C+, C, C-, D, F), '
        . 'label (2-4 words, e.g. "Excellent", "Needs follow-up"), note (one short sentence explaining the mark). '
        . 'Be fair and consistent. Do not invent feedback.';

    $result = ai_openai_request([
        ['role' => 'system', 'content' => $system],
        ['role' => 'user', 'content' => "Grade these reviews:\n{$payload}"],
    ]);

    $content = (string) ($result['content'] ?? '');
    $content = preg_replace('/^```(?:json)?|```$/m', '', trim($content));
    $content = trim((string) $content);

    $parsed = json_decode($content, true);
    if (!is_array($parsed)) {
        if (preg_match('/\{.*\}/s', $content, $m)) {
            $parsed = json_decode($m[0], true);
        }
    }

    $gradeRows = [];
    if (is_array($parsed)) {
        if (isset($parsed['grades']) && is_array($parsed['grades'])) {
            $gradeRows = $parsed['grades'];
        } elseif (isset($parsed[0]) && is_array($parsed[0])) {
            $gradeRows = $parsed;
        }
    }

    $grades = deliveries_grade_normalize_ai_rows($reviews, $gradeRows);

    if (function_exists('ai_estimate_cost') && function_exists('ai_log_usage') && $companyId > 0) {
        $cost = ai_estimate_cost((int) $result['prompt_tokens'], (int) $result['completion_tokens'], (string) $result['model']);
        ai_log_usage($companyId, $userId, 'deliveries', 'feedback_grade', (int) $result['prompt_tokens'], (int) $result['completion_tokens'], $cost);
    }

    echo json_encode([
        'ok' => true,
        'grades' => $grades,
        'viaAi' => true,
    ], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    error_log('deliveries/deliveries-ui/api/grade-feedback.php failed: ' . $e->getMessage());
    http_response_code(200);
    echo json_encode([
        'ok' => false,
        'error' => 'Could not grade feedback right now.',
        'viaAi' => false,
    ], JSON_UNESCAPED_UNICODE);
}
