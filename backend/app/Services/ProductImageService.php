<?php

namespace App\Services;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class ProductImageService
{
    public const MAX_BYTES = 5242880;

    public function store(UploadedFile $file, int $businessId, int $productId): string
    {
        if (!$file->isValid() || $file->getSize() > self::MAX_BYTES || !in_array($file->getMimeType(), ['image/jpeg', 'image/png', 'image/webp'], true)) {
            throw new \InvalidArgumentException('The image must be a valid JPG, PNG, or WebP file under 5 MB.');
        }

        $contents = file_get_contents($file->getRealPath());
        $source = @imagecreatefromstring($contents ?: '');
        if (!$source) throw new \InvalidArgumentException('The uploaded file is not a readable image.');

        $width = imagesx($source); $height = imagesy($source);
        $max = 1600; $scale = min(1, $max / max($width, $height));
        $targetWidth = max(1, (int) round($width * $scale)); $targetHeight = max(1, (int) round($height * $scale));
        $canvas = imagecreatetruecolor($targetWidth, $targetHeight);
        imagealphablending($canvas, false); imagesavealpha($canvas, true);
        imagecopyresampled($canvas, $source, 0, 0, 0, 0, $targetWidth, $targetHeight, $width, $height);
        ob_start(); imagewebp($canvas, null, 82); $optimized = ob_get_clean();
        imagedestroy($canvas); imagedestroy($source);
        if (!$optimized) throw new \RuntimeException('The image could not be optimized.');

        $key = "businesses/{$businessId}/products/{$productId}/" . Str::uuid() . '.webp';
        Storage::disk('local')->put($key, $optimized);
        return $key;
    }

    public function delete(?string $key): void
    {
        if ($key) Storage::disk('local')->delete($key);
    }
}
