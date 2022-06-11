<?php

namespace DTApi\Traits;

use DTApi\Models\Job;
use DTApi\Models\User;
use DTApi\Models\UsersBlacklist;
use Carbon\Carbon;
use DTApi\Models\Translator;
use Illuminate\Http\Request;

trait TranslatorTrait {

     public function getPotentialTranslators(Job $job)
     {
         $job_type = $job->job_type;

         if ($job_type == 'paid')
             $translator_type = 'professional';
         else if ($job_type == 'rws')
             $translator_type = 'rwstranslator';
         else if ($job_type == 'unpaid')
             $translator_type = 'volunteer';

         $joblanguage = $job->from_language_id;
         $gender = $job->gender;
         $translator_level = [];
         if (!empty($job->certified)) {
             if ($job->certified == 'yes' || $job->certified == 'both') {
                 $translator_level[] = 'Certified';
                 $translator_level[] = 'Certified with specialisation in law';
                 $translator_level[] = 'Certified with specialisation in health care';
             }
             elseif($job->certified == 'law' || $job->certified == 'n_law')
             {
                 $translator_level[] = 'Certified with specialisation in law';
             }
             elseif($job->certified == 'health' || $job->certified == 'n_health')
             {
                 $translator_level[] = 'Certified with specialisation in health care';
             }
             else if ($job->certified == 'normal' || $job->certified == 'both') {
                 $translator_level[] = 'Layman';
                 $translator_level[] = 'Read Translation courses';
             }
             elseif ($job->certified == null) {
                 $translator_level[] = 'Certified';
                 $translator_level[] = 'Certified with specialisation in law';
                 $translator_level[] = 'Certified with specialisation in health care';
                 $translator_level[] = 'Layman';
                 $translator_level[] = 'Read Translation courses';
             }
         }

         $blacklist = UsersBlacklist::where('user_id', $job->user_id)->get();
         $translatorsId = collect($blacklist)->pluck('translator_id')->all();
         $users = User::getPotentialUsers($translator_type, $joblanguage, $gender, $translator_level, $translatorsId);

         return $users;
     }

     private function changeTranslator($current_translator, $data, $job)
     {
         $translatorChanged = false;

         if (!is_null($current_translator) || (isset($data['translator']) && $data['translator'] != 0) || $data['translator_email'] != '') {
             $log_data = [];
             if (!is_null($current_translator) && ((isset($data['translator']) && $current_translator->user_id != $data['translator']) || $data['translator_email'] != '') && (isset($data['translator']) && $data['translator'] != 0)) {
                 if ($data['translator_email'] != '') $data['translator'] = User::where('email', $data['translator_email'])->first()->id;
                 $new_translator = $current_translator->toArray();
                 $new_translator['user_id'] = $data['translator'];
                 unset($new_translator['id']);
                 $new_translator = Translator::create($new_translator);
                 $current_translator->cancel_at = Carbon::now();
                 $current_translator->save();
                 $log_data[] = [
                     'old_translator' => $current_translator->user->email,
                     'new_translator' => $new_translator->user->email
                 ];
                 $translatorChanged = true;
             } elseif (is_null($current_translator) && isset($data['translator']) && ($data['translator'] != 0 || $data['translator_email'] != '')) {
                 if ($data['translator_email'] != '') $data['translator'] = User::where('email', $data['translator_email'])->first()->id;
                 $new_translator = Translator::create(['user_id' => $data['translator'], 'job_id' => $job->id]);
                 $log_data[] = [
                     'old_translator' => null,
                     'new_translator' => $new_translator->user->email
                 ];
                 $translatorChanged = true;
             }
             if ($translatorChanged)
                 return ['translatorChanged' => $translatorChanged, 'new_translator' => $new_translator, 'log_data' => $log_data];

         }

         return ['translatorChanged' => $translatorChanged];
     }
}
