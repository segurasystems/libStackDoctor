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
use StackDoctor\Exceptions;
use StackDoctor\Interfaces\BackendInterface;
use DockerCloud\API;
use StackDoctor\Interfaces\SSLGeneratorInterface;

class DockerCloudBackend extends AbstractBackend implements BackendInterface
{
    /** @var API\Stack */
    protected $stackApi;
    /** @var API\Service */
    protected $serviceApi;
    /** @var API\Container  */
    protected $containerApi;
    /** @var API\Node */
    protected $nodeApi;

    public function __construct(string $username, string $apikey, string $namespace = null)
    {
        \DockerCloud\Client::configure($username, $apikey, $namespace);

        $this->stackApi = new API\Stack();
        $this->serviceApi = new API\Service();
        $this->containerApi = new API\Container();
        $this->nodeApi = new API\Node();
    }

    /**
     * @return Stack[]
     */
    public function getListOfStacks() : array
    {
        $stacks = [];
        foreach ($this->stackApi->getList()->getObjects() as $stack) {
            $stacks[$stack->getName()] = $stack;
        }
        return $stacks;
    }

    public function deployStack(\StackDoctor\Entities\Stack $stack)
    {
        $dockerCloudStack = $this->generateDockerCloudStackFromStackEntities($stack);
        echo "Creating {$dockerCloudStack->getName()}...";
        if($this->checkForStackNameCollision($stack->getName())) {
            $matches = $this->stackApi->getList(['name' => $stack->getName()]);
            $match = $matches->getObjects()[0];
            $dockerCloudStack->setUuid($match->getUuid());
            $dockerCloudStack = $this->stackApi->update($dockerCloudStack);
        }else{
            $dockerCloudStack = $this->stackApi->create($dockerCloudStack);
        }
        echo " [DONE]\n";
        $this->waitUntilStatus([Statuses::DOCKER_CLOUD_NOT_RUNNING, Statuses::DOCKER_CLOUD_RUNNING], $dockerCloudStack, true);
        echo "Deployed {$dockerCloudStack->getName()}\n";

        return $this->getExistingStack($stack);.
    }

    private function getExistingStack(\StackDoctor\Entities\Stack $stack)  : ?Stack
    {
        $existingStack = $this->stackApi->findByName($stack->getName());
        if($existingStack) {
            $dockerCloudStack = $this->generateDockerCloudStackFromStackEntities($stack);
            $dockerCloudStack->setUuid($existingStack->getUuid());
            return $dockerCloudStack;
        }else{
            return null;
        }
    }

    public function startStack(\StackDoctor\Entities\Stack $stack) : Stack
    {
        $dockerCloudStack = $this->getExistingStack($stack);
        if(!$dockerCloudStack){
            throw new Exceptions\ResourceNotFound("Cannot find stack called {$stack->getName()}");
        }
        echo "Starting {$dockerCloudStack->getName()}...";
        $dockerCloudStack = $this->stackApi->start($dockerCloudStack->getUuid());
        echo " [DONE]\n";
        $this->waitUntilStatus([Statuses::DOCKER_CLOUD_RUNNING], $dockerCloudStack, true);
        return $dockerCloudStack;
    }

    public function stopStack(\StackDoctor\Entities\Stack $stack) : Stack
    {
        $dockerCloudStack = $this->getExistingStack($stack);
        if(!$dockerCloudStack){
            throw new Exceptions\ResourceNotFound("Cannot find stack called {$stack->getName()}");
        }
        echo "Stopping {$dockerCloudStack->getName()}...";
        $dockerCloudStack = $this->stackApi->stop($dockerCloudStack->getUuid());
        echo " [DONE]\n";
        $this->waitUntilStatus(Statuses::DOCKER_CLOUD_STOPPED, $dockerCloudStack);
        echo "Stopped {$dockerCloudStack->getName()}\n";
        return $dockerCloudStack;
    }

    public function updateStack(\StackDoctor\Entities\Stack $stack) : Stack
    {
        $dockerCloudStack = $this->getExistingStack($stack);
        if(!$dockerCloudStack){
            throw new Exceptions\ResourceNotFound("Cannot find stack called {$stack->getName()}");
        }
        if (!$dockerCloudStack) {
            die("Cannot update a non-existent stack! Did you mean to --deploy?\n\n");
        }
        if ($dockerCloudStack->getState() == Statuses::DOCKER_CLOUD_TERMINATED) {
            die("Cannot update a terminated stack!\n\n");
        }

        $currentState = $this->stackApi->get($dockerCloudStack->getUuid())->getState();
        if(!in_array($currentState, [Statuses::DOCKER_CLOUD_RUNNING, Statuses::DOCKER_CLOUD_NOT_RUNNING, Statuses::DOCKER_CLOUD_STOPPED])){
            die("Cannot update a stack that is in a partial state! State = {$currentState}\n\n");
        }

        echo "Updating {$dockerCloudStack->getName()}...";
        $dockerCloudStack = $this->stackApi->update($dockerCloudStack);
        echo " [DONE]\n";
        $this->waitUntilStatus([Statuses::DOCKER_CLOUD_RUNNING, Statuses::DOCKER_CLOUD_STOPPED, Statuses::DOCKER_CLOUD_NOT_RUNNING], $dockerCloudStack);
        echo "Redeploying {$dockerCloudStack->getName()}...\n";
        $dockerCloudStack = $this->stackApi->redeploy($dockerCloudStack->getUuid());
        $this->waitUntilStatus([Statuses::DOCKER_CLOUD_RUNNING], $dockerCloudStack);
        echo "Redeploy Complete.\n";

        return true;
    }

    public function terminateStack(\StackDoctor\Entities\Stack $stack) : Stack
    {
        $dockerCloudStack = $this->getExistingStack($stack);
        if(!$dockerCloudStack){
            throw new Exceptions\ResourceNotFound("Cannot find stack called {$stack->getName()}");
        }
        echo "Terminating {$dockerCloudStack->getName()}...";
        $this->stackApi->terminate($dockerCloudStack->getUuid());
        echo " [DONE]\n";
        $this->waitUntilStatus(Statuses::DOCKER_CLOUD_TERMINATED, $dockerCloudStack);
        echo "Terminated {$dockerCloudStack->getName()}.\n";

        return $dockerCloudStack;
    }

    public function getLoadbalancerIps() : array
    {
        $ips = [];
        $loadBalancer = $this->stackApi->findByName("Load-Balancer");
        $lbServices = $loadBalancer->getServices();

        foreach($lbServices as $lbService) {
            $lbService = $this->serviceApi->getByUri($lbService);
            if ($lbService->getName() == 'load-balancer') {
                foreach($lbService->getContainers() as $lbContainer){
                    $lbContainer = $this->containerApi->getByUri($lbContainer);
                    $lbNode = $this->nodeApi->getByUri($lbContainer->getNode());
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
            $host = trim($host);
            $host = parse_url($host);
            $hosts[$i] = $host['host'];

        }
        $hosts = array_unique($hosts);
        $hosts = array_filter($hosts);
        return $hosts;
    }

    public function updateCertificates(\StackDoctor\Entities\Stack $stack, SSLGeneratorInterface $SSLGenerator)
    {
        $existingStack = $this->getExistingStack($stack);
        $this->waitUntilStatus(Statuses::DOCKER_CLOUD_RUNNING, $existingStack, true);
        echo "Updating SSL_CERT environment variables: \n";
        foreach($existingStack->getServices() as $service){
            $service = $this->serviceApi->getByUri($service);
            if($service->hasContainerEnvvar("VIRTUAL_HOST")){
                $domains = $this->parseVirtualHostToDomainList($service->getContainerEnvvar("VIRTUAL_HOST")->getValue());
                foreach($domains as $domain){
                    $cert = $SSLGenerator->getCertForDomain($domain);
                    $service->deleteContainerEnvvar("SSL_CERT");
                    $service->addContainerEnvvar(
                        EnvironmentVariable::build(
                            "SSL_CERT", 
                            $cert->getPrivateKey() .
                            "\n\n" .
                            $cert->getCertificate()
                        )
                    );
                }
                echo " > {$service->getName()} ...";
                $this->serviceApi->update($service);
                $this->serviceApi->redeploy($service->getUuid());
                echo " [DONE]\n";
            }
        }
        $loadBalancer = $this->getLoadBalancer();
        $loadBalancerService = $this->serviceApi->findByName('load-balancer', $loadBalancer);
        $this->serviceApi->redeploy($loadBalancerService->getUuid());
    }

    private function getLoadBalancer() : ?Stack
    {
        $loadBalancer = $this->stackApi->findByName("Load-Balancer");
        return $loadBalancer;
    }

    public function updateLoadBalancer(\StackDoctor\Entities\Stack $stack)
    {
        echo "Updating Loadbalancer...\n";
        $loadBalancer = $this->getLoadBalancer();

        $existingStack = $this->stackApi->findByName($stack->getName());

        $lbServices = $loadBalancer->getServices();

        foreach($lbServices as $lbService){
            $lbService = $this->serviceApi->getByUri($lbService);
            if($lbService->getName() == 'load-balancer') {
                $modifiedCount = 0;
                foreach ($existingStack->getServices() as $serviceToLink) {
                    $serviceToLink = $this->serviceApi->getByUri($serviceToLink);
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
                    $this->serviceApi->update($lbService);
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

        $dockerCloudStack = $this->generateDockerCloudStackFromStackEntities($drStack);
        echo "Creating {$dockerCloudStack->getName()} to inject StackDoctor...";
        $dockerCloudStack = $this->stackApi->create($dockerCloudStack);
        $this->waitUntilStatus([Statuses::DOCKER_CLOUD_NOT_RUNNING, Statuses::DOCKER_CLOUD_RUNNING], $dockerCloudStack, true);
        echo " [DONE]\n";

        $existingStack = $this->stackApi->findByName($stack->getName());
        $dockerCloudStack = $this->generateDockerCloudStackFromStackEntities($stack);
        $dockerCloudStack->setUuid($existingStack->getUuid());
        echo "Starting {$dockerCloudStack->getName()} to inject StackDoctor...";
        $dockerCloudStack = $this->stackApi->start($dockerCloudStack->getUuid());
        echo " [DONE]\n";
        $databaseInstall = $this->serviceApi->findByName('database-install', $stack);
        echo " > Waiting for Doctor to run.";
        $this->waitUntilStatus([Statuses::DOCKER_CLOUD_RUNNING], $dockerCloudStack, true);
        echo " [DONE]\n";
        echo " > Waiting for Doctor to stop.";
        $this->waitUntilStatus([Statuses::DOCKER_CLOUD_STOPPED], $dockerCloudStack, true);
        echo " [DONE]\n";
        $this->serviceApi->terminate($databaseInstall->getUuid());
        echo " > Waiting for Doctor terminate.";
        $this->waitUntilStatus([Statuses::DOCKER_CLOUD_TERMINATED], $databaseInstall, true);
        echo " [DONE]\n";
    }

    public function waitForDomainPropagation(\StackDoctor\Entities\Stack $stack)
    {
        // TODO: Implement waitForDomainPropogation() method.
        // Also this probably can go in AbstractBackend.
    }

    public function updateLetsEncrypt(\StackDoctor\Entities\Stack $stack)
    {
        echo "Updating Loadbalancer...\n";

        $loadBalancer = $this->stackApi->findByName("Load-Balancer");

        $existingStack = $this->stackApi->findByName($stack->getName());

        $lbServices = $loadBalancer->getServices();

        foreach($lbServices as $lbService) {
            $lbService = $this->serviceApi->getByUri($lbService);
            if ($lbService->getName() == 'load-balancer') {
                $loadBalancerService = $lbService;
            }
        }

        foreach($lbServices as $lbService){
            $lbService = $this->serviceApi->getByUri($lbService);
            if($lbService->getName() == 'letsencrypt'){
                $domainsToAddToCert = [];
                foreach ($existingStack->getServices() as $serviceToLink) {
                    $serviceToLink = $this->serviceApi->getByUri($serviceToLink);
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
                $this->serviceApi->update($lbService);
                $targetMet = false;
                while (!$targetMet) {
                    $stateCurrent = $this->serviceApi->get($lbService->getUuid())->getState();
                    if ($stateCurrent == Statuses::DOCKER_CLOUD_RUNNING) {
                        $targetMet = true;
                        echo " [DONE]\n";
                    }else {
                        echo ".";
                        sleep(1);
                    }
                }

                echo "Redeploying LetsEncrypt Service...";
                $this->serviceApi->redeploy($lbService->getUuid());
                $targetMet = false;
                while (!$targetMet) {
                    $stateCurrent = $this->serviceApi->get($lbService->getUuid())->getState();
                    if ($stateCurrent == Statuses::DOCKER_CLOUD_RUNNING) {
                        $targetMet = true;
                        echo " [DONE]\n";
                    }else {
                        echo ".";
                        sleep(1);
                    }
                }

                echo "Redeploying Loadbalancer Service...";
                $this->serviceApi->redeploy($loadBalancerService->getUuid());
                $targetMet = false;
                while (!$targetMet) {
                    $stateCurrent = $this->serviceApi->get($loadBalancerService->getUuid())->getState();
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
        return $this->stackApi->findByName($stack->getName())->getState();
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

    private function waitUntilStatus($targetStatus, AbstractApplicationModel $stack, $silent = false)
    {
        if (is_string($targetStatus)) {
            $targetStatus = [$targetStatus];
        }
        $targetMet = false;
        if(!$silent)
            echo " > Waiting for {$stack->getName()} to change state to " . implode(" or ", $targetStatus) . "...";
        while (!$targetMet) {
            if($stack instanceof Stack) {
                $s = $this->stackApi;
            }elseif($stack instanceof \DockerCloud\Model\Service){
                $s = $this->serviceApi;
            }else{
                throw new \Exception("Waiting for a " . get_class($stack) . " is not yet supported.");
            }
            $stateCurrent = $s->get($stack->getUuid())->getState();
            if (in_array($stateCurrent, $targetStatus)) {
                $targetMet = true;
                if(!$silent)
                    echo " [DONE]";
            }else {
                if(!$silent)
                    echo ".";
                sleep(1);
            }
        }
        if(!$silent)
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
                'stdin_open' => $service->isStdinOpen(),
                'priviledged' => $service->isPrivileged(),
                'tty' => $service->isTTY(),
            ];

            $service = array_filter($service);

            $stackfile[] = $service;
        }
        return $stackfile;
    }
}
