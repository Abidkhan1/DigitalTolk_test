<?php

namespace DTApi\Traits;

use DTApi\Models\Job;
use DTApi\Models\User;
use Illuminate\Http\Request;

trait UsersJobsTrait {

    /**
     * @param Request $request
     * @return $this|false|string
     */
     public function getUsersJobs($user_id)
     {
         $cuser = User::find($user_id);
         $usertype = '';
         $emergencyJobs = array();
         $noramlJobs = array();
         if ($cuser && $cuser->is('customer')) {
             $jobs = $cuser->jobs()->with('user.userMeta', 'user.average', 'translatorJobRel.user.average', 'language', 'feedback')->whereIn('status', ['pending', 'assigned', 'started'])->orderBy('due', 'asc')->get();
             $usertype = 'customer';
         } elseif ($cuser && $cuser->is('translator')) {
             $jobs = Job::getTranslatorJobs($cuser->id, 'new');
             $jobs = $jobs->pluck('jobs')->all();
             $usertype = 'translator';
         }
         if ($jobs) {
             foreach ($jobs as $jobitem) {
                 if ($jobitem->immediate == 'yes') {
                     $emergencyJobs[] = $jobitem;
                 } else {
                     $noramlJobs[] = $jobitem;
                 }
             }
             $noramlJobs = collect($noramlJobs)->each(function ($item, $key) use ($user_id) {
                 $item['usercheck'] = Job::checkParticularJob($user_id, $item);
             })->sortBy('due')->all();
         }

         return ['emergencyJobs' => $emergencyJobs, 'noramlJobs' => $noramlJobs, 'cuser' => $cuser, 'usertype' => $usertype];
     }

     /**
      * @param $user_id
      * @return array
      */
     public function getUsersJobsHistory($user_id, Request $request)
     {
         $page = $request->get('page');
         if (isset($page)) {
             $pagenum = $page;
         } else {
             $pagenum = "1";
         }
         $cuser = User::find($user_id);
         $usertype = '';
         $emergencyJobs = array();
         $noramlJobs = array();
         if ($cuser && $cuser->is('customer')) {
             $jobs = $cuser->jobs()->with('user.userMeta', 'user.average', 'translatorJobRel.user.average', 'language', 'feedback', 'distance')->whereIn('status', ['completed', 'withdrawbefore24', 'withdrawafter24', 'timedout'])->orderBy('due', 'desc')->paginate(15);
             $usertype = 'customer';
             return ['emergencyJobs' => $emergencyJobs, 'noramlJobs' => [], 'jobs' => $jobs, 'cuser' => $cuser, 'usertype' => $usertype, 'numpages' => 0, 'pagenum' => 0];
         } elseif ($cuser && $cuser->is('translator')) {
             $jobs_ids = Job::getTranslatorJobsHistoric($cuser->id, 'historic', $pagenum);
             $totaljobs = $jobs_ids->total();
             $numpages = ceil($totaljobs / 15);

             $usertype = 'translator';

             $jobs = $jobs_ids;
             $noramlJobs = $jobs_ids;
 //            $jobs['data'] = $noramlJobs;
 //            $jobs['total'] = $totaljobs;
             return ['emergencyJobs' => $emergencyJobs, 'noramlJobs' => $noramlJobs, 'jobs' => $jobs, 'cuser' => $cuser, 'usertype' => $usertype, 'numpages' => $numpages, 'pagenum' => $pagenum];
         }
     }
}
