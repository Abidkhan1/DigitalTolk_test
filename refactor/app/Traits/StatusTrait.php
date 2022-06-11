<?php

namespace DTApi\Traits;

use DTApi\Models\Job;
use DTApi\Models\User;
TeHelper;
use Illuminate\Http\Request;

trait StatusTrait {
  private function changeStatus($job, $data, $changedTranslator)
  {
      $old_status = $job->status;
      $statusChanged = false;
      if ($old_status != $data['status']) {
          switch ($job->status) {
              case 'timedout':
                  $statusChanged = $this->changeTimedoutStatus($job, $data, $changedTranslator);
                  break;
              case 'completed':
                  $statusChanged = $this->changeCompletedStatus($job, $data);
                  break;
              case 'started':
                  $statusChanged = $this->changeStartedStatus($job, $data);
                  break;
              case 'pending':
                  $statusChanged = $this->changePendingStatus($job, $data, $changedTranslator);
                  break;
              case 'withdrawafter24':
                  $statusChanged = $this->changeWithdrawafter24Status($job, $data);
                  break;
              case 'assigned':
                  $statusChanged = $this->changeAssignedStatus($job, $data);
                  break;
              default:
                  $statusChanged = false;
                  break;
          }

          if ($statusChanged) {
              $log_data = [
                  'old_status' => $old_status,
                  'new_status' => $data['status']
              ];
              $statusChanged = true;
              return ['statusChanged' => $statusChanged, 'log_data' => $log_data];
          }
      }
  }

  private function changeTimedoutStatus($job, $data, $changedTranslator)
  {
//        if (in_array($data['status'], ['pending', 'assigned']) && date('Y-m-d H:i:s') <= $job->due) {
      $old_status = $job->status;
      $job->status = $data['status'];
      $user = $job->user()->first();
      if (!empty($job->user_email)) {
          $email = $job->user_email;
      } else {
          $email = $user->email;
      }
      $name = $user->name;
      $dataEmail = [
          'user' => $user,
          'job'  => $job
      ];
      if ($data['status'] == 'pending') {
          $job->created_at = date('Y-m-d H:i:s');
          $job->emailsent = 0;
          $job->emailsenttovirpal = 0;
          $job->save();
          $job_data = $this->jobToData($job);

          $subject = 'Vi har nu återöppnat er bokning av ' . TeHelper::fetchLanguageFromJobId($job->from_language_id) . 'tolk för bokning #' . $job->id;
          $this->mailer->send($email, $name, $subject, 'emails.job-change-status-to-customer', $dataEmail);

          $this->sendNotificationTranslator($job, $job_data, '*');   // send Push all sutiable translators

          return true;
      } elseif ($changedTranslator) {
          $job->save();
          $subject = 'Bekräftelse - tolk har accepterat er bokning (bokning # ' . $job->id . ')';
          $this->mailer->send($email, $name, $subject, 'emails.job-accepted', $dataEmail);
          return true;
      }
      return false;
  }

  private function changeCompletedStatus($job, $data)
  {
//        if (in_array($data['status'], ['withdrawnbefore24', 'withdrawafter24', 'timedout'])) {
      $job->status = $data['status'];
      if ($data['status'] == 'timedout') {
          if ($data['admin_comments'] == '') return false;
          $job->admin_comments = $data['admin_comments'];
      }
      $job->save();
      return true;
//        }
      return false;
  }

  /**
   * @param $job
   * @param $data
   * @return bool
   */
  private function changeStartedStatus($job, $data)
  {
//        if (in_array($data['status'], ['withdrawnbefore24', 'withdrawafter24', 'timedout', 'completed'])) {
      $job->status = $data['status'];
      if ($data['admin_comments'] == '') return false;
      $job->admin_comments = $data['admin_comments'];
      if ($data['status'] == 'completed') {
          $user = $job->user()->first();
          if ($data['sesion_time'] == '') return false;
          $interval = $data['sesion_time'];
          $diff = explode(':', $interval);
          $job->end_at = date('Y-m-d H:i:s');
          $job->session_time = $interval;
          $session_time = $diff[0] . ' tim ' . $diff[1] . ' min';
          if (!empty($job->user_email)) {
              $email = $job->user_email;
          } else {
              $email = $user->email;
          }
          $name = $user->name;
          $dataEmail = [
              'user'         => $user,
              'job'          => $job,
              'session_time' => $session_time,
              'for_text'     => 'faktura'
          ];

          $subject = 'Information om avslutad tolkning för bokningsnummer #' . $job->id;
          $this->mailer->send($email, $name, $subject, 'emails.session-ended', $dataEmail);

          $user = $job->translatorJobRel->where('completed_at', Null)->where('cancel_at', Null)->first();

          $email = $user->user->email;
          $name = $user->user->name;
          $subject = 'Information om avslutad tolkning för bokningsnummer # ' . $job->id;
          $dataEmail = [
              'user'         => $user,
              'job'          => $job,
              'session_time' => $session_time,
              'for_text'     => 'lön'
          ];
          $this->mailer->send($email, $name, $subject, 'emails.session-ended', $dataEmail);

      }
      $job->save();
      return true;
//        }
      return false;
  }

  /**
   * @param $job
   * @param $data
   * @param $changedTranslator
   * @return bool
   */
  private function changePendingStatus($job, $data, $changedTranslator)
  {
//        if (in_array($data['status'], ['withdrawnbefore24', 'withdrawafter24', 'timedout', 'assigned'])) {
      $job->status = $data['status'];
      if ($data['admin_comments'] == '' && $data['status'] == 'timedout') return false;
      $job->admin_comments = $data['admin_comments'];
      $user = $job->user()->first();
      if (!empty($job->user_email)) {
          $email = $job->user_email;
      } else {
          $email = $user->email;
      }
      $name = $user->name;
      $dataEmail = [
          'user' => $user,
          'job'  => $job
      ];

      if ($data['status'] == 'assigned' && $changedTranslator) {

          $job->save();
          $job_data = $this->jobToData($job);

          $subject = 'Bekräftelse - tolk har accepterat er bokning (bokning # ' . $job->id . ')';
          $this->mailer->send($email, $name, $subject, 'emails.job-accepted', $dataEmail);

          $translator = Job::getJobsAssignedTranslatorDetail($job);
          $this->mailer->send($translator->email, $translator->name, $subject, 'emails.job-changed-translator-new-translator', $dataEmail);

          $language = TeHelper::fetchLanguageFromJobId($job->from_language_id);

          $this->sendSessionStartRemindNotification($user, $job, $language, $job->due, $job->duration);
          $this->sendSessionStartRemindNotification($translator, $job, $language, $job->due, $job->duration);
          return true;
      } else {
          $subject = 'Avbokning av bokningsnr: #' . $job->id;
          $this->mailer->send($email, $name, $subject, 'emails.status-changed-from-pending-or-assigned-customer', $dataEmail);
          $job->save();
          return true;
      }
      return false;
  }

  private function changeWithdrawafter24Status($job, $data)
  {
      if (in_array($data['status'], ['timedout'])) {
          $job->status = $data['status'];
          if ($data['admin_comments'] == '') return false;
          $job->admin_comments = $data['admin_comments'];
          $job->save();
          return true;
      }
      return false;
  }

  private function changeAssignedStatus($job, $data)
  {
      if (in_array($data['status'], ['withdrawbefore24', 'withdrawafter24', 'timedout'])) {
          $job->status = $data['status'];
          if ($data['admin_comments'] == '' && $data['status'] == 'timedout') return false;
          $job->admin_comments = $data['admin_comments'];
          if (in_array($data['status'], ['withdrawbefore24', 'withdrawafter24'])) {
              $user = $job->user()->first();

              if (!empty($job->user_email)) {
                  $email = $job->user_email;
              } else {
                  $email = $user->email;
              }
              $name = $user->name;
              $dataEmail = [
                  'user' => $user,
                  'job'  => $job
              ];

              $subject = 'Information om avslutad tolkning för bokningsnummer #' . $job->id;
              $this->mailer->send($email, $name, $subject, 'emails.status-changed-from-pending-or-assigned-customer', $dataEmail);

              $user = $job->translatorJobRel->where('completed_at', Null)->where('cancel_at', Null)->first();

              $email = $user->user->email;
              $name = $user->user->name;
              $subject = 'Information om avslutad tolkning för bokningsnummer # ' . $job->id;
              $dataEmail = [
                  'user' => $user,
                  'job'  => $job
              ];
              $this->mailer->send($email, $name, $subject, 'emails.job-cancel-translator', $dataEmail);
          }
          $job->save();
          return true;
      }
      return false;
  }
}
