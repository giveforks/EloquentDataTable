<?php

namespace Tests;

use Illuminate\Database\Eloquent\Builder;
use LiveControl\EloquentDataTable\DataTable;
use PHPUnit\Framework\TestCase;
use Mockery;
use ReflectionClass;

/**
 * Basic unit tests for DataTable class
 * 
 * Note: Complex functionality that interacts with the database
 * is tested in the Integration test suite instead of here.
 */
class DataTableTest extends TestCase
{
    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function testInitialization()
    {
        $builder = Mockery::mock(Builder::class);
        $dataTable = new DataTable($builder, ['id']);
        
        $this->assertInstanceOf(DataTable::class, $dataTable);
    }
    
    public function testSetCountThreshold()
    {
        $builder = Mockery::mock(Builder::class);
        $dataTable = new DataTable($builder, ['id']);

        $result = $dataTable->setCountThreshold(5000);
        
        $this->assertInstanceOf(DataTable::class, $result);
        
        // Test the threshold value using reflection
        $reflection = new ReflectionClass($dataTable);
        $property = $reflection->getProperty('countThreshold');
        $property->setAccessible(true);
        
        $this->assertEquals(5000, $property->getValue($dataTable));
    }
    
    public function testWithApproximateCount()
    {
        $builder = Mockery::mock(Builder::class);
        $dataTable = new DataTable($builder, ['id']);

        // Default should be false
        $reflection = new ReflectionClass($dataTable);
        $property = $reflection->getProperty('withApproximateCount');
        $property->setAccessible(true);
        $this->assertFalse($property->getValue($dataTable));
        
        // Test enabling approximate count
        $result = $dataTable->withApproximateCount(true);
        
        $this->assertInstanceOf(DataTable::class, $result);
        $this->assertTrue($property->getValue($dataTable));
        
        // Test disabling approximate count
        $result = $dataTable->withApproximateCount(false);
        
        $this->assertInstanceOf(DataTable::class, $result);
        $this->assertFalse($property->getValue($dataTable));
    }
}
