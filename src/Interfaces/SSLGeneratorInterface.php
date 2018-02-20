<?php
namespace StackDoctor\Interfaces;

use StackDoctor\Entities\Certificate;

interface SSLGeneratorInterface
{
    public static function Factory();

    public function getCertForDomain(string $domain) : Certificate;
}
