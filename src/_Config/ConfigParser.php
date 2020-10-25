<?php

namespace Sim\Cart\Config;

use PDO;
use Sim\Cart\Exceptions\ConfigException;
use Sim\Cart\Helpers\DB;
use Sim\Cart\Interfaces\IDBException;

class ConfigParser
{
    /**
     * @var ConfigParser
     */
    protected static $parser = null;

    /**
     * @var DB
     */
    protected $db = null;

    /**
     * @var array
     */
    protected $config;

    /**
     * @var string
     */
    protected $blueprint_key = 'blueprints';

    /**
     * @var string
     */
    protected $table_name_key = 'table_name';

    /**
     * @var string
     */
    protected $columns_key = 'columns';

    /**
     * @var string
     */
    protected $types_key = 'types';

    /**
     * @var string
     */
    protected $constraints_key = 'constraints';

    /**
     * @var array
     */
    protected $table_aliases = [
        'users', 'products', 'product_property',
        'carts', 'cart_item'
    ];

    /**
     * @var array
     */
    protected $tables = [];

    /**
     * @var array
     */
    protected $tables_columns = [];

    /**
     * @var array
     */
    protected $structures = [];

    /**
     * ConfigParser constructor.
     * @param array $config
     * @param PDO $pdo
     * @throws IDBException
     */
    public function __construct(array $config, PDO $pdo)
    {
        $this->config = $config;
        $this->parse();
        if (!is_null($pdo)) {
            $this->db = new DB($pdo);
        }
    }

    /**
     * @return static
     * @throws ConfigException
     */
    public function up()
    {
        if (is_null($this->db)) {
            throw new ConfigException('There is no connection to database!');
        }

        // change database collation
        try {
            $this->db->changeDBCollation();
        } catch (IDBException $e) {
            // do nothing
        } catch (\Exception $e) {
            // do nothing
        }

        if (!empty($this->structures)) {
            // iterate through all tables for their structure
            foreach ($this->structures as $tableName => $items) {
                // create table is not exists
                try {
                    $this->db->createTableIfNotExists(
                        $tableName,
                        $items[$this->columns_key]['id'],
                        $items[$this->types_key]['id']
                    );
                } catch (IDBException $e) {
                    // do nothing
                } catch (\Exception $e) {
                    // do nothing
                }

                // iterate through all columns and create column if not exists
                foreach ($items[$this->columns_key] as $columnKey => $columnName) {
                    $typeKey = $items[$this->types_key][$columnKey] ?? null;
                    if (
                        'id' !== $columnKey &&
                        is_string($typeKey) &&
                        !empty($typeKey)
                    ) {
                        try {
                            $this->db->createColumnIfNotExists($tableName, $columnName, $typeKey);
                        } catch (IDBException $e) {
                            // do nothing
                        } catch (\Exception $e) {
                            // do nothing
                        }
                    }
                }
            }

            // iterate through all tables for their constraint(s)
            foreach ($this->structures as $tableName => $items) {
                if (isset($items[$this->constraints_key])) {
                    foreach ($items[$this->constraints_key] as $constraint) {
                        if (is_string($constraint) && !empty($constraint)) {
                            try {
                                $this->db->addConstraint($tableName, $constraint);
                            } catch (IDBException $e) {
                                // do nothing
                            } catch (\Exception $e) {
                                // do nothing
                            }
                        }
                    }
                }
            }
        }
        return $this;
    }

    /**
     * @return array
     */
    public function getTables(): array
    {
        return $this->tables;
    }

    /**
     * In following format:
     * [
     *   'table alias' => [
     *     columns1, column2, ...
     *   ]
     *   ...
     * ]
     *
     * @return array
     */
    public function getTablesColumns(): array
    {
        return $this->tables_columns;
    }

    /**
     * Array of columns' name
     *
     * @param $table_alias
     * @return array
     */
    public function getTablesColumn($table_alias): array
    {
        return $this->tables_columns[$table_alias] ?? [];
    }

    /**
     * Parse config file
     */
    protected function parse(): void
    {
        if (!empty($this->config)) {
            foreach ($this->config as $key => $structures) {
                if ($key === $this->blueprint_key) { // if it was blueprints key
                    // iterate through all blueprints
                    foreach ($structures as $tableAlias => $structure) {
                        // if alias is in aliases array
                        if (in_array($tableAlias, $this->table_aliases)) {
                            // get tables
                            $this->tables[$tableAlias] = $structure[$this->table_name_key] ?? '';
                            // get tables and other structure
                            $this->structures[$tableAlias] = [
                                $this->columns_key => $structure[$this->columns_key] ?? [],
                                $this->types_key => $structure[$this->types_key] ?? [],
                                $this->constraints_key => $structure[$this->constraints_key] ?? [],
                            ];
                            // get tables keys that should be fixed
                            $this->tables_columns[$tableAlias] = $structure[$this->columns_key] ?? [];
                        }
                    }
                }
            }
        }
    }
}