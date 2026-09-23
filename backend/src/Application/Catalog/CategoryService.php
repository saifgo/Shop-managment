<?php

declare(strict_types=1);

namespace App\Application\Catalog;

use App\Domain\Shared\EntityId;
use App\Infrastructure\Persistence\Entity\Catalog\Category;
use App\Infrastructure\Persistence\Entity\Identity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

final class CategoryService
{
    public function __construct(
        private EntityManagerInterface $entityManager,
    ) {
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function tree(User $user): array
    {
        /** @var list<Category> $categories */
        $categories = $this->entityManager->getRepository(Category::class)->findBy(
            ['companyId' => $user->companyId()->toString()],
            ['sortOrder' => 'ASC', 'name' => 'ASC'],
        );

        $byParent = [];

        foreach ($categories as $category) {
            $parentId = $category->getParent()?->getId() ?? 'root';
            $byParent[$parentId][] = $category;
        }

        return $this->buildTree($byParent, 'root');
    }

    /**
     * @param array<string, mixed> $data
     *
     * @return array<string, mixed>
     */
    public function create(User $user, array $data): array
    {
        $parent = null;

        if (!empty($data['parent_id'])) {
            $parent = $this->findCategory($user, $data['parent_id']);
        }

        $category = new Category(
            id: EntityId::generate(),
            companyId: $user->companyId(),
            name: $data['name'],
            slug: $data['slug'],
            parent: $parent,
            sortOrder: (int) ($data['sort_order'] ?? 0),
        );

        $this->entityManager->persist($category);
        $this->entityManager->flush();

        return $this->serializeCategory($category);
    }

    private function findCategory(User $user, string $categoryId): Category
    {
        /** @var Category|null $category */
        $category = $this->entityManager->getRepository(Category::class)->findOneBy([
            'id' => $categoryId,
            'companyId' => $user->companyId()->toString(),
        ]);

        if ($category === null) {
            throw new NotFoundHttpException('Category not found.');
        }

        return $category;
    }

    /**
     * @param array<string, list<Category>> $byParent
     *
     * @return list<array<string, mixed>>
     */
    private function buildTree(array $byParent, string $parentId): array
    {
        $nodes = [];

        foreach ($byParent[$parentId] ?? [] as $category) {
            $nodes[] = [
                ...$this->serializeCategory($category),
                'children' => $this->buildTree($byParent, $category->getId()),
            ];
        }

        return $nodes;
    }

    /**
     * @return array<string, mixed>
     */
    private function serializeCategory(Category $category): array
    {
        return [
            'id' => $category->getId(),
            'name' => $category->getName(),
            'slug' => $category->getSlug(),
            'parent_id' => $category->getParent()?->getId(),
            'sort_order' => $category->getSortOrder(),
        ];
    }
}
