<?php

namespace Tests\Integration;

use Illuminate\Database\Capsule\Manager as DB;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use LiveControl\EloquentDataTable\DataTable;
use PHPUnit\Framework\TestCase;

class TestModel extends Model
{
    protected $table = 'test_table';
    public $timestamps = false;
}

class DataTableIntegrationTest extends TestCase
{
    protected static $db;
    
    public static function setUpBeforeClass(): void
    {
        // Set up the database connection
        self::$db = new DB;
        self::$db->addConnection([
            'driver'    => $_ENV['DB_CONNECTION'] ?? 'mysql',
            'host'      => $_ENV['DB_HOST'] ?? '127.0.0.1',
            'port'      => $_ENV['DB_PORT'] ?? '3306',
            'database'  => $_ENV['DB_DATABASE'] ?? 'eloquent_datatable_test',
            'username'  => $_ENV['DB_USERNAME'] ?? 'root',
            'password'  => $_ENV['DB_PASSWORD'] ?? '',
            'charset'   => 'utf8',
            'collation' => 'utf8_unicode_ci',
            'prefix'    => '',
        ]);
        
        self::$db->setAsGlobal();
        self::$db->bootEloquent();
        
        // Create test table
        if (!self::$db->schema()->hasTable('test_table')) {
            self::$db->schema()->create('test_table', function (Blueprint $table) {
                $table->id();
                $table->string('name');
                $table->integer('value');
            });
        } else {
            // Clear the table
            DB::table('test_table')->truncate();
        }
    }
    
    public static function tearDownAfterClass(): void
    {
        // Drop the test table
        self::$db->schema()->dropIfExists('test_table');
    }
    
    protected function setUp(): void
    {
        // Clear the table before each test
        DB::table('test_table')->truncate();
    }
    
    /**
     * Helper method to insert a specific number of records
     */
    protected function insertRecords(int $count): void
    {
        $data = [];
        for ($i = 1; $i <= $count; $i++) {
            $data[] = [
                'name' => "Test Record {$i}",
                'value' => $i,
            ];
        }
        
        // Insert in chunks to avoid memory issues
        foreach (array_chunk($data, 1000) as $chunk) {
            DB::table('test_table')->insert($chunk);
        }
    }
    
    public function testExactCountBelowThreshold(): void
    {
        // Insert 5,000 records (below the default threshold of 10,000)
        $this->insertRecords(5000);
        
        $query = TestModel::query();
        $dataTable = new DataTable($query, ['id', 'name', 'value']);
        
        // Access the protected count method using reflection
        $reflection = new \ReflectionClass($dataTable);
        $method = $reflection->getMethod('count');
        $method->setAccessible(true);
        
        $result = $method->invoke($dataTable);
        
        // Should return exact count, not approximate
        $this->assertEquals(5000, $result);
        
        // Verify isApproximateCount flag is not set
        $property = $reflection->getProperty('isApproximateCount');
        $property->setAccessible(true);
        $this->assertFalse($property->getValue($dataTable));
    }
    
    public function testApproximateCountAboveThreshold(): void
    {
        // Insert 15,000 records (above the default threshold of 10,000)
        $this->insertRecords(15000);
        
        $query = TestModel::query();
        $dataTable = new DataTable($query, ['id', 'name', 'value']);
        
        // Explicitly enable approximate count
        $dataTable->withApproximateCount(true);
        
        // Access the protected count method using reflection
        $reflection = new \ReflectionClass($dataTable);
        $method = $reflection->getMethod('count');
        $method->setAccessible(true);
        
        $result = $method->invoke($dataTable);
        
        // Should return threshold value (10,000), not exact count
        $this->assertEquals(10000, $result);
        
        // Verify isApproximateCount flag is set
        $property = $reflection->getProperty('isApproximateCount');
        $property->setAccessible(true);
        $this->assertTrue($property->getValue($dataTable));
    }
    
    public function testCustomThreshold(): void
    {
        // Insert 8,000 records
        $this->insertRecords(8000);
        
        $query = TestModel::query();
        $dataTable = new DataTable($query, ['id', 'name', 'value']);
        
        // Set a custom threshold of 5,000 and enable approximate count
        $dataTable->setCountThreshold(5000);
        $dataTable->withApproximateCount(true);
        
        // Access the protected count method using reflection
        $reflection = new \ReflectionClass($dataTable);
        $method = $reflection->getMethod('count');
        $method->setAccessible(true);
        
        $result = $method->invoke($dataTable);
        
        // Should return threshold value (5,000), not exact count
        $this->assertEquals(5000, $result);
        
        // Verify isApproximateCount flag is set
        $property = $reflection->getProperty('isApproximateCount');
        $property->setAccessible(true);
        $this->assertTrue($property->getValue($dataTable));
    }
    
    public function testMakeMethodIncludesApproximateCountFlag(): void
    {
        // Insert records above threshold
        $this->insertRecords(15000);
        
        $query = TestModel::query();
        $dataTable = new DataTable($query, ['id', 'name', 'value']);
        
        // Enable approximate count
        $dataTable->withApproximateCount(true);
        
        // Set up $_POST variables that DataTable expects
        $_POST['draw'] = 1;
        $_POST['start'] = 0;
        $_POST['length'] = 10;
        $_POST['search'] = ['value' => ''];
        $_POST['order'] = [['column' => 0, 'dir' => 'asc']];
        
        $result = $dataTable->make();
        
        // Check that the isApproximateCount flag is included in the response
        $this->assertArrayHasKey('isApproximateCount', $result);
        $this->assertTrue($result['isApproximateCount']);
        
        // Verify recordsTotal is the threshold value
        $this->assertEquals(10000, $result['recordsTotal']);
    }
    
    public function testWithApproximateCountDisabled(): void
    {
        // Insert 15,000 records (above the default threshold of 10,000)
        $this->insertRecords(15000);
        
        $query = TestModel::query();
        $dataTable = new DataTable($query, ['id', 'name', 'value']);
        
        // Explicitly disable approximate count
        $dataTable->withApproximateCount(false);
        
        // Access the protected count method using reflection
        $reflection = new \ReflectionClass($dataTable);
        $method = $reflection->getMethod('count');
        $method->setAccessible(true);
        
        $result = $method->invoke($dataTable);
        
        // Should return exact count even though above threshold
        $this->assertEquals(15000, $result);
        
        // Verify isApproximateCount flag is not set
        $property = $reflection->getProperty('isApproximateCount');
        $property->setAccessible(true);
        $this->assertFalse($property->getValue($dataTable));
    }
    
    public function testWithApproximateCountEnabled(): void
    {
        // Insert 5,000 records (below the default threshold)
        $this->insertRecords(5000);
        
        $query = TestModel::query();
        $dataTable = new DataTable($query, ['id', 'name', 'value']);
        
        // Explicitly enable approximate count
        $dataTable->withApproximateCount(true);
        
        // Set up $_POST variables that DataTable expects
        $_POST['draw'] = 1;
        $_POST['start'] = 0;
        $_POST['length'] = 10;
        $_POST['search'] = ['value' => ''];
        $_POST['order'] = [['column' => 0, 'dir' => 'asc']];
        
        $result = $dataTable->make();
        
        // Check that the isApproximateCount flag is included in the response
        $this->assertArrayHasKey('isApproximateCount', $result);
        
        // Count should still be exact since we're below threshold
        $this->assertEquals(5000, $result['recordsTotal']);
        $this->assertFalse($result['isApproximateCount']);
    }
}
