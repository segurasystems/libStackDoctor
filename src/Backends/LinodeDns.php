<?php
namespace StackDoctor\Backends;

use Aws\Route53\Route53Client;
use StackDoctor\Entities;
use StackDoctor\Entities\Stack;
use StackDoctor\Interfaces\DnsInterface;

class LinodeDns implements DnsInterface
{
    const ENDPOINT = '/domains';
    private static $entitiesAvailable;
    /** @var LinodeRequest */
    private static $linodeRequest;

    /** @var string */
    private $apiKey;
    /** @var int Default TTL for DNS */
    private $defaultTTL = 300;

    public function __construct(string $key)
    {
        $this->apiKey = $key;
        self::$linodeRequest = new LinodeRequest($this->apiKey);
    }
    
    static public function getHttpRequest()
    {
        return self::$linodeRequest;
    }

    private function getDomainElements(string $domain) : array
    {
        return array_reverse(
            explode(
                ".",
                rtrim(
                    $domain,
                    '.'
                )
            )
        );
    }

    public function hasRecord($type, $domain, $value) : bool
    {
        $linodeDomain = $this->getLinodeDomain($domain);
        if ($linodeDomain) {
            $domainRecords = self::getHttpRequest()->getJson(self::ENDPOINT . "/{$linodeDomain->id}/records");
            foreach($domainRecords->data as $domainRecord){
                if(
                    strtolower($type) == strtolower($domainRecord->type)
                    && strtolower($value) == strtolower($domainRecord->target)
                    && strtolower($domain) == trim(strtolower($domainRecord->name) . "." . strtolower($linodeDomain->domain),".")
                ){
                    return true;
                }
            }
        }
        return false;
    }

    public function setDomain(array $ips, string $domain)
    {
        // @todo: Prevent this from creating duplicates.
        foreach ($ips as $ip) {
            echo " > DNS A Record {$domain} => " . implode(", ", $ips) . "...";
            if ($this->hasRecord('a', $domain, $ip)) {
                echo " [SKIP]\n";
            } else {
                $this->createRecord('a', $domain, $ip);

                echo " [DONE]\n";
            }
        }
    }

    /**
     * @param string[] $of
     * @param $domain
     */
    public function setCname(array $ips, string $domain, $blocking = true)
    {
        echo " > DNS CNAME Record {$domain} => " . implode(", ", $ips) . "...";
        foreach($ips as $ip){
            $this->createRecord('cname', $domain, $ip);
        }
        echo " [DONE]\n";
    }

    public function createRecord(string $type, string $domain, string $value): ?int
    {
        $linodeDomain = $this->getLinodeDomain($domain);
        if ($linodeDomain) {
            $domainRecord = self::getHttpRequest()->postJson(self::ENDPOINT . "/{$linodeDomain->id}/records", [
                'type' => strtoupper($type),
                'target' => $value,
                'name' => $domain,
                'ttl_sec' => $this->defaultTTL,
            ]);
            echo " [DONE]\n";
            return $domainRecord->id;
        } else {
            echo " [FAIL]\n";
            return null;
        }
    }


    static public function describeAvailable()
    {
        $called = get_called_class();
        self::$entitiesAvailable['described-' . $called] = [];
        $entitiesResponse = self::getHttpRequest()->getJson($called::ENDPOINT);
        foreach ($entitiesResponse->data as $entity) {
            self::$entitiesAvailable['described-' . $called][$entity->id] = $entity;
        }
        return self::$entitiesAvailable['described-' . $called];
    }
    
    private function getLinodeDomain($domain)
    {
        $zones = self::describeAvailable();
        $domainFragments = explode(".", trim($domain, '.'));
        $domainFragments = array_reverse($domainFragments);
        $stub = '';
        foreach ($domainFragments as $domainFragment) {
            $stub = trim($domainFragment . "." . $stub, ".");
            foreach ($zones as $zone) {
                if ($zone->domain == $stub) {
                    return $zone;
                }
            }
        }
        return null;
    }

    public function removeDomain(string $ip, string $domain)
    {
        echo "    > Purging {$domain} record...";
        $linodeDomain = $this->getLinodeDomain($domain);

        $allSubdomains = self::getHttpRequest()->getJson(self::ENDPOINT . "/{$linodeDomain->id}/records");
        $linodeDomainIdsToPurge = [];
        foreach ($allSubdomains->data as $potentialMatch) {
            if ($potentialMatch->name . "." . $linodeDomain->domain == $domain && $potentialMatch->target == $ip) {
                $linodeDomainIdsToPurge[] = $potentialMatch->id;
            }
        }
        foreach ($linodeDomainIdsToPurge as $purgeId) {
            self::getHttpRequest()->deleteJson(self::ENDPOINT . "/{$linodeDomain->id}/records/{$purgeId}");
        }
        echo sprintf(" [%d REMOVED]\n", count($linodeDomainIdsToPurge));
    }

    /**
     * @param string[] $ips
     * @param Stack $stack
     */
    public function updateDomainsToMatchLoadbalancer(array $ips, Stack $stack)
    {
        echo "Setting up {$stack->getName()} dns entries...\n";

        $hostnames = [];
        $cnames = [];
        foreach ($stack->getServices() as $service) {
            if (key_exists('VIRTUAL_HOST', $service->getEnvironmentVariables())) {
                $hostname = DockerCloudBackend::ScrubDomain($service->getEnvironmentVariables()['VIRTUAL_HOST']);
                $hostnames[] = $hostname;
            }
        }

        usort($hostnames, function ($a, $b) {
            return strlen($a) - strlen($b);
        });

        foreach ($hostnames as $i => $hostname) {
            $hostnameElements = array_reverse(explode(".", $hostname));

            $test = '';

            foreach ($hostnameElements as $hostnameElement) {
                $test = rtrim($hostnameElement . "." . $test, ".");
                $match = array_search($test, $hostnames);
                if ($match !== false && $hostnames[$match] != $hostname) {
                    echo "{$hostname} is a subdomain of {$hostnames[$match]}\n";
                    $cnames[$hostname] = $hostnames[$match];
                    unset($hostnames[$i]);
                }
            }
        }

        foreach ($hostnames as $hostname) {
            $this->setDomain($ips, $hostname);
        }
        foreach ($cnames as $cname => $cnameOf) {
            $this->setCname([$cnameOf], $cname);
        }
    }

    public function removeDomains(array $ips, Entities\Stack $stack)
    {
        echo "Removing all of {$stack->getName()}'s DNS entries...\n";
        foreach ($stack->getServices() as $service) {
            if (key_exists('VIRTUAL_HOST', $service->getEnvironmentVariables())) {
                $hostname = $service->getEnvironmentVariables()['VIRTUAL_HOST'];
                foreach ($ips as $ip) {
                    $this->removeDomain($ip, $hostname);
                }
            }
        }
    }
}
