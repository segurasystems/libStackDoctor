<?php

namespace StackDoctor\Entities;

use StackDoctor\Enums;
use StackDoctor\Interfaces\EntityInterface;

class Certificate implements EntityInterface
{
    public static function Factory() : Certificate
    {
        return new self();
    }
}
