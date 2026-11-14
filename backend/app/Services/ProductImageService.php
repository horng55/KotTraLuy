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
        $info = @getimagesizefromstring($contents ?: '');
        $extension = match ($info[2] ?? null) {
            IMAGETYPE_JPEG => 'jpg',
            IMAGETYPE_PNG => 'png',
            IMAGETYPE_WEBP => 'webp',
            default => null,
        };
        if (!$extension) throw new \InvalidArgumentException('The uploaded file is not a readable image.');

        // Some production PHP builds do not include GD or its WebP encoder.
        // Keep the validated original when optimization is unavailable.
        if (!$this->canOptimize()) {
            return $this->persist($contents, $extension, $businessId, $productId);
        }

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

        return $this->persist($optimized, 'webp', $businessId, $productId);
    }

    protected function canOptimize(): bool
    {
        return function_exists('imagecreatefromstring') && function_exists('imagewebp');
    }

    private function persist(string $contents, string $extension, int $businessId, int $productId): string
    {
        $key = "businesses/{$businessId}/products/{$productId}/" . Str::uuid() . '.'.$extension;
        if (!Storage::disk('local')->put($key, $contents)) {
            throw new \RuntimeException('The product image could not be stored.');
        }
        return $key;
    }

    public function delete(?string $key): void
    {
        if ($key) Storage::disk('local')->delete($key);
    }
}
