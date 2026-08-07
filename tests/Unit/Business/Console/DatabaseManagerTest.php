<?php

declare(strict_types=1);

namespace Tests\Unit\Business\Console;

use App\Business\Console\DatabaseManager;
use BlueFission\Behavioral\Behaviors\Behavior;
use BlueFission\BlueCore\Business\Managers\DatasourceManager;
use PHPUnit\Framework\TestCase;

class DatabaseManagerTest extends TestCase
{
    public function testPopulateRecognizesAutoAsAPositionalArgument(): void
    {
        $datasource = $this->createMock(DatasourceManager::class);
        $datasource->expects($this->once())
            ->method('populate')
            ->with(true);

        $manager = new DatabaseManager($datasource);
        $behavior = new Behavior('populate');
        $behavior->context = ['data' => ['auto']];

        $manager->populate($behavior);
    }

    public function testPopulateRemainsInteractiveWithoutAutoArgument(): void
    {
        $datasource = $this->createMock(DatasourceManager::class);
        $datasource->expects($this->once())
            ->method('populate')
            ->with(false);

        $manager = new DatabaseManager($datasource);
        $behavior = new Behavior('populate');
        $behavior->context = ['data' => []];

        $manager->populate($behavior);
    }
}
