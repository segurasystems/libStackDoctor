<?php
namespace StackDoctor\Backends;

use DockerCloud\Model\AbstractApplicationModel;
use DockerCloud\Model\Service\EnvironmentVariable;
use DockerCloud\Model\Service\Link;
use DockerCloud\Model\Stack;
use StackDoctor\Entities\Service;
use StackDoctor\Enums\DeploymentStrategies;
use StackDoctor\Enums\DeploymentTags;
use StackDoctor\Enums\Statuses;
use StackDoctor\Interfaces\BackendInterface;
use DockerCloud as DockerCloudApi;
use StackDoctor\Interfaces\SSLGeneratorInterface;

class DockerCloudBackend extends AbstractBackend implements BackendInterface
{
    public function __construct(string $username, string $apikey, string $namespace = null)
    {
        DockerCloudApi\Client::configure($username, $apikey, $namespace);
    }

    /**
     * @return Stack[]
     */
    public function getListOfStacks() : array
    {
        $stacks = [];
        $s = new DockerCloudApi\API\Stack();
        foreach ($s->getList()->getObjects() as $stack) {
            $stacks[$stack->getName()] = $stack;
        }
        return $stacks;
    }

    public function deployStack(\StackDoctor\Entities\Stack $stack)
    {
        $s = new DockerCloudApi\API\Stack();
        $dockerCloudStack = $this->generateDockerCloudStackFromStackEntities($stack);
        echo "Creating {$dockerCloudStack->getName()}...";
        if($this->checkForStackNameCollision($stack->getName())) {
            $matches = $s->getList(['name' => $stack->getName()]);
            $match = $matches->getObjects()[0];
            $dockerCloudStack->setUuid($match->getUuid());
            $dockerCloudStack = $s->update($dockerCloudStack);
        }else{
            $dockerCloudStack = $s->create($dockerCloudStack);
        }
        echo " [DONE]\n";
        $this->waitUntilStatus([Statuses::DOCKER_CLOUD_NOT_RUNNING, Statuses::DOCKER_CLOUD_RUNNING], $dockerCloudStack);
        echo "Deployed {$dockerCloudStack->getName()}\n";

        #die("HALT!\n\n");

        $this->startStack($stack);
    }

    public function startStack(\StackDoctor\Entities\Stack $stack)
    {
        $s = new DockerCloudApi\API\Stack();

        $existingStack = $s->findByName($stack->getName());
        $dockerCloudStack = $this->generateDockerCloudStackFromStackEntities($stack);
        $dockerCloudStack->setUuid($existingStack->getUuid());
        echo "Starting {$dockerCloudStack->getName()}...";
        $dockerCloudStack = $s->start($dockerCloudStack->getUuid());
        echo " [DONE]\n";
        $this->waitUntilStatus([Statuses::DOCKER_CLOUD_RUNNING], $dockerCloudStack);
        echo "Started {$dockerCloudStack->getName()}\n";

        return true;
    }

    public function stopStack(\StackDoctor\Entities\Stack $stack)
    {
        $s = new DockerCloudApi\API\Stack();

        $existingStack = $s->findByName($stack->getName());
        $dockerCloudStack = $this->generateDockerCloudStackFromStackEntities($stack);
        $dockerCloudStack->setUuid($existingStack->getUuid());
        echo "Stopping {$dockerCloudStack->getName()}...";
        $dockerCloudStack = $s->stop($dockerCloudStack->getUuid());
        echo " [DONE]\n";
        $this->waitUntilStatus(Statuses::DOCKER_CLOUD_STOPPED, $dockerCloudStack);
        echo "Stopped {$dockerCloudStack->getName()}\n";

        return true;
    }

    public function updateStack(\StackDoctor\Entities\Stack $stack)
    {
        // Talk to Docker Cloud
        $s = new DockerCloudApi\API\Stack();

        $existingStack = $s->findByName($stack->getName());
        if (!$existingStack) {
            die("Cannot update a non-existent stack! Did you mean to --deploy?\n\n");
        }
        if ($existingStack->getState() == Statuses::DOCKER_CLOUD_TERMINATED) {
            die("Cannot update a terminated stack!\n\n");
        }

        $dockerCloudStack = $this->generateDockerCloudStackFromStackEntities($stack);
        $dockerCloudStack->setUuid($existingStack->getUuid());

        $currentState = $s->get($existingStack->getUuid())->getState();
        if(!in_array($currentState, [Statuses::DOCKER_CLOUD_RUNNING, Statuses::DOCKER_CLOUD_NOT_RUNNING, Statuses::DOCKER_CLOUD_STOPPED])){
            die("Cannot update a stack that is in a partial state! State = {$currentState}\n\n");
        }

        echo "Updating {$dockerCloudStack->getName()}...";
        $dockerCloudStack = $s->update($dockerCloudStack);
        echo " [DONE]\n";
        $this->waitUntilStatus([Statuses::DOCKER_CLOUD_RUNNING, Statuses::DOCKER_CLOUD_STOPPED, Statuses::DOCKER_CLOUD_NOT_RUNNING], $dockerCloudStack);
        echo "Redeploying {$dockerCloudStack->getName()}...\n";
        $dockerCloudStack = $s->redeploy($dockerCloudStack->getUuid());
        $this->waitUntilStatus([Statuses::DOCKER_CLOUD_RUNNING], $dockerCloudStack);
        echo "Redeploy Complete.\n";

        return true;
    }

    public function terminateStack(\StackDoctor\Entities\Stack $stack)
    {
        // Talk to Docker Cloud
        $s = new DockerCloudApi\API\Stack();

        $existingStack = $s->findByName($stack->getName());

        $dockerCloudStack = $this->generateDockerCloudStackFromStackEntities($stack);
        $dockerCloudStack->setUuid($existingStack->getUuid());

        // Talk to Docker Cloud
        $s = new DockerCloudApi\API\Stack();
        echo "Terminating {$dockerCloudStack->getName()}...";
        $s->terminate($dockerCloudStack->getUuid());
        echo " [DONE]\n";
        $this->waitUntilStatus(Statuses::DOCKER_CLOUD_TERMINATED, $dockerCloudStack);
        echo "Terminated {$dockerCloudStack->getName()}.\n";
    }

    public function getLoadbalancerIps() : array
    {
        $ips = [];
        $s = new DockerCloudApi\API\Stack();
        $serv = new DockerCloudApi\API\Service();
        $cont = new DockerCloudApi\API\Container();
        $node = new DockerCloudApi\API\Node();

        $loadBalancer = $s->findByName("Load-Balancer");

        $lbServices = $loadBalancer->getServices();

        foreach($lbServices as $lbService) {
            $lbService = $serv->getByUri($lbService);
            if ($lbService->getName() == 'load-balancer') {
                foreach($lbService->getContainers() as $lbContainer){
                    $lbContainer = $cont->getByUri($lbContainer);
                    $lbNode = $node->getByUri($lbContainer->getNode());
                    $ips[] = $lbNode->getPublicIp();
                }
            }
        }
        $ips = array_filter($ips);
        return $ips;
    }

    private function parseVirtualHostToDomainList(string $virtualHost) : array
    {
        $hosts = explode(",", $virtualHost);
        foreach($hosts as $i => $host){
            $hosts[$i] = trim($host);
        }
        $hosts = array_filter($hosts);
        return $hosts;
    }

    public function updateCertificates(\StackDoctor\Entities\Stack $stack, SSLGeneratorInterface $SSLGenerator)
    {
        foreach($stack->getServices() as $service){
            if($service->hasEnvironmentVariable('VIRTUAL_HOST')){
                $hosts = $this->parseVirtualHostToDomainList($service->hasEnvironmentVariable('VIRTUAL_HOST'));
//@todo continue this
            }
        }
    }

    public function updateLoadBalancer(\StackDoctor\Entities\Stack $stack)
    {
        echo "Updating Loadbalancer...\n";
        // Talk to Docker Cloud
        $s = new DockerCloudApi\API\Stack();
        $loadBalancer = $s->findByName("Load-Balancer");

        $existingStack = $s->findByName($stack->getName());

        $lbServices = $loadBalancer->getServices();

        foreach($lbServices as $lbService){
            $serv = new DockerCloudApi\API\Service();
            $lbService = $serv->getByUri($lbService);
            if($lbService->getName() == 'load-balancer') {
                $modifiedCount = 0;
                foreach ($existingStack->getServices() as $serviceToLink) {
                    $serviceToLink = $serv->getByUri($serviceToLink);
                    $hasVirtualHost = false;
                    foreach ($serviceToLink->getContainerEnvvars() as $environmentVariable) {
                        if ($environmentVariable->getKey() == 'VIRTUAL_HOST') {
                            $hasVirtualHost = true;
                        }
                    }
                    if ($hasVirtualHost) {
                        $linkExists = false;
                        foreach($lbService->getLinkedToService() as $alreadyLinkedService){
                            if($alreadyLinkedService->getToService() == $serviceToLink->getResourceUri()){
                                $linkExists = true;
                            }
                        }
                        if(!$linkExists) {
                            echo " > Linking {$stack->getName()}'s {$serviceToLink->getName()}...";
                            $lbService->addLinkedToService(
                                Link::build(
                                    $lbService,
                                    $serviceToLink,
                                    "{$stack->getName()}-{$serviceToLink->getName()}"
                                )
                            );
                            $modifiedCount++;
                            echo " [DONE]\n";

                        }else{
                            echo " > Skipping {$stack->getName()}'s {$serviceToLink->getName()}, as it already exists.\n";
                        }
                    }
                }
                if($modifiedCount > 0) {
                    echo " > Updating Loadbalancer config...";
                    $serv->update($lbService);
                    echo " [DONE]\n";
                }else{
                    echo " > No change required to loadbalancer config [SKIP]\n";
                }
            }
        }
    }

    public function injectDoctor(\StackDoctor\Entities\Stack $stack)
    {
        $environment = array_merge($_ENV, $_SERVER);
        ksort($environment);
        $stackName = $stack->getName();
        // Build stack out of entities
        $drStack = \StackDoctor\Entities\Stack::Factory()
            ->setName($stackName)
            ->addService(
                Service::Factory()
                    ->setName("database-install")
                    ->setImage("segura/stack-doctor")
                    ->setImageVersion("latest")
                    ->addEnvironmentalVariable("RDS_API_KEY",$environment['RDS_API_KEY'])
                    ->addEnvironmentalVariable("RDS_API_SECRET",$environment['RDS_API_SECRET'])
                    ->addEnvironmentalVariable("RDS_INSTANCE_NAME",$environment['RDS_INSTANCE_NAME'])
                    ->addEnvironmentalVariable("RDS_INSTANCE_MASTER_USERNAME",$environment['RDS_INSTANCE_MASTER_USERNAME'])
                    ->addEnvironmentalVariable("RDS_INSTANCE_MASTER_PASSWORD",$environment['RDS_INSTANCE_MASTER_PASSWORD'])
                    ->setDeploymentStrategy(DeploymentStrategies::EMPTIEST_NODE)
                    ->setInstanceCountMax(1)
                    ->addTag(DeploymentTags::BACKEND)
                    ->setRestart(Service::RESTART_MODE_NO)
                    ->setCommand("php /app/stack-doctor --rds-refresh --stack-name {$stackName}")
        );

        $serv = new DockerCloudApi\API\Service();
        $s = new DockerCloudApi\API\Stack();
        $dockerCloudStack = $this->generateDockerCloudStackFromStackEntities($drStack);
        echo "Creating {$dockerCloudStack->getName()} to inject StackDoctor...";
        $dockerCloudStack = $s->create($dockerCloudStack);
        echo " [DONE]\n";
        $this->waitUntilStatus([Statuses::DOCKER_CLOUD_NOT_RUNNING, Statuses::DOCKER_CLOUD_RUNNING], $dockerCloudStack);

        $existingStack = $s->findByName($stack->getName());
        $dockerCloudStack = $this->generateDockerCloudStackFromStackEntities($stack);
        $dockerCloudStack->setUuid($existingStack->getUuid());
        echo "Starting {$dockerCloudStack->getName()} to inject StackDoctor...";
        $dockerCloudStack = $s->start($dockerCloudStack->getUuid());
        echo " [DONE]\n";
        $databaseInstall = $serv->findByName('database-install', $stack);

        echo "Waiting for Doctor to run.\n";
        $this->waitUntilStatus([Statuses::DOCKER_CLOUD_RUNNING], $dockerCloudStack);
        echo "Waiting for Doctor to stop.\n";
        $this->waitUntilStatus([Statuses::DOCKER_CLOUD_STOPPED], $dockerCloudStack);

        #echo "Waiting for enter key...\n";
        #$handle = fopen ("php://stdin","r");
        #fgets($handle);

        $serv->terminate($databaseInstall->getUuid());
        echo "Waiting for Doctor to be terminated.\n";
        $this->waitUntilStatus([Statuses::DOCKER_CLOUD_TERMINATED], $databaseInstall);
        echo "Doctor complete.\n\n";
    }

    public function waitForDomainPropagation(\StackDoctor\Entities\Stack $stack)
    {
        // TODO: Implement waitForDomainPropogation() method.
        // Also this probably can go in AbstractBackend.
    }

    public function updateLetsEncrypt(\StackDoctor\Entities\Stack $stack)
    {
        echo "Updating Loadbalancer...\n";
        // Talk to Docker Cloud
        $s = new DockerCloudApi\API\Stack();
        $serv = new DockerCloudApi\API\Service();

        $loadBalancer = $s->findByName("Load-Balancer");

        $existingStack = $s->findByName($stack->getName());

        $lbServices = $loadBalancer->getServices();

        foreach($lbServices as $lbService) {
            $lbService = $serv->getByUri($lbService);
            if ($lbService->getName() == 'load-balancer') {
                $loadBalancerService = $lbService;
            }
        }

        foreach($lbServices as $lbService){
            $lbService = $serv->getByUri($lbService);
            if($lbService->getName() == 'letsencrypt'){
                $domainsToAddToCert = [];
                foreach ($existingStack->getServices() as $serviceToLink) {
                    $serviceToLink = $serv->getByUri($serviceToLink);
                    foreach ($serviceToLink->getContainerEnvvars() as $environmentVariable) {
                        if ($environmentVariable->getKey() == 'VIRTUAL_HOST') {
                            $domainsToAddToCert[] = self::ScrubDomain($environmentVariable->getValue());
                        }
                    }
                }

                // Create CSV (with no spaces!) of the domains to add to the cert
                ksort($domainsToAddToCert);
                $newCertificate = implode(",", $domainsToAddToCert);
                #\Kint::dump($newCertificate);

                // Get DOMAINS envar from letsencrypt, or make one if non-existant
                $domainsEnvar = EnvironmentVariable::build('DOMAINS','');
                $letsEncryptEnvars = $lbService->getContainerEnvvars();
                foreach($letsEncryptEnvars as $environmentVariable){
                    if($environmentVariable->getKey() == 'DOMAINS'){
                        $domainsEnvar = $environmentVariable;
                    }
                }

                // get array of existing cert records
                $existingCertificates = explode(";" ,$domainsEnvar->getValue());

                // Remove blanks
                $existingCertificates = array_filter($existingCertificates);

                // Remove matching existing row
                foreach($existingCertificates as $i => $existingCertificate){
                    foreach($domainsToAddToCert as $domain){
                        if(in_array($domain, explode(",", $existingCertificate))){
                            unset($existingCertificates[$i]);
                        }
                    }
                }

                // inject new cert
                $existingCertificates[] = $newCertificate;

                $domainsEnvar->setValue(implode(";", $existingCertificates));

                // Update and redeploy
                echo "Updating LetsEncrypt Service...";
                $serv->update($lbService);
                $targetMet = false;
                while (!$targetMet) {
                    $stateCurrent = $serv->get($lbService->getUuid())->getState();
                    if ($stateCurrent == Statuses::DOCKER_CLOUD_RUNNING) {
                        $targetMet = true;
                        echo " [DONE]\n";
                    }else {
                        echo ".";
                        sleep(1);
                    }
                }

                echo "Redeploying LetsEncrypt Service...";
                $serv->redeploy($lbService->getUuid());
                $targetMet = false;
                while (!$targetMet) {
                    $stateCurrent = $serv->get($lbService->getUuid())->getState();
                    if ($stateCurrent == Statuses::DOCKER_CLOUD_RUNNING) {
                        $targetMet = true;
                        echo " [DONE]\n";
                    }else {
                        echo ".";
                        sleep(1);
                    }
                }

                echo "Redeploying Loadbalancer Service...";
                $serv->redeploy($loadBalancerService->getUuid());
                $targetMet = false;
                while (!$targetMet) {
                    $stateCurrent = $serv->get($loadBalancerService->getUuid())->getState();
                    if ($stateCurrent == Statuses::DOCKER_CLOUD_RUNNING) {
                        $targetMet = true;
                        echo " [DONE]\n";
                    }else {
                        echo ".";
                        sleep(1);
                    }
                }
            }
        }
    }

    static public function ScrubDomain($domain)
    {
        $domains = explode(",",$domain);
        $domain = reset($domains);
        $host = parse_url($domain, PHP_URL_HOST);
        return $host;
    }

    public function getStackState(\StackDoctor\Entities\Stack $stack): string
    {
        $s = new DockerCloudApi\API\Stack();
        return $s->findByName($stack->getName())->getState();
    }

    private function generateDockerCloudStackFromStackEntities(\StackDoctor\Entities\Stack $stack) : Stack
    {
        // Build stack data
        $dockerCloudStack = new Stack();
        $dockerCloudStack
            ->setName($stack->getName())
            ->setNickname($stack->getName())
            ->setServices($this->getStackfile($stack->getServices()));

        return $dockerCloudStack;
    }

    private function waitUntilStatus($targetStatus, AbstractApplicationModel $stack)
    {
        if (is_string($targetStatus)) {
            $targetStatus = [$targetStatus];
        }
        $targetMet = false;
        echo " > Waiting for {$stack->getName()} to change state to " . implode(" or ", $targetStatus) . "...";
        while (!$targetMet) {
            if($stack instanceof Stack) {
                $s = new DockerCloudApi\API\Stack();
            }elseif($stack instanceof \DockerCloud\Model\Service){
                $s = new DockerCloudApi\API\Service();
            }else{
                throw new \Exception("Waiting for a " . get_class($stack) . " is not yet supported.");
            }
            $stateCurrent = $s->get($stack->getUuid())->getState();
            if (in_array($stateCurrent, $targetStatus)) {
                $targetMet = true;
                echo " [DONE]";
            }else {
                echo ".";
                sleep(1);
            }
        }
        echo "\n";
    }

    /**
     * @param Service[] $services
     * @return string[]
     */
    public function getStackfile(array $services)
    {
        $stackfile = [];

        foreach ($services as $service) {
            $service = [
                'name' => $service->getName(),
                'image' => $service->getImageString(),
                'environment' => $service->getEnvironmentVariablesAsStrings(),
                'ports' => $service->getPorts() > 0 ? $service->getPortsAsStrings() : null,
                'tags' => $service->getDeploymentTags() > 0 ? $service->getDeploymentTags() : null,
                'links' => $service->getLinks() > 0 ? $service->getLinks() : null,
                'deployment_strategy' => $service->getDeploymentStrategy(),
                'target_num_containers' => $service->getContainerCountTarget(),
                'sequential_deployment' => true,
                'restart' => $service->getRestart(),
                'command' => $service->getCommand(),
            ];

            $service = array_filter($service);

            $stackfile[] = $service;
        }
        return $stackfile;
    }
}
