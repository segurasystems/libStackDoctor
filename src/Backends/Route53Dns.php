<?php
namespace StackDoctor\Backends;

use Aws\Route53\Route53Client;
use StackDoctor\Entities;
use StackDoctor\Entities\Stack;
use StackDoctor\Interfaces\DnsInterface;

class Route53Dns implements DnsInterface
{
    /** @var string */
    private $apiKey;
    /** @var string */
    private $apiSecret;
    /** @var string */
    private $apiRegion;
    /** @var Route53Client */
    private $route53;

    public function __construct(string $key, string $secret, string $region = 'eu-west-2')
    {
        $this->apiKey = $key;
        $this->apiSecret = $secret;
        $this->apiRegion = $region;
        $this->route53 = \Aws\Route53\Route53Client::factory([
            'credentials' => [
                'key' => $this->apiKey,
                'secret' => $this->apiSecret,
            ],
            'region' => $this->apiRegion,
            'version' => 'latest'
        ]);
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

    private function getAppropriateHostedZone(string $domain)
    {
        $hostedZones = $this->route53->listHostedZones()->get('HostedZones') ;;
        $domainElements = $this->getDomainElements($domain) ;
        foreach ($hostedZones as $zoneId => $hostedZone){
            $hostedZoneElements = $this->getDomainElements($hostedZone['Name']);
            foreach($hostedZoneElements as $i => $value){
                if($hostedZoneElements[$i] != $domainElements[$i]){
                    unset($hostedZones[$zoneId]);
                    continue 2;
                }
            }
        }
        return str_replace("/hostedzone/", "", reset($hostedZones)['Id']);
    }



    public function setDomain(string $ip, string $domain)
    {
        echo " > Mapping {$domain} to {$ip}...";
        $hostedZoneId = $this->getAppropriateHostedZone($domain);
        $this->route53->changeResourceRecordSets([
            // HostedZoneId is required
            'HostedZoneId' => $hostedZoneId,
            // ChangeBatch is required
            'ChangeBatch' => [
                'Comment' => 'string',
                // Changes is required
                'Changes' => [
                    [
                        // Action is required
                        'Action' => 'UPSERT',
                        // ResourceRecordSet is required
                        'ResourceRecordSet' => [
                            // Name is required
                            'Name' => "{$domain}.",
                            // Type is required
                            'Type'            => 'A',
                            'TTL'             => 60,
                            'ResourceRecords' => [
                                [
                                    // Value is required
                                    'Value' => $ip,
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ]);
        echo " [DONE]\n";
    }

    public function removeDomain(string $ip, string $domain)
    {
        echo " > Removing {$domain} from {$ip}...";
        $hostedZoneId = $this->getAppropriateHostedZone($domain);
        $this->route53->changeResourceRecordSets([
            // HostedZoneId is required
            'HostedZoneId' => $hostedZoneId,
            // ChangeBatch is required
            'ChangeBatch' => [
                'Comment' => 'string',
                // Changes is required
                'Changes' => [
                    [
                        // Action is required
                        'Action' => 'DELETE',
                        // ResourceRecordSet is required
                        'ResourceRecordSet' => [
                            // Name is required
                            'Name' => "{$domain}.",
                            // Type is required
                            'Type'            => 'A',
                            'TTL'             => 60,
                            'ResourceRecords' => [
                                [
                                    // Value is required
                                    'Value' => $ip,
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ]);
        echo " [DONE]\n";
    }

    /**
     * @param string[] $ips
     * @param Stack $stack
     */
    public function updateDomainsToMatchLoadbalancer(array $ips, Stack $stack){
        echo "Setting up {$stack->getName()} dns entries...\n";
        foreach($stack->getServices() as $service){
            if(key_exists('VIRTUAL_HOST', $service->getEnvironmentVariables())){
                $hostname = DockerCloudBackend::ScrubDomain($service->getEnvironmentVariables()['VIRTUAL_HOST']);
                foreach($ips as $ip) {
                    $this->setDomain($ip, $hostname);
                }
            }
        }
    }

    public function removeDomains(array $ips, Entities\Stack $stack)
    {
        echo "Removing all of {$stack->getName()}'s DNS entries...\n";
        foreach($stack->getServices() as $service){
            if(key_exists('VIRTUAL_HOST', $service->getEnvironmentVariables())){
                $hostname = $service->getEnvironmentVariables()['VIRTUAL_HOST'];
                foreach($ips as $ip) {
                    $this->removeDomain($ip, $hostname);
                }
            }
        }
    }
}