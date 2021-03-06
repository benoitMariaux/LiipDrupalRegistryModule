<?php

namespace Liip\Drupal\Modules\Registry\Database;

use Assert\Assertion;
use Liip\Drupal\Modules\Registry\Registry;
use Liip\Drupal\Modules\Registry\RegistryException;
use Liip\Registry\Adaptor\Decorator\DecoratorInterface;


class MySql extends Registry
{
    /**
     * @var \PDO Database connection object
     */
    protected $mysql;
    /**
     * @var \Liip\Registry\Adaptor\Decorator\DecoratorInterface
     */
    protected $decorator;

    /**
     * @param string $section
     * @param \Assert\Assertion $assertion
     * @param \PDO $mysql
     * @param \Liip\Registry\Adaptor\Decorator\DecoratorInterface $decorator
     */
    public function __construct($section, Assertion $assertion, \PDO $mysql, DecoratorInterface $decorator)
    {
        $section = strtolower($section);

        parent::__construct($section, $assertion);

        $this->mysql = $mysql;
        $this->decorator = $decorator;
    }

    /**
     * Registers the provided value.
     *
     * @param string $identifier
     * @param string $value
     */
    public function register($identifier, $value)
    {
        parent::register($identifier, $value);

        $sql = sprintf(
            'INSERT INTO %s (`entityId`, `data`) set (`%s`, `%s`);',
            $this->mysql->quote($this->section),
            $this->mysql->quote($identifier),
            $this->decorator->normalizeValue($value)
        );
        $result = $this->mysql->query($sql);

        if (false === $result) {

            $this->registry[$this->section][$identifier] = null;

            $this->throwException(
                'Error occurred while registering an entity: ',
                $this->mysql->errorInfo()
            );
        }
    }

    /**
     * @param string $message
     * @param array $error
     *
     * @throws \Liip\Drupal\Modules\Registry\RegistryException
     */
    protected function throwException($message, array $error)
    {
        throw new RegistryException(
            $message . $error[2],
            $error[1]
        );
    }

    /**
     * @param string $identifier
     *
     * @return bool
     */
    public function isRegistered($identifier)
    {
        if (false === parent::isRegistered($identifier)) {
            $entity = $this->getContentById($identifier);

            if (!empty($entity)) {
                return true;
            }
        } else {

            return true;
        }

        return false;
    }

    /**
     * Provides the registry content identified by its ID.
     *
     * @param string $identifier
     * @param null $default
     *
     * @throws \Liip\Drupal\Modules\Registry\RegistryException
     * @return array
     */
    public function getContentById($identifier, $default = null)
    {
        if (empty($this->registry[$this->section][$identifier])) {

            $this->registry[$this->section][$identifier] = $this->getContentByIds(array($identifier));

            if (empty($this->registry[$this->section][$identifier])) {
                return $default;
            }
        }

        return $this->registry[$this->section][$identifier];
    }

    /**
     * Provides a set of registry items.
     *
     * @param array $identifiers
     *
     * @throws \Liip\Drupal\Modules\Registry\RegistryException
     * @return array
     */
    public function getContentByIds(array $identifiers)
    {
        $sql = sprintf(
            'SELECT * FROM %s WHERE entityId IN (`%s`);',
            $this->mysql->quote($this->section),
            implode('`,`', $identifiers)
        );
        $result = $this->mysql->query($sql);

        if (false === $result) {

            $this->throwException(
                'Error occurred while querying the registry: ',
                $this->mysql->errorInfo()
            );
        }

        $content = $result->fetchAll(\PDO::FETCH_ASSOC);
        $this->registry[$this->section] = array_merge($this->registry[$this->section], $content);

        return $content;
    }

    /**
     * Replaces the identified entity with the provided one.
     *
     * @param string $identifier
     * @param mixed $value
     */
    public function replace($identifier, $value)
    {
        // store current data to be able to restore on error;
        $oldValue = @$this->registry[$this->section][$identifier];

        parent::replace($identifier, $value);

        $sql = sprintf(
            'UPDATE %s SET `data`=`%s` WHERE `entityId`=`%s`;',
            $this->mysql->quote($this->section),
            $this->mysql->quote($value),
            $this->mysql->quote($identifier)
        );

        $result = $this->mysql->exec($sql);

        if (false === $result) {

            $this->registry[$this->section][$identifier] = $oldValue;

            $this->throwException(
                'Failed to fetch information from the registry: ',
                $this->mysql->errorInfo()
            );
        }
    }

    /**
     * Removes the identified entity form the registry.
     *
     * @param string $identifier
     */
    public function unregister($identifier)
    {
        $oldValue = @$this->registry[$this->section][$identifier];

        parent::unregister($identifier);

        $sql = sprintf(
            'DELETE FROM %s WHERE `entityId`=`%s`;',
            $this->mysql->quote($this->section),
            $this->mysql->quote($identifier)
        );

        $result = $this->mysql->exec($sql);

        if (false === $result) {

            $this->registry[$this->section][$identifier] = $oldValue;

            $this->throwException(
                'Failed to fetch information from the registry: ',
                $this->mysql->errorInfo()
            );
        }
    }

    /**
     * Shall delete the current registry from the database.
     * @throws RegistryException in case the deletion of the database failed.
     */
    public function destroy()
    {
        // delete DB
        $sql = sprintf(
            'DROP TABLE `%s`;',
            $this->mysql->quote($this->section)
        );

        if (false === $this->mysql->exec($sql)) {

            $this->throwException(
                'Unable to delete the database: ',
                $this->mysql->errorInfo()
            );
        }

        $this->registry[$this->section] = array();
    }

    /**
     * Shall register a new section in the registry
     * @return array
     */
    public function init()
    {
        $this->registryTableExists();

        if (empty($this->registry[$this->section])) {

            $this->registry[$this->section] = $this->getContent();
        }

        return $this->registry[$this->section];
    }

    /**
     * Validates that the registry table exists.
     * @throws \Liip\Drupal\Modules\Registry\RegistryException
     */
    protected function registryTableExists()
    {
        $sql = sprintf(
            'SHOW CREATE TABLE `%s`;',
            $this->mysql->quote($this->section)
        );

        if (false === $this->mysql->query($sql)) {

            $this->throwException(
                'The registry table does not exists: ',
                $this->mysql->errorInfo()
            );
        }
    }

    /**
     * Provides the current content of the registry.
     *
     *
     * NOTICE:
     *  Setting $limit to anything else than 0 (zero) currently not have any affect on the amount of returned results.
     *  This functionality is due to be implemented.
     *
     *
     * @param integer $limit  Amount of documents to be returned in result set. If set to 0 (zero) all documents of the result set will be returned. Defaults to 0.
     *
     * @throws \Liip\Drupal\Modules\Registry\RegistryException
     * @return array
     */
    public function getContent($limit = 0)
    {
        $this->registry[$this->section] = parent::getContent();

        if (empty($this->registry[$this->section])) {

            $sql = sprintf('SELECT * FROM `%s`;', $this->mysql->quote($this->section));
            $result = $this->mysql->query($sql);

            if (false === $result) {

                $this->throwException(
                    'Failed to fetch information from the registry: ',
                    $this->mysql->errorInfo()
                );
            }

            $this->registry[$this->section] = $this->processResult($result->fetchAll(\PDO::FETCH_ASSOC));
        }

        return $this->registry[$this->section];
    }

    /**
     * Processes the results to return a denormalized entity set.
     *
     * @param array $results
     *
     * @return array
     */
    protected function processResult(array $results)
    {
        foreach ($results as $key => $entity) {

            if (!empty($entity['data'])) {

                $entity['data'] = $this->decorator->denormalizeValue($entity['data']);
                $results[$key] = $entity;
            }
        }

        return $results;
    }
}
