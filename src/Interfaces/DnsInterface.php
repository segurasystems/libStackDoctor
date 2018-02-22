<?php
namespace StackDoctor\Interfaces;

use StackDoctor\Entities;

interface DnsInterface
{
    /**
     * @param string[] $ips IP Address of remote system to direct address to
     * @param string $domain Domain-name wished to be registered
     * @return mixed
     */
    public function setDomain(array $ips, string $domain);
    
    public function setCname(array $of, string $domain);

    /**
     * @param string[] $ips
     * @param Entities\Stack $stack
     */
    public function updateDomainsToMatchLoadbalancer(array $ips, Entities\Stack $stack);

    /**
     * @param string[] $ips
     * @param Entities\Stack $stack
     */
    public function removeDomains(array $ips, Entities\Stack $stack);
}
