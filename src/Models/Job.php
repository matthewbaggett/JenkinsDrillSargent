<?php
namespace DrillSargent\Models;

use TigerKit\Services\ImageService;
use Thru\ActiveRecord\ActiveRecord;

/**
 * Class Job
 * @package DrillSargent\Models
 * @var $job_id INTEGER
 * @var $name TEXT
 */
class Job extends ActiveRecord
{
    protected $_table = "jobs";

    public $job_id;
    public $name;

    static public function CreateOrFind($name){
        $job = Job::search()->where('name', $name)->execOne();
        if(!$job instanceof Job){
            $job = new Job();
            $job->name = $name;
            $job->save();
        }
        return $job;
    }
}
