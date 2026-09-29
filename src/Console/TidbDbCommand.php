<?php

namespace StringKe\TidbPhp\Laravel\Console;

use Illuminate\Database\Console\DbCommand;

/**
 * Opens the mysql client for tidb connections. The password goes through MYSQL_PWD instead of the process list, and --comments keeps optimizer hints.
 */
class TidbDbCommand extends DbCommand
{
    /**
     * @param  array<string, mixed>  $connection
     */
    public function getCommand(array $connection)
    {
        return $connection['driver'] === 'tidb' ? 'mysql' : parent::getCommand($connection);
    }

    /**
     * @param  array<string, mixed>  $connection
     * @return array<int, string>
     */
    protected function getTidbArguments(array $connection): array
    {
        return array_merge([
            '--host='.$connection['host'],
            '--port='.($connection['port'] ?? 4000),
            '--user='.$connection['username'],
            '--comments',
        ], $this->getOptionalArguments([
            'unix_socket' => '--socket='.($connection['unix_socket'] ?? ''),
            'charset' => '--default-character-set='.($connection['charset'] ?? ''),
        ], $connection), [$connection['database']]);
    }

    /**
     * @param  array<string, mixed>  $connection
     * @return array<string, string>|null
     */
    protected function getTidbEnvironment(array $connection): ?array
    {
        return empty($connection['password']) ? null : ['MYSQL_PWD' => (string) $connection['password']];
    }
}
