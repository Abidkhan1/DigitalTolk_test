<?php

namespace DTApi\Traits;

use DTApi\Models\Job;
TeHelper;
Carbon;
AppMailer
Event
JobWasCanceled
UserLanguages
SessionEnded
JobWasCreated
UserMeta

use Illuminate\Http\Request;

trait JobTrait {

  public function updateJob($id, $data, $cuser)
  {
      $job = Job::find($id);

      $current_translator = $job->translatorJobRel->where('cancel_at', Null)->first();
      if (is_null($current_translator))
          $current_translator = $job->translatorJobRel->where('completed_at', '!=', Null)->first();

      $log_data = [];

      $langChanged = false;

      $changeTranslator = $this->changeTranslator($current_translator, $data, $job);
      if ($changeTranslator['translatorChanged']) $log_data[] = $changeTranslator['log_data'];

      $changeDue = $this->changeDue($job->due, $data['due']);
      if ($changeDue['dateChanged']) {
          $old_time = $job->due;
          $job->due = $data['due'];
          $log_data[] = $changeDue['log_data'];
      }

      if ($job->from_language_id != $data['from_language_id']) {
          $log_data[] = [
              'old_lang' => TeHelper::fetchLanguageFromJobId($job->from_language_id),
              'new_lang' => TeHelper::fetchLanguageFromJobId($data['from_language_id'])
          ];
          $old_lang = $job->from_language_id;
          $job->from_language_id = $data['from_language_id'];
          $langChanged = true;
      }

      $changeStatus = $this->changeStatus($job, $data, $changeTranslator['translatorChanged']);
      if ($changeStatus['statusChanged'])
          $log_data[] = $changeStatus['log_data'];

      $job->admin_comments = $data['admin_comments'];

      $this->logger->addInfo('USER #' . $cuser->id . '(' . $cuser->name . ')' . ' has been updated booking <a class="openjob" href="/admin/jobs/' . $id . '">#' . $id . '</a> with data:  ', $log_data);

      $job->reference = $data['reference'];

      if ($job->due <= Carbon::now()) {
          $job->save();
          return ['Updated'];
      } else {
          $job->save();
          if ($changeDue['dateChanged']) $this->sendChangedDateNotification($job, $old_time);
          if ($changeTranslator['translatorChanged']) $this->sendChangedTranslatorNotification($job, $current_translator, $changeTranslator['new_translator']);
          if ($langChanged) $this->sendChangedLangNotification($job, $old_lang);
      }
  }

  public function acceptJob($data, $user)
  {

      $adminemail = config('app.admin_email');
      $adminSenderEmail = config('app.admin_sender_email');

      $cuser = $user;
      $job_id = $data['job_id'];
      $job = Job::findOrFail($job_id);
      if (!Job::isTranslatorAlreadyBooked($job_id, $cuser->id, $job->due)) {
          if ($job->status == 'pending' && Job::insertTranslatorJobRel($cuser->id, $job_id)) {
              $job->status = 'assigned';
              $job->save();
              $user = $job->user()->get()->first();
              $mailer = new AppMailer();

              if (!empty($job->user_email)) {
                  $email = $job->user_email;
                  $name = $user->name;
                  $subject = 'Bekräftelse - tolk har accepterat er bokning (bokning # ' . $job->id . ')';
              } else {
                  $email = $user->email;
                  $name = $user->name;
                  $subject = 'Bekräftelse - tolk har accepterat er bokning (bokning # ' . $job->id . ')';
              }
              $data = [
                  'user' => $user,
                  'job'  => $job
              ];
              $mailer->send($email, $name, $subject, 'emails.job-accepted', $data);

          }
          /*@todo
              add flash message here.
          */
          $jobs = $this->getPotentialJobs($cuser);
          $response = array();
          $response['list'] = json_encode(['jobs' => $jobs, 'job' => $job], true);
          $response['status'] = 'success';
      } else {
          $response['status'] = 'fail';
          $response['message'] = 'Du har redan en bokning den tiden! Bokningen är inte accepterad.';
      }

      return $response;

  }

  /*Function to accept the job with the job id*/
  public function acceptJobWithId($job_id, $cuser)
  {
      $adminemail = config('app.admin_email');
      $adminSenderEmail = config('app.admin_sender_email');
      $job = Job::findOrFail($job_id);
      $response = array();

      if (!Job::isTranslatorAlreadyBooked($job_id, $cuser->id, $job->due)) {
          if ($job->status == 'pending' && Job::insertTranslatorJobRel($cuser->id, $job_id)) {
              $job->status = 'assigned';
              $job->save();
              $user = $job->user()->get()->first();
              $mailer = new AppMailer();

              if (!empty($job->user_email)) {
                  $email = $job->user_email;
                  $name = $user->name;
              } else {
                  $email = $user->email;
                  $name = $user->name;
              }
              $subject = 'Bekräftelse - tolk har accepterat er bokning (bokning # ' . $job->id . ')';
              $data = [
                  'user' => $user,
                  'job'  => $job
              ];
              $mailer->send($email, $name, $subject, 'emails.job-accepted', $data);

              $data = array();
              $data['notification_type'] = 'job_accepted';
              $language = TeHelper::fetchLanguageFromJobId($job->from_language_id);
              $msg_text = array(
                  "en" => 'Din bokning för ' . $language . ' translators, ' . $job->duration . 'min, ' . $job->due . ' har accepterats av en tolk. Vänligen öppna appen för att se detaljer om tolken.'
              );
              if ($this->isNeedToSendPush($user->id)) {
                  $users_array = array($user);
                  $this->sendPushNotificationToSpecificUsers($users_array, $job_id, $data, $msg_text, $this->isNeedToDelayPush($user->id));
              }
              // Your Booking is accepted sucessfully
              $response['status'] = 'success';
              $response['list']['job'] = $job;
              $response['message'] = 'Du har nu accepterat och fått bokningen för ' . $language . 'tolk ' . $job->duration . 'min ' . $job->due;
          } else {
              // Booking already accepted by someone else
              $language = TeHelper::fetchLanguageFromJobId($job->from_language_id);
              $response['status'] = 'fail';
              $response['message'] = 'Denna ' . $language . 'tolkning ' . $job->duration . 'min ' . $job->due . ' har redan accepterats av annan tolk. Du har inte fått denna tolkning';
          }
      } else {
          // You already have a booking the time
          $response['status'] = 'fail';
          $response['message'] = 'Du har redan en bokning den tiden ' . $job->due . '. Du har inte fått denna tolkning';
      }
      return $response;
  }

  public function cancelJobAjax($data, $user)
  {
      $response = array();
      /*@todo
          add 24hrs loging here.
          If the cancelation is before 24 hours before the booking tie - supplier will be informed. Flow ended
          if the cancelation is within 24
          if cancelation is within 24 hours - translator will be informed AND the customer will get an addition to his number of bookings - so we will charge of it if the cancelation is within 24 hours
          so we must treat it as if it was an executed session
      */
      $cuser = $user;
      $job_id = $data['job_id'];
      $job = Job::findOrFail($job_id);
      $translator = Job::getJobsAssignedTranslatorDetail($job);
      if ($cuser->is('customer')) {
          $job->withdraw_at = Carbon::now();
          if ($job->withdraw_at->diffInHours($job->due) >= 24) {
              $job->status = 'withdrawbefore24';
              $response['jobstatus'] = 'success';
          } else {
              $job->status = 'withdrawafter24';
              $response['jobstatus'] = 'success';
          }
          $job->save();
          Event::fire(new JobWasCanceled($job));
          $response['status'] = 'success';
          $response['jobstatus'] = 'success';
          if ($translator) {
              $data = array();
              $data['notification_type'] = 'job_cancelled';
              $language = TeHelper::fetchLanguageFromJobId($job->from_language_id);
              $msg_text = array(
                  "en" => 'Kunden har avbokat bokningen för ' . $language . 'tolk, ' . $job->duration . 'min, ' . $job->due . '. Var god och kolla dina tidigare bokningar för detaljer.'
              );
              if ($this->isNeedToSendPush($translator->id)) {
                  $users_array = array($translator);
                  $this->sendPushNotificationToSpecificUsers($users_array, $job_id, $data, $msg_text, $this->isNeedToDelayPush($translator->id));// send Session Cancel Push to Translaotor
              }
          }
      } else {
          if ($job->due->diffInHours(Carbon::now()) > 24) {
              $customer = $job->user()->get()->first();
              if ($customer) {
                  $data = array();
                  $data['notification_type'] = 'job_cancelled';
                  $language = TeHelper::fetchLanguageFromJobId($job->from_language_id);
                  $msg_text = array(
                      "en" => 'Er ' . $language . 'tolk, ' . $job->duration . 'min ' . $job->due . ', har avbokat tolkningen. Vi letar nu efter en ny tolk som kan ersätta denne. Tack.'
                  );
                  if ($this->isNeedToSendPush($customer->id)) {
                      $users_array = array($customer);
                      $this->sendPushNotificationToSpecificUsers($users_array, $job_id, $data, $msg_text, $this->isNeedToDelayPush($customer->id));     // send Session Cancel Push to customer
                  }
              }
              $job->status = 'pending';
              $job->created_at = date('Y-m-d H:i:s');
              $job->will_expire_at = TeHelper::willExpireAt($job->due, date('Y-m-d H:i:s'));
              $job->save();
//                Event::fire(new JobWasCanceled($job));
              Job::deleteTranslatorJobRel($translator->id, $job_id);

              $data = $this->jobToData($job);

              $this->sendNotificationTranslator($job, $data, $translator->id);   // send Push all sutiable translators
              $response['status'] = 'success';
          } else {
              $response['status'] = 'fail';
              $response['message'] = 'Du kan inte avboka en bokning som sker inom 24 timmar genom DigitalTolk. Vänligen ring på +46 73 75 86 865 och gör din avbokning over telefon. Tack!';
          }
      }
      return $response;
  }

  /*Function to get the potential jobs for paid,rws,unpaid translators*/
  public function getPotentialJobs($cuser)
  {
      $cuser_meta = $cuser->userMeta;
      $job_type = 'unpaid';
      $translator_type = $cuser_meta->translator_type;
      if ($translator_type == 'professional')
          $job_type = 'paid';   /*show all jobs for professionals.*/
      else if ($translator_type == 'rwstranslator')
          $job_type = 'rws';  /* for rwstranslator only show rws jobs. */
      else if ($translator_type == 'volunteer')
          $job_type = 'unpaid';  /* for volunteers only show unpaid jobs. */

      $languages = UserLanguages::where('user_id', '=', $cuser->id)->get();
      $userlanguage = collect($languages)->pluck('lang_id')->all();
      $gender = $cuser_meta->gender;
      $translator_level = $cuser_meta->translator_level;
      /*Call the town function for checking if the job physical, then translators in one town can get job*/
      $job_ids = Job::getJobs($cuser->id, $job_type, 'pending', $userlanguage, $gender, $translator_level);
      foreach ($job_ids as $k => $job) {
          $jobuserid = $job->user_id;
          $job->specific_job = Job::assignedToPaticularTranslator($cuser->id, $job->id);
          $job->check_particular_job = Job::checkParticularJob($cuser->id, $job);
          $checktown = Job::checkTowns($jobuserid, $cuser->id);

          if($job->specific_job == 'SpecificJob')
              if ($job->check_particular_job == 'userCanNotAcceptJob')
              unset($job_ids[$k]);

          if (($job->customer_phone_type == 'no' || $job->customer_phone_type == '') && $job->customer_physical_type == 'yes' && $checktown == false) {
              unset($job_ids[$k]);
          }
      }
//        $jobs = TeHelper::convertJobIdsInObjs($job_ids);
      return $job_ids;
  }

  public function endJob($post_data)
  {
      $completeddate = date('Y-m-d H:i:s');
      $jobid = $post_data["job_id"];
      $job_detail = Job::with('translatorJobRel')->find($jobid);

      if($job_detail->status != 'started')
          return ['status' => 'success'];

      $duedate = $job_detail->due;
      $start = date_create($duedate);
      $end = date_create($completeddate);
      $diff = date_diff($end, $start);
      $interval = $diff->h . ':' . $diff->i . ':' . $diff->s;
      $job = $job_detail;
      $job->end_at = date('Y-m-d H:i:s');
      $job->status = 'completed';
      $job->session_time = $interval;

      $user = $job->user()->get()->first();
      if (!empty($job->user_email)) {
          $email = $job->user_email;
      } else {
          $email = $user->email;
      }
      $name = $user->name;
      $subject = 'Information om avslutad tolkning för bokningsnummer # ' . $job->id;
      $session_explode = explode(':', $job->session_time);
      $session_time = $session_explode[0] . ' tim ' . $session_explode[1] . ' min';
      $data = [
          'user'         => $user,
          'job'          => $job,
          'session_time' => $session_time,
          'for_text'     => 'faktura'
      ];
      $mailer = new AppMailer();
      $mailer->send($email, $name, $subject, 'emails.session-ended', $data);

      $job->save();

      $tr = $job->translatorJobRel()->where('completed_at', Null)->where('cancel_at', Null)->first();

      Event::fire(new SessionEnded($job, ($post_data['user_id'] == $job->user_id) ? $tr->user_id : $job->user_id));

      $user = $tr->user()->first();
      $email = $user->email;
      $name = $user->name;
      $subject = 'Information om avslutad tolkning för bokningsnummer # ' . $job->id;
      $data = [
          'user'         => $user,
          'job'          => $job,
          'session_time' => $session_time,
          'for_text'     => 'lön'
      ];
      $mailer = new AppMailer();
      $mailer->send($email, $name, $subject, 'emails.session-ended', $data);

      $tr->completed_at = $completeddate;
      $tr->completed_by = $post_data['user_id'];
      $tr->save();
      $response['status'] = 'success';
      return $response;
  }

  public function storeJobEmail($data)
  {
      $user_type = $data['user_type'];
      $job = Job::findOrFail(@$data['user_email_job_id']);
      $job->user_email = @$data['user_email'];
      $job->reference = isset($data['reference']) ? $data['reference'] : '';
      $user = $job->user()->get()->first();
      if (isset($data['address'])) {
          $job->address = ($data['address'] != '') ? $data['address'] : $user->userMeta->address;
          $job->instructions = ($data['instructions'] != '') ? $data['instructions'] : $user->userMeta->instructions;
          $job->town = ($data['town'] != '') ? $data['town'] : $user->userMeta->city;
      }
      $job->save();

      if (!empty($job->user_email)) {
          $email = $job->user_email;
          $name = $user->name;
      } else {
          $email = $user->email;
          $name = $user->name;
      }
      $subject = 'Vi har mottagit er tolkbokning. Bokningsnr: #' . $job->id;
      $send_data = [
          'user' => $user,
          'job'  => $job
      ];
      $this->mailer->send($email, $name, $subject, 'emails.job-created', $send_data);

      $response['type'] = $user_type;
      $response['job'] = $job;
      $response['status'] = 'success';
      $data = $this->jobToData($job);
      Event::fire(new JobWasCreated($job, $data, '*'));
      return $response;

  }

  /**
   * @param $job
   * @return array
   */
  public function jobToData($job)
  {

      $data = array();            // save job's information to data for sending Push
      $data['job_id'] = $job->id;
      $data['from_language_id'] = $job->from_language_id;
      $data['immediate'] = $job->immediate;
      $data['duration'] = $job->duration;
      $data['status'] = $job->status;
      $data['gender'] = $job->gender;
      $data['certified'] = $job->certified;
      $data['due'] = $job->due;
      $data['job_type'] = $job->job_type;
      $data['customer_phone_type'] = $job->customer_phone_type;
      $data['customer_physical_type'] = $job->customer_physical_type;
      $data['customer_town'] = $job->town;
      $data['customer_type'] = $job->user->userMeta->customer_type;

      $due_Date = explode(" ", $job->due);
      $due_date = $due_Date[0];
      $due_time = $due_Date[1];

      $data['due_date'] = $due_date;
      $data['due_time'] = $due_time;

      $data['job_for'] = array();
      if ($job->gender != null) {
          if ($job->gender == 'male') {
              $data['job_for'][] = 'Man';
          } else if ($job->gender == 'female') {
              $data['job_for'][] = 'Kvinna';
          }
      }
      if ($job->certified != null) {
          if ($job->certified == 'both') {
              $data['job_for'][] = 'Godkänd tolk';
              $data['job_for'][] = 'Auktoriserad';
          } else if ($job->certified == 'yes') {
              $data['job_for'][] = 'Auktoriserad';
          } else if ($job->certified == 'n_health') {
              $data['job_for'][] = 'Sjukvårdstolk';
          } else if ($job->certified == 'law' || $job->certified == 'n_law') {
              $data['job_for'][] = 'Rätttstolk';
          } else {
              $data['job_for'][] = $job->certified;
          }
      }

      return $data;

  }

  /**
   * @param array $post_data
   */
  public function jobEnd($post_data = array())
  {
      $completeddate = date('Y-m-d H:i:s');
      $jobid = $post_data["job_id"];
      $job_detail = Job::with('translatorJobRel')->find($jobid);
      $duedate = $job_detail->due;
      $start = date_create($duedate);
      $end = date_create($completeddate);
      $diff = date_diff($end, $start);
      $interval = $diff->h . ':' . $diff->i . ':' . $diff->s;
      $job = $job_detail;
      $job->end_at = date('Y-m-d H:i:s');
      $job->status = 'completed';
      $job->session_time = $interval;

      $user = $job->user()->get()->first();
      if (!empty($job->user_email)) {
          $email = $job->user_email;
      } else {
          $email = $user->email;
      }
      $name = $user->name;
      $subject = 'Information om avslutad tolkning för bokningsnummer # ' . $job->id;
      $session_explode = explode(':', $job->session_time);
      $session_time = $session_explode[0] . ' tim ' . $session_explode[1] . ' min';
      $data = [
          'user'         => $user,
          'job'          => $job,
          'session_time' => $session_time,
          'for_text'     => 'faktura'
      ];
      $mailer = new AppMailer();
      $mailer->send($email, $name, $subject, 'emails.session-ended', $data);

      $job->save();

      $tr = $job->translatorJobRel->where('completed_at', Null)->where('cancel_at', Null)->first();

      Event::fire(new SessionEnded($job, ($post_data['userid'] == $job->user_id) ? $tr->user_id : $job->user_id));

      $user = $tr->user()->first();
      $email = $user->email;
      $name = $user->name;
      $subject = 'Information om avslutad tolkning för bokningsnummer # ' . $job->id;
      $data = [
          'user'         => $user,
          'job'          => $job,
          'session_time' => $session_time,
          'for_text'     => 'lön'
      ];
      $mailer = new AppMailer();
      $mailer->send($email, $name, $subject, 'emails.session-ended', $data);

      $tr->completed_at = $completeddate;
      $tr->completed_by = $post_data['userid'];
      $tr->save();
  }

  /**
   * Function to get all Potential jobs of user with his ID
   * @param $user_id
   * @return array
   */
  public function getPotentialJobIdsWithUserId($user_id)
  {
      $user_meta = UserMeta::where('user_id', $user_id)->first();
      $translator_type = $user_meta->translator_type;
      $job_type = 'unpaid';
      if ($translator_type == 'professional')
          $job_type = 'paid';   /*show all jobs for professionals.*/
      else if ($translator_type == 'rwstranslator')
          $job_type = 'rws';  /* for rwstranslator only show rws jobs. */
      else if ($translator_type == 'volunteer')
          $job_type = 'unpaid';  /* for volunteers only show unpaid jobs. */

      $languages = UserLanguages::where('user_id', '=', $user_id)->get();
      $userlanguage = collect($languages)->pluck('lang_id')->all();
      $gender = $user_meta->gender;
      $translator_level = $user_meta->translator_level;
      $job_ids = Job::getJobs($user_id, $job_type, 'pending', $userlanguage, $gender, $translator_level);
      foreach ($job_ids as $k => $v)     // checking translator town
      {
          $job = Job::find($v->id);
          $jobuserid = $job->user_id;
          $checktown = Job::checkTowns($jobuserid, $user_id);
          if (($job->customer_phone_type == 'no' || $job->customer_phone_type == '') && $job->customer_physical_type == 'yes' && $checktown == false) {
              unset($job_ids[$k]);
          }
      }
      $jobs = TeHelper::convertJobIdsInObjs($job_ids);
      return $jobs;
  }

  public function getAll(Request $request, $limit = null)
  {
      $requestdata = $request->all();
      $cuser = $request->__authenticatedUser;
      $consumer_type = $cuser->consumer_type;

      if ($cuser && $cuser->user_type == env('SUPERADMIN_ROLE_ID')) {
          $allJobs = Job::query();

          if (isset($requestdata['feedback']) && $requestdata['feedback'] != 'false') {
              $allJobs->where('ignore_feedback', '0');
              $allJobs->whereHas('feedback', function ($q) {
                  $q->where('rating', '<=', '3');
              });
              if (isset($requestdata['count']) && $requestdata['count'] != 'false') return ['count' => $allJobs->count()];
          }

          if (isset($requestdata['id']) && $requestdata['id'] != '') {
              if (is_array($requestdata['id']))
                  $allJobs->whereIn('id', $requestdata['id']);
              else
                  $allJobs->where('id', $requestdata['id']);
              $requestdata = array_only($requestdata, ['id']);
          }

          if (isset($requestdata['lang']) && $requestdata['lang'] != '') {
              $allJobs->whereIn('from_language_id', $requestdata['lang']);
          }
          if (isset($requestdata['status']) && $requestdata['status'] != '') {
              $allJobs->whereIn('status', $requestdata['status']);
          }
          if (isset($requestdata['expired_at']) && $requestdata['expired_at'] != '') {
              $allJobs->where('expired_at', '>=', $requestdata['expired_at']);
          }
          if (isset($requestdata['will_expire_at']) && $requestdata['will_expire_at'] != '') {
              $allJobs->where('will_expire_at', '>=', $requestdata['will_expire_at']);
          }
          if (isset($requestdata['customer_email']) && count($requestdata['customer_email']) && $requestdata['customer_email'] != '') {
              $users = DB::table('users')->whereIn('email', $requestdata['customer_email'])->get();
              if ($users) {
                  $allJobs->whereIn('user_id', collect($users)->pluck('id')->all());
              }
          }
          if (isset($requestdata['translator_email']) && count($requestdata['translator_email'])) {
              $users = DB::table('users')->whereIn('email', $requestdata['translator_email'])->get();
              if ($users) {
                  $allJobIDs = DB::table('translator_job_rel')->whereNull('cancel_at')->whereIn('user_id', collect($users)->pluck('id')->all())->lists('job_id');
                  $allJobs->whereIn('id', $allJobIDs);
              }
          }
          if (isset($requestdata['filter_timetype']) && $requestdata['filter_timetype'] == "created") {
              if (isset($requestdata['from']) && $requestdata['from'] != "") {
                  $allJobs->where('created_at', '>=', $requestdata["from"]);
              }
              if (isset($requestdata['to']) && $requestdata['to'] != "") {
                  $to = $requestdata["to"] . " 23:59:00";
                  $allJobs->where('created_at', '<=', $to);
              }
              $allJobs->orderBy('created_at', 'desc');
          }
          if (isset($requestdata['filter_timetype']) && $requestdata['filter_timetype'] == "due") {
              if (isset($requestdata['from']) && $requestdata['from'] != "") {
                  $allJobs->where('due', '>=', $requestdata["from"]);
              }
              if (isset($requestdata['to']) && $requestdata['to'] != "") {
                  $to = $requestdata["to"] . " 23:59:00";
                  $allJobs->where('due', '<=', $to);
              }
              $allJobs->orderBy('due', 'desc');
          }

          if (isset($requestdata['job_type']) && $requestdata['job_type'] != '') {
              $allJobs->whereIn('job_type', $requestdata['job_type']);
              /*$allJobs->where('jobs.job_type', '=', $requestdata['job_type']);*/
          }

          if (isset($requestdata['physical'])) {
              $allJobs->where('customer_physical_type', $requestdata['physical']);
              $allJobs->where('ignore_physical', 0);
          }

          if (isset($requestdata['phone'])) {
              $allJobs->where('customer_phone_type', $requestdata['phone']);
              if(isset($requestdata['physical']))
              $allJobs->where('ignore_physical_phone', 0);
          }

          if (isset($requestdata['flagged'])) {
              $allJobs->where('flagged', $requestdata['flagged']);
              $allJobs->where('ignore_flagged', 0);
          }

          if (isset($requestdata['distance']) && $requestdata['distance'] == 'empty') {
              $allJobs->whereDoesntHave('distance');
          }

          if(isset($requestdata['salary']) &&  $requestdata['salary'] == 'yes') {
              $allJobs->whereDoesntHave('user.salaries');
          }

          if (isset($requestdata['count']) && $requestdata['count'] == 'true') {
              $allJobs = $allJobs->count();

              return ['count' => $allJobs];
          }

          if (isset($requestdata['consumer_type']) && $requestdata['consumer_type'] != '') {
              $allJobs->whereHas('user.userMeta', function($q) use ($requestdata) {
                  $q->where('consumer_type', $requestdata['consumer_type']);
              });
          }

          if (isset($requestdata['booking_type'])) {
              if ($requestdata['booking_type'] == 'physical')
                  $allJobs->where('customer_physical_type', 'yes');
              if ($requestdata['booking_type'] == 'phone')
                  $allJobs->where('customer_phone_type', 'yes');
          }

          $allJobs->orderBy('created_at', 'desc');
          $allJobs->with('user', 'language', 'feedback.user', 'translatorJobRel.user', 'distance');
          if ($limit == 'all')
              $allJobs = $allJobs->get();
          else
              $allJobs = $allJobs->paginate(15);

      } else {

          $allJobs = Job::query();

          if (isset($requestdata['id']) && $requestdata['id'] != '') {
              $allJobs->where('id', $requestdata['id']);
              $requestdata = array_only($requestdata, ['id']);
          }

          if ($consumer_type == 'RWS') {
              $allJobs->where('job_type', '=', 'rws');
          } else {
              $allJobs->where('job_type', '=', 'unpaid');
          }
          if (isset($requestdata['feedback']) && $requestdata['feedback'] != 'false') {
              $allJobs->where('ignore_feedback', '0');
              $allJobs->whereHas('feedback', function($q) {
                  $q->where('rating', '<=', '3');
              });
              if(isset($requestdata['count']) && $requestdata['count'] != 'false') return ['count' => $allJobs->count()];
          }

          if (isset($requestdata['lang']) && $requestdata['lang'] != '') {
              $allJobs->whereIn('from_language_id', $requestdata['lang']);
          }
          if (isset($requestdata['status']) && $requestdata['status'] != '') {
              $allJobs->whereIn('status', $requestdata['status']);
          }
          if (isset($requestdata['job_type']) && $requestdata['job_type'] != '') {
              $allJobs->whereIn('job_type', $requestdata['job_type']);
          }
          if (isset($requestdata['customer_email']) && $requestdata['customer_email'] != '') {
              $user = DB::table('users')->where('email', $requestdata['customer_email'])->first();
              if ($user) {
                  $allJobs->where('user_id', '=', $user->id);
              }
          }
          if (isset($requestdata['filter_timetype']) && $requestdata['filter_timetype'] == "created") {
              if (isset($requestdata['from']) && $requestdata['from'] != "") {
                  $allJobs->where('created_at', '>=', $requestdata["from"]);
              }
              if (isset($requestdata['to']) && $requestdata['to'] != "") {
                  $to = $requestdata["to"] . " 23:59:00";
                  $allJobs->where('created_at', '<=', $to);
              }
              $allJobs->orderBy('created_at', 'desc');
          }
          if (isset($requestdata['filter_timetype']) && $requestdata['filter_timetype'] == "due") {
              if (isset($requestdata['from']) && $requestdata['from'] != "") {
                  $allJobs->where('due', '>=', $requestdata["from"]);
              }
              if (isset($requestdata['to']) && $requestdata['to'] != "") {
                  $to = $requestdata["to"] . " 23:59:00";
                  $allJobs->where('due', '<=', $to);
              }
              $allJobs->orderBy('due', 'desc');
          }

          $allJobs->orderBy('created_at', 'desc');
          $allJobs->with('user', 'language', 'feedback.user', 'translatorJobRel.user', 'distance');
          if ($limit == 'all')
              $allJobs = $allJobs->get();
          else
              $allJobs = $allJobs->paginate(15);

      }
      return $allJobs;
  }

}
