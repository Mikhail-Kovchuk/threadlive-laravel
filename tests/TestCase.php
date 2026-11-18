<?php

declare(strict_types=1);

namespace Threadlive\Tests;

use Orchestra\Testbench\TestCase as Orchestra;
use Threadlive\ThreadliveServiceProvider;

abstract class TestCase extends Orchestra
{
    /**
     * @return list<class-string>
     */
    protected function getPackageProviders($app): array
    {
        return [ThreadliveServiceProvider::class];
    }
}
