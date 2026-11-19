<?php
$h = file_get_contents(__DIR__ . '/login_page.html');
if (!preg_match('/window\.__LOGIN_CFG__ = (\{.*?\});/s', $h, $m)) {
    fwrite(STDERR, "no cfg\n");
    exit(1);
}
$cfg = json_decode($m[1], true);
echo json_encode($cfg, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), "\n";
