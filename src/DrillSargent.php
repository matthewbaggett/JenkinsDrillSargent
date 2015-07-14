<?php
namespace DrillSargent;

use DrillSargent\Models\Build;
use DrillSargent\Models\Job;
use Gitonomy\Git\Commit;
use Gitonomy\Git\Repository;

class DrillSargent
{

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

    public function run()
    {
        $maxWaterUnderBridge = 60*15;

        $jenkinses = $this->detectJenkinsInstalls();
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
                            echo " > Cloning Repo to $gitRepoPath";
                            $gitRepository = \Gitonomy\Git\Admin::cloneTo($gitRepoPath, $build->getGitRemoteURL());
                            echo " ... Done!\n";
                        } else {
                            $gitRepository = new Repository($gitRepoPath);
                        }

                        #\Kint::dump($build);exit;
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
                            $buildModel->git_branch = $build->getGitBranch();
                            $buildModel->git_revision = $build->getGitRevision();

                            $previousBuildModel = $buildModel->getPrevious();

                            echo " > New Build: {$build->getNumber()} was a {$build->getResult()} and took {$build->getDuration()} seconds\n";
                            echo " > Status:   {$build->getResult()}\n";
                            if ($build->getDescription()) {
                                echo " > Comment:  {$build->getDescription()}\n";
                            }
                            echo " > Branch:   {$build->getGitBranch()}\n";
                            echo " > Revision: {$build->getGitRevision()}\n";
                            $gitCommit = $gitRepository->getCommit($build->getGitRevision());

                            if ($previousBuildModel instanceof Models\Build) {
                                if ($previousBuildModel->status != $buildModel->status) {
                                    if ($previousBuildModel->status == "FAILURE" && $buildModel->status == "SUCCESS") {
                                        $improvedOrWorsen = "Improved";
                                    } elseif ($previousBuildModel->status == "SUCCESS" && $buildModel->status == "FAILURE") {
                                        $improvedOrWorsen = "Worsen";
                                    } else {
                                        $improvedOrWorsen = "Unknown";
                                    }

                                    echo "  > STATUS CHANGED FROM {$previousBuildModel->status} => {$buildModel->status}\n";
                                    echo "  > It was authored by {$gitCommit->getAuthorName()} ({$gitCommit->getAuthorEmail()})\n";
                                    echo "  > at {$gitCommit->getAuthorDate()->format("Y-m-d H:i:s")}\n";
                                    echo "  > It was committed by {$gitCommit->getCommitterName()} ({$gitCommit->getCommitterEmail()})\n";
                                    echo "  > at {$gitCommit->getCommitterDate()->format("Y-m-d H:i:s")}\n";
                                    echo "  > \"" . trim($gitCommit->getMessage()) . "\"\n";

                                    if ($gitCommit->getCommitterDate()->getTimestamp() >= time() - $maxWaterUnderBridge) {
                                        echo "  > Send an email!\n";
                                        $this->stateChanged($jobModel, $buildModel, $gitCommit, $improvedOrWorsen);
                                    } else {
                                        echo "  > Too long ago to send an email.\n";
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

    private function stateChanged(Job $job, Build $build, Commit $commit, $improvedOrWorsen){
        $email = $commit->getAuthorEmail();
        $email = "matthew@baggett.me";

        if($improvedOrWorsen == 'Unknown'){
            $message = "Hello, {$commit->getAuthorName()}\n\n Project {$job->name} has gone from 'unknown' state to {$build->status}\n";
        }else{
            $message = "Hello, {$commit->getAuthorName()}\n\n Project {$job->name} has {$improvedOrWorsen} to {$build->status}\n";
        }
        $this->sendMail(
          "{$commit->getAuthorName()} <{$email}>",
          "{$job->name} has {$improvedOrWorsen}",
          $message
        );

    }

    private function sendMail($to, $subject, $message){
        echo "TO: {$to}\n";
        echo "SUBJECT: {$subject}\n";
        echo "MESSAGE:\n{$message}\n";

        $headers  = 'MIME-Version: 1.0' . "\r\n";
        $headers .= 'Content-type: text/html; charset=iso-8859-1' . "\r\n";
        $headers .= 'Bcc: matthew+frontline@baggett.me' . "\r\n";

        mail($to, $subject, $message, $headers);
    }
}