<?php

declare(strict_types=1);

namespace Doctrine\Tests\ORM\Cache\Persister\Entity;

use Doctrine\ORM\Cache\ConcurrentRegion;
use Doctrine\ORM\Cache\EntityCacheKey;
use Doctrine\ORM\Cache\Lock;
use Doctrine\ORM\Cache\Persister\Entity\AbstractEntityPersister;
use Doctrine\ORM\Cache\Persister\Entity\ReadWriteCachedEntityPersister;
use Doctrine\ORM\Cache\Region;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\Mapping\ClassMetadata;
use Doctrine\ORM\Persisters\Entity\EntityPersister;
use Doctrine\Tests\Models\Cache\Country;
use ReflectionProperty;

/**
 * @group DDC-2183
 */
class ReadWriteCachedEntityPersisterTest extends AbstractEntityPersisterTest
{
    protected function createPersister(EntityManager $em, EntityPersister $persister, Region $region, ClassMetadata $metadata): AbstractEntityPersister
    {
        return new ReadWriteCachedEntityPersister($persister, $region, $em, $metadata);
    }

    protected function createRegion(): Region
    {
        return $this->createMock(ConcurrentRegion::class);
    }

    public function testDeleteShouldLockItem(): void
    {
        $entity    = new Country('Foo');
        $lock      = Lock::createLockRead();
        $persister = $this->createPersisterDefault();
        $key       = new EntityCacheKey(Country::class, ['id' => 1]);

        $this->region->expects(self::once())
            ->method('lock')
            ->with(self::equalTo($key))
            ->will(self::returnValue($lock));

        $this->em->getUnitOfWork()->registerManaged($entity, ['id' => 1], ['id' => 1, 'name' => 'Foo']);

        $persister->delete($entity);
    }

    public function testUpdateShouldLockItem(): void
    {
        $entity    = new Country('Foo');
        $lock      = Lock::createLockRead();
        $persister = $this->createPersisterDefault();
        $key       = new EntityCacheKey(Country::class, ['id' => 1]);

        $this->region->expects(self::once())
            ->method('lock')
            ->with(self::equalTo($key))
            ->will(self::returnValue($lock));

        $this->em->getUnitOfWork()->registerManaged($entity, ['id' => 1], ['id' => 1, 'name' => 'Foo']);

        $persister->update($entity);
    }

    public function testUpdateTransactionRollBackShouldEvictItem(): void
    {
        $entity    = new Country('Foo');
        $lock      = Lock::createLockRead();
        $persister = $this->createPersisterDefault();
        $key       = new EntityCacheKey(Country::class, ['id' => 1]);

        $this->region->expects(self::once())
            ->method('lock')
            ->with(self::equalTo($key))
            ->will(self::returnValue($lock));

        $this->region->expects(self::once())
            ->method('evict')
            ->with(self::equalTo($key))
            ->will(self::returnValue($lock));

        $this->em->getUnitOfWork()->registerManaged($entity, ['id' => 1], ['id' => 1, 'name' => 'Foo']);

        $persister->update($entity);
        $persister->afterTransactionRolledBack();
    }

    public function testDeleteTransactionRollBackShouldEvictItem(): void
    {
        $entity    = new Country('Foo');
        $lock      = Lock::createLockRead();
        $persister = $this->createPersisterDefault();
        $key       = new EntityCacheKey(Country::class, ['id' => 1]);

        $this->region->expects(self::once())
            ->method('lock')
            ->with(self::equalTo($key))
            ->will(self::returnValue($lock));

        $this->region->expects(self::once())
            ->method('evict')
            ->with(self::equalTo($key));

        $this->em->getUnitOfWork()->registerManaged($entity, ['id' => 1], ['id' => 1, 'name' => 'Foo']);

        $persister->delete($entity);
        $persister->afterTransactionRolledBack();
    }

    public function testTransactionRollBackShouldClearQueue(): void
    {
        $entity    = new Country('Foo');
        $lock      = Lock::createLockRead();
        $persister = $this->createPersisterDefault();
        $key       = new EntityCacheKey(Country::class, ['id' => 1]);
        $property  = new ReflectionProperty(ReadWriteCachedEntityPersister::class, 'queuedCache');

        $property->setAccessible(true);

        $this->region->expects(self::exactly(2))
            ->method('lock')
            ->with(self::equalTo($key))
            ->will(self::returnValue($lock));

        $this->region->expects(self::exactly(2))
            ->method('evict')
            ->with(self::equalTo($key));

        $this->em->getUnitOfWork()->registerManaged($entity, ['id' => 1], ['id' => 1, 'name' => 'Foo']);

        $persister->update($entity);
        $persister->delete($entity);

        self::assertCount(2, $property->getValue($persister));

        $persister->afterTransactionRolledBack();

        self::assertCount(0, $property->getValue($persister));
    }

    public function testTransactionCommitShouldClearQueue(): void
    {
        $entity    = new Country('Foo');
        $lock      = Lock::createLockRead();
        $persister = $this->createPersisterDefault();
        $key       = new EntityCacheKey(Country::class, ['id' => 1]);
        $property  = new ReflectionProperty(ReadWriteCachedEntityPersister::class, 'queuedCache');

        $property->setAccessible(true);

        $this->region->expects(self::exactly(2))
            ->method('lock')
            ->with(self::equalTo($key))
            ->will(self::returnValue($lock));

        $this->region->expects(self::exactly(2))
            ->method('evict')
            ->with(self::equalTo($key));

        $this->em->getUnitOfWork()->registerManaged($entity, ['id' => 1], ['id' => 1, 'name' => 'Foo']);

        $persister->update($entity);
        $persister->delete($entity);

        self::assertCount(2, $property->getValue($persister));

        $persister->afterTransactionComplete();

        self::assertCount(0, $property->getValue($persister));
    }

    public function testDeleteLockFailureShouldIgnoreQueue(): void
    {
        $entity    = new Country('Foo');
        $persister = $this->createPersisterDefault();
        $key       = new EntityCacheKey(Country::class, ['id' => 1]);
        $property  = new ReflectionProperty(ReadWriteCachedEntityPersister::class, 'queuedCache');

        $property->setAccessible(true);

        $this->region->expects(self::once())
            ->method('lock')
            ->with(self::equalTo($key))
            ->will(self::returnValue(null));

        $this->entityPersister->expects(self::once())
            ->method('delete')
            ->with(self::equalTo($entity));

        $this->em->getUnitOfWork()->registerManaged($entity, ['id' => 1], ['id' => 1, 'name' => 'Foo']);

        $persister->delete($entity);
        self::assertCount(0, $property->getValue($persister));
    }

    public function testUpdateLockFailureShouldIgnoreQueue(): void
    {
        $entity    = new Country('Foo');
        $persister = $this->createPersisterDefault();
        $key       = new EntityCacheKey(Country::class, ['id' => 1]);
        $property  = new ReflectionProperty(ReadWriteCachedEntityPersister::class, 'queuedCache');

        $property->setAccessible(true);

        $this->region->expects(self::once())
            ->method('lock')
            ->with(self::equalTo($key))
            ->will(self::returnValue(null));

        $this->entityPersister->expects(self::once())
            ->method('update')
            ->with(self::equalTo($entity));

        $this->em->getUnitOfWork()->registerManaged($entity, ['id' => 1], ['id' => 1, 'name' => 'Foo']);

        $persister->update($entity);
        self::assertCount(0, $property->getValue($persister));
    }
}
