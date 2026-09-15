<?php

declare(strict_types=1);

/** @var bool $isPdf */
/** @var bool $isImage */
/** @var string $filename */

$ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
if ($isPdf || $ext === 'pdf') {
    $kind = 'pdf';
} elseif ($isImage || in_array($ext, ['png', 'jpg', 'jpeg', 'gif', 'webp', 'bmp', 'svg'], true)) {
    $kind = 'image';
} elseif (in_array($ext, ['doc', 'docx'], true)) {
    $kind = 'word';
} elseif (in_array($ext, ['xls', 'xlsx', 'csv'], true)) {
    $kind = 'excel';
} elseif (in_array($ext, ['ppt', 'pptx'], true)) {
    $kind = 'ppt';
} elseif (in_array($ext, ['zip', 'rar', '7z'], true)) {
    $kind = 'zip';
} else {
    $kind = 'file';
}
?>
<span class="file-type-icon kind-<?= $kind ?>" aria-hidden="true">
<?php if ($kind === 'pdf'): ?>
<svg viewBox="0 0 32 32"><path fill="#E53935" d="M19.5 2H8.2C6.98 2 6 2.98 6 4.2v23.6C6 29.02 6.98 30 8.2 30h15.6c1.22 0 2.2-.98 2.2-2.2V10.5L19.5 2z"/><path fill="#FFCDD2" d="M19.5 2v6.8c0 .94.76 1.7 1.7 1.7H28L19.5 2z"/><path fill="#fff" d="M11.2 18.2c0-.55.4-1 1-1h1.55c1.55 0 2.55.85 2.55 2.2 0 1.35-1 2.2-2.55 2.2H13.1v2.15c0 .5-.4.9-.9.9s-.9-.4-.9-.9v-5.55zm1.9 2.35h.55c.7 0 1.15-.35 1.15-1.05S14.35 18.4 13.65 18.4h-.55v2.15zM17.3 17.2h1.7c1.85 0 3.05 1.2 3.05 3.1s-1.2 3.1-3.05 3.1h-1.7c-.5 0-.9-.4-.9-.9v-4.4c0-.5.4-.9.9-.9zm1.85 4.95c.95 0 1.5-.6 1.5-1.85s-.55-1.85-1.5-1.85h-.8v3.7h.8z"/></svg>
<?php elseif ($kind === 'image'): ?>
<svg viewBox="0 0 32 32"><rect x="3" y="5" width="26" height="22" rx="3" fill="#1E88E5"/><circle cx="11" cy="12.5" r="3" fill="#FFEB3B"/><path fill="#E3F2FD" d="M5 24.5 12 15l4.5 5.5L21 14l6 10.5H5z"/></svg>
<?php elseif ($kind === 'word'): ?>
<svg viewBox="0 0 32 32"><path fill="#1565C0" d="M19.5 2H8.2C6.98 2 6 2.98 6 4.2v23.6C6 29.02 6.98 30 8.2 30h15.6c1.22 0 2.2-.98 2.2-2.2V10.5L19.5 2z"/><path fill="#BBDEFB" d="M19.5 2v6.8c0 .94.76 1.7 1.7 1.7H28L19.5 2z"/><path fill="#fff" d="M10.4 22.8 12.1 14h2.05l1.1 5.35L16.45 14H18.5l1.7 8.8h-2.05l-.95-5.45-1.2 5.45h-1.85l-1.2-5.45-.95 5.45h-2.05z"/></svg>
<?php elseif ($kind === 'excel'): ?>
<svg viewBox="0 0 32 32"><path fill="#2E7D32" d="M19.5 2H8.2C6.98 2 6 2.98 6 4.2v23.6C6 29.02 6.98 30 8.2 30h15.6c1.22 0 2.2-.98 2.2-2.2V10.5L19.5 2z"/><path fill="#C8E6C9" d="M19.5 2v6.8c0 .94.76 1.7 1.7 1.7H28L19.5 2z"/><path fill="#fff" d="M12.2 14h2.3l1.55 3.35L17.65 14h2.3l-2.7 4.4 2.85 4.4h-2.4l-1.7-3.45-1.7 3.45h-2.4l2.85-4.4L12.2 14z"/></svg>
<?php elseif ($kind === 'ppt'): ?>
<svg viewBox="0 0 32 32"><path fill="#E65100" d="M19.5 2H8.2C6.98 2 6 2.98 6 4.2v23.6C6 29.02 6.98 30 8.2 30h15.6c1.22 0 2.2-.98 2.2-2.2V10.5L19.5 2z"/><path fill="#FFE0B2" d="M19.5 2v6.8c0 .94.76 1.7 1.7 1.7H28L19.5 2z"/><path fill="#fff" d="M12 14h3.2c2.15 0 3.55 1.25 3.55 3.2S17.35 20.4 15.2 20.4H13.9V23c0 .5-.4.9-.95.9s-.95-.4-.95-.9V14zm1.9 4.95h1.15c1 0 1.6-.5 1.6-1.4s-.6-1.4-1.6-1.4H13.9v2.8z"/></svg>
<?php elseif ($kind === 'zip'): ?>
<svg viewBox="0 0 32 32"><path fill="#6D4C41" d="M19.5 2H8.2C6.98 2 6 2.98 6 4.2v23.6C6 29.02 6.98 30 8.2 30h15.6c1.22 0 2.2-.98 2.2-2.2V10.5L19.5 2z"/><path fill="#D7CCC8" d="M19.5 2v6.8c0 .94.76 1.7 1.7 1.7H28L19.5 2z"/><path fill="#FFECB3" d="M14 5h3v2h-3V5zm0 3h3v2h-3V8zm0 3h3v2h-3v-2zm-1 3h5v7h-5v-7zm1.4 1.4v4.2h2.2v-4.2h-2.2z"/></svg>
<?php else: ?>
<svg viewBox="0 0 32 32"><path fill="#607D8B" d="M19.5 2H8.2C6.98 2 6 2.98 6 4.2v23.6C6 29.02 6.98 30 8.2 30h15.6c1.22 0 2.2-.98 2.2-2.2V10.5L19.5 2z"/><path fill="#CFD8DC" d="M19.5 2v6.8c0 .94.76 1.7 1.7 1.7H28L19.5 2z"/><path fill="#ECEFF1" d="M10 15h12v1.8H10V15zm0 3.5h12V20H10v-1.5zm0 3.5h8V24h-8v-2z"/></svg>
<?php endif; ?>
</span>
