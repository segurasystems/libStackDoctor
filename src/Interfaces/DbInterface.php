<?php
namespace StackDoctor\Interfaces;

use StackDoctor\Entities\Stack;

interface DbInterface
{
    /**
     * Assert the credentials and connectivity required
     * @param Stack $stack
     * @return mixed
     */
    public function assert(Stack $stack);

    public function checkUserExists(string $username): bool;

    public function createUser(string $username, string $password, string $hostname = '%'): bool;

    public function checkDatabaseExists(string $databaseName): bool;

    public function createDatabase(string $databaseName): bool;

    public function createUserPermissionOnDatabase(string $username, string $databaseName): bool;

    public function getConnectionDetails() : array;
}
