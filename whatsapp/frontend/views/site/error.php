<?php

/** @var yii\web\View $this */
/** @var string $name */
/** @var string $message */
/** @var Exception $exception */

$this->title = $name;
?>
<div style="font-family: system-ui; padding: 2rem;">
  <h1><?= htmlspecialchars($name) ?></h1>
  <p><?= nl2br(htmlspecialchars($message)) ?></p>
</div>
