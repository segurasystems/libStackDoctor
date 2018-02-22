<?php
namespace StackDoctor;

use CLIToolkit\MenuItems\Item;
use CLIToolkit\MenuItems\Menu;
use Faker\Factory as FakeDataFactory;
use Faker\Generator as FakeDataGenerator;
use StackDoctor\Entities\Stack;
use StackDoctor\Interfaces\BackendInterface;
use StackDoctor\Interfaces\DbInterface;
use StackDoctor\Interfaces\DnsInterface;
use StackDoctor\Interfaces\SSLGeneratorInterface;

class StackDoctor
{
    /** @var BackendInterface */
    private $backend;
    /** @var DnsInterface */
    private $dns;
    /** @var DbInterface */
    private $db;
    /** @var FakeDataGenerator */
    private $faker;
    /** @var SSLGeneratorInterface */
    private $sslGenerator;

    const SSL_MODE_DISABLED = 'disabled';
    const SSL_MODE_SELFCERT = 'self-cert';
    const SSL_MODE_LETSENCRYPT = 'lets-encrypt';

    /** @var string[] List of modes for SSL generation. default will be whichever element is indexed 0. */
    private $sslModes = [
        0 => StackDoctor::SSL_MODE_DISABLED,
        1 => StackDoctor::SSL_MODE_SELFCERT,
        2 => StackDoctor::SSL_MODE_LETSENCRYPT,
    ];

    private $sslMode = StackDoctor::SSL_MODE_SELFCERT;

    public static function Factory() : StackDoctor
    {
        $called = get_called_class();
        return new $called();
    }

    public function __construct()
    {
        $this->faker = FakeDataFactory::create();
    }

    public function buildMenu() : Menu
    {
        $menuTree = Menu::Factory([
            Item::Factory("Deploy Defaults", "--deploy", [$this, "deploy"]),
            Item::Factory("Update Stack", "--update", [$this, "update"]),
            Item::Factory("Deploy or Update Stack", "--deploy-or-update", [$this, "deployOrUpdate"]),
            Item::Factory("RDS Refresh", "--rds-refresh", [$this, "rdsRefresh"]),
            Item::Factory("DNS Refresh", "--dns-refresh", [$this, "dnsRefresh"]),
            Item::Factory("SSL Refresh", "--ssl-refresh", [$this, "sslRefresh"]),
            Item::Factory("Start Existing Stack", "--start", [$this, "start"]),
            Item::Factory("Stop Existing Stack", "--stop", [$this, "stop"]),
            Item::Factory("Terminate Existing Stack", "--terminate", [$this, "terminate"]),
            Item::Factory("Exit", null, [$this, "exit"]),
        ]);

        $menuTree->addOptionalCliParam("--ssl <mode>", "Optional SSL Mode. One of: " . implode(", ", $this->getSslModes()) . ". Default: {$this->getSslModes()[0]}");

        return $menuTree;
    }

    public function getStack(Menu $menu) : Stack
    {
        $stack = Stack::Factory();

        // Validate stack name
        $stackName = $menu->getArgumentValues()->offsetGet("stack-name");
        $stack->setName($stackName);

        if (!$this->checkNameValid($stackName)) {
            $this->throwShowstopper("Name cannot contain anything other than letters, numbers or hyphens");
        }

        if ($menu->getArgumentValues()->offsetExists('ssl')) {
            if (!in_array($menu->getArgumentValues()->offsetGet('ssl'), $this->getSslModes())) {
                $this->throwShowstopper("--ssl must be one of (" . implode("|", $this->getSslModes()) . ")");
            }
            $this->setSslMode($menu->getArgumentValues()->offsetGet("ssl"));
        }

        return $stack;
    }

    public function run()
    {
        $this->buildMenu()->run();
    }

    /**
     * @return SSLGeneratorInterface
     */
    public function getSslGenerator(): SSLGeneratorInterface
    {
        return $this->sslGenerator;
    }

    /**
     * @param SSLGeneratorInterface $sslGenerator
     * @return StackDoctor
     */
    public function setSslGenerator(SSLGeneratorInterface $sslGenerator): StackDoctor
    {
        $this->sslGenerator = $sslGenerator;
        return $this;
    }

    /**
     * @return array
     */
    public function getSslModes(): array
    {
        return $this->sslModes;
    }

    /**
     * @param array $sslModes
     * @return StackDoctor
     */
    public function setSslModes(array $sslModes): StackDoctor
    {
        $this->sslModes = $sslModes;
        return $this;
    }

    public function addSslMode(string $sslMode, int $weight = null): StackDoctor
    {
        $this->sslModes[$weight] = $sslMode;
        return $this;
    }

    /**
     * @return string
     */
    public function getSslMode(): string
    {
        return $this->sslMode;
    }

    /**
     * @param string $sslMode
     * @return StackDoctor
     */
    public function setSslMode(string $sslMode): StackDoctor
    {
        $this->sslMode = $sslMode;
        return $this;
    }

    /**
     * @return FakeDataGenerator
     */
    public function getFaker(): FakeDataGenerator
    {
        return $this->faker;
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

    protected function checkNameValid($name)
    {
        return $name == preg_replace("/[^A-Za-z0-9-]/", '', $name);
    }

    protected function computeVirtualHost($domain)
    {
        $domains = [];
        if ($this->getSslMode() != StackDoctor::SSL_MODE_DISABLED) {
            $domains[] = "https://{$domain}";
        }
        $domains[] = "http://{$domain}";
        return implode(", ", $domains);
    }
}
