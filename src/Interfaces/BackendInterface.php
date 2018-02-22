<?php
namespace StackDoctor\Interfaces;

use DockerCloud\Model\Stack;
use StackDoctor\Exceptions;

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
     * @throws Exceptions\ResourceNotFound
     */
    public function startStack(\StackDoctor\Entities\Stack $stack);

    /**
     * @param \StackDoctor\Entities\Stack $stack
     * @throws Exceptions\ResourceNotFound
     */
    public function stopStack(\StackDoctor\Entities\Stack $stack);

    /**
     * @param \StackDoctor\Entities\Stack $stack
     */
    public function deployStack(\StackDoctor\Entities\Stack $stack);

    /**
     * @param \StackDoctor\Entities\Stack $stack
     * @throws Exceptions\ResourceNotFound
     */
    public function updateStack(\StackDoctor\Entities\Stack $stack);

    /**
     * @param \StackDoctor\Entities\Stack $stack
     * @throws Exceptions\ResourceNotFound
     */
    public function terminateStack(\StackDoctor\Entities\Stack $stack);

    /**
     * @param \StackDoctor\Entities\Stack $stack
     * @throws Exceptions\ResourceNotFound
     */
    public function updateLoadBalancer(\StackDoctor\Entities\Stack $stack);

    /**
     * @param \StackDoctor\Entities\Stack $stack
     * @throws Exceptions\ResourceNotFound
     */
    public function waitForDomainPropagation(\StackDoctor\Entities\Stack $stack);

    /**
     * @param \StackDoctor\Entities\Stack $stack
     * @throws Exceptions\ResourceNotFound
     */
    public function updateLetsEncrypt(\StackDoctor\Entities\Stack $stack);

    /**
     * @param \StackDoctor\Entities\Stack $stack
     * @throws Exceptions\ResourceNotFound
     */
    public function updateCertificates(\StackDoctor\Entities\Stack $stack, SSLGeneratorInterface $SSLGenerator);

    /**
     * @return string[]
     */
    public function getLoadbalancerIps() : array;

    /**
     * @param \StackDoctor\Entities\Stack $stack
     * @throws Exceptions\ResourceNotFound
     */
    public function getStackState(\StackDoctor\Entities\Stack $stack) : string;

    public function getRawStack(\StackDoctor\Entities\Stack $stack);

    public function injectDoctor(\StackDoctor\Entities\Stack $stack);
}
