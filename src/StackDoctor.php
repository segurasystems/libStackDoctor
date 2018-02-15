<?php
namespace StackDoctor;

use CLIToolkit\MenuItems\Menu;
use Hackzilla\PasswordGenerator\Generator\ComputerPasswordGenerator;
use Hackzilla\PasswordGenerator\Generator\PasswordGeneratorInterface;
use StackDoctor\Interfaces\BackendInterface;
use StackDoctor\Interfaces\DbInterface;
use StackDoctor\Interfaces\DnsInterface;

class StackDoctor
{
    /** @var BackendInterface */
    private $backend;
    /** @var DnsInterface */
    private $dns;
    /** @var DbInterface */
    private $db;
    /** @var PasswordGeneratorInterface */
    protected $passwordGenerator;

    static public function Factory() : StackDoctor
    {
        return new self();
    }

    public function __construct()
    {
        $this->passwordGenerator = new ComputerPasswordGenerator();
    }

    public function setBackend(BackendInterface $backend) : StackDoctor
    {
        $this->backend = $backend;
        return $this;
    }

    public function getBackend() : BackendInterface
    {
        return $this->backend;
    }

    /**
     * @return DnsInterface
     */
    public function getDns(): DnsInterface
    {
        return $this->dns;
    }

    /**
     * @param DnsInterface $dns
     * @return StackDoctor
     */
    public function setDns(DnsInterface $dns): StackDoctor
    {
        $this->dns = $dns;
        return $this;
    }

    /**
     * @return DbInterface
     */
    public function getDb(): DbInterface
    {
        return $this->db;
    }

    /**
     * @param DbInterface $db
     * @return StackDoctor
     */
    public function setDb(DbInterface $db): StackDoctor
    {
        $this->db = $db;
        return $this;
    }

    public function throwShowstopper($errorMessage)
    {
        die("Oh no! {$errorMessage}\n\n");
    }

    public function exit(Menu $menu)
    {
        die("Exit called.\n");
    }
}
