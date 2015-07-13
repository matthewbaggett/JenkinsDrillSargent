<?php
namespace DrillSargent\Models;

use TigerKit\Services\ImageService;
use Thru\ActiveRecord\ActiveRecord;

/**
 * Class Build
 * @package DrillSargent\Models
 * @var $build_id INTEGER
 * @var $jenkins_build_id TEXT
 * @var $job_id INTEGER
 * @var $name TEXT
 */
class Build extends ActiveRecord
{
    protected $_table = "builds";

    public $build_id;
    public $jenkins_build_id;
    public $job_id;
    public $name = "";


}
