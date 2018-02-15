<?php
namespace StackDoctor\Entities;

use StackDoctor\Interfaces\EntityInterface;

class Stack implements EntityInterface
{
    /** @var string */
    private $name;

    /** @var Service[] */
    private $services;

    /**
     * @return string
     */
    public function getName(): string
    {
        return $this->name;
    }

    /**
     * @param string $name
     * @return Stack
     */
    public function setName(string $name): Stack
    {
        $this->name = $name;
        return $this;
    }

    /**
     * @return Service[]
     */
    public function getServices() : array
    {
        return $this->services;
    }

    /**
     * @param mixed $services
     * @return Stack
     */
    public function setServices($services) : Stack
    {
        $this->services = $services;
        return $this;
    }

    /**
     * @param Service $service
     * @return Stack
     */
    public function addService(Service $service) : Stack
    {
        $this->validateService($service);
        $this->services[] = $service;
        return $this;
    }

    public function validateService(Service $service)
    {
        if (!$service->getName()) {
            throw new \Exception("Service cannot have a blank name!");
        }
        if (!$service->getImage()) {
            throw new \Exception("Service cannot have a blank image!");
        }
        if (!$service->getImageVersion()) {
            throw new \Exception("Service cannot have a blank image version!");
        }
    }

    public static function Factory() : Stack
    {
        return new self();
    }
}
