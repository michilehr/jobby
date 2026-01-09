<?php

namespace Jobby\Tests;

use Jobby\Exception;
use PHPUnit\Framework\TestCase;

/**
 * @covers Exception
 */
class ExceptionTest extends TestCase
{
    public function testInheritsBaseException()
    {
        $e = new Exception();
        $this->assertTrue($e instanceof \Exception);
    }
}
