<?php
namespace StackDoctor\Backends;

use StackDoctor\Interfaces\BackendInterface;

abstract class AbstractBackend implements BackendInterface
{
    public function checkForStackNameCollision(string $stackName): bool
    {
        $stacks = $this->getListOfStacks();
        $stacks = array_keys($stacks);
        return in_array($stackName, $stacks);
    }
}
