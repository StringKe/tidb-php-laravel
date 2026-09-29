<?php

namespace StringKe\TidbPhp\Laravel;

use Illuminate\Database\Migrations\DatabaseMigrationRepository;

/**
 * On a tidb connection the migrations table gets an AUTO_RANDOM clustered key instead of an AUTO_INCREMENT one.
 */
class TidbMigrationRepository extends DatabaseMigrationRepository
{
    public function createRepository()
    {
        $connection = $this->getConnection();

        if (! $connection instanceof TidbConnection) {
            parent::createRepository();

            return;
        }

        $connection->getSchemaBuilder()->create($this->table, function (TidbBlueprint $table) {
            $table->autoRandom();
            $table->string('migration');
            $table->integer('batch');
        });
    }
}
