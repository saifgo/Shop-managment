<?php

declare(strict_types=1);

namespace App\Infrastructure\Console;

use App\Domain\Catalog\BackorderPolicy;
use App\Domain\Catalog\Visibility;
use App\Domain\Customer\CustomerType;
use App\Domain\Shared\EntityId;
use App\Domain\Shared\Money;
use App\Infrastructure\Persistence\Entity\Catalog\CatalogAttribute;
use App\Infrastructure\Persistence\Entity\Catalog\Category;
use App\Infrastructure\Persistence\Entity\Catalog\CustomerPriceOverride;
use App\Infrastructure\Persistence\Entity\Catalog\PriceList;
use App\Infrastructure\Persistence\Entity\Catalog\PriceListItem;
use App\Infrastructure\Persistence\Entity\Catalog\Product;
use App\Infrastructure\Persistence\Entity\Catalog\ProductMedia;
use App\Infrastructure\Persistence\Entity\Catalog\ProductVariant;
use App\Infrastructure\Persistence\Entity\Customer\Address;
use App\Infrastructure\Persistence\Entity\Customer\Contact;
use App\Infrastructure\Persistence\Entity\Customer\Customer;
use App\Infrastructure\Persistence\Entity\Customer\PortalUser;
use App\Infrastructure\Persistence\Entity\Identity\User;
use App\Infrastructure\Persistence\UnitOfWork;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:seed-catalog',
    description: 'Seed demo categories, products, customers, and portal links',
)]
final class SeedCatalogCommand extends Command
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private UnitOfWork $unitOfWork,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $companyId = EntityId::fromString(SeedIdentityCommand::DEFAULT_COMPANY_ID);

        if ($this->entityManager->getRepository(Category::class)->findOneBy(['slug' => 'ceramics', 'companyId' => $companyId->toString()]) !== null) {
            $io->note('Catalog seed already applied.');

            return Command::SUCCESS;
        }

        $ceramics = new Category(EntityId::generate(), $companyId, 'Ceramics', 'ceramics', sortOrder: 1);
        $tableware = new Category(EntityId::generate(), $companyId, 'Tableware', 'tableware', $ceramics, 1);
        $decor = new Category(EntityId::generate(), $companyId, 'Decor', 'decor', $ceramics, 2);

        foreach ([$ceramics, $tableware, $decor] as $category) {
            $this->entityManager->persist($category);
        }

        $sizeAttr = new CatalogAttribute(EntityId::generate(), $companyId, 'size', 'Size', 'select', ['S', 'M', 'L']);
        $colorAttr = new CatalogAttribute(EntityId::generate(), $companyId, 'color', 'Color', 'select', ['Ivory', 'Terracotta', 'Sage']);
        $this->entityManager->persist($sizeAttr);
        $this->entityManager->persist($colorAttr);

        $priceList = new PriceList(EntityId::generate(), $companyId, 'default', 'Default Price List', 'TND', true);
        $this->entityManager->persist($priceList);

        $tagineVariants = $this->createProduct(
            $companyId,
            $tableware,
            'Berber Tagine',
            'berber-tagine',
            'Handcrafted tagine for slow cooking.',
            Visibility::Public,
            BackorderPolicy::Allow,
            'https://minio.local/tittawin/products/tagine-primary.jpg',
            [
                ['sku' => 'TAG-S', 'name' => 'Small', 'price' => '249.0000', 'attrs' => ['size' => 'S', 'color' => 'Terracotta']],
                ['sku' => 'TAG-M', 'name' => 'Medium', 'price' => '299.0000', 'attrs' => ['size' => 'M', 'color' => 'Terracotta']],
                ['sku' => 'TAG-L', 'name' => 'Large', 'price' => '349.0000', 'attrs' => ['size' => 'L', 'color' => 'Terracotta']],
            ],
            $priceList,
        );

        $this->createProduct(
            $companyId,
            $decor,
            'Atlas Vase',
            'atlas-vase',
            'Decorative vase with Atlas motif.',
            Visibility::Public,
            BackorderPolicy::Deny,
            'https://minio.local/tittawin/products/vase-primary.jpg',
            [
                ['sku' => 'VAS-M', 'name' => 'Medium Vase', 'price' => '189.0000', 'attrs' => ['size' => 'M', 'color' => 'Ivory']],
            ],
            $priceList,
        );

        $this->createProduct(
            $companyId,
            $tableware,
            'Artisan Bowl Set',
            'artisan-bowl-set',
            'Set of four serving bowls.',
            Visibility::Public,
            BackorderPolicy::Allow,
            'https://minio.local/tittawin/products/bowls-primary.jpg',
            [
                ['sku' => 'BWL-4', 'name' => 'Set of 4', 'price' => '159.0000', 'attrs' => ['size' => 'M', 'color' => 'Sage']],
            ],
            $priceList,
        );

        $portalCustomer = $this->createPortalCustomer($companyId);
        $wholesaleCustomer = $this->createWholesaleCustomer($companyId);

        // Use the in-memory reference: the variants are persisted but not flushed yet,
        // so a repository lookup would not find them on a fresh database.
        $mediumTagine = $tagineVariants['TAG-M'];

        $override = new CustomerPriceOverride(
            id: EntityId::generate(),
            customer: $portalCustomer,
            variant: $mediumTagine,
            price: Money::of('279.0000', 'TND'),
        );
        $this->entityManager->persist($override);

        $override = new CustomerPriceOverride(
            id: EntityId::generate(),
            customer: $wholesaleCustomer,
            variant: $mediumTagine,
            price: Money::of('259.0000', 'TND'),
        );
        $this->entityManager->persist($override);

        $this->unitOfWork->flush();

        $io->success('Catalog and customer seed complete.');
        $io->listing([
            sprintf('Products seeded including "%s"', $mediumTagine->getProduct()->getName()),
            'Portal customer linked to customer@tittawin.local',
            'Wholesale demo customer: Atlas Hotel Group',
        ]);

        return Command::SUCCESS;
    }

    /**
     * @param list<array{sku: string, name: string, price: string, attrs: array<string, string>}> $variants
     *
     * @return array<string, ProductVariant> the created variants, keyed by SKU
     */
    private function createProduct(
        EntityId $companyId,
        Category $category,
        string $name,
        string $slug,
        string $description,
        Visibility $visibility,
        BackorderPolicy $backorderPolicy,
        string $imageUrl,
        array $variants,
        PriceList $priceList,
    ): array {
        $product = new Product(
            id: EntityId::generate(),
            companyId: $companyId,
            name: $name,
            slug: $slug,
            visibility: $visibility,
            backorderPolicy: $backorderPolicy,
            category: $category,
            description: $description,
        );
        $this->entityManager->persist($product);

        $media = new ProductMedia(
            id: EntityId::generate(),
            product: $product,
            url: $imageUrl,
            altText: $name,
            isPrimary: true,
        );
        $this->entityManager->persist($media);

        $created = [];
        foreach ($variants as $variantData) {
            $variant = new ProductVariant(
                id: EntityId::generate(),
                companyId: $companyId,
                product: $product,
                sku: $variantData['sku'],
                name: $variantData['name'],
                basePrice: Money::of($variantData['price'], 'TND'),
                attributes: $variantData['attrs'],
            );
            $this->entityManager->persist($variant);

            $listPrice = Money::of($variantData['price'], 'TND');
            $item = new PriceListItem(EntityId::generate(), $priceList, $variant, $listPrice);
            $this->entityManager->persist($item);

            $created[$variantData['sku']] = $variant;
        }

        return $created;
    }

    private function createPortalCustomer(EntityId $companyId): Customer
    {
        $customer = new Customer(
            id: EntityId::generate(),
            companyId: $companyId,
            type: CustomerType::Person,
            displayName: 'Portal Customer',
            legalName: 'Portal Customer',
        );
        $this->entityManager->persist($customer);

        $address = new Address(
            id: EntityId::generate(),
            customer: $customer,
            type: 'shipping',
            line1: '12 Rue des Artisans',
            city: 'Marrakech',
            postalCode: '40000',
            country: 'MA',
            isDefault: true,
        );
        $this->entityManager->persist($address);

        $contact = new Contact(
            id: EntityId::generate(),
            customer: $customer,
            name: 'Portal Customer',
            email: 'customer@tittawin.local',
            phone: '+212600000001',
            isPrimary: true,
        );
        $this->entityManager->persist($contact);

        /** @var User|null $user */
        $user = $this->entityManager->getRepository(User::class)->findOneBy(['email' => 'customer@tittawin.local']);

        if ($user !== null) {
            $portalUser = new PortalUser(EntityId::generate(), $customer, $user);
            $this->entityManager->persist($portalUser);
        }

        return $customer;
    }

    private function createWholesaleCustomer(EntityId $companyId): Customer
    {
        $customer = new Customer(
            id: EntityId::generate(),
            companyId: $companyId,
            type: CustomerType::Company,
            displayName: 'Atlas Hotel Group',
            legalName: 'Atlas Hotel Group SARL',
            taxId: 'RC-123456',
            vatNumber: 'MA-VAT-789012',
            notes: 'Wholesale buyer — negotiated pricing.',
        );
        $this->entityManager->persist($customer);

        $address = new Address(
            id: EntityId::generate(),
            customer: $customer,
            type: 'billing',
            line1: '45 Avenue Mohammed V',
            city: 'Casablanca',
            postalCode: '20000',
            country: 'MA',
            isDefault: true,
        );
        $this->entityManager->persist($address);

        $contact = new Contact(
            id: EntityId::generate(),
            customer: $customer,
            name: 'Purchasing Desk',
            email: 'purchasing@atlashotel.local',
            phone: '+212522000000',
            role: 'Purchasing',
            isPrimary: true,
        );
        $this->entityManager->persist($contact);

        return $customer;
    }
}
