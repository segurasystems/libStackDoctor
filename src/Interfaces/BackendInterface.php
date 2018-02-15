<?php
namespace StackDoctor\Interfaces;

use DockerCloud\Model\Stack;

interface BackendInterface
{

    /**
     * @param string $stackName
     * @return bool
     */
    public function checkForStackNameCollision(string $stackName) : bool;

    /**
     * @return Stack[]
     */
    public function getListOfStacks() : array;

    /**
     * Start a stack based on a stack entity we're fed.
     * @param \StackDoctor\Entities\Stack $stack
     */
    public function startStack(\StackDoctor\Entities\Stack $stack);

    public function stopStack(\StackDoctor\Entities\Stack $stack);

    public function deployStack(\StackDoctor\Entities\Stack $stack);

    public function updateStack(\StackDoctor\Entities\Stack $stack);

    public function terminateStack(\StackDoctor\Entities\Stack $stack);

    public function updateLoadBalancer(\StackDoctor\Entities\Stack $stack);

    public function waitForDomainPropagation(\StackDoctor\Entities\Stack $stack);
    
    public function updateLetsEncrypt(\StackDoctor\Entities\Stack $stack);

    /**
     * @return string[]
     */
    public function getLoadbalancerIps() : array;

    public function getStackState(\StackDoctor\Entities\Stack $stack) : string;

    public function injectDoctor(\StackDoctor\Entities\Stack $stack);
}
