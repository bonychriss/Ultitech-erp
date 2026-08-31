<?php
// classes/ImageProcessor.php

class ImageProcessor {
    private $allowedTypes = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
    private $maxSize = 5242880; // 5MB
    private $uploadBaseDir;

    public function __construct($baseDir) {
        $this->uploadBaseDir = $baseDir;
    }

    public static function gdAvailable(): bool
    {
        return extension_loaded('gd')
            && function_exists('imagecreatetruecolor')
            && function_exists('imagejpeg');
    }

    public function processUploadedImage($tempFile, $productId) {
        $productDir = $this->uploadBaseDir . "/products/" . $productId;

        // Ensure directories exist
        if (!file_exists($productDir)) mkdir($productDir, 0755, true);
        if (!file_exists($productDir . '/original')) mkdir($productDir . '/original', 0755, true);
        if (!file_exists($productDir . '/thumbnail')) mkdir($productDir . '/thumbnail', 0755, true);
        if (!file_exists($productDir . '/medium')) mkdir($productDir . '/medium', 0755, true);
        if (!file_exists($productDir . '/large')) mkdir($productDir . '/large', 0755, true);

        if (!file_exists($tempFile)) {
            throw new Exception("Temporary file not found.");
        }

        $filename = uniqid() . '.jpg';
        $originalPath = $productDir . '/original/' . $filename;

        if (self::gdAvailable()) {
            $this->createThumbnail($tempFile, $productDir . '/thumbnail/' . $filename, 150, 150);
            $this->resizeImage($tempFile, $productDir . '/medium/' . $filename, 400, 400);
            $this->resizeImage($tempFile, $productDir . '/large/' . $filename, 800, 800);
        } else {
            // GD missing: store originals in each size folder so upload still works
            foreach (['thumbnail', 'medium', 'large'] as $sizeDir) {
                if (!@copy($tempFile, $productDir . '/' . $sizeDir . '/' . $filename)) {
                    throw new Exception(
                        'PHP GD extension is not enabled (imagecreatetruecolor missing). '
                        . 'Enable extension=gd in php.ini and restart Apache.'
                    );
                }
            }
        }

        if (!move_uploaded_file($tempFile, $originalPath)) {
            // Temp may already have been copied; try rename/copy as fallback
            if (!@rename($tempFile, $originalPath) && !@copy($tempFile, $originalPath)) {
                throw new Exception("Failed to move uploaded file. Check permissions for $productDir/original");
            }
            @unlink($tempFile);
        }

        return $filename;
    }

    public function createThumbnail($source, $destination, $width, $height) {
        return $this->resizeImage($source, $destination, $width, $height);
    }

    public function resizeImage($source, $destination, $maxWidth, $maxHeight) {
        if (!self::gdAvailable()) {
            return @copy($source, $destination);
        }

        $info = @getimagesize($source);
        if ($info === false) {
            return false;
        }
        list($origWidth, $origHeight, $type) = $info;

        if ($origWidth < 1 || $origHeight < 1) {
            return false;
        }

        $ratio = $origWidth / $origHeight;
        if ($maxWidth / $maxHeight > $ratio) {
           $newWidth = (int) round($maxHeight * $ratio);
           $newHeight = (int) $maxHeight;
        } else {
           $newHeight = (int) round($maxWidth / $ratio);
           $newWidth = (int) $maxWidth;
        }
        $newWidth = max(1, $newWidth);
        $newHeight = max(1, $newHeight);

        $image_p = imagecreatetruecolor($newWidth, $newHeight);

        switch ($type) {
            case IMAGETYPE_JPEG: $image = imagecreatefromjpeg($source); break;
            case IMAGETYPE_PNG: $image = imagecreatefrompng($source); break;
            case IMAGETYPE_GIF: $image = imagecreatefromgif($source); break;
            case IMAGETYPE_WEBP:
                if (!function_exists('imagecreatefromwebp')) {
                    imagedestroy($image_p);
                    return @copy($source, $destination);
                }
                $image = imagecreatefromwebp($source);
                break;
            default:
                imagedestroy($image_p);
                return false;
        }

        if (!$image) {
            imagedestroy($image_p);
            return false;
        }

        // Preserve transparency
        if ($type == IMAGETYPE_PNG || $type == IMAGETYPE_WEBP) {
            imagecolortransparent($image_p, imagecolorallocatealpha($image_p, 0, 0, 0, 127));
            imagealphablending($image_p, false);
            imagesavealpha($image_p, true);
        }

        imagecopyresampled($image_p, $image, 0, 0, 0, 0, $newWidth, $newHeight, $origWidth, $origHeight);

        imagejpeg($image_p, $destination, 90);
        imagedestroy($image);
        imagedestroy($image_p);

        return true;
    }
}
