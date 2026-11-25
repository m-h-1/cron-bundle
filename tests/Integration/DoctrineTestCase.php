<?php
declare(strict_types=1);

namespace MH1\CronBundle\Tests\Integration;

use Doctrine\DBAL\Configuration as DbalConfiguration;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Types\Type;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\ORMSetup;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

abstract class DoctrineTestCase extends TestCase
{
    protected function createEntityManager(?string $databasePath = null): EntityManager
    {
        $dbalConfig = new DbalConfiguration();
        $typeRegistry = method_exists($dbalConfig, 'getTypeProvider')
            ? $dbalConfig->getTypeProvider()
            : Type::getTypeRegistry();
        $doctrineConfig = Yaml::parseFile(__DIR__ . '/../../config/packages/doctrine.yaml');
        foreach ($doctrineConfig['doctrine']['dbal']['types'] as $name => $type) {
            if (!$typeRegistry->has($name)) {
                $typeRegistry->register($name, new $type());
            }
        }

        $entityPaths = [__DIR__ . '/../../src/Entity'];
        if (PHP_VERSION_ID >= 80400 && method_exists(ORMSetup::class, 'createAttributeMetadataConfig')) {
            $config = ORMSetup::createAttributeMetadataConfig($entityPaths, true);
            $config->enableNativeLazyObjects(true);
        } else {
            $config = ORMSetup::createAttributeMetadataConfiguration($entityPaths, true);
        }

        if (method_exists($config, 'setUseSchemaEditorApi') && method_exists(Schema::class, 'edit')) {
            $config->setUseSchemaEditorApi(true);
        }

        $params = ['driver' => 'pdo_sqlite'] + ($databasePath === null
            ? ['memory' => true]
            : ['path' => $databasePath]);
        $connection = DriverManager::getConnection($params, $dbalConfig);
        $connection->executeStatement('PRAGMA foreign_keys = ON');

        return new EntityManager($connection, $config);
    }
}
