<?php

declare(strict_types=1);

/**
 * Contact details shown on the public ultitech.io pages (Contact, About, footer, search schema).
 * Stored in system_settings on the control database so they stay the same for every tenant session.
 */

if (!function_exists('erp_site_contact_defaults')) {
    /**
     * @return array{phone:string,whatsapp:string,email:string,hours:string,address:string,instagram:string}
     */
    function erp_site_contact_defaults(): array
    {
        return [
            'phone' => '0785653817',
            'whatsapp' => '',
            'email' => 'wolfwiganz@gmail.com',
            'hours' => '8:00 AM - 5:00 PM',
            'address' => 'Dar es Salaam, Tanzania',
            'instagram' => 'official_ace84',
        ];
    }

    function erp_site_contact_pdo(): ?PDO
    {
        $pdo = $GLOBALS['control_pdo'] ?? ($GLOBALS['pdo'] ?? null);
        return $pdo instanceof PDO ? $pdo : null;
    }

    /** Digits only, with a local 0 prefix turned into the Tanzania country code. */
    function erp_site_contact_intl_digits(string $phone): string
    {
        $digits = preg_replace('/\D+/', '', $phone) ?? '';
        if (strlen($digits) === 10 && $digits[0] === '0') {
            $digits = '255' . substr($digits, 1);
        }
        return $digits;
    }

    function erp_site_contact_instagram_handle(string $value): string
    {
        $value = trim($value);
        if (preg_match('#instagram\.com/([A-Za-z0-9._]+)#i', $value, $m)) {
            $value = $m[1];
        }
        return ltrim($value, '@');
    }

    /**
     * Saved values merged over the defaults, plus ready-to-use links.
     *
     * @return array<string,string>
     */
    function erp_site_contact(): array
    {
        static $cache = null;
        if ($cache !== null) {
            return $cache;
        }

        $values = erp_site_contact_defaults();
        $pdo = erp_site_contact_pdo();
        if ($pdo !== null) {
            try {
                $stmt = $pdo->query("SELECT setting_key, setting_value FROM system_settings WHERE setting_key LIKE 'public_site\\_%'");
                foreach ($stmt->fetchAll(PDO::FETCH_KEY_PAIR) as $key => $value) {
                    $field = substr((string) $key, strlen('public_site_'));
                    if (array_key_exists($field, $values) && $value !== null) {
                        $values[$field] = (string) $value;
                    }
                }
            } catch (Throwable $e) {
            }
        }

        $phoneDigits = erp_site_contact_intl_digits($values['phone']);
        $whatsappDigits = erp_site_contact_intl_digits($values['whatsapp'] !== '' ? $values['whatsapp'] : $values['phone']);
        $handle = erp_site_contact_instagram_handle($values['instagram']);

        $cache = $values + [
            'phone_tel' => $phoneDigits !== '' ? '+' . $phoneDigits : '',
            'whatsapp_url' => $whatsappDigits !== '' ? 'https://wa.me/' . $whatsappDigits : '',
            'instagram_handle' => $handle,
            'instagram_url' => $handle !== '' ? 'https://www.instagram.com/' . $handle . '/' : '',
        ];
        return $cache;
    }

    /**
     * Editable wording on the Contact page and footer: key => [default text, max length].
     *
     * @return array<string,array{0:string,1:int}>
     */
    function erp_site_text_schema(): array
    {
        return [
            'contact_eyebrow' => ['Contact us', 40],
            'contact_title' => ['Talk to the UltiTech team', 120],
            'contact_lead' => ['Questions about UltiTech ERP, a demo for your team, or help with your account? Reach us on the channel that suits you.', 400],
            'call_label' => ['Call us', 40],
            'call_action' => ['Call now', 40],
            'whatsapp_label' => ['WhatsApp', 40],
            'whatsapp_action' => ['Open chat', 40],
            'whatsapp_greeting' => ['Hello UltiTech, I would like to know more about UltiTech ERP.', 300],
            'email_label' => ['Email', 40],
            'email_action' => ['Send email', 40],
            'email_subject' => ['UltiTech ERP enquiry', 120],
            'instagram_label' => ['Instagram', 40],
            'instagram_action' => ['Follow us', 40],
            'location_label' => ['Location', 40],
            'hours_label' => ['Opening hours', 40],
            'cta_title' => ['Prefer to try it first?', 120],
            'cta_text' => ['Start a 14-day free trial of the full suite. No card required.', 300],
            'cta_button' => ['Start free trial', 40],
            'footer_tagline' => ['One platform for finance, sales, stock, people, and delivery.', 200],
        ];
    }

    /** @return array<string,string> */
    function erp_site_text_defaults(): array
    {
        return array_map(static fn (array $field): string => $field[0], erp_site_text_schema());
    }

    /**
     * Saved wording merged over the defaults; empty saved values fall back to the default text.
     *
     * @return array<string,string>
     */
    function erp_site_texts(): array
    {
        static $cache = null;
        if ($cache !== null) {
            return $cache;
        }

        $texts = erp_site_text_defaults();
        $pdo = erp_site_contact_pdo();
        if ($pdo !== null) {
            try {
                $stmt = $pdo->prepare('SELECT setting_value FROM system_settings WHERE setting_key = ? LIMIT 1');
                $stmt->execute(['public_site_texts']);
                $saved = json_decode((string) $stmt->fetchColumn(), true);
                if (is_array($saved)) {
                    foreach ($texts as $key => $default) {
                        $value = trim((string) ($saved[$key] ?? ''));
                        if ($value !== '') {
                            $texts[$key] = $value;
                        }
                    }
                }
            } catch (Throwable $e) {
            }
        }

        $cache = $texts;
        return $cache;
    }

    /**
     * @param array<string,mixed> $input Contact fields plus an optional "texts" array of page wording.
     * @return array{ok:bool,message:string,contact?:array<string,string>,texts?:array<string,string>}
     */
    function erp_site_contact_save(array $input): array
    {
        $texts = null;
        if (isset($input['texts']) && is_array($input['texts'])) {
            $texts = [];
            foreach (erp_site_text_schema() as $key => [$default, $max]) {
                $value = trim(preg_replace('/\s+/', ' ', (string) ($input['texts'][$key] ?? '')) ?? '');
                if (mb_strlen($value) > $max) {
                    return ['ok' => false, 'message' => 'Page text "' . $default . '" can be up to ' . $max . ' characters.'];
                }
                $texts[$key] = $value !== '' ? $value : $default;
            }
        }

        $clean = [];
        foreach (array_keys(erp_site_contact_defaults()) as $field) {
            $clean[$field] = trim(preg_replace('/\s+/', ' ', (string) ($input[$field] ?? '')) ?? '');
        }
        $clean['instagram'] = erp_site_contact_instagram_handle($clean['instagram']);

        foreach (['phone' => 'Phone number', 'whatsapp' => 'WhatsApp number'] as $field => $label) {
            if ($clean[$field] === '') {
                continue;
            }
            if (!preg_match('/^\+?[0-9 ()-]{7,20}$/', $clean[$field]) || strlen(erp_site_contact_intl_digits($clean[$field])) < 9) {
                return ['ok' => false, 'message' => $label . ' must contain 9 to 15 digits, for example 0785653817.'];
            }
        }
        if ($clean['phone'] === '') {
            return ['ok' => false, 'message' => 'Enter a phone number.'];
        }
        if ($clean['email'] === '' || !filter_var($clean['email'], FILTER_VALIDATE_EMAIL)) {
            return ['ok' => false, 'message' => 'Enter a valid email address.'];
        }
        if ($clean['instagram'] !== '' && !preg_match('/^[A-Za-z0-9._]{1,30}$/', $clean['instagram'])) {
            return ['ok' => false, 'message' => 'Instagram should be a username such as official_ace84 or a profile link.'];
        }
        foreach (['hours' => 'Opening hours', 'address' => 'Location'] as $field => $label) {
            if (mb_strlen($clean[$field]) > 120) {
                return ['ok' => false, 'message' => $label . ' can be up to 120 characters.'];
            }
        }

        $pdo = erp_site_contact_pdo();
        if ($pdo === null) {
            return ['ok' => false, 'message' => 'Database connection unavailable.'];
        }

        try {
            $pdo->exec("
                CREATE TABLE IF NOT EXISTS system_settings (
                    setting_key VARCHAR(100) NOT NULL PRIMARY KEY,
                    setting_value TEXT NULL,
                    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            ");
            $stmt = $pdo->prepare(
                'INSERT INTO system_settings (setting_key, setting_value) VALUES (?, ?)
                 ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)'
            );
            foreach ($clean as $field => $value) {
                $stmt->execute(['public_site_' . $field, $value]);
            }
            if ($texts !== null) {
                $stmt->execute(['public_site_texts', json_encode($texts, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)]);
            }
        } catch (Throwable $e) {
            return ['ok' => false, 'message' => 'Could not save the contact details. Please try again.'];
        }

        $result = ['ok' => true, 'message' => 'Contact page updated.', 'contact' => $clean];
        if ($texts !== null) {
            $result['texts'] = $texts;
        }
        return $result;
    }
}
