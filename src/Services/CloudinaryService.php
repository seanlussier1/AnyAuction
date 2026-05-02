<?php

declare(strict_types=1);

namespace App\Services;

use Cloudinary\Cloudinary;
use Throwable;

/**
 * Thin wrapper around the Cloudinary SDK so the rest of the app doesn't
 * import vendor classes. New listing photos go to the configured folder
 * (`anyauction/listings` by default) with auto-quality + auto-format
 * transformations applied at delivery time, so the browser gets WebP/AVIF
 * where supported and a small JPEG fallback otherwise.
 *
 * `upload()` is silent-no-op when creds aren't configured — callers can
 * gate on isConfigured() if they want a hard fail in dev.
 */
final class CloudinaryService
{
    private ?Cloudinary $client = null;

    public function __construct(
        private readonly string $cloudName,
        private readonly string $apiKey,
        private readonly string $apiSecret,
        private readonly string $folder
    ) {
    }

    public function isConfigured(): bool
    {
        return $this->cloudName !== '' && $this->apiKey !== '' && $this->apiSecret !== '';
    }

    /**
     * Upload a local file to Cloudinary. Returns the secure delivery URL
     * (already includes f_auto,q_auto so the browser gets the best format
     * for its viewport), or null on any failure (logged via error_log).
     *
     * @param  string $localPath  Absolute path to the file already saved on disk.
     * @param  string $publicIdHint  Stable id slug — typically "auction_{id}_{seq}".
     *                               Cloudinary appends a hash if a collision occurs.
     */
    public function upload(string $localPath, string $publicIdHint): ?string
    {
        if (!$this->isConfigured()) {
            error_log('[CloudinaryService] upload skipped — creds not configured.');
            return null;
        }
        if (!is_file($localPath)) {
            error_log('[CloudinaryService] upload skipped — file not found: ' . $localPath);
            return null;
        }

        try {
            $result = $this->client()->uploadApi()->upload($localPath, [
                'folder'         => $this->folder,
                'public_id'      => $publicIdHint,
                'use_filename'   => false,
                'unique_filename'=> true,
                'overwrite'      => false,
                'resource_type'  => 'image',
            ]);
            $secureUrl = (string)($result['secure_url'] ?? '');
            if ($secureUrl === '') {
                return null;
            }

            // Inject f_auto,q_auto so the browser gets the right format
            // (WebP/AVIF where supported) at the right quality without
            // a transform call in the template. Pattern: insert after the
            // /upload/ segment.
            return preg_replace('#/upload/#', '/upload/f_auto,q_auto/', $secureUrl, 1) ?? $secureUrl;
        } catch (Throwable $e) {
            error_log('[CloudinaryService] upload failed: ' . $e->getMessage());
            return null;
        }
    }

    private function client(): Cloudinary
    {
        return $this->client ??= new Cloudinary([
            'cloud' => [
                'cloud_name' => $this->cloudName,
                'api_key'    => $this->apiKey,
                'api_secret' => $this->apiSecret,
            ],
            'url' => [
                'secure' => true,
            ],
        ]);
    }
}
