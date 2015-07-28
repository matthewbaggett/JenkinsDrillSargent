<?php
namespace DrillSargent;

use Carbon\Carbon;
use DrillSargent\Models\Build;
use DrillSargent\Models\Job;
use Gitonomy\Git\Blame\Line;
use Gitonomy\Git\Commit;
use Gitonomy\Git\Diff\FileChange;
use Gitonomy\Git\Repository;
use Symfony\Component\Yaml\Yaml;

class DrillSargent
{

    private $configuration;

    public function __construct(){
        define('SCRIPT_ROOT', $_SERVER['PWD']);
    }
    /**
     * @return \JenkinsKhan\Jenkins[]
     */
    private function detectJenkinsInstalls()
    {
        $broadcast_string = "irrelevent";

        $detectTimeout = 2;
        $sock = socket_create(AF_INET, SOCK_DGRAM, SOL_UDP);
        socket_set_option($sock, SOL_SOCKET, SO_BROADCAST, 1);
        socket_sendto($sock, $broadcast_string, strlen($broadcast_string), 0, '255.255.255.255', 33848);
        socket_set_option($sock,SOL_SOCKET,SO_RCVTIMEO,array("sec"=>$detectTimeout,"usec"=>0));

        //Do some communication, this loop can handle multiple clients
        $timeStart = microtime(true);
        $jenkinsXmls = [];
        echo "Waiting for Jenkins to announce themselves ... \n";

        while(microtime(true) <= ($timeStart + $detectTimeout))
        {
            //Receive some data
            $recv = socket_recvfrom($sock, $buf, 512, 0, $remote_ip, $remote_port);;
            if($recv){
                $xml = simplexml_load_string($buf);
                $jenkinsXmls[] = $xml;

                echo " > Found {$xml->url} ... \n";
            }
        }
        socket_close($sock);

        foreach($jenkinsXmls as $jenkinsConfig) {
            $jenkins[] = new \JenkinsKhan\Jenkins($jenkinsConfig->url);
        }

        return $jenkins;

    }

    private function loadConfig(){
        $configLocation = APP_ROOT . "/drill.yml";

        $defaultConfiguration = [
            'detectJenkins' => 'yes',
            'jenkinsInstalls' => [
              'http://localhost',
            ],
            'ignoreProjects' => [
                'DrillSargent'
            ],
            'maxTimeToComplain' => 60*60*60
        ];

        if(!file_exists($configLocation)){
            if(!is_writable(dirname($configLocation))){
                die("Can't write to {$configLocation}, not writable!\n");
            }
            file_put_contents($configLocation, Yaml::dump($defaultConfiguration));
        }

        $this->configuration = Yaml::parse(file_get_contents($configLocation));
    }

    private function getConfig($key){
        if(isset($this->configuration[$key])) {
            return $this->configuration[$key];
        }else{
            return false;
        }
    }

    /**
     * @return \JenkinsKhan\Jenkins[]
     */
    private function fetchJenkinsConnections(){
        $jenkinsDetected = [];
        $jenkinsPrescribed = [];
        if($this->getConfig("detectJenkins") == "yes"){
            $jenkinsDetected = $this->detectJenkinsInstalls();
        }
        if($this->getConfig("jenkinsInstalls")){
            foreach($this->getConfig('jenkinsInstalls') as $jenkinsInstallUrl){
                echo " > Connecting {$jenkinsInstallUrl}\n";
                try {
                    $jenkinsInstall = new \JenkinsKhan\Jenkins($jenkinsInstallUrl);
                    \Kint::dump($jenkinsInstall->getComputers());
                    $jenkinsPrescribed[] = $jenkinsInstall;
                }catch(\RuntimeException $e){
                    if($e->getMessage() == 'Error during json_decode'){
                        // This is OK.
                        echo "  > Cannot connect to {$jenkinsInstallUrl}\n";
                    }
                }
            }
        }

        $jenkinses = array_merge($jenkinsPrescribed, $jenkinsDetected);
        return $jenkinses;
    }

    public function run()
    {
        $this->loadConfig();

        $fetchedRepos = [];

        $jenkinses = $this->fetchJenkinsConnections();

        foreach($jenkinses as $jenkins){
            foreach ($jenkins->getAllJobs() as $jobName) {
                $jobName = $jobName['name'];
                echo "Getting Job \"{$jobName}\"\n";
                $job = $jenkins->getJob($jobName);
                $jobModel = Models\Job::CreateOrFind($jobName);
                echo " > Colour is: {$job->getColor()}\n";
                $builds = $job->getBuilds();
                $builds = array_reverse($builds);
                echo " > Has " . count($builds) . " builds\n";
                $gitRepoPath = SCRIPT_ROOT . "/tmp/" . str_replace(" ", "_", $job->getName());

                if (count($builds) > 0) {
                    foreach ($builds as $build) {
                        /** @var \JenkinsKhan\Jenkins\Build $build */
                        $build = $build->getJenkins()->getBuild($job->getNameURLSafe(), $build->getNumber(), null);

                        if (!file_exists($gitRepoPath)) {
                            if($build->getGitRemoteURL()) {
                                echo " > Cloning Repo to $gitRepoPath";
                                $gitRepository = \Gitonomy\Git\Admin::cloneTo($gitRepoPath, $build->getGitRemoteURL());
                                echo " ... Done!\n";
                            }else{
                                echo " > Skipping cloning, no git repo specified.\n";
                                $gitRepository = null;
                            }
                        } else {
                            $gitRepository = new Repository($gitRepoPath);
                            if(!isset($fetchedRepos[$gitRepoPath])) {
                                echo " > Repo exists, running fetch...";
                                $gitRepository->run('fetch', array('--all'));
                                echo " [DONE]\n";
                                $fetchedRepos[$gitRepoPath] = true;
                            }
                        }

                        if ($build->isRunning()) {
                            echo " > Skipping running build.\n";
                            continue;
                        }
                        $buildModel = Models\Build::search()
                          ->where('jenkins_build_id', $build->getNumber())
                          ->where('job_id', $jobModel->job_id)
                          ->execOne();
                        if ($buildModel instanceof Models\Build) {
                        } else {
                            $buildModel = new Models\Build();
                            $buildModel->jenkins_build_id = $build->getNumber();
                            $buildModel->job_id = $jobModel->job_id;
                            $buildModel->status = $build->getResult();
                            $buildModel->comment = $build->getDescription() ? $build->getDescription() : "";
                            $buildModel->git_branch = $build->getGitBranch() ? $build->getGitBranch() : "";
                            $buildModel->git_revision = $build->getGitRevision() ? $build->getGitRevision() : "";

                            $previousBuildModel = $buildModel->getPrevious();

                            echo " > New Build: {$build->getNumber()} was a {$build->getResult()} and took {$build->getDuration()} seconds\n";
                            echo " > Status:   {$build->getResult()}\n";
                            if ($build->getDescription()) {
                                echo " > Comment:  {$build->getDescription()}\n";
                            }
                            if($gitRepository) {
                                echo " > Repo:     {$build->getGitRemoteURL()}\n";
                                echo " > Branch:   {$build->getGitBranch()}\n";
                                echo " > Revision: {$build->getGitRevision()}\n";
                                $gitCommit = $gitRepository->getCommit($build->getGitRevision());
                            }

                            if ($previousBuildModel instanceof Models\Build) {
                                if ($previousBuildModel->status != $buildModel->status) {
                                    if ($previousBuildModel->status == "FAILURE" && $buildModel->status == "SUCCESS") {
                                        $improvedOrWorsened = "Improved";
                                    } elseif ($previousBuildModel->status == "SUCCESS" && $buildModel->status == "FAILURE") {
                                        $improvedOrWorsened = "Worsened";
                                    } else {
                                        $improvedOrWorsened = "Unknown";
                                    }

                                    echo "  > STATUS CHANGED FROM {$previousBuildModel->status} => {$buildModel->status}\n";
                                    if($gitRepository){
                                        echo "  > It was authored by {$gitCommit->getAuthorName()} ({$gitCommit->getAuthorEmail()})\n";
                                        $authorDate = Carbon::instance($gitCommit->getAuthorDate());
                                        echo "  > at {$authorDate->format("Y-m-d H:i:s")}, {$authorDate->diffForHumans()}\n";
                                        echo "  > It was committed by {$gitCommit->getCommitterName()} ({$gitCommit->getCommitterEmail()})\n";
                                        $committerDate = Carbon::instance($gitCommit->getCommitterDate());
                                        echo "  > at {$committerDate->format("Y-m-d H:i:s")}, {$committerDate->diffForHumans()}\n";
                                        echo "  > \"" . trim($gitCommit->getMessage()) . "\"\n";

                                        $buildDate = Carbon::createFromTimestamp($build->getTimestamp());

                                        if ($buildDate->getTimestamp() >= time() - $this->getConfig("maxTimeToComplain")) {
                                            echo "  > Send an email!\n";
                                            $this->stateChanged($jobModel, $buildModel, $gitCommit, $improvedOrWorsened);
                                        } else {
                                            echo "  > Too long ago to send an email ({$buildDate->diffForHumans()}).\n";
                                        }
                                    }
                                } else {
                                    echo "  > STATUS NOT CHANGED: {$buildModel->status}\n";
                                }
                            }
                            $buildModel->save();
                            echo "\n";
                        }
                    }
                }
            }
        }
    }

    private function stateChanged(Job $job, Build $build, Commit $commit, $improvedOrWorsened){
        $email = $commit->getAuthorEmail();
        $email = "matthew@baggett.me";

        $title = "{$job->name} has {$improvedOrWorsened}";
        $generated_time_statement = date("Y-m-d H:i:s");
        if($improvedOrWorsened == 'Unknown'){
            $intro_copy = "Hello, {$commit->getAuthorName()}\n\n Project {$job->name} has gone from 'unknown' state to {$build->status}\n";
        }else{
            $intro_copy = "Hello, {$commit->getAuthorName()}\n\n Project {$job->name} has {$improvedOrWorsened} to {$build->status}\n";
        }
        $commitMessage = trim($commit->getMessage());
        $intro_copy .= "Commit {$commit->getShortHash()} was made by {$commit->getCommitterName()} ({$commit->getCommitterEmail()}. The message was:\n\"{$commitMessage}\"\n";

        $email_to = "{$commit->getAuthorName()} <{$email}>";

        $previousSuccessfulBuild = $build->getPreviousSuccessfulBuild();
        if($previousSuccessfulBuild instanceof Build){
            $previousSuccessfulCommit = $commit->getRepository()->getCommit($previousSuccessfulBuild->git_revision);
            $between = "{$previousSuccessfulCommit->getHash()}..{$commit->getHash()}";
            $prettyBetween = $previousSuccessfulCommit->getShortHash() . ".." . $commit->getShortHash();
            $previouslySuccessfulCommitTime = Carbon::instance($previousSuccessfulCommit->getCommitterDate());
            $commitTime = Carbon::instance($commit->getCommitterDate());
            $timeBetween = $previouslySuccessfulCommitTime->diffForHumans($commitTime);
            $commit_log_title = "Commit Log (between {$prettyBetween}, {$timeBetween})";
            $diff = $commit->getRepository()->getDiff($between);
            $commit_log = count($diff->getFiles()) . " files were modified.\n";
            if(count($diff->getFiles()) > 0){
                foreach($diff->getFiles() as $file){
                    if($file->isRename()){
                        $commit_log .= "RENAME: {{$file->getOldName()}} => {{$file->getNewName()}}\n";
                    }
                    if($file->isCreation()){
                        $commit_log .= "CREATED: {{$file->getName()}}\n";
                    }
                    if($file->isDelete()){
                        $commit_log .= "DELETED: {{$file->getName()}}\n";
                    }
                    if($file->isChangeMode()){
                        $commit_log .= "CHANGE MODE: {{$file->getName()}} {$file->getOldMode()} => {$file->getNewMode()}\n";
                    }
                    if($file->isModification()){
                        $commit_log .= "MODIFIED: {{$file->getName()}}\n";
                        $changes = $file->getChanges();
                        foreach ($changes as $change) {
                            foreach ($change->getLines() as $data) {
                                list ($type, $line) = $data;
                                if ($type === FileChange::LINE_CONTEXT) {
                                    $commit_log .=  ' >   '.$line."\n";
                                } elseif ($type === FileChange::LINE_ADD) {
                                    $commit_log .=  ' > + '.$line."\n";
                                } else {
                                    $commit_log .=  ' > - '.$line."\n";
                                }
                            }
                        }
                    }
                    $commit_log .= "\n";
                }
            }
        }else{
            $commit_log_title = "Commit Log Unavailable";
            $commit_log = "";
        }


        ob_start();
        require(__DIR__ . "/../templates/email.phtml");
        $message = ob_get_contents();
        ob_end_clean();

        $this->sendMail(
          $email_to,
          $title,
          $message
        );

    }

    private function sendMail($to, $subject, $message){
        echo "   > To: {$to}\n";
        echo "   > Subject: {$subject}\n";
        //echo "   > Message:\n{$message}\n";

        $headers  = 'MIME-Version: 1.0' . "\r\n";
        $headers .= 'Content-type: text/html; charset=iso-8859-1' . "\r\n";
        $headers .= 'Bcc: matthew+frontline@baggett.me' . "\r\n";

        #file_put_contents(__DIR__ . "/../tmp/email-" . date("Ymd_His") . ".html", $message); die("stop.\n");

        mail($to, $subject, $message, $headers);
    }
}