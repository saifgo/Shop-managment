<?php

declare(strict_types=1);

namespace App\Application\Catalog;

use App\Domain\Shared\EntityId;
use App\Infrastructure\Persistence\Entity\Catalog\Product;
use App\Infrastructure\Persistence\Entity\Catalog\ProductMedia;
use App\Infrastructure\Persistence\Entity\Identity\User;
use App\Infrastructure\Persistence\UnitOfWork;
use App\Infrastructure\Storage\DocumentStorage;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

/**
 * Uploaded product pictures. Files live in DocumentStorage and are served through
 * /api/media/{id} so <img> tags work without a bearer token.
 */
final class ProductMediaService
{
    public const MAX_BYTES = 5 * 1024 * 1024;

    private const EXTENSIONS = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp',
        'image/gif' => 'gif',
    ];

    public function __construct(
        private EntityManagerInterface $entityManager,
        private UnitOfWork $unitOfWork,
        private DocumentStorage $storage,
        private ProductService $productService,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function upload(User $user, string $productId, string $contents, ?string $altText): array
    {
        $product = $this->findProduct($user, $productId);

        if ($contents === '') {
            throw new UnprocessableEntityHttpException('The picture is empty.');
        }

        if (strlen($contents) > self::MAX_BYTES) {
            throw new UnprocessableEntityHttpException('The picture must be 5 MB or smaller.');
        }

        // Sniff the real type from the bytes; the client-supplied type and extension are not trusted.
        $info = @getimagesizefromstring($contents);
        $mimeType = is_array($info) ? $info['mime'] : null;

        if ($mimeType === null || !isset(self::EXTENSIONS[$mimeType])) {
            throw new UnprocessableEntityHttpException('Upload a JPEG, PNG, WebP or GIF image.');
        }

        $id = EntityId::generate();
        $key = sprintf('products/%s/%s.%s', $product->getId(), $id->toString(), self::EXTENSIONS[$mimeType]);
        $this->storage->store($key, $contents, $mimeType);

        $existing = $product->getMedia();
        $sortOrder = 0;

        foreach ($existing as $media) {
            $sortOrder = max($sortOrder, $media->getSortOrder() + 1);
        }

        $media = new ProductMedia(
            id: $id,
            product: $product,
            url: '/api/media/'.$id->toString(),
            altText: $altText !== null && $altText !== '' ? $altText : null,
            sortOrder: $sortOrder,
            isPrimary: $existing->count() === 0,
            storageKey: $key,
            mimeType: $mimeType,
        );

        $this->entityManager->persist($media);
        $this->unitOfWork->flush();

        return $this->productService->get($user, $product->getId(), false);
    }

    /**
     * @return array<string, mixed>
     */
    public function delete(User $user, string $productId, string $mediaId): array
    {
        $product = $this->findProduct($user, $productId);
        $media = $this->findMedia($product, $mediaId);
        $storageKey = $media->getStorageKey();
        $wasPrimary = $media->isPrimary();

        $product->removeMedia($media);

        if ($wasPrimary) {
            $next = $product->getMedia()->first();

            if ($next instanceof ProductMedia) {
                $next->setPrimary(true);
            }
        }

        $this->unitOfWork->flush();

        if ($storageKey !== null) {
            $this->storage->delete($storageKey);
        }

        return $this->productService->get($user, $product->getId(), false);
    }

    /**
     * @return array<string, mixed>
     */
    public function setPrimary(User $user, string $productId, string $mediaId): array
    {
        $product = $this->findProduct($user, $productId);
        $target = $this->findMedia($product, $mediaId);

        foreach ($product->getMedia() as $media) {
            $media->setPrimary($media === $target);
        }

        $this->unitOfWork->flush();

        return $this->productService->get($user, $product->getId(), false);
    }

    /**
     * @return array{contents: string, mime_type: string}
     */
    public function readFile(string $mediaId): array
    {
        $media = $this->entityManager->getRepository(ProductMedia::class)->find($mediaId);

        if (!$media instanceof ProductMedia || $media->getStorageKey() === null) {
            throw new NotFoundHttpException('Picture not found.');
        }

        $contents = $this->storage->read($media->getStorageKey());

        if ($contents === null) {
            throw new NotFoundHttpException('Picture not found.');
        }

        return [
            'contents' => $contents,
            'mime_type' => $media->getMimeType() ?? 'application/octet-stream',
        ];
    }

    private function findProduct(User $user, string $productId): Product
    {
        /** @var Product|null $product */
        $product = $this->entityManager->getRepository(Product::class)->findOneBy([
            'id' => $productId,
            'companyId' => $user->companyId()->toString(),
        ]);

        if ($product === null) {
            throw new NotFoundHttpException('Product not found.');
        }

        return $product;
    }

    private function findMedia(Product $product, string $mediaId): ProductMedia
    {
        foreach ($product->getMedia() as $media) {
            if ($media->getId() === $mediaId) {
                return $media;
            }
        }

        throw new NotFoundHttpException('Picture not found.');
    }
}
