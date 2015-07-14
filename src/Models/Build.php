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
 * @var $status TEXT
 * @var $comment TEXT
 * @var $git_branch TEXT
 * @var $git_revision TEXT
 */
class Build extends ActiveRecord
{
    protected $_table = "builds";

    public $build_id;
    public $jenkins_build_id;
    public $job_id;
    public $name = "";
    public $status;
    public $comment;
    public $git_branch;
    public $git_revision;

    public function getPrevious(){
        $jenkins_build_id = $this->jenkins_build_id - 1;
        #echo " > Find Build: \n";
        #echo "  > Job ID: {$this->job_id}\n";
        #echo "  > Jenkins Build ID: {$jenkins_build_id}\n";
        #echo "  > Git Branch: {$this->git_branch}\n";

        return Build::search()
          ->where('job_id', $this->job_id)
          ->where('jenkins_build_id', $jenkins_build_id)
          ->where('git_branch', $this->git_branch)
          ->execOne();
    }

}
