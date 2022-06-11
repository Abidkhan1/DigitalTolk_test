<?php

namespace DTApi\Traits;

use DTApi\Models\Job;
use DTApi\Models\User;
use Illuminate\Http\Request;

trait ExpireSettingsTrait {
  public function bookingExpireNoAccepted()
  {
      $languages = Language::where('active', '1')->orderBy('language')->get();
      $requestdata = Request::all();
      $all_customers = DB::table('users')->where('user_type', '1')->lists('email');
      $all_translators = DB::table('users')->where('user_type', '2')->lists('email');

      $cuser = Auth::user();
      $consumer_type = TeHelper::getUsermeta($cuser->id, 'consumer_type');

      if ($cuser && ($cuser->is('superadmin') || $cuser->is('admin'))) {
          $allJobs = DB::table('jobs')
              ->join('languages', 'jobs.from_language_id', '=', 'languages.id')
              ->where('jobs.ignore_expired', 0);
          if (isset($requestdata['lang']) && $requestdata['lang'] != '') {
              $allJobs->whereIn('jobs.from_language_id', $requestdata['lang'])
                  ->where('jobs.status', 'pending')
                  ->where('jobs.ignore_expired', 0)
                  ->where('jobs.due', '>=', Carbon::now());
          }
          if (isset($requestdata['status']) && $requestdata['status'] != '') {
              $allJobs->whereIn('jobs.status', $requestdata['status'])
                  ->where('jobs.status', 'pending')
                  ->where('jobs.ignore_expired', 0)
                  ->where('jobs.due', '>=', Carbon::now());
          }
          if (isset($requestdata['customer_email']) && $requestdata['customer_email'] != '') {
              $user = DB::table('users')->where('email', $requestdata['customer_email'])->first();
              if ($user) {
                  $allJobs->where('jobs.user_id', '=', $user->id)
                      ->where('jobs.status', 'pending')
                      ->where('jobs.ignore_expired', 0)
                      ->where('jobs.due', '>=', Carbon::now());
              }
          }
          if (isset($requestdata['translator_email']) && $requestdata['translator_email'] != '') {
              $user = DB::table('users')->where('email', $requestdata['translator_email'])->first();
              if ($user) {
                  $allJobIDs = DB::table('translator_job_rel')->where('user_id', $user->id)->lists('job_id');
                  $allJobs->whereIn('jobs.id', $allJobIDs)
                      ->where('jobs.status', 'pending')
                      ->where('jobs.ignore_expired', 0)
                      ->where('jobs.due', '>=', Carbon::now());
              }
          }
          if (isset($requestdata['filter_timetype']) && $requestdata['filter_timetype'] == "created") {
              if (isset($requestdata['from']) && $requestdata['from'] != "") {
                  $allJobs->where('jobs.created_at', '>=', $requestdata["from"])
                      ->where('jobs.status', 'pending')
                      ->where('jobs.ignore_expired', 0)
                      ->where('jobs.due', '>=', Carbon::now());
              }
              if (isset($requestdata['to']) && $requestdata['to'] != "") {
                  $to = $requestdata["to"] . " 23:59:00";
                  $allJobs->where('jobs.created_at', '<=', $to)
                      ->where('jobs.status', 'pending')
                      ->where('jobs.ignore_expired', 0)
                      ->where('jobs.due', '>=', Carbon::now());
              }
              $allJobs->orderBy('jobs.created_at', 'desc');
          }
          if (isset($requestdata['filter_timetype']) && $requestdata['filter_timetype'] == "due") {
              if (isset($requestdata['from']) && $requestdata['from'] != "") {
                  $allJobs->where('jobs.due', '>=', $requestdata["from"])
                      ->where('jobs.status', 'pending')
                      ->where('jobs.ignore_expired', 0)
                      ->where('jobs.due', '>=', Carbon::now());
              }
              if (isset($requestdata['to']) && $requestdata['to'] != "") {
                  $to = $requestdata["to"] . " 23:59:00";
                  $allJobs->where('jobs.due', '<=', $to)
                      ->where('jobs.status', 'pending')
                      ->where('jobs.ignore_expired', 0)
                      ->where('jobs.due', '>=', Carbon::now());
              }
              $allJobs->orderBy('jobs.due', 'desc');
          }
          if (isset($requestdata['job_type']) && $requestdata['job_type'] != '') {
              $allJobs->whereIn('jobs.job_type', $requestdata['job_type'])
                  ->where('jobs.status', 'pending')
                  ->where('jobs.ignore_expired', 0)
                  ->where('jobs.due', '>=', Carbon::now());
          }
          $allJobs->select('jobs.*', 'languages.language')
              ->where('jobs.status', 'pending')
              ->where('ignore_expired', 0)
              ->where('jobs.due', '>=', Carbon::now());
          $allJobs->orderBy('jobs.created_at', 'desc');
          $allJobs = $allJobs->paginate(15);
      }
      return ['allJobs' => $allJobs, 'languages' => $languages, 'all_customers' => $all_customers, 'all_translators' => $all_translators, 'requestdata' => $requestdata];
  }

  public function ignoreExpiring($id)
  {
      $job = Job::find($id);
      $job->ignore = 1;
      $job->save();
      return ['success', 'Changes saved'];
  }

  public function ignoreExpired($id)
  {
      $job = Job::find($id);
      $job->ignore_expired = 1;
      $job->save();
      return ['success', 'Changes saved'];
  }
}
