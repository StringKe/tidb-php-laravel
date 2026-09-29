<?php

namespace StringKe\TidbPhp\Laravel;

use Illuminate\Database\Connectors\Connector;
use Illuminate\Database\Connectors\ConnectorInterface;
use LogicException;
use Pdo\Tidb;

/**
 * Session settings travel in the DSN, so pdo_tidb applies them again after every reconnect and every resetConnection().
 */
final class TidbConnector extends Connector implements ConnectorInterface
{
    private const array DSN_KEYS = [
        'host', 'port', 'unix_socket', 'charset', 'collation', 'sslmode', 'ssl_ca', 'ssl_capath', 'ssl_cert', 'ssl_key', 'tls_version',
        'compression', 'zstd_level', 'connect_timeout', 'read_timeout', 'write_timeout', 'multi_statements', 'found_rows', 'local_infile',
        'local_infile_directory', 'init_command', 'max_execution_time', 'idle_transaction_timeout', 'discover', 'discover_ttl', 'max_lifetime',
        'idle_ping', 'auth_plugin',
    ];

    private const string STRICT_MODE = 'ONLY_FULL_GROUP_BY,STRICT_TRANS_TABLES,NO_ZERO_IN_DATE,NO_ZERO_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION';

    /**
     * @param  array<string, mixed>  $config
     */
    public function connect(array $config): Tidb
    {
        $pdo = $this->createConnection($this->dsn($config), $config, $this->getOptions($config));

        if (! $pdo instanceof Tidb) {
            throw new LogicException('The tidb connection did not return Pdo\Tidb; make sure the pdo_tidb extension is loaded.');
        }

        return $pdo;
    }

    /**
     * @param  array<string, mixed>  $config
     */
    public function dsn(array $config): string
    {
        $pairs = ['dbname' => $config['database'] ?? null];

        foreach (self::DSN_KEYS as $key) {
            $pairs[$key] = $config[$key] ?? null;
        }

        if (($config['unix_socket'] ?? '') !== '') {
            $pairs['host'] = null;
            $pairs['port'] = null;
        }

        $pairs['time_zone'] = $config['timezone'] ?? null;

        foreach ($this->sessionVariables($config) as $name => $value) {
            $pairs['session.'.$name] = $value;
        }

        foreach ((array) ($config['attributes'] ?? []) as $name => $value) {
            $pairs['attr.'.$name] = $value;
        }

        $parts = [];

        foreach ($pairs as $key => $value) {
            if ($value === null || $value === '') {
                continue;
            }

            $value = is_bool($value) ? (int) $value : $value;

            if (! is_scalar($value) || preg_match('/^[A-Za-z0-9_.]+$/', $key) !== 1 || str_contains((string) $value, ';')) {
                throw new LogicException("The tidb connection option [{$key}] cannot be written into the DSN.");
            }

            $parts[] = $key.'='.$value;
        }

        return 'tidb:'.implode(';', $parts);
    }

    /**
     * @param  array<string, mixed>  $config
     * @return array<string, mixed>
     */
    private function sessionVariables(array $config): array
    {
        $variables = [];

        if (isset($config['isolation_level'])) {
            $variables['transaction_isolation'] = str_replace(' ', '-', strtoupper((string) $config['isolation_level']));
        }

        if (isset($config['modes'])) {
            $variables['sql_mode'] = implode(',', (array) $config['modes']);
        } elseif (isset($config['strict'])) {
            $variables['sql_mode'] = $config['strict'] ? self::STRICT_MODE : 'NO_ENGINE_SUBSTITUTION';
        }

        foreach ((array) ($config['settings'] ?? []) as $name => $value) {
            $variables[(string) $name] = $value;
        }

        return $variables;
    }
}
