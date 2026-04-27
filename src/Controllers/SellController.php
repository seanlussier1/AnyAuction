<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Models\Category;
use App\Services\AuthService;
use App\Services\FlashService;
use PDO;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use RuntimeException;
use InvalidArgumentException;
use Slim\Views\Twig;

final class SellController
{
    public function __construct(
        private readonly PDO $db,
        private readonly Twig $view,
        private readonly AuthService $auth,
        private readonly FlashService $flash
    ) {
    }

    public function showForm(Request $request, Response $response): Response
    {
        if (!$this->auth->isLoggedIn()) {
            $this->flash->error('Please log in to list an item.');
            return $response->withHeader('Location', '/login')->withStatus(302);
        }

        $categories = (new Category($this->db))->all();

        return $this->view->render($response, 'pages/sell.twig', [
            'categories' => $categories,
            'csrf' => $this->ensureCsrfToken(),
        ]);
    }

    public function create(Request $request, Response $response): Response
    {
        if (!$this->auth->isLoggedIn()) {
            $this->flash->error('Please log in to list an item.');
            return $response->withHeader('Location', '/login')->withStatus(302);
        }

        $body = (array)$request->getParsedBody();
        $files = $request->getUploadedFiles();

        if (!$this->verifyCsrf((string)($body['_csrf'] ?? ''))) {
            $this->flash->error('Your session expired. Please try again.');
            return $response->withHeader('Location', '/sell')->withStatus(302);
        }

        // Validation
        $errors = $this->validateAuctionData($body);
        if (!empty($errors)) {
            $this->flash->error('Please correct the errors below.');
            $categories = (new Category($this->db))->all();
            return $this->view->render($response, 'pages/sell.twig', [
                'categories' => $categories,
                'errors' => $errors,
                'old' => $body,
            ]);
        }

        try {
            $auctionId = (new \App\Models\Auction($this->db))->create(
                sellerId: (int)$_SESSION['user_id'],
                data: $body
            );

            // Handle image uploads
            $upload = $this->handleImageUploads($auctionId, $files);

            $successMsg = 'Auction created successfully!';
            if ($upload['uploaded'] > 0) {
                $successMsg .= " {$upload['uploaded']} image(s) uploaded.";
            }
            $this->flash->success($successMsg);

            if ($upload['skipped'] > 0) {
                $tally = array_count_values($upload['reasons']);
                $parts = [];
                foreach ($tally as $reason => $n) {
                    $parts[] = "$n $reason";
                }
                $this->flash->add(
                    'warning',
                    $upload['skipped'] . ' image(s) skipped: ' . implode(', ', $parts) . '.'
                );
            }

            return $response->withHeader('Location', '/auction/' . $auctionId)->withStatus(302);
        } catch (RuntimeException $e) {
            $this->flash->error($e->getMessage());
            return $response->withHeader('Location', '/sell')->withStatus(302);
        }
    }

    private function validateAuctionData(array $data): array
    {
        $errors = [];

        // Title
        if (empty(trim($data['title'] ?? ''))) {
            $errors['title'] = 'Title is required.';
        } elseif (strlen($data['title']) > 255) {
            $errors['title'] = 'Title must be less than 255 characters.';
        }

        // Description
        if (empty(trim($data['description'] ?? ''))) {
            $errors['description'] = 'Description is required.';
        }

        // Category
        $categoryId = filter_var($data['category_id'] ?? '', FILTER_VALIDATE_INT);
        if ($categoryId === false || $categoryId <= 0) {
            $errors['category_id'] = 'Please select a valid category.';
        }

        // Starting price
        $startingPrice = filter_var($data['starting_price'] ?? '', FILTER_VALIDATE_FLOAT);
        if ($startingPrice === false || $startingPrice < 0) {
            $errors['starting_price'] = 'Starting price must be a positive number.';
        }

        // Reserve price (optional)
        if (!empty($data['reserve_price'])) {
            $reservePrice = filter_var($data['reserve_price'], FILTER_VALIDATE_FLOAT);
            if ($reservePrice === false || $reservePrice < 0) {
                $errors['reserve_price'] = 'Reserve price must be a positive number.';
            } elseif ($reservePrice < $startingPrice) {
                $errors['reserve_price'] = 'Reserve price must be at least the starting price.';
            }
        }

        // Buy now price (optional)
        if (!empty($data['buy_now_price'])) {
            $buyNowPrice = filter_var($data['buy_now_price'], FILTER_VALIDATE_FLOAT);
            if ($buyNowPrice === false || $buyNowPrice < 0) {
                $errors['buy_now_price'] = 'Buy now price must be a positive number.';
            }
        }

        // Duration
        $duration = filter_var($data['duration'] ?? '', FILTER_VALIDATE_INT);
        if ($duration === false || !in_array($duration, [1, 3, 7])) {
            $errors['duration'] = 'Please select a valid duration.';
        }

        return $errors;
    }

    /**
     * @param  array<string, mixed> $files
     * @return array{uploaded: int, skipped: int, reasons: array<int, string>}
     */
    private function handleImageUploads(int $auctionId, array $files): array
    {
        $result = ['uploaded' => 0, 'skipped' => 0, 'reasons' => []];

        if (empty($files['images'])) {
            return $result;
        }

        $mimeToExt = [
            'image/jpeg' => 'jpg',
            'image/png'  => 'png',
            'image/gif'  => 'gif',
            'image/webp' => 'webp',
        ];
        $maxFileSize = 5 * 1024 * 1024; // 5MB
        $maxFiles    = 5;

        $uploadDir = __DIR__ . '/../../public/assets/uploads/';
        if (!is_dir($uploadDir) && !@mkdir($uploadDir, 0755, true) && !is_dir($uploadDir)) {
            throw new RuntimeException('Failed to create upload directory.');
        }
        if (!is_writable($uploadDir)) {
            throw new RuntimeException('Upload directory is not writable.');
        }

        $images = is_array($files['images']) ? $files['images'] : [$files['images']];

        foreach ($images as $uploadedFile) {
            if ($result['uploaded'] >= $maxFiles) {
                $result['reasons'][] = 'per-listing limit of 5 reached';
                break;
            }

            $error = $uploadedFile->getError();
            if ($error === UPLOAD_ERR_NO_FILE) {
                continue; // empty slot — not a skip
            }
            if ($error !== UPLOAD_ERR_OK) {
                $result['reasons'][] = 'upload error (code ' . $error . ')';
                continue;
            }
            if (($uploadedFile->getSize() ?? 0) > $maxFileSize) {
                $result['reasons'][] = 'file too large (max 5MB)';
                continue;
            }

            $base    = uniqid('auction_' . $auctionId . '_', true);
            $tmpPath = $uploadDir . $base . '.tmp';

            try {
                $uploadedFile->moveTo($tmpPath);
            } catch (RuntimeException | InvalidArgumentException $e) {
                $result['reasons'][] = 'could not save file';
                continue;
            }

            // Server-side MIME detection — client-supplied media type is untrusted
            // and on some browsers comes back as application/octet-stream for real images.
            $finfo    = new \finfo(FILEINFO_MIME_TYPE);
            $detected = $finfo->file($tmpPath) ?: '';
            if (!isset($mimeToExt[$detected])) {
                @unlink($tmpPath);
                $result['reasons'][] = 'unsupported file type';
                continue;
            }

            $finalName = $base . '.' . $mimeToExt[$detected];
            $finalPath = $uploadDir . $finalName;
            if (!@rename($tmpPath, $finalPath)) {
                @unlink($tmpPath);
                $result['reasons'][] = 'could not save file';
                continue;
            }

            try {
                $stmt = $this->db->prepare(
                    'INSERT INTO item_images (item_id, image_url, is_primary, display_order) VALUES (?, ?, ?, ?)'
                );
                $stmt->execute([
                    $auctionId,
                    '/assets/uploads/' . $finalName,
                    $result['uploaded'] === 0 ? 1 : 0,
                    $result['uploaded'],
                ]);
            } catch (\PDOException $e) {
                @unlink($finalPath);
                $result['reasons'][] = 'database error';
                continue;
            }

            $result['uploaded']++;
        }

        $result['skipped'] = count($result['reasons']);
        return $result;
    }

    private function ensureCsrfToken(): string
    {
        if (empty($_SESSION['_csrf'])) {
            $_SESSION['_csrf'] = bin2hex(random_bytes(16));
        }
        return $_SESSION['_csrf'];
    }

    private function verifyCsrf(string $submitted): bool
    {
        return isset($_SESSION['_csrf']) && hash_equals($_SESSION['_csrf'], $submitted);
    }
}