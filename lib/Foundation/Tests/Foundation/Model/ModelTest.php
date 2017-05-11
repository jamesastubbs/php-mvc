<?php

use PHPMVC\Foundation\Model\Model;
use PHPMVC\Foundation\Service\DBService;
use PHPMVC\Foundation\Service\ModelService;
use PHPMVC\Foundation\Services;
use PHPUnit\Framework\TestCase;

class TestModel extends Model
{
    public static $tableName = 'table';
    public static $primaryKey = 'primary_key';    
    public static $columns = ['primary_key' => self::COLUMN_INTEGER];
}

class ModelTest extends TestCase
{
    /**
     * @var  Model
     */
    private $model = null;

    protected function setUp()
    {
        $services = $this->createMock(Services::class);
        $services
            ->expects($this->once())
            ->method('getNameForServiceClass')
            ->with(DBService::class)
            ->will($this->returnValue('db'));

        $services
            ->method('get')
            ->with('db')
            ->will($this->returnValue(new DBService()));

        $modelService = new ModelService();
        $modelService->setServices($services);
        $modelService->onServiceStart();

        $this->model = new TestModel();
    }

    // public function testCreate()
    // {
    //     $this->model->create();
    // }

    // public function testDelete()
    // {
    //     $this->model->delete();
    // }

    public function testUpdate()
    {
        $modelClass = get_class($this->model);
        $primaryKey = $modelClass::$primaryKey;

        $this->model->{$primaryKey} = 1;
        $this->assertEquals($this->model->{$primaryKey}, 1);

        $this->assertTrue($this->model->update());
        $this->assertEquals($this->model->{$primaryKey}, 1);
    }

    protected function tearDown()
    {
        unset($this->model);
    }
}
