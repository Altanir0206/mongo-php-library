<?php

namespace MongoDB\Tests;

use MongoDB\Driver\Command;
use MongoDB\Driver\Cursor;
use MongoDB\Driver\Manager;
use MongoDB\Driver\ReadPreference;
use MongoDB\Driver\Query;
use MongoDB\Driver\Server;
use stdClass;
use UnexpectedValueException;

abstract class FunctionalTestCase extends TestCase
{
    protected $manager;

    public function setUp()
    {
        $this->manager = new Manager($this->getUri());
    }

    protected function assertCollectionCount($namespace, $count)
    {
        list($databaseName, $collectionName) = explode('.', $namespace, 2);

        $cursor = $this->manager->executeCommand($databaseName, new Command(['count' => $collectionName]));
        $cursor->setTypeMap(['root' => 'array', 'document' => 'array']);
        $document = current($cursor->toArray());

        $this->assertArrayHasKey('n', $document);
        $this->assertEquals($count, $document['n']);
    }

    protected function assertCommandSucceeded($document)
    {
        $document = is_object($document) ? (array) $document : $document;

        $this->assertArrayHasKey('ok', $document);
        $this->assertEquals(1, $document['ok']);
    }

    protected function assertSameObjectId($expectedObjectId, $actualObjectId)
    {
        $this->assertInstanceOf('MongoDB\BSON\ObjectId', $expectedObjectId);
        $this->assertInstanceOf('MongoDB\BSON\ObjectId', $actualObjectId);
        $this->assertEquals((string) $expectedObjectId, (string) $actualObjectId);
    }

    protected function getFeatureCompatibilityVersion(ReadPreference $readPreference = null)
    {
        if (version_compare($this->getServerVersion(), '3.4.0', '<')) {
            return $this->getServerVersion($readPreference);
        }

        $cursor = $this->manager->executeCommand(
            'admin',
            new Command(['getParameter' => 1, 'featureCompatibilityVersion' => 1]),
            $readPreference ?: new ReadPreference(ReadPreference::RP_PRIMARY)
        );

        $cursor->setTypeMap(['root' => 'array', 'document' => 'array']);
        $document = current($cursor->toArray());

        // MongoDB 3.6: featureCompatibilityVersion is an embedded document
        if (isset($document['featureCompatibilityVersion']['version']) && is_string($document['featureCompatibilityVersion']['version'])) {
            return $document['featureCompatibilityVersion']['version'];
        }

        // MongoDB 3.4: featureCompatibilityVersion is a string
        if (isset($document['featureCompatibilityVersion']) && is_string($document['featureCompatibilityVersion'])) {
            return $document['featureCompatibilityVersion'];
        }

        throw new UnexpectedValueException('Could not determine featureCompatibilityVersion');
    }

    protected function getPrimaryServer()
    {
        return $this->manager->selectServer(new ReadPreference(ReadPreference::RP_PRIMARY));
    }

    protected function getServerVersion(ReadPreference $readPreference = null)
    {
        $cursor = $this->manager->executeCommand(
            $this->getDatabaseName(),
            new Command(['buildInfo' => 1]),
            $readPreference ?: new ReadPreference(ReadPreference::RP_PRIMARY)
        );

        $cursor->setTypeMap(['root' => 'array', 'document' => 'array']);
        $document = current($cursor->toArray());

        if (isset($document['version']) && is_string($document['version'])) {
            return $document['version'];
        }

        throw new UnexpectedValueException('Could not determine server version');
    }

    protected function getServerStorageEngine(ReadPreference $readPreference = null)
    {
        $cursor = $this->manager->executeCommand(
            $this->getDatabaseName(),
            new Command(['serverStatus' => 1]),
            $readPreference ?: new ReadPreference('primary')
        );

        $result = current($cursor->toArray());

        if (isset($result->storageEngine->name) && is_string($result->storageEngine->name)) {
            return $result->storageEngine->name;
        }

        throw new UnexpectedValueException('Could not determine server storage engine');
    }

    protected function isShardedClusterUsingReplicasets()
    {
        $cursor = $this->getPrimaryServer()->executeQuery(
            'config.shards',
            new Query([], ['limit' => 1])
        );

        $cursor->setTypeMap(['root' => 'array', 'document' => 'array']);
        $document = current($cursor->toArray());

        if (! $document ) {
            return false;
        }

        /**
         * Use regular expression to distinguish between standalone or replicaset:
         * Without a replicaset: "host" : "localhost:4100"
         * With a replicaset: "host" : "dec6d8a7-9bc1-4c0e-960c-615f860b956f/localhost:4400,localhost:4401"
         */
        return preg_match('@^.*/.*:\d+@', $document['host']);
    }

    protected function skipIfChangeStreamIsNotSupported()
    {
        switch ( $this->getPrimaryServer()->getType() )
        {
            case Server::TYPE_MONGOS:
                if (version_compare($this->getServerVersion(), '3.6.0', '<')) {
                    $this->markTestSkipped('$changeStream is only supported on MongoDB 3.6 or higher');
                }
                if (!$this->isShardedClusterUsingReplicasets()) {
                    $this->markTestSkipped('$changeStream is only supported with replicasets');
                }
                break;

            case Server::TYPE_RS_PRIMARY:
                if (version_compare($this->getFeatureCompatibilityVersion(), '3.6', '<')) {
                    $this->markTestSkipped('$changeStream is only supported on FCV 3.6 or higher');
                }
                break;

            default:
                $this->markTestSkipped('$changeStream is not supported');
        }
    }

    protected function skipIfCausalConsistencyIsNotSupported()
    {
        switch ( $this->getPrimaryServer()->getType() )
        {
            case Server::TYPE_MONGOS:
                if (version_compare($this->getServerVersion(), '3.6.0', '<')) {
                    $this->markTestSkipped('Causal Consistency is only supported on MongoDB 3.6 or higher');
                }
                if (!$this->isShardedClusterUsingReplicasets()) {
                    $this->markTestSkipped('Causal Consistency is only supported with replicasets');
                }
                break;

            case Server::TYPE_RS_PRIMARY:
                if (version_compare($this->getFeatureCompatibilityVersion(), '3.6', '<')) {
                    $this->markTestSkipped('Causal Consistency is only supported on FCV 3.6 or higher');
                }
                if ($this->getServerStorageEngine() !== 'wiredTiger') {
                    $this->markTestSkipped('Causal Consistency requires WiredTiger storage engine');
                }
                break;

            default:
                $this->markTestSkipped('Causal Consistency is not supported');
        }
    }

    protected function skipIfTransactionsAreNotSupported()
    {
        if ($this->getPrimaryServer()->getType() === Server::TYPE_STANDALONE) {
            $this->markTestSkipped('Transactions are not supported on standalone servers');
        }

        // TODO: MongoDB 4.2 should support sharded clusters (see: PHPLIB-374)
        if ($this->getPrimaryServer()->getType() === Server::TYPE_MONGOS) {
            $this->markTestSkipped('Transactions are not supported on sharded clusters');
        }

        if (version_compare($this->getFeatureCompatibilityVersion(), '4.0', '<')) {
            $this->markTestSkipped('Transactions are only supported on FCV 4.0 or higher');
        }

        if ($this->getServerStorageEngine() !== 'wiredTiger') {
            $this->markTestSkipped('Transactions require WiredTiger storage engine');
        }
    }
}
