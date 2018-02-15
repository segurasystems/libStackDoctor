<?php

namespace StackDoctor\Entities;

use StackDoctor\Enums;
use StackDoctor\Interfaces\EntityInterface;

class Port implements EntityInterface
{
    /** @var int */
    private $internalPort;
    /** @var int */
    private $externalPort;
    /** @var string */
    private $externalIp;
    /** @var string */
    private $protocol = Enums\Protocol::TCP;

    public static function Factory()
    {
        return new Port();
    }

    /**
     * @return int
     */
    public function getInternalPort(): int
    {
        return $this->internalPort;
    }

    /**
     * @param int $internalPort
     * @return Port
     */
    public function setInternalPort(int $internalPort): Port
    {
        $this->internalPort = $internalPort;
        return $this;
    }

    /**
     * @return int
     */
    public function getExternalPort(): ?int
    {
        return $this->externalPort;
    }

    /**
     * @param int $externalPort
     * @return Port
     */
    public function setExternalPort(int $externalPort): Port
    {
        $this->externalPort = $externalPort;
        return $this;
    }

    /**
     * @return string
     */
    public function getExternalIp(): ?string
    {
        return $this->externalIp;
    }

    /**
     * @param string $externalIp
     * @return Port
     */
    public function setExternalIp(string $externalIp): Port
    {
        $this->externalIp = $externalIp;
        return $this;
    }

    /**
     * @return string
     */
    public function getProtocol(): ?string
    {
        return $this->protocol;
    }

    /**
     * @param string $protocol
     * @return Port
     */
    public function setProtocol(string $protocol): Port
    {
        $this->protocol = $protocol;
        return $this;
    }

    public function getExpression() : string
    {
        return ($this->getExternalIp() ? $this->getExternalIp() . ":" : "") .
            ($this->getExternalIp() ? $this->getExternalIp() . ":" : "") .
            $this->getInternalPort() .
            ($this->getProtocol() ? "/" . $this->getProtocol() : "");
    }
}
