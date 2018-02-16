<?php
namespace StackDoctor\Backends;

use Aws\Rds\RdsClient;
use StackDoctor\Entities\Stack;
use StackDoctor\Interfaces\DbInterface;

class AuroraDb implements DbInterface
{
    /** @var string */
    private $apiKey;
    /** @var string */
    private $apiSecret;
    /** @var string */
    private $apiRegion;
    /** @var string */
    private $instanceName;
    /** @var string */
    private $dbUsername;
    /** @var string */
    private $dbPassword;
    /** @var RdsClient */
    private $rds;

    public function __construct(
        string $key,
        string $secret,
        string $region = 'eu-west-2',
        string $instanceName,
        string $dbUsername,
        string $dbPassword
    ){
        $this->apiKey = $key;
        $this->apiSecret = $secret;
        $this->apiRegion = $region;
        $this->instanceName = $instanceName;
        $this->rds = RdsClient::factory([
            'credentials' => [
                'key' => $this->apiKey,
                'secret' => $this->apiSecret,
            ],
            'region' => $this->apiRegion,
            'version' => 'latest'
        ]);
        $this->dbUsername = $dbUsername;
        $this->dbPassword = $dbPassword;
    }

    public function assert(Stack $stack)
    {
        echo "Doing RDS Refresh...\n";
        echo " > Looking up {$this->instanceName}...";
        $instance = $this->getDatabaseInstance();
        echo "[DONE]\n";
        #\Kint::dump($instance);

        foreach($this->getListOfIdentitiesToAssert($stack) as $identity){
            if(!$this->checkUserExists($identity['username'])){
                $this->createUser($identity['username'], $identity['password']);
            }
            if(!$this->checkDatabaseExists($identity['database'])){
                $this->createDatabase($identity['database']);
            }
            $this->createUserPermissionOnDatabase($identity['username'], $identity['database']);
        }
    }

    private function getDatabaseConnection() : \PDO
    {
        $connDetails = $this->getConnectionDetails();
        $dsn = "{$connDetails['dbengine']}:host={$connDetails['hostname']};port={$connDetails['port']};dbname=mysql";
        return new \PDO($dsn, $connDetails['username'], $connDetails['password']);
    }

    public function checkUserExists(string $username): bool
    {
        #echo "Checking User Exists {$username}... ";
        $db = $this->getDatabaseConnection();
        $sth = $db->prepare("SELECT EXISTS(SELECT 1 FROM mysql.user WHERE `user` = :username) as `EXISTS`");
        $sth->execute([':username' => $username]);
        if($sth->fetch(\PDO::FETCH_ASSOC)['EXISTS']){
        #    echo " [EXISTS]\n";
            return true;
        }else{
        #    echo " [MISSING]\n";
            return false;
        }
    }

    public function createUser(string $username, string $password, string $hostname = '%'): bool
    {
        echo "Creating User '{$username}'@'{$hostname}' w/password '{$password}'...";

        $createUserStatement = "CREATE USER '{$username}'@'{$hostname}' IDENTIFIED BY '{$password}'";
        $db = $this->getDatabaseConnection();
        $sth = $db->prepare($createUserStatement);
        $sth->execute();

        $setPasswordStatement = "SET PASSWORD FOR '{$username}'@'{$hostname} = PASSWORD('{$password}')";
        $sth = $db->prepare($setPasswordStatement);
        $sth->execute();


        if($this->checkUserExists($username)){
            echo " [SUCCESS]\n";
            return true;
        }else{
            echo " [FAIL]\n";
            throw new \Exception("Could not create user '{$username}': {$createUserStatement} : {$db->errorCode()} : {$db->errorInfo()[2]}");
        }
    }

    public function checkDatabaseExists(string $databaseName): bool
    {
        #echo "Checking Database Exists {$databaseName}... ";
        $db = $this->getDatabaseConnection();
        $sth = $db->prepare("SELECT SCHEMA_NAME as `EXISTS` FROM INFORMATION_SCHEMA.SCHEMATA WHERE SCHEMA_NAME = :databaseName");
        $sth->execute([':databaseName' => $databaseName]);
        $result = $sth->fetch(\PDO::FETCH_ASSOC)['EXISTS'];
        #\Kint::dump("Result:", $result);
        if($result == $databaseName){
            #echo " [EXISTS]\n";
            return true;
        }else{
            #echo " [MISSING]\n";
            return false;
        }
    }

    public function createDatabase(string $databaseName): bool
    {
        echo "Creating Database '{$databaseName}'...";
        $db = $this->getDatabaseConnection();
        $db->query("CREATE DATABASE {$databaseName}");

        if($this->checkDatabaseExists($databaseName)){
            echo " [SUCCESS]\n";
            return true;
        }else{
            echo " [FAIL]\n";
            throw new \Exception("Could not create database '{$databaseName}': {$db->errorCode()} : {$db->errorInfo()[2]}");
        }
    }

    public function createUserPermissionOnDatabase(string $username, string $databaseName, string $hostmask = '%'): bool
    {
        $db = $this->getDatabaseConnection();
        $query = "USE {$databaseName}; GRANT ALL PRIVILEGES ON `{$databaseName}`.* TO '{$username}'@'{$hostmask}'; FLUSH PRIVILEGES;";
        $success = $db->query($query);
        if(!$success){
            throw new \Exception("Could not set privileges for {$username}@{$hostmask} on {$databaseName} : {$db->errorCode()} : {$db->errorInfo()[2]}");
        }else{
            return true;
        }
    }

    private function getListOfIdentitiesToAssert(Stack $stack){
        $identities = [];
        foreach($stack->getServices() as $service){
            if($service->hasEnvironmentVariable('MYSQL_USERNAME')) {
                $identity = [
                    'username' => $service->getEnvironmentVariable('MYSQL_USERNAME'),
                    'password' => $service->getEnvironmentVariable('MYSQL_PASSWORD'),
                    'database' => $service->getEnvironmentVariable('MYSQL_DATABASE'),
                ];
                $identities[] = $identity;
            }
        }
        return $identities;
    }

    private function getDatabaseInstance()
    {
        $response = $this->rds->describeDBInstances([
            'Filters' => [
                [
                    'Name' => 'db-instance-id',
                    'Values' => [$this->instanceName],
                ],
            ],
        ]);
        return $response['DBInstances'][0];
    }

    public function getConnectionDetails() : array{
        $instance = $this->getDatabaseInstance();
        return [
            'hostname' => $instance['Endpoint']['Address'],
            'port'     => $instance['Endpoint']['Port'],
            'dbengine' => $instance['Engine'],
            'username' => $this->dbUsername,
            'password' => $this->dbPassword,
        ];
    }
}