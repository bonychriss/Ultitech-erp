<?php
// classes/ImageProcessor.php

class ImageProcessor {
    private $allowedTypes = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
    private $maxSize = 5242880; // 5MB
    private $uploadBaseDir;

    public function __construct($baseDir) {
        $this->uploadBaseDir = $baseDir;
    }

    public function processUploadedImage($tempFile, $productId) {
        $productDir = $this->uploadBaseDir . "/products/" . $productId;
        
        // Ensure directories exist
        if (!file_exists($productDir)) mkdir($productDir, 0755, true);
        if (!file_exists($productDir . '/original')) mkdir($productDir . '/original', 0755, true);
        if (!file_exists($productDir . '/thumbnail')) mkdir($productDir . '/thumbnail', 0755, true);
        if (!file_exists($productDir . '/medium')) mkdir($productDir . '/medium', 0755, true);
        if (!file_exists($productDir . '/large')) mkdir($productDir . '/large', 0755, true);

        // Validation (Already done via $_FILES check usually, but double checking temp file exists)
        if (!file_exists($tempFile)) {
            throw new Exception("Temporary file not found.");
        }

        // Generate Filename
        $filename = uniqid() . '.jpg'; // Convert everything to JPG for consistency or keep original ext? Prompt says JPG.

        // Process Sizes
        $this->createThumbnail($tempFile, $productDir . '/thumbnail/' . $filename, 150, 150);
        $this->resizeImage($tempFile, $productDir . '/medium/' . $filename, 400, 400);
        $this->resizeImage($tempFile, $productDir . '/large/' . $filename, 800, 800);
        
        // Save Original
        // `move_uploaded_file` can fail on some hosts even when resizing worked (e.g. temp file not treated as "uploaded").
        // Fall back to rename/copy so create + edit behave consistently.
        $destOriginal = $productDir . '/original/' . $filename;
        $moved = @move_uploaded_file($tempFile, $destOriginal);
        if (!$moved) {
            $moved = @rename($tempFile, $destOriginal);
        }
        if (!$moved) {
            $moved = @copy($tempFile, $destOriginal);
            if ($moved) {
                @unlink($tempFile);
            }
        }
        if (!$moved) {
            throw new Exception("Failed to save uploaded file. Check permissions for $productDir/original");
        }

        return $filename;
    }

    public function createThumbnail($source, $destination, $width, $height) {
        return $this->resizeImage($source, $destination, $width, $height);
    }

    public function resizeImage($source, $destination, $maxWidth, $maxHeight) {
        list($origWidth, $origHeight, $type) = getimagesize($source);

        $ratio = $origWidth / $origHeight;
        if ($maxWidth / $maxHeight > $ratio) {
           $newWidth = $maxHeight * $ratio;
           $newHeight = $maxHeight;
        } else {
           $newHeight = $maxWidth / $ratio;
           $newWidth = $maxWidth;
        }

        $image_p = imagecreatetruecolor($newWidth, $newHeight);
        
        switch ($type) {
            case IMAGETYPE_JPEG: $image = imagecreatefromjpeg($source); break;
            case IMAGETYPE_PNG: $image = imagecreatefrompng($source); break;
            case IMAGETYPE_GIF: $image = imagecreatefromgif($source); break;
            case IMAGETYPE_WEBP: $image = imagecreatefromwebp($source); break;
            default: return false; 
        }

        // Preserve transparency
        if($type == IMAGETYPE_PNG || $type == IMAGETYPE_WEBP){
            imagecolortransparent($image_p, imagecolorallocatealpha($image_p, 0, 0, 0, 127));
            imagealphablending($image_p, false);
            imagesavealpha($image_p, true);
        }

        imagecopyresampled($image_p, $image, 0, 0, 0, 0, $newWidth, $newHeight, $origWidth, $origHeight);

        // Quality 90 for JPG
        imagejpeg($image_p, $destination, 90);
        
        return true;
    }
}
?>
