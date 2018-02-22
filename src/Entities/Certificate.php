<?php

namespace StackDoctor\Entities;

use StackDoctor\Enums;
use StackDoctor\Interfaces\EntityInterface;

class Certificate implements EntityInterface
{
    /** @var string */
    private $domain;
    /** @var string */
    private $privateKey;
    /** @var string */
    private $certificate;

    public static function Factory(string $domainApplicable = null) : Certificate
    {
        return new Certificate($domainApplicable);
    }

    public function __construct(string $domainApplicable = null)
    {
        if ($domainApplicable) {
            $this->setDomain($domainApplicable);
        }
    }

    /**
     * @return string
     */
    public function getDomain() : string
    {
        return $this->domain;
    }

    /**
     * @param string $domain
     * @return Certificate
     */
    public function setDomain(string $domain)
    {
        $this->domain = $domain;
        return $this;
    }

    /**
     * @param string $privateKey
     * @return Certificate
     */
    public function setPrivateKey(string $privateKey)
    {
        $this->privateKey = $privateKey;
        return $this;
    }

    /**
     * @return string
     */
    public function getPrivateKey() : string
    {
        return $this->privateKey;
    }

    /**
     * @return string
     */
    public function getCertificate() : string
    {
        return $this->certificate;
    }

    /**
     * @param mixed $certificate
     * @return Certificate
     */
    public function setCertificate(string $certificate)
    {
        $this->certificate = $certificate;
        return $this;
    }
}
