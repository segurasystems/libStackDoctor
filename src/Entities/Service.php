<?php

namespace StackDoctor\Entities;

use StackDoctor\Interfaces\EntityInterface;
use StackDoctor\Enums;

class Service implements EntityInterface
{
    /** @var string */
    private $name;
    /** @var string */
    private $image;
    /** @var string */
    private $imageVersion = 'latest';
    /** @var string */
    private $deploymentStrategy = Enums\DeploymentStrategies::EMPTIEST_NODE;
    /** @var int */
    private $instanceCountMin = 1;
    /** @var int */
    private $instanceCountTarget = 2;
    /** @var int */
    private $instanceCountMax = 10;
    /** @var string[] */
    private $environmentVariables = [];
    /** @var string[] */
    private $links = [];
    /** @var Port[] */
    private $ports = [];
    /** @var string[] */
    private $deploymentTags = [];
    /** @var string */
    private $command;
    /** @var string */
    private $restart = 'always';
    
    /** @var bool */
    private $isTTY = false;
    /** @var bool */
    private $isStdinOpen = false;
    /** @var bool */
    private $isPrivileged = false;
    
    const RESTART_MODE_ALWAYS = 'always';
    const RESTART_MODE_NO = 'no';
    const RESTART_MODE_ON_FAILURE = 'on-failure';

    public static function Factory(): Service
    {
        return new self();
    }

    /**
     * @return string[]
     */
    public function getDeploymentTags(): array
    {
        return $this->deploymentTags;
    }

    /**
     * @param string[] $deploymentTags
     * @return Service
     */
    public function setDeploymentTags(array $deploymentTags): Service
    {
        $this->deploymentTags = $deploymentTags;
        return $this;
    }

    public function addTag(string $tag) : Service
    {
        $this->deploymentTags[] = $tag;
        return $this;
    }

    /**
     * @return string
     */
    public function getName(): string
    {
        return $this->name;
    }

    /**
     * @param string $name
     * @return Service
     */
    public function setName(string $name): Service
    {
        $this->name = $name;
        return $this;
    }

    /**
     * @return string
     */
    public function getCommand(): ?string
    {
        return $this->command;
    }

    /**
     * @param string $command
     * @return Service
     */
    public function setCommand(string $command): Service
    {
        $this->command = $command;
        return $this;
    }

    /**
     * @return string
     */
    public function getRestart(): string
    {
        return $this->restart;
    }

    /**
     * @param string $restart
     * @return Service
     */
    public function setRestart(string $restart): Service
    {
        $this->restart = $restart;
        return $this;
    }

    /**
     * @return string
     */
    public function getImageString() : string
    {
        return $this->getImage() . ":" . $this->getImageVersion();
    }

    /**
     * @return string
     */
    public function getImage(): string
    {
        return $this->image;
    }

    /**
     * @param string $image
     * @return Service
     */
    public function setImage(string $image): Service
    {
        $this->image = $image;
        return $this;
    }

    /**
     * @return string
     */
    public function getImageVersion(): string
    {
        return $this->imageVersion;
    }

    /**
     * @param string $imageVersion
     * @return Service
     */
    public function setImageVersion(string $imageVersion): Service
    {
        $this->imageVersion = $imageVersion;
        return $this;
    }

    /**
     * @return string
     */
    public function getDeploymentStrategy(): string
    {
        return $this->deploymentStrategy;
    }

    /**
     * @param string $deploymentStrategy
     * @return Service
     */
    public function setDeploymentStrategy(string $deploymentStrategy): Service
    {
        $this->deploymentStrategy = $deploymentStrategy;
        return $this;
    }

    public function addEnvironmentalVariable(string $name, string $value): Service
    {
        $this->environmentVariables[$name] = $value;
        return $this;
    }

    /**
     * @return string[]
     */
    public function getEnvironmentVariablesAsStrings() : array
    {
        $env = [];
        foreach ($this->getEnvironmentVariables() as $key => $value) {
            $env[] = "{$key}={$value}";
        }
        return $env;
    }

    /**
     * @return string[]
     */
    public function getEnvironmentVariables(): array
    {
        return $this->environmentVariables;
    }

    public function hasEnvironmentVariable($key)
    {
        return isset($this->environmentVariables[$key]);
    }

    public function getEnvironmentVariable($key)
    {
        return $this->hasEnvironmentVariable($key) ? $this->environmentVariables[$key] : false;
    }

    /**
     * @param string[] $environmentVariables
     * @return Service
     */
    public function setEnvironmentVariables(array $environmentVariables): Service
    {
        $this->environmentVariables = $environmentVariables;
        return $this;
    }

    /**
     * @return string[]
     */
    public function getLinks(): array
    {
        return $this->links;
    }

    /**
     * @param string[] $links
     * @return Service
     */
    public function setLinks(array $links): Service
    {
        $this->links = $links;
        return $this;
    }

    public function addLink(string $link) : Service
    {
        $this->links[] = $link;
        return $this;
    }

    public function addPort(Port $port) : Service
    {
        $this->ports[] = $port;
        return $this;
    }

    /**
     * @return string[]
     */
    public function getPortsAsStrings() : array
    {
        $ports = [];
        foreach ($this->getPorts() as $value) {
            $ports[] = $value->getExpression();
        }
        return $ports;
    }

    /**
     * @return Port[]
     */
    public function getPorts() : array
    {
        return $this->ports;
    }

    /**
     * @param Port[] $ports
     * @return Service
     */
    public function setPorts($ports) : Service
    {
        $this->ports = $ports;
        return $this;
    }

    /**
     * @return int
     */
    public function getContainerCountTarget() : int
    {
        return clamp(
            $this->getInstanceCountMin(),
            $this->getInstanceCountMax(),
            $this->getInstanceCountTarget()
        );
    }

    /**
     * @return int
     */
    public function getInstanceCountMin(): int
    {
        return $this->instanceCountMin;
    }

    /**
     * @param int $instanceCountMin
     * @return Service
     */
    public function setInstanceCountMin(int $instanceCountMin): Service
    {
        $this->instanceCountMin = $instanceCountMin;
        return $this;
    }

    /**
     * @return int
     */
    public function getInstanceCountMax(): int
    {
        return $this->instanceCountMax;
    }

    /**
     * @param int $instanceCountMax
     * @return Service
     */
    public function setInstanceCountMax(int $instanceCountMax): Service
    {
        $this->instanceCountMax = $instanceCountMax;
        return $this;
    }

    /**
     * @return int
     */
    public function getInstanceCountTarget(): int
    {
        return $this->instanceCountTarget;
    }

    /**
     * @param int $instanceCountTarget
     * @return Service
     */
    public function setInstanceCountTarget(int $instanceCountTarget): Service
    {
        $this->instanceCountTarget = $instanceCountTarget;
        return $this;
    }

    /**
     * @return bool
     */
    public function isTTY(): bool
    {
        return $this->isTTY;
    }

    /**
     * @param bool $isTTY
     * @return Service
     */
    public function setIsTTY(bool $isTTY): Service
    {
        $this->isTTY = $isTTY;
        return $this;
    }

    /**
     * @return bool
     */
    public function isStdinOpen(): bool
    {
        return $this->isStdinOpen;
    }

    /**
     * @param bool $isStdinOpen
     * @return Service
     */
    public function setIsStdinOpen(bool $isStdinOpen): Service
    {
        $this->isStdinOpen = $isStdinOpen;
        return $this;
    }

    /**
     * @return bool
     */
    public function isPrivileged(): bool
    {
        return $this->isPrivileged;
    }

    /**
     * @param bool $isPrivileged
     * @return Service
     */
    public function setIsPrivileged(bool $isPrivileged): Service
    {
        $this->isPrivileged = $isPrivileged;
        return $this;
    }
    
    
}
