<?php

declare(strict_types=1);

use common\models\MailMessage;
use common\services\AttachmentStorageService;
use yii\db\Migration;
use yii\db\Query;

/**
 * Adds a sample PDF attachment to the demo inbox for View PDF.
 */
class m260910_000003_seed_demo_pdf_attachment extends Migration
{
    public function safeUp(): void
    {
        $messageId = (new Query())
            ->from('{{%mail_message}}')
            ->select('id')
            ->where(['like', 'subject', 'PO-10482%', false])
            ->scalar();

        if (!$messageId) {
            $messageId = (new Query())
                ->from('{{%mail_message}}')
                ->select('id')
                ->orderBy(['id' => SORT_ASC])
                ->scalar();
        }

        if (!$messageId) {
            return;
        }

        $message = MailMessage::findOne((int) $messageId);
        if (!$message) {
            return;
        }

        $pdf = $this->buildSamplePdf(
            "Mail ERP Demo Invoice\nPO-10482\n\n120 units confirmed for shipment.\nGenerated for PDF preview in Mail.",
        );

        (new AttachmentStorageService())->storeBinary(
            $message,
            'PO-10482-confirmation.pdf',
            $pdf,
            'application/pdf',
        );
    }

    public function safeDown(): void
    {
        $this->delete('{{%mail_attachment}}', ['filename' => 'PO-10482-confirmation.pdf']);
    }

    private function buildSamplePdf(string $text): string
    {
        $escaped = str_replace(['\\', '(', ')'], ['\\\\', '\\(', '\\)'], $text);
        $lines = explode("\n", $escaped);
        $content = "BT /F1 16 Tf 50 750 Td 18 TL\n";
        foreach ($lines as $i => $line) {
            if ($i === 0) {
                $content .= "({$line}) Tj\n";
            } else {
                $content .= "T* ({$line}) Tj\n";
            }
        }
        $content .= "ET";

        $objects = [];
        $objects[] = '1 0 obj<< /Type /Catalog /Pages 2 0 R >>endobj';
        $objects[] = '2 0 obj<< /Type /Pages /Kids [3 0 R] /Count 1 >>endobj';
        $objects[] = '3 0 obj<< /Type /Page /Parent 2 0 R /MediaBox [0 0 612 792] /Contents 4 0 R /Resources<< /Font<< /F1 5 0 R >> >> >>endobj';
        $objects[] = '4 0 obj<< /Length ' . strlen($content) . " >>stream\n" . $content . "\nendstream endobj";
        $objects[] = '5 0 obj<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>endobj';

        $pdf = "%PDF-1.4\n";
        $offsets = [0];
        foreach ($objects as $obj) {
            $offsets[] = strlen($pdf);
            $pdf .= $obj . "\n";
        }
        $xrefPos = strlen($pdf);
        $pdf .= 'xref' . "\n";
        $pdf .= '0 ' . (count($offsets)) . "\n";
        $pdf .= "0000000000 65535 f \n";
        for ($i = 1; $i < count($offsets); $i++) {
            $pdf .= sprintf("%010d 00000 n \n", $offsets[$i]);
        }
        $pdf .= "trailer<< /Size " . count($offsets) . " /Root 1 0 R >>\n";
        $pdf .= "startxref\n" . $xrefPos . "\n%%EOF";

        return $pdf;
    }
}
