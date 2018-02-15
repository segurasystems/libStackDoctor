<?php
namespace StackDoctor\Interfaces;


use StackDoctor\Entities;

interface DnsInterface
{
    /**
     * @param string $ip IP Address of remote system to direct address to
     * @param string $domain Domain-name wished to be registered
     * @return mixed
     */
    public function setDomain(string $ip, string $domain);

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