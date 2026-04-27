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
            $uploadedCount = $this->handleImageUploads($auctionId, $files);

            $this->flash->success('Auction created successfully!' . ($uploadedCount > 0 ? " $uploadedCount image(s) uploaded." : ""));
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

    private function handleImageUploads(int $auctionId, array $files): int
    {
        if (empty($files['images'])) {
            return -1;
        }

        $allowedMimeTypes = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
        $mimeToExt = [
            'image/jpeg' => 'jpg',
            'image/png' => 'png',
            'image/gif' => 'gif',
            'image/webp' => 'webp',
        ];
        $maxFileSize = 5 * 1024 * 1024; // 5MB
        $maxFiles = 5;

        $uploadDir = __DIR__ . '/../../public/assets/uploads/';
        if (!is_dir($uploadDir)) {
            if (!mkdir($uploadDir, 0755, true)) {
                throw new RuntimeException('Failed to create upload directory.');
            }
        }

        $images = $files['images'];
        if (!is_array($images)) {
            $images = [$images];
        }

        $uploadedCount = 0;
        foreach ($images as $index => $uploadedFile) {
            if ($uploadedCount >= $maxFiles) {
                break;
            }

            if ($uploadedFile->getError() !== UPLOAD_ERR_OK) {
                continue;
            }

            $size = $uploadedFile->getSize();
            if ($size > $maxFileSize) {
                continue; // Skip large files
            }

            $mimeType = $uploadedFile->getClientMediaType();
            if (!in_array($mimeType, $allowedMimeTypes)) {
                continue; // Skip invalid types
            }

            $extension = $mimeToExt[$mimeType] ?? 'jpg'; // Fallback
            $filename = uniqid('auction_' . $auctionId . '_', true) . '.' . $extension;
            $targetPath = $uploadDir . $filename;

            try {
                $uploadedFile->moveTo($targetPath);

                // Insert into item_images
                $stmt = $this->db->prepare('INSERT INTO item_images (item_id, image_url, is_primary, display_order) VALUES (?, ?, ?, ?)');
                $stmt->execute([$auctionId, '/assets/uploads/' . $filename, $uploadedCount === 0 ? 1 : 0, $uploadedCount]);

                $uploadedCount++;
            } catch (RuntimeException | InvalidArgumentException $e) {
                // Skip this file
                continue;
            }
        }

        return $uploadedCount;
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