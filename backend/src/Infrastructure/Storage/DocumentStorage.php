<?php

declare(strict_types=1);

namespace App\Infrastructure\Storage;

use Symfony\Component\Filesystem\Filesystem;

/**
 * Document storage with local filesystem fallback and optional MinIO/S3 backend.
 */
final class DocumentStorage
{
    private readonly MinioDocumentStorage|LocalFilesystemStorage $backend;

    public function __construct(
        string $storagePath,
        ?string $minioEndpoint = null,
        ?string $minioAccessKey = null,
        ?string $minioSecretKey = null,
        ?string $minioBucket = null,
    ) {
        if (
            is_string($minioEndpoint) && $minioEndpoint !== ''
            && is_string($minioAccessKey) && $minioAccessKey !== ''
            && is_string($minioSecretKey) && $minioSecretKey !== ''
            && is_string($minioBucket) && $minioBucket !== ''
        ) {
            $this->backend = new MinioDocumentStorage(
                endpoint: $minioEndpoint,
                accessKey: $minioAccessKey,
                secretKey: $minioSecretKey,
                bucket: $minioBucket,
            );
        } else {
            $this->backend = new LocalFilesystemStorage($storagePath);
        }
    }

    public function store(string $key, string $contents, string $mimeType): string
    {
        return $this->backend->store($key, $contents, $mimeType);
    }

    public function read(string $key): ?string
    {
        return $this->backend->read($key);
    }

    public function exists(string $key): bool
    {
        return $this->backend->exists($key);
    }

    public function delete(string $key): void
    {
        $this->backend->delete($key);
    }
}

final class LocalFilesystemStorage
{
    public function __construct(
        private string $storagePath,
        private Filesystem $filesystem = new Filesystem(),
    ) {
    }

    public function store(string $key, string $contents, string $mimeType): string
    {
        unset($mimeType);
        $path = $this->resolvePath($key);
        $this->filesystem->mkdir(\dirname($path));
        $this->filesystem->dumpFile($path, $contents);

        return $key;
    }

    public function read(string $key): ?string
    {
        $path = $this->resolvePath($key);

        if (!$this->filesystem->exists($path)) {
            return null;
        }

        return file_get_contents($path) ?: null;
    }

    public function exists(string $key): bool
    {
        return $this->filesystem->exists($this->resolvePath($key));
    }

    public function delete(string $key): void
    {
        $this->filesystem->remove($this->resolvePath($key));
    }

    private function resolvePath(string $key): string
    {
        return rtrim($this->storagePath, '/\\').DIRECTORY_SEPARATOR.str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $key);
    }
}
