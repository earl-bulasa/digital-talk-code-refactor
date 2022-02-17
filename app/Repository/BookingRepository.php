<?php

namespace DTApi\Repository;

use DTApi\Events\SessionEnded;
use DTApi\Helpers\SendSMSHelper;
use Event;
use Carbon\Carbon;
use Monolog\Logger;
use DTApi\Models\Job;
use DTApi\Models\User;
use DTApi\Models\Language;
use DTApi\Models\UserMeta;
use DTApi\Helpers\TeHelper;
use Illuminate\Http\Request;
use DTApi\Models\Translator;
use DTApi\Mailers\AppMailer;
use DTApi\Models\UserLanguages;
use DTApi\Events\JobWasCreated;
use DTApi\Events\JobWasCanceled;
use DTApi\Models\UsersBlacklist;
use DTApi\Helpers\DateTimeHelper;
use DTApi\Mailers\MailerInterface;
use Illuminate\Support\Facades\DB;
use Monolog\Handler\StreamHandler;
use Illuminate\Support\Facades\Log;
use Monolog\Handler\FirePHPHandler;
use Illuminate\Support\Facades\Auth;

/**
 * Class BookingRepository
 * @package DTApi\Repository
 */
class BookingRepository extends BaseRepository
{

    protected $model;
    protected $mailer;
    protected $logger;

    /**
     * @param Job $model
     */
    function __construct(Job $model, MailerInterface $mailer)
    {
        parent::__construct($model);
        $this->mailer = $mailer;
        $this->logger = new Logger('admin_logger');

        $this->logger->pushHandler(new StreamHandler(storage_path('logs/admin/laravel-' . date('Y-m-d') . '.log'), Logger::DEBUG));
        $this->logger->pushHandler(new FirePHPHandler());
    }

    /**
     * @param $userId
     * @return array
     */
    public function getUsersJobs($userId)
    {
        $user = User::find($userId);
        $userType = '';
        $emergencyJobs = array();
        $normalJobs = array();
        if ($user && $user->is('customer')) {
            $jobs = $user->jobs()->with('user.userMeta', 'user.average', 'translatorJobRel.user.average', 'language', 'feedback')->whereIn('status', ['pending', 'assigned', 'started'])->orderBy('due', 'asc')->get();
            $userType = 'customer';
        } elseif ($user && $user->is('translator')) {
            $jobs = Job::getTranslatorJobs($user->id, 'new');
            $jobs = $jobs->pluck('jobs')->all();
            $userType = 'translator';
        }
        if ($jobs) {
            foreach ($jobs as $jobitem) {
                if ($jobitem->immediate == 'yes') {
                    $emergencyJobs[] = $jobitem;
                } else {
                    $normalJobs[] = $jobitem;
                }
            }
            $normalJobs = collect($normalJobs)->each(function ($item, $key) use ($userId) {
                $item['usercheck'] = Job::checkParticularJob($userId, $item);
            })->sortBy('due')->all();
        }

        return ['emergencyJobs' => $emergencyJobs, 'normalJobs' => $normalJobs, 'cuser' => $user, 'userType' => $userType];
    }

    /**
     * @param $userId
     * @return array
     */
    public function getUsersJobsHistory($userId, Request $request)
    {
        $page = $request->get('page');
        if (isset($page)) {
            $pagenum = $page;
        } else {
            $pagenum = "1";
        }
        $user = User::find($userId);
        $userType = '';
        $emergencyJobs = array();
        $normalJobs = array();
        if ($user && $user->is('customer')) {
            $jobs = $user->jobs()->with('user.userMeta', 'user.average', 'translatorJobRel.user.average', 'language', 'feedback', 'distance')->whereIn('status', ['completed', 'withdrawbefore24', 'withdrawafter24', 'timedout'])->orderBy('due', 'desc')->paginate(15);
            $userType = 'customer';
            return ['emergencyJobs' => $emergencyJobs, 'normalJobs' => [], 'jobs' => $jobs, 'user' => $user, 'userType' => $userType, 'numpages' => 0, 'pagenum' => 0];
        } elseif ($user && $user->is('translator')) {
            $jobsIds = Job::getTranslatorJobsHistoric($user->id, 'historic', $pagenum);
            $totaljobs = $jobsIds->total();
            $numpages = ceil($totaljobs / 15);

            $userType = 'translator';

            // $jobs = $jobsIds;
            // $normalJobs = $jobsIds;
//            $jobs['data'] = $normalJobs;
//            $jobs['total'] = $totaljobs;
            return ['emergencyJobs' => $emergencyJobs, 'normalJobs' => $jobsIds, 'jobs' => $jobsIds, 'user' => $user, 'userType' => $userType, 'numpages' => $numpages, 'pagenum' => $pagenum];
        }
    }

    /**
     * @param $user
     * @param $data
     * @return mixed
     */
    public function store($user, $data)
    {
        $immediatetime = 5;
        $consumer_type = $user->userMeta->consumer_type;
        if ($user->user_type == config('user_role.customer_role_id')) {
            $user = $user;

            if (!isset($data['from_language_id'])) {
                $response['status'] = 'fail';
                $response['message'] = "Du måste fylla in alla fält";
                $response['field_name'] = "from_language_id";
                return $response;
            }
            if ($data['immediate'] == 'no') {
                if (isset($data['due_date']) && $data['due_date'] == '') {
                    $response['status'] = 'fail';
                    $response['message'] = "Du måste fylla in alla fält";
                    $response['field_name'] = "due_date";
                    return $response;
                }
                if (isset($data['due_time']) && $data['due_time'] == '') {
                    $response['status'] = 'fail';
                    $response['message'] = "Du måste fylla in alla fält";
                    $response['field_name'] = "due_time";
                    return $response;
                }
                if (!isset($data['customer_phone_type']) && !isset($data['customer_physical_type'])) {
                    $response['status'] = 'fail';
                    $response['message'] = "Du måste göra ett val här";
                    $response['field_name'] = "customer_phone_type";
                    return $response;
                }
                if (isset($data['duration']) && $data['duration'] == '') {
                    $response['status'] = 'fail';
                    $response['message'] = "Du måste fylla in alla fält";
                    $response['field_name'] = "duration";
                    return $response;
                }
            } else {
                if (isset($data['duration']) && $data['duration'] == '') {
                    $response['status'] = 'fail';
                    $response['message'] = "Du måste fylla in alla fält";
                    $response['field_name'] = "duration";
                    return $response;
                }
            }
            if (isset($data['customer_phone_type'])) {
                $data['customer_phone_type'] = 'yes';
            } else {
                $data['customer_phone_type'] = 'no';
            }

            if (isset($data['customer_physical_type'])) {
                $data['customer_physical_type'] = 'yes';
                $response['customer_physical_type'] = 'yes';
            } else {
                $data['customer_physical_type'] = 'no';
                $response['customer_physical_type'] = 'no';
            }

            if ($data['immediate'] == 'yes') {
                $due_carbon = Carbon::now()->addMinute($immediatetime);
                $data['due'] = $due_carbon->format('Y-m-d H:i:s');
                $data['immediate'] = 'yes';
                $data['customer_phone_type'] = 'yes';
                $response['type'] = 'immediate';

            } else {
                $due = $data['due_date'] . " " . $data['due_time'];
                $response['type'] = 'regular';
                $dueCarbon = Carbon::createFromFormat('m/d/Y H:i', $due);
                $data['due'] = $dueCarbon->format('Y-m-d H:i:s');
                if ($dueCarbon->isPast()) {
                    $response['status'] = 'fail';
                    $response['message'] = "Can't create booking in past";
                    return $response;
                }
            }
            if (in_array('male', $data['job_for'])) {
                $data['gender'] = 'male';
            } else if (in_array('female', $data['job_for'])) {
                $data['gender'] = 'female';
            }
            if (in_array('normal', $data['job_for'])) {
                $data['certified'] = 'normal';
            }
            else if (in_array('certified', $data['job_for'])) {
                $data['certified'] = 'yes';
            } else if (in_array('certified_in_law', $data['job_for'])) {
                $data['certified'] = 'law';
            } else if (in_array('certified_in_helth', $data['job_for'])) {
                $data['certified'] = 'health';
            }
            if (in_array('normal', $data['job_for']) && in_array('certified', $data['job_for'])) {
                $data['certified'] = 'both';
            }
            else if(in_array('normal', $data['job_for']) && in_array('certified_in_law', $data['job_for']))
            {
                $data['certified'] = 'n_law';
            }
            else if(in_array('normal', $data['job_for']) && in_array('certified_in_helth', $data['job_for']))
            {
                $data['certified'] = 'n_health';
            }
            if ($consumerType == 'rwsconsumer')
                $data['job_type'] = 'rws';
            else if ($consumerType == 'ngo')
                $data['job_type'] = 'unpaid';
            else if ($consumerType == 'paid')
                $data['job_type'] = 'paid';
            $data['b_created_at'] = date('Y-m-d H:i:s');
            if (isset($due))
                $data['will_expire_at'] = TeHelper::willExpireAt($due, $data['b_created_at']);
            $data['by_admin'] = isset($data['by_admin']) ? $data['by_admin'] : 'no';

            $job = $user->jobs()->create($data);

            $response['status'] = 'success';
            $response['id'] = $job->id;
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
                    $data['job_for'][] = 'normal';
                    $data['job_for'][] = 'certified';
                } else if ($job->certified == 'yes') {
                    $data['job_for'][] = 'certified';
                } else {
                    $data['job_for'][] = $job->certified;
                }
            }

            $data['customer_town'] = $user->userMeta->city;
            $data['customer_type'] = $user->userMeta->customer_type;

            //Event::fire(new JobWasCreated($job, $data, '*'));

//            $this->sendNotificationToSuitableTranslators($job->id, $data, '*');// send Push for New job posting
        } else {
            $response['status'] = 'fail';
            $response['message'] = "Translator can not create booking";
        }

        return $response;

    }

    /**
     * @param $data
     * @return mixed
     */
    public function storeJobEmail($data)
    {
        $userType = $data['user_type'];
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

        $email = !empty($job->user_email) ? $job->user_email : $user->email;
        $name = $user->name;

        $subject = 'Vi har mottagit er tolkbokning. Bokningsnr: #' . $job->id;
        $sendData = [
            'user' => $user,
            'job'  => $job
        ];
        $this->mailer->send($email, $name, $subject, 'emails.job-created', $send_data);

        $response['type'] = $userType;
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

        $jobDue = explode(" ", $job->due);
        $data['due_date'] = $jobDue[0];
        $data['due_time'] = $jobDue[1];


        $data['job_for'] = array();
        if ($job->gender != null) {
            $data['job_for'][] = $job->gender == 'male' ? 'Man' : 'Kvinna';
        }
        if ($job->certified != null) {
            switch($job->certified) {
                case "both":
                    $data['job_for'][] = 'Godkänd tolk';
                    $data['job_for'][] = 'Auktoriserad';
                    break;
                case "yes":
                    $data['job_for'][] = 'Auktoriserad';
                    break;
                case "n_health":
                    $data['job_for'][] = 'Sjukvårdstolk';
                    break;
                case "law":
                case "n_law":
                    $data['job_for'][] = 'Rätttstolk';
                    break;
                default:
                    $data['job_for'][] = $job->certified;     
    
            }
        }

        return $data;

    }

    /**
     * @param array $post_data
     */
    public function jobEnd($postData = array())
    {
        $job = Job::with('translatorJobRel')->find($jobid);
        $this->updateJobDetails($job, 'completed');

        $user = $job->user()->get()->first();
        $email = !empty($job->user_email) ? $job->user_email : $user->email;

        $translator = $this->updateTranslator($job);
        $user = $translator->user()->first();
        $this->sendSessionEndedMail($user, $job, 'faktura');

        Event::fire(new SessionEnded($job, ($postData['userid'] == $job->userId) ? $tr->userId : $job->userId));

 
    }

    /**
     * Function to get all Potential jobs of user with his ID
     * @param $userId
     * @return array
     */
    public function getPotentialJobIdsWithUserId($userId)
    {
        $userMeta = UserMeta::where('userId', $userId)->first();
        $jobs = $this->getJobs($userMeta);
        foreach ($jobs as $i => $job)     // checking translator town
        {
            $jobUserid = $job->userId;
            $checktown = Job::checkTowns($jobUserId, $userId);
            if (($job->customer_phone_type == 'no' || $job->customer_phone_type == '') && $job->customer_physical_type == 'yes' && $checkTown == false) {
                unset($jobIds[$i]);
            }
        }
        $jobs = TeHelper::convertJobIdsInObjs($jobIds);
        return $jobs;
    }

    /**
     * @param $job
     * @param array $data
     * @param $exclude_user_id
     */
    public function sendNotificationTranslator($job, $data = [], $excludeUserId)
    {
        $users = User::all();
        $translatorArray = array();            // suitable translators (no need to delay push)
        $delpayTranslatorArray = array();     // suitable translators (need to delay push)

        foreach ($users as $oneUser) {
            if ($oneUser->user_type == '2' && $oneUser->status == '1' && $oneUser->id != $excludeUserId) { // user is translator and he is not disabled
                if (!$this->isNeedToSendPush($oneUser->id)) continue;
                $notGetEmergency = TeHelper::getUsermeta($oneUser->id, 'not_get_emergency');
                if ($data['immediate'] == 'yes' && $notGetEmergency == 'yes') continue;
                $jobs = $this->getPotentialJobIdsWithUserId($oneUser->id); // get all potential jobs of this user
                foreach ($jobs as $oneJob) {
                    if ($job->id == $oneJob->id) { // one potential job is the same with current job
                        $userId = $oneUser->id;
                        $jobForTranslator = Job::assignedToPaticularTranslator($userId, $oneJob->id);
                        if ($jobForTranslator == 'SpecificJob') {
                            $jobChecker = Job::checkParticularJob($userId, $oneJob);
                            if (($jobChecker != 'userCanNotAcceptJob')) {
                                if ($this->isNeedToDelayPush($oneUser->id)) {
                                    $delpayTranslatorArray[] = $oneUser;
                                } else {
                                    $translatorArray[] = $oneUser;
                                }
                            }
                        }
                    }
                }
            }
        }
        $data['language'] = TeHelper::fetchLanguageFromJobId($data['from_language_id']);
        $data['notification_type'] = 'suitable_job';
        $msgContents = '';
        if ($data['immediate'] == 'no') {
            $msgContents = 'Ny bokning för ' . $data['language'] . 'tolk ' . $data['duration'] . 'min ' . $data['due'];
        } else {
            $msgContents = 'Ny akutbokning för ' . $data['language'] . 'tolk ' . $data['duration'] . 'min';
        }
        $msgText = array(
            "en" => $msgContents
        );

        $logger = new Logger('push_logger');

        $logger->pushHandler(new StreamHandler(storage_path('logs/push/laravel-' . date('Y-m-d') . '.log'), Logger::DEBUG));
        $logger->pushHandler(new FirePHPHandler());
        $logger->addInfo('Push send for job ' . $job->id, [$translatorArray, $delpayTranslatorArray, $msgText, $data]);
        $this->sendPushNotificationToSpecificUsers($translatorArray, $job->id, $data, $msgText, false);       // send new booking push to suitable translators(not delay)
        $this->sendPushNotificationToSpecificUsers($delpayTranslatorArray, $job->id, $data, $msgText, true); // send new booking push to suitable translators(need to delay)
    }

    /**
     * Sends SMS to translators and retuns count of translators
     * @param $job
     * @return int
     */
    public function sendSMSNotificationToTranslator($job)
    {
        $translators = $this->getPotentialTranslators($job);
        $jobPosterMeta = UserMeta::where('userId', $job->userId)->first();

        // prepare message templates
        $date = date('d.m.Y', strtotime($job->due));
        $time = date('H:i', strtotime($job->due));
        $duration = $this->convertToHoursMins($job->duration);
        $jobId = $job->id;
        $city = $job->city ? $job->city : $jobPosterMeta->city;

        $phoneJobMessageTemplate = trans('sms.phone_job', ['date' => $date, 'time' => $time, 'duration' => $duration, 'jobId' => $jobId]);

        $physicalJobMessageTemplate = trans('sms.physical_job', ['date' => $date, 'time' => $time, 'town' => $city, 'duration' => $duration, 'jobId' => $jobId]);

        // analyse weather it's phone or physical; if both = default to phone
        if ($job->customer_physical_type == 'yes' && $job->customer_phone_type == 'no') {
            // It's a physical job
            $message = $physicalJobMessageTemplate;
        } else if ($job->customer_physical_type == 'no' && $job->customer_phone_type == 'yes') {
            // It's a phone job
            $message = $phoneJobMessageTemplate;
        } else if ($job->customer_physical_type == 'yes' && $job->customer_phone_type == 'yes') {
            // It's both, but should be handled as phone job
            $message = $phoneJobMessageTemplate;
        } else {
            // This shouldn't be feasible, so no handling of this edge case
            $message = '';
        }
        Log::info($message);

        // send messages via sms handler
        foreach ($translators as $translator) {
            // send message to translator
            $status = SendSMSHelper::send(env('SMS_NUMBER'), $translator->mobile, $message);
            Log::info('Send SMS to ' . $translator->email . ' (' . $translator->mobile . '), status: ' . print_r($status, true));
        }

        return count($translators);
    }

    /**
     * Function to delay the push
     * @param $userId
     * @return bool
     */
    public function isNeedToDelayPush($userId)
    {
        if (!DateTimeHelper::isNightTime()) return false;
        $notGetNighttime = TeHelper::getUsermeta($userId, 'not_get_nighttime');
        return $notGetNighttime == 'yes';
    }

    /**
     * Function to check if need to send the push
     * @param $userId
     * @return bool
     */
    public function isNeedToSendPush($userId)
    {
        $notGetNotification = TeHelper::getUsermeta($userId, 'not_get_notification');
        return  !$notGetNotification == 'yes';
    }

    /**
     * Function to send Onesignal Push Notifications with User-Tags
     * @param $users
     * @param $job_id
     * @param $data
     * @param $msg_text
     * @param $is_need_delay
     */
    public function sendPushNotificationToSpecificUsers($users, $jobId, $data, $msgText, $isNeedDelay)
    {

        $logger = new Logger('push_logger');

        $logger->pushHandler(new StreamHandler(storage_path('logs/push/laravel-' . date('Y-m-d') . '.log'), Logger::DEBUG));
        $logger->pushHandler(new FirePHPHandler());
        $logger->addInfo('Push send for job ' . $jobId, [$users, $data, $msgText, $isNeedDelay]);
        if (config('app.env') == 'production') {
            $onesignalAppID = config('app.prodOnesignalAppID');
            $onesignalRestAuthKey = sprintf("Authorization: Basic %s", config('app.prodOnesignalApiKey'));
        } else {
            $onesignalAppID = config('app.devOnesignalAppID');
            $onesignalRestAuthKey = sprintf("Authorization: Basic %s", config('app.devOnesignalApiKey'));
        }

        $userTags = $this->getUserTagsStringFromArray($users);

        $data['job_id'] = $jobId;
        $iosSound = 'default';
        $androidSound = 'default';

        if ($data['notification_type'] == 'suitable_job') {
            if ($data['immediate'] == 'no') {
                $androidSound = 'normal_booking';
                $iosSound = 'normal_booking.mp3';
            } else {
                $androidSound = 'emergency_booking';
                $iosSound = 'emergency_booking.mp3';
            }
        }

        $fields = array(
            'app_id'         => $onesignalAppID,
            'tags'           => json_decode($userTags),
            'data'           => $data,
            'title'          => array('en' => 'DigitalTolk'),
            'contents'       => $msgText,
            'ios_badgeType'  => 'Increase',
            'ios_badgeCount' => 1,
            'android_sound'  => $androidSound,
            'ios_sound'      => $iosSound
        );
        if ($isNeedDelay) {
            $fields['send_after'] = DateTimeHelper::getNextBusinessTimeString();
        }
        $fields = json_encode($fields);
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, "https://onesignal.com/api/v1/notifications");
        curl_setopt($ch, CURLOPT_HTTPHEADER, array('Content-Type: application/json', $onesignalRestAuthKey));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, TRUE);
        curl_setopt($ch, CURLOPT_HEADER, FALSE);
        curl_setopt($ch, CURLOPT_POST, TRUE);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $fields);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, FALSE);
        $response = curl_exec($ch);
        $logger->addInfo('Push send for job ' . $job_id . ' curl answer', [$response]);
        curl_close($ch);
    }

    private function getTranslatorType($jobType)
    {
        $translatorType = '';
        switch($job->job_type) {
            case "paid":
                $translatorType = 'professional';
                break;
            case "rws":
                $translatorType = 'rwstranslator';
                break;
            default:
                $translatorType = 'volunteer';
        }
        return $translatorType;
    }

    /**
     * @param Job $job
     * @return mixed
     */
    public function getPotentialTranslators(Job $job)
    {

        // $jobType = $job->job_type;
        $translatorType = $this->getTranslatorType($job->job_type);
        

        $jobLanguage = $job->from_language_id;
        $gender = $job->gender;
        $translatorLevel = [];
        if (!empty($job->certified)) {
            switch($job->certified) {
                case "yes":
                case "no":
                    $translatorLevel[] = 'Certified';
                    $translatorLevel[] = 'Certified with specialisation in law';
                    $translatorLevel[] = 'Certified with specialisation in health care';
                    break;
                case "law":
                case "n_law":
                    $translatorLevel[] = 'Certified with specialisation in law';
                    break;
                case "health":
                case "n_health":
                    $translatorLevel[] = 'Certified with specialisation in health care';
                    break;
                case "normal":
                case "both":
                    $translatorLevel[] = 'Layman';
                    $translatorLevel[] = 'Read Translation courses';
                    break;
                default:
                    $translatorLevel[] = 'Certified';
                    $translatorLevel[] = 'Certified with specialisation in law';
                    $translatorLevel[] = 'Certified with specialisation in health care';
                    $translatorLevel[] = 'Layman';
                    $translatorLevel[] = 'Read Translation courses';    
            }
        }

        $blackList = UsersBlacklist::where('userId', $job->userId)->get();
        $translatorsId = collect($blacklist)->pluck('translator_id')->all();
        $users = User::getPotentialUsers($translatorType, $jobLanguage, $gender, $translatorLevel, $translatorsId);

//        foreach ($job_ids as $k => $v)     // checking translator town
//        {
//            $job = Job::find($v->id);
//            $jobuserid = $job->userId;
//            $checktown = Job::checkTowns($jobuserid, $userId);
//            if (($job->customer_phone_type == 'no' || $job->customer_phone_type == '') && $job->customer_physical_type == 'yes' && $checktown == false) {
//                unset($job_ids[$k]);
//            }
//        }
//        $jobs = TeHelper::convertJobIdsInObjs($job_ids);
        return $users;

    }

    /**
     * @param $id
     * @param $data
     * @return mixed
     */
    public function updateJob($id, $data, $user)
    {
        $job = Job::find($id);

        $currentTranslator = $job->translatorJobRel->where('cancel_at', Null)->first();
        if (is_null($current_translator))
            $currentTranslator = $job->translatorJobRel->where('completed_at', '!=', Null)->first();

        $logData = [];

        $langChanged = false;

        $changeTranslator = $this->changeTranslator($currentTranslator, $data, $job);
        if ($changeTranslator['translatorChanged']) $log_data[] = $changeTranslator['log_data'];

        $changeDue = $this->changeDue($job->due, $data['due']);
        if ($changeDue['dateChanged']) {
            $oldTime = $job->due;
            $job->due = $data['due'];
            $logData[] = $changeDue['log_data'];
        }

        if ($job->from_language_id != $data['from_language_id']) {
            $logData[] = [
                'old_lang' => TeHelper::fetchLanguageFromJobId($job->from_language_id),
                'new_lang' => TeHelper::fetchLanguageFromJobId($data['from_language_id'])
            ];
            $oldLang = $job->from_language_id;
            $job->from_language_id = $data['from_language_id'];
            $langChanged = true;
        }

        $changeStatus = $this->changeStatus($job, $data, $changeTranslator['translatorChanged']);
        if ($changeStatus['statusChanged'])
            $logData[] = $changeStatus['log_data'];

        $job->admin_comments = $data['admin_comments'];

        $this->logger->addInfo('USER #' . $user->id . '(' . $user->name . ')' . ' has been updated booking <a class="openjob" href="/admin/jobs/' . $id . '">#' . $id . '</a> with data:  ', $log_data);

        $job->reference = $data['reference'];

        $job->save();

        if ($job->due <= Carbon::now()) {
            return ['Updated'];
        } else {
            if ($changeDue['dateChanged']) $this->sendChangedDateNotification($job, $oldTime);
            if ($changeTranslator['translatorChanged']) $this->sendChangedTranslatorNotification($job, $currentTranslator, $changeTranslator['new_translator']);
            if ($langChanged) $this->sendChangedLangNotification($job, $oldLang);
        }
    }

    /**
     * @param $job
     * @param $data
     * @param $changedTranslator
     * @return array
     */
    private function changeStatus($job, $data, $changedTranslator)
    {
        $oldStatus = $job->status;
        $statusChanged = false;
        if ($oldStatus != $data['status']) {
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
                $logData = [
                    'old_status' => $oldStatus,
                    'new_status' => $data['status']
                ];
                $statusChanged = true;
                return ['statusChanged' => $statusChanged, 'log_data' => $logData];
            }
        }
    }

    /**
     * @param $job
     * @param $data
     * @param $changedTranslator
     * @return bool
     */
    private function changeTimedoutStatus($job, $data, $changedTranslator)
    {
//        if (in_array($data['status'], ['pending', 'assigned']) && date('Y-m-d H:i:s') <= $job->due) {
        $oldStatus = $job->status;
        $job->status = $data['status'];
        $user = $job->user()->first();
        $email = !empty($job->user_email) ? $job->user_email : $user->email;
        $name = $user->name;
        $dataEmail = [
            'user' => $user,
            'job'  => $job
        ];
        if ($data['status'] == 'pending') {
            $job->created_at = date('Y-m-d H:i:s');
            $job->emailsent = 0;
            $job->emailsenttovirpal = 0;
            $job_data = $this->jobToData($job);

            $subject = 'Vi har nu återöppnat er bokning av ' . TeHelper::fetchLanguageFromJobId($job->from_language_id) . 'tolk för bokning #' . $job->id;
            $this->mailer->send($email, $name, $subject, 'emails.job-change-status-to-customer', $dataEmail);

            $this->sendNotificationTranslator($job, $job_data, '*');   // send Push all sutiable translators

        } elseif ($changedTranslator) {
            $subject = 'Bekräftelse - tolk har accepterat er bokning (bokning # ' . $job->id . ')';
            $this->mailer->send($email, $name, $subject, 'emails.job-accepted', $dataEmail);

        }
        return $job->save();

    }

    /**
     * @param $job
     * @param $data
     * @return bool
     */
    private function changeCompletedStatus($job, $data)
    {
        if ($data['admin_comments'] == '') 
            return false;
        $job->status = $data['status'];
        if ($data['status'] == 'timedout') {
            $job->admin_comments = $data['admin_comments'];
        }
        return $job->save();
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
        if ($data['admin_comments'] == '' || $data['sesion_time'] == '') 
            return false;
        $job->admin_comments = $data['admin_comments'];
        if ($data['status'] == 'completed') {
            $user = $job->user()->first();
            $interval = $data['sesion_time'];
            $diff = explode(':', $interval);
            $job->end_at = date('Y-m-d H:i:s');
            $job->session_time = $interval;
            $session_time = $diff[0] . ' tim ' . $diff[1] . ' min';

            $email = !empty($job->user_email) ? $job->user_email : $user->email;
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

            $this->sendSessionEndedMail($user->user, $job, 'lön');

        }
        return $job->save();
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
            $job_data = $this->jobToData($job);

            $subject = 'Bekräftelse - tolk har accepterat er bokning (bokning # ' . $job->id . ')';
            $this->mailer->send($email, $name, $subject, 'emails.job-accepted', $dataEmail);

            $translator = Job::getJobsAssignedTranslatorDetail($job);
            $this->mailer->send($translator->email, $translator->name, $subject, 'emails.job-changed-translator-new-translator', $dataEmail);

            $language = TeHelper::fetchLanguageFromJobId($job->from_language_id);

            $this->sendSessionStartRemindNotification($user, $job, $language, $job->due, $job->duration);
            $this->sendSessionStartRemindNotification($translator, $job, $language, $job->due, $job->duration);

        } else {
            $subject = 'Avbokning av bokningsnr: #' . $job->id;
            $this->mailer->send($email, $name, $subject, 'emails.status-changed-from-pending-or-assigned-customer', $dataEmail);

        }

        return $job->save();
    }

    /*
     * TODO remove method and add service for notification
     * TEMP method
     * send session start remind notification
     */
    public function sendSessionStartRemindNotification($user, $job, $language, $due, $duration)
    {

        $this->logger->pushHandler(new StreamHandler(storage_path('logs/cron/laravel-' . date('Y-m-d') . '.log'), Logger::DEBUG));
        $this->logger->pushHandler(new FirePHPHandler());
        $data = array();
        $data['notification_type'] = 'session_start_remind';
        $due_explode = explode(' ', $due);
        if ($job->customer_physical_type == 'yes')
            $msg_text = array(
                "en" => 'Detta är en påminnelse om att du har en ' . $language . 'tolkning (på plats i ' . $job->town . ') kl ' . $due_explode[1] . ' på ' . $due_explode[0] . ' som vara i ' . $duration . ' min. Lycka till och kom ihåg att ge feedback efter utförd tolkning!'
            );
        else
            $msg_text = array(
                "en" => 'Detta är en påminnelse om att du har en ' . $language . 'tolkning (telefon) kl ' . $due_explode[1] . ' på ' . $due_explode[0] . ' som vara i ' . $duration . ' min.Lycka till och kom ihåg att ge feedback efter utförd tolkning!'
            );

        if ($this->bookingRepository->isNeedToSendPush($user->id)) {
            $users_array = array($user);
            $this->bookingRepository->sendPushNotificationToSpecificUsers($users_array, $job->id, $data, $msg_text, $this->bookingRepository->isNeedToDelayPush($user->id));
            $this->logger->addInfo('sendSessionStartRemindNotification ', ['job' => $job->id]);
        }
    }

    /**
     * @param $job
     * @param $data
     * @return bool
     */
    private function changeWithdrawafter24Status($job, $data)
    {
        if (in_array($data['status'], ['timedout']) && $data['admin_comments'] != '') {
            $job->status = $data['status'];
            $job->admin_comments = $data['admin_comments'];
            return $job->save();
        }
        return false;
    }

    /**
     * @param $job
     * @param $data
     * @return bool
     */
    private function changeAssignedStatus($job, $data)
    {
        if (in_array($data['status'], ['withdrawbefore24', 'withdrawafter24', 'timedout'])) {
            $job->status = $data['status'];
            if ($data['admin_comments'] == '' && $data['status'] == 'timedout') return false;
            $job->admin_comments = $data['admin_comments'];
            if (in_array($data['status'], ['withdrawbefore24', 'withdrawafter24'])) {
                $user = $job->user()->first();

                $email = !empty($job->user_email) ? $job->user_email : $user->email;
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

    /**
     * @param $current_translator
     * @param $data
     * @param $job
     * @return array
     */
    private function changeTranslator($current_translator, $data, $job)
    {
        $translatorChanged = false;

        if (!is_null($current_translator) || (isset($data['translator']) && $data['translator'] != 0) || $data['translator_email'] != '') {
            $log_data = [];
            if (!is_null($current_translator) && ((isset($data['translator']) && $current_translator->userId != $data['translator']) || $data['translator_email'] != '') && (isset($data['translator']) && $data['translator'] != 0)) {
                if ($data['translator_email'] != '') $data['translator'] = User::where('email', $data['translator_email'])->first()->id;
                $new_translator = $current_translator->toArray();
                $new_translator['userId'] = $data['translator'];
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
                $new_translator = Translator::create(['userId' => $data['translator'], 'job_id' => $job->id]);
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

    /**
     * @param $old_due
     * @param $new_due
     * @return array
     */
    private function changeDue($old_due, $new_due)
    {
        $dateChanged = false;
        if ($old_due != $new_due) {
            $log_data = [
                'old_due' => $old_due,
                'new_due' => $new_due
            ];
            $dateChanged = true;
            return ['dateChanged' => $dateChanged, 'log_data' => $log_data];
        }

        return ['dateChanged' => $dateChanged];

    }

    /**
     * @param $job
     * @param $current_translator
     * @param $new_translator
     */
    public function sendChangedTranslatorNotification($job, $current_translator, $new_translator)
    {
        $user = $job->user()->first();
        if (!empty($job->user_email)) {
            $email = $job->user_email;
        } else {
            $email = $user->email;
        }
        $name = $user->name;
        $subject = 'Meddelande om tilldelning av tolkuppdrag för uppdrag # ' . $job->id . ')';
        $data = [
            'user' => $user,
            'job'  => $job
        ];
        $this->mailer->send($email, $name, $subject, 'emails.job-changed-translator-customer', $data);
        if ($current_translator) {
            $user = $current_translator->user;
            $name = $user->name;
            $email = $user->email;
            $data['user'] = $user;

            $this->mailer->send($email, $name, $subject, 'emails.job-changed-translator-old-translator', $data);
        }

        $user = $newTranslator->user;
        $name = $user->name;
        $email = $user->email;
        $data['user'] = $user;

        $this->mailer->send($email, $name, $subject, 'emails.job-changed-translator-new-translator', $data);

    }

    /**
     * @param $job
     * @param $old_time
     */
    public function sendChangedDateNotification($job, $old_time)
    {
        $user = $job->user()->first();
        $email = !empty($job->user_email) ? $job->user_email : $email = $user->email;
        $name = $user->name;
        $subject = 'Meddelande om ändring av tolkbokning för uppdrag # ' . $job->id . '';
        $data = [
            'user'     => $user,
            'job'      => $job,
            'old_time' => $old_time
        ];
        $this->mailer->send($email, $name, $subject, 'emails.job-changed-date', $data);

        $translator = Job::getJobsAssignedTranslatorDetail($job);
        $data = [
            'user'     => $translator,
            'job'      => $job,
            'old_time' => $old_time
        ];
        $this->mailer->send($translator->email, $translator->name, $subject, 'emails.job-changed-date', $data);

    }

    /**
     * @param $job
     * @param $old_lang
     */
    public function sendChangedLangNotification($job, $old_lang)
    {
        $user = $job->user()->first();
        $email = !empty($job->user_email) ? $job->user_email : $email = $user->email;
        $name = $user->name;
        $subject = 'Meddelande om ändring av tolkbokning för uppdrag # ' . $job->id . '';
        $data = [
            'user'     => $user,
            'job'      => $job,
            'old_lang' => $old_lang
        ];
        $this->mailer->send($email, $name, $subject, 'emails.job-changed-lang', $data);
        $translator = Job::getJobsAssignedTranslatorDetail($job);
        $this->mailer->send($translator->email, $translator->name, $subject, 'emails.job-changed-date', $data);
    }

    /**
     * Function to send Job Expired Push Notification
     * @param $job
     * @param $user
     */
    public function sendExpiredNotification($job, $user)
    {
        $data = array();
        $data['notification_type'] = 'job_expired';
        $language = TeHelper::fetchLanguageFromJobId($job->from_language_id);
        $msg_text = array(
            "en" => 'Tyvärr har ingen tolk accepterat er bokning: (' . $language . ', ' . $job->duration . 'min, ' . $job->due . '). Vänligen pröva boka om tiden.'
        );

        if ($this->isNeedToSendPush($user->id)) {
            $users_array = array($user);
            $this->sendPushNotificationToSpecificUsers($users_array, $job->id, $data, $msg_text, $this->isNeedToDelayPush($user->id));
        }
    }

    /**
     * Function to send the notification for sending the admin job cancel
     * @param $job_id
     */
    public function sendNotificationByAdminCancelJob($job_id)
    {
        $job = Job::findOrFail($job_id);
        $user_meta = $job->user->userMeta()->first();
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
        $data['customer_town'] = $user_meta->city;
        $data['customer_type'] = $user_meta->customer_type;

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
                $data['job_for'][] = 'normal';
                $data['job_for'][] = 'certified';
            } else if ($job->certified == 'yes') {
                $data['job_for'][] = 'certified';
            } else {
                $data['job_for'][] = $job->certified;
            }
        }
        $this->sendNotificationTranslator($job, $data, '*');   // send Push all sutiable translators
    }

    /**
     * send session start remind notificatio
     * @param $user
     * @param $job
     * @param $language
     * @param $due
     * @param $duration
     */
    private function sendNotificationChangePending($user, $job, $language, $due, $duration)
    {
        $data = array();
        $data['notification_type'] = 'session_start_remind';
        if ($job->customer_physical_type == 'yes')
            $msg_text = array(
                "en" => 'Du har nu fått platstolkningen för ' . $language . ' kl ' . $duration . ' den ' . $due . '. Vänligen säkerställ att du är förberedd för den tiden. Tack!'
            );
        else
            $msg_text = array(
                "en" => 'Du har nu fått telefontolkningen för ' . $language . ' kl ' . $duration . ' den ' . $due . '. Vänligen säkerställ att du är förberedd för den tiden. Tack!'
            );

        if ($this->bookingRepository->isNeedToSendPush($user->id)) {
            $users_array = array($user);
            $this->bookingRepository->sendPushNotificationToSpecificUsers($users_array, $job->id, $data, $msg_text, $this->bookingRepository->isNeedToDelayPush($user->id));
        }
    }

    /**
     * making user_tags string from users array for creating onesignal notifications
     * @param $users
     * @return string
     */
    private function getUserTagsStringFromArray($users)
    {
        $userTags = "[";
        $first = true;
        foreach ($users as $oneUser) {
            if ($first) {
                $first = false;
            } else {
                $userTags .= ',{"operator": "OR"},';
            }
            $userTags .= '{"key": "email", "relation": "=", "value": "' . strtolower($oneUser->email) . '"}';
        }
        $userTags .= ']';
        return $userTags;
    }

    /**
     * @param $data
     * @param $user
     */
    public function acceptJob($data, $user)
    {

        $adminemail = config('app.admin_email');
        $adminSenderEmail = config('app.admin_sender_email');

        $user = $user;
        $job = Job::findOrFail($data['job_id']);
        if (!Job::isTranslatorAlreadyBooked($job->id, $user->id, $job->due)) {
            if ($job->status == 'pending' && Job::insertTranslatorJobRel($user->id, $job->id)) {
                $job->status = 'assigned';
                $job->save();
                $user = $job->user()->get()->first();
                $mailer = new AppMailer();

              
                $email = !empty($job->user_email) ? $job->user_email : $user->email;
                $name = $user->name;
                $subject = 'Bekräftelse - tolk har accepterat er bokning (bokning # ' . $job->id . ')';

                $data = [
                    'user' => $user,
                    'job'  => $job
                ];
                $mailer->send($email, $name, $subject, 'emails.job-accepted', $data);

            }
            /*@todo
                add flash message here.
            */
            $jobs = $this->getPotentialJobs($user);
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
    public function acceptJobWithId($job_id, $user)
    {
        $adminemail = config('app.admin_email');
        $adminSenderEmail = config('app.admin_sender_email');
        $job = Job::findOrFail($job_id);
        $response = array();

        if (!Job::isTranslatorAlreadyBooked($job_id, $user->id, $job->due)) {
            if ($job->status == 'pending' && Job::insertTranslatorJobRel($user->id, $job_id)) {
                $job->status = 'assigned';
                $job->save();
                $user = $job->user()->get()->first();
                $mailer = new AppMailer();
                $email = !empty($job->user_email) ? $job->user_email : $email = $user->email;
                $name = $user->name;
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
        $user = $user;
        $job_id = $data['job_id'];
        $job = Job::findOrFail($job_id);
        $translator = Job::getJobsAssignedTranslatorDetail($job);
        if ($user->is('customer')) {
            $job->withdraw_at = Carbon::now();
            $job->status = ($job->withdraw_at->diffInHours($job->due) >= 24) ? 'withdrawbefore24' : 'withdrawafter24';
            $response['jobstatus'] = 'success';
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

    private function getJobType($translatorType) 
    {
        $jobType = '';
        switch($translator)
        {
            case 'professional':
                $jobType = 'paid';
                break;
            case 'rwstranslator':
                $jobType = 'rws';
                break;
            default:
                $jobType = 'unpaid';
        }
    }

    /*Function to get the potential jobs for paid,rws,unpaid translators*/
    public function getPotentialJobs($user)
    {
        $userMeta = $user->userMeta;
        $jobs = $this->getJobs($userMeta);

        foreach ($jobs as $i => $job) {
            $job->specific_job = Job::assignedToPaticularTranslator($user->id, $job->id);
            $job->check_particular_job = Job::checkParticularJob($user->id, $job);
            $checktown = Job::checkTowns($job->userId, $user->id);

            if($job->specific_job == 'SpecificJob')
                if ($job->check_particular_job == 'userCanNotAcceptJob')
                unset($jobIds[$k]);

            if (($job->customer_phone_type == 'no' || $job->customer_phone_type == '') && $job->customer_physical_type == 'yes' && $checktown == false) {
                unset($jobs[$k]);
            }
        }
//        $jobs = TeHelper::convertJobIdsInObjs($job_ids);
        return $jobs;
    }

    public function endJob($postData)
    {
        $job = Job::with('translatorJobRel')->find($postData['job_id']);

        if($job->status != 'started')
            return ['status' => 'success'];
        $this->updateJobDetails($job, 'completed');
        $user = $job->user()->get()->first();

        $email = !empty($job->user_email) ? $job->user_email : $email = $user->email;

        $translator = updateTranslator($job);

        $user = $translator->user()->first();
        $this->sendSessionEndedMail($user, $job, 'lön');


        

        Event::fire(new SessionEnded($job, ($postData['userId'] == $job->userId) ? $translator->userId : $job->userId));


        $response['status'] = 'success';
        return $response;
    }


    public function customerNotCall($postData)
    {
        $job = Job::with('translatorJobRel')->find($postData["job_id"]);
        $this->updateJobDetails($job, 'not_carried_out_customer');

        updateTranslator($job);

        $response['status'] = 'success';
        return $response;
    }

    public function getAll(Request $request, $limit = null)
    {
        $requestData = $request->all();
        $user = $request->__authenticatedUser;
        $consumerType = $user->consumer_type;
        $allJobs = Job::query();
        if (isset($requestData['lang']) && $requestData['lang'] != '') {
            $allJobs->whereIn('from_language_id', $requestData['lang']);
        }

        if (isset($requestData['status']) && $requestData['status'] != '') {
            $allJobs->whereIn('status', $requestData['status']);
        }

        if (isset($requestData['filter_timetype']) && $requestData['filter_timetype'] == "created") {
            if (isset($requestData['from']) && $requestData['from'] != "") {
                $allJobs->where('created_at', '>=', $requestData["from"]);
            }
            if (isset($requestData['to']) && $requestData['to'] != "") {
                $to = $requestData["to"] . " 23:59:00";
                $allJobs->where('created_at', '<=', $to);
            }
            $allJobs->orderBy('created_at', 'desc');
        }

        if (isset($requestData['filter_timetype']) && $requestData['filter_timetype'] == "due") {
            if (isset($requestData['from']) && $requestData['from'] != "") {
                $allJobs->where('due', '>=', $requestData["from"]);
            }
            if (isset($requestData['to']) && $requestData['to'] != "") {
                $to = $requestData["to"] . " 23:59:00";
                $allJobs->where('due', '<=', $to);
            }
            $allJobs->orderBy('due', 'desc');
        }

        if (isset($requestData['job_type']) && $requestData['job_type'] != '') {
            $allJobs->whereIn('job_type', $requestData['job_type']);
        }

        if ($user && $user->user_type == config('user_role.super_admin_role_id')) {
            

            if (isset($requestData['feedback']) && $requestData['feedback'] != 'false') {
                $allJobs->where('ignore_feedback', '0');
                $allJobs->whereHas('feedback', function ($q) {
                    $q->where('rating', '<=', '3');
                });
                if (isset($requestData['count']) && $requestData['count'] != 'false') return ['count' => $allJobs->count()];
            }

            if (isset($requestData['id']) && $requestData['id'] != '') {
                if (is_array($requestdata['id']))
                    $allJobs->whereIn('id', $requestData['id']);
                else
                    $allJobs->where('id', $requestData['id']);
                $requestData = array_only($requestData, ['id']);
            }

            if (isset($requestData['expired_at']) && $requestData['expired_at'] != '') {
                $allJobs->where('expired_at', '>=', $requestdata['expired_at']);
            }
            if (isset($requestData['will_expire_at']) && $requestData['will_expire_at'] != '') {
                $allJobs->where('will_expire_at', '>=', $requestData['will_expire_at']);
            }
            if (isset($requestData['customer_email']) && count($requestData['customer_email']) && $requestdata['customer_email'] != '') {
                $users = DB::table('users')->whereIn('email', $requestData['customer_email'])->get();
                if ($users) {
                    $allJobs->whereIn('userId', collect($users)->pluck('id')->all());
                }
            }
            if (isset($requestData['translator_email']) && count($requestData['translator_email'])) {
                $users = DB::table('users')->whereIn('email', $requestData['translator_email'])->get();
                if ($users) {
                    $allJobIDs = DB::table('translator_job_rel')->whereNull('cancel_at')->whereIn('userId', collect($users)->pluck('id')->all())->lists('job_id');
                    $allJobs->whereIn('id', $allJobIDs);
                }
            }

            if (isset($requestData['physical'])) {
                $allJobs->where('customer_physical_type', $requestData['physical']);
                $allJobs->where('ignore_physical', 0);
            }

            if (isset($requestData['phone'])) {
                $allJobs->where('customer_phone_type', $requestData['phone']);
                if(isset($requestdata['physical']))
                $allJobs->where('ignore_physical_phone', 0);
            }

            if (isset($requestData['flagged'])) {
                $allJobs->where('flagged', $requestData['flagged']);
                $allJobs->where('ignore_flagged', 0);
            }

            if (isset($requestData['distance']) && $requestData['distance'] == 'empty') {
                $allJobs->whereDoesntHave('distance');
            }

            if(isset($requestData['salary']) &&  $requestData['salary'] == 'yes') {
                $allJobs->whereDoesntHave('user.salaries');
            }

            if (isset($requestData['count']) && $requestData['count'] == 'true') {
                $allJobs = $allJobs->count();

                return ['count' => $allJobs];
            }

            if (isset($requestData['consumer_type']) && $requestData['consumer_type'] != '') {
                $allJobs->whereHas('user.userMeta', function($q) use ($requestData) {
                    $q->where('consumer_type', $requestData['consumer_type']);
                });
            }

            if (isset($requestData['booking_type'])) {
                if ($requestData['booking_type'] == 'physical')
                    $allJobs->where('customer_physical_type', 'yes');
                if ($requestData['booking_type'] == 'phone')
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

            if (isset($requestData['id']) && $requestData['id'] != '') {
                $allJobs->where('id', $requestdata['id']);
                $requestData = array_only($requestData, ['id']);
            }

            if ($consumerType == 'RWS') {
                $allJobs->where('job_type', '=', 'rws');
            } else {
                $allJobs->where('job_type', '=', 'unpaid');
            }
            if (isset($requestData['feedback']) && $requestData['feedback'] != 'false') {
                $allJobs->where('ignore_feedback', '0');
                $allJobs->whereHas('feedback', function($q) {
                    $q->where('rating', '<=', '3');
                });
                if(isset($requestData['count']) && $requestData['count'] != 'false') return ['count' => $allJobs->count()];
            }
                    
            if (isset($requestData['customer_email']) && $requestData['customer_email'] != '') {
                $user = DB::table('users')->where('email', $requestData['customer_email'])->first();
                if ($user) {
                    $allJobs->where('userId', '=', $user->id);
                }
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

    public function alerts()
    {
        $jobs = Job::all();
        $sesJobs = [];
        $jobId = [];
        $diff = [];
        $i = 0;

        foreach ($jobs as $job) {
            $sessionTime = explode(':', $job->session_time);
            if (count($sessionTime) >= 3) {
                $diff[$i] = ($sessionTime[0] * 60) + $sessionTime[1] + ($sessionTime[2] / 60);

                if ($diff[$i] >= $job->duration) {
                    if ($diff[$i] >= $job->duration * 2) {
                        $sesJobs [$i] = $job;
                    }
                }
                $i++;
            }
        }

        foreach ($sesJobs as $job) {
            $jobId [] = $job->id;
        }

        $languages = Language::where('active', '1')->orderBy('language')->get();
        $requestData = Request::all();
        $allCustomers = DB::table('users')->where('user_type', '1')->lists('email');
        $allTranslators = DB::table('users')->where('user_type', '2')->lists('email');

        $user = Auth::user();
        $consumerType = TeHelper::getUsermeta($user->id, 'consumer_type');


        if ($user && $user->is('superadmin')) {
            $allJobs = DB::table('jobs')
                ->join('languages', 'jobs.from_language_id', '=', 'languages.id')->whereIn('jobs.id', $jobId);
            if (isset($requestData['lang']) && $requestData['lang'] != '') {
                $allJobs->whereIn('jobs.from_language_id', $requestdata['lang'])
                    ->where('jobs.ignore', 0);
                /*$allJobs->where('jobs.from_language_id', '=', $requestdata['lang']);*/
            }
            if (isset($requestData['status']) && $requestData['status'] != '') {
                $allJobs->whereIn('jobs.status', $requestdata['status'])
                    ->where('jobs.ignore', 0);
                /*$allJobs->where('jobs.status', '=', $requestdata['status']);*/
            }
            if (isset($requestData['customer_email']) && $requestData['customer_email'] != '') {
                $user = DB::table('users')->where('email', $requestData['customer_email'])->first();
                if ($user) {
                    $allJobs->where('jobs.userId', '=', $user->id)
                        ->where('jobs.ignore', 0);
                }
            }
            if (isset($requestData['translator_email']) && $requestData['translator_email'] != '') {
                $user = DB::table('users')->where('email', $requestData['translator_email'])->first();
                if ($user) {
                    $allJobIDs = DB::table('translator_job_rel')->where('userId', $user->id)->lists('job_id');
                    $allJobs->whereIn('jobs.id', $allJobIDs)
                        ->where('jobs.ignore', 0);
                }
            }
            if (isset($requestData['filter_timetype']) && $requestData['filter_timetype'] == "created") {
                if (isset($requestData['from']) && $requestData['from'] != "") {
                    $allJobs->where('jobs.created_at', '>=', $requestData["from"])
                        ->where('jobs.ignore', 0);
                }
                if (isset($requestData['to']) && $requestData['to'] != "") {
                    $to = $requestData["to"] . " 23:59:00";
                    $allJobs->where('jobs.created_at', '<=', $to)
                        ->where('jobs.ignore', 0);
                }
                $allJobs->orderBy('jobs.created_at', 'desc');
            }
            if (isset($requestData['filter_timetype']) && $requestData['filter_timetype'] == "due") {
                if (isset($requestData['from']) && $requestData['from'] != "") {
                    $allJobs->where('jobs.due', '>=', $requestData["from"])
                        ->where('jobs.ignore', 0);
                }
                if (isset($requestData['to']) && $requestData['to'] != "") {
                    $to = $requestData["to"] . " 23:59:00";
                    $allJobs->where('jobs.due', '<=', $to)
                        ->where('jobs.ignore', 0);
                }
                $allJobs->orderBy('jobs.due', 'desc');
            }

            if (isset($requestData['job_type']) && $requestData['job_type'] != '') {
                $allJobs->whereIn('jobs.job_type', $requestData['job_type'])
                    ->where('jobs.ignore', 0);
                /*$allJobs->where('jobs.job_type', '=', $requestdata['job_type']);*/
            }
            $allJobs->select('jobs.*', 'languages.language')
                ->where('jobs.ignore', 0)
                ->whereIn('jobs.id', $jobId);

            $allJobs->orderBy('jobs.created_at', 'desc');
            $allJobs = $allJobs->paginate(15);
        }

        return ['allJobs' => $allJobs, 'languages' => $languages, 'all_customers' => $allCustomers, 'all_translators' => $allTranslators, 'requestdata' => $requestData];
    }

    public function userLoginFailed()
    {
        $throttles = Throttles::where('ignore', 0)->with('user')->paginate(15);

        return ['throttles' => $throttles];
    }

    public function bookingExpireNoAccepted()
    {
        $languages = Language::where('active', '1')->orderBy('language')->get();
        $requestData = Request::all();
        $allCustomers = DB::table('users')->where('user_type', '1')->lists('email');
        $allTranslators = DB::table('users')->where('user_type', '2')->lists('email');

        $user = Auth::user();
        $consumerType = TeHelper::getUsermeta($user->id, 'consumer_type');


        if ($user && ($user->is('superadmin') || $user->is('admin'))) {
            $allJobs = DB::table('jobs')
                ->join('languages', 'jobs.from_language_id', '=', 'languages.id')
                ->where('jobs.ignore_expired', 0);
            if (isset($requestData['lang']) && $requestData['lang'] != '') {
                $allJobs->whereIn('jobs.from_language_id', $requestData['lang'])
                    ->where('jobs.status', 'pending')
                    ->where('jobs.ignore_expired', 0)
                    ->where('jobs.due', '>=', Carbon::now());
                /*$allJobs->where('jobs.from_language_id', '=', $requestdata['lang']);*/
            }
            if (isset($requestData['status']) && $requestData['status'] != '') {
                $allJobs->whereIn('jobs.status', $requestData['status'])
                    ->where('jobs.status', 'pending')
                    ->where('jobs.ignore_expired', 0)
                    ->where('jobs.due', '>=', Carbon::now());
                /*$allJobs->where('jobs.status', '=', $requestdata['status']);*/
            }
            if (isset($requestData['customer_email']) && $requestData['customer_email'] != '') {
                $user = DB::table('users')->where('email', $requestData['customer_email'])->first();
                if ($user) {
                    $allJobs->where('jobs.userId', '=', $user->id)
                        ->where('jobs.status', 'pending')
                        ->where('jobs.ignore_expired', 0)
                        ->where('jobs.due', '>=', Carbon::now());
                }
            }
            if (isset($requestData['translator_email']) && $requestData['translator_email'] != '') {
                $user = DB::table('users')->where('email', $requestData['translator_email'])->first();
                if ($user) {
                    $allJobIDs = DB::table('translator_job_rel')->where('userId', $user->id)->lists('job_id');
                    $allJobs->whereIn('jobs.id', $allJobIDs)
                        ->where('jobs.status', 'pending')
                        ->where('jobs.ignore_expired', 0)
                        ->where('jobs.due', '>=', Carbon::now());
                }
            }
            if (isset($requestData['filter_timetype']) && $requestData['filter_timetype'] == "created") {
                if (isset($requestData['from']) && $requestData['from'] != "") {
                    $allJobs->where('jobs.created_at', '>=', $requestData["from"])
                        ->where('jobs.status', 'pending')
                        ->where('jobs.ignore_expired', 0)
                        ->where('jobs.due', '>=', Carbon::now());
                }
                if (isset($requestData['to']) && $requestData['to'] != "") {
                    $to = $requestData["to"] . " 23:59:00";
                    $allJobs->where('jobs.created_at', '<=', $to)
                        ->where('jobs.status', 'pending')
                        ->where('jobs.ignore_expired', 0)
                        ->where('jobs.due', '>=', Carbon::now());
                }
                $allJobs->orderBy('jobs.created_at', 'desc');
            }
            if (isset($requestData['filter_timetype']) && $requestData['filter_timetype'] == "due") {
                if (isset($requestData['from']) && $requestData['from'] != "") {
                    $allJobs->where('jobs.due', '>=', $requestData["from"])
                        ->where('jobs.status', 'pending')
                        ->where('jobs.ignore_expired', 0)
                        ->where('jobs.due', '>=', Carbon::now());
                }
                if (isset($requestData['to']) && $requestData['to'] != "") {
                    $to = $requestData["to"] . " 23:59:00";
                    $allJobs->where('jobs.due', '<=', $to)
                        ->where('jobs.status', 'pending')
                        ->where('jobs.ignore_expired', 0)
                        ->where('jobs.due', '>=', Carbon::now());
                }
                $allJobs->orderBy('jobs.due', 'desc');
            }

            if (isset($requestData['job_type']) && $requestData['job_type'] != '') {
                $allJobs->whereIn('jobs.job_type', $requestData['job_type'])
                    ->where('jobs.status', 'pending')
                    ->where('jobs.ignore_expired', 0)
                    ->where('jobs.due', '>=', Carbon::now());
                /*$allJobs->where('jobs.job_type', '=', $requestdata['job_type']);*/
            }
            $allJobs->select('jobs.*', 'languages.language')
                ->where('jobs.status', 'pending')
                ->where('ignore_expired', 0)
                ->where('jobs.due', '>=', Carbon::now());

            $allJobs->orderBy('jobs.created_at', 'desc');
            $allJobs = $allJobs->paginate(15);

        }
        return ['allJobs' => $allJobs, 'languages' => $languages, 'all_customers' => $allCustomers, 'all_translators' => $allTranslators, 'requestdata' => $requestData];
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

    public function ignoreThrottle($id)
    {
        $throttle = Throttles::find($id);
        $throttle->ignore = 1;
        $throttle->save();
        return ['success', 'Changes saved'];
    }

    public function reopen($request)
    {
        $jobId = $request['jobid'];
        $userId = $request['userid'];

        $job = Job::find($jobId);
        $job = $job->toArray();

        $data = array();
        $data['created_at'] = date('Y-m-d H:i:s');
        $data['will_expire_at'] = TeHelper::willExpireAt($job['due'], $data['created_at']);
        $data['updated_at'] = date('Y-m-d H:i:s');
        $data['user_id'] = $userId;
        $data['job_id'] = $jobId;
        $data['cancel_at'] = Carbon::now();

        $dataReopen = array();
        $dataReopen['status'] = 'pending';
        $dataReopen['created_at'] = Carbon::now();
        $dataReopen['will_expire_at'] = TeHelper::willExpireAt($job['due'], $datareopen['created_at']);
        //$datareopen['updated_at'] = date('Y-m-d H:i:s');

//        $this->logger->addInfo('USER #' . Auth::user()->id . ' reopen booking #: ' . $jobid);

        if ($job['status'] != 'timedout') {
            $affectedRows = Job::where('id', '=', $jobid)->update($datareopen);
            $new_jobid = $jobid;
        } else {
            $job = $this->updateJobFields($job);
            $affectedRows = Job::create($job);
            $new_jobid = $affectedRows['id'];
        }
        //$result = DB::table('translator_job_rel')->insertGetId($data);
        Translator::where('job_id', $jobid)->where('cancel_at', NULL)->update(['cancel_at' => $data['cancel_at']]);
        Translator::create($data);
        if (isset($affectedRows)) {
            $this->sendNotificationByAdminCancelJob($new_jobid);
            return ["Tolk cancelled!"];
        } else {
            return ["Please try again!"];
        }
    }

    /**
     * Convert number of minutes to hour and minute variant
     * @param  int $time   
     * @param  string $format 
     * @return string         
     */
    private function convertToHoursMins($time, $format = '%02dh %02dmin')
    {
        if ($time < 60) {
            return $time . 'min';
        } else if ($time == 60) {
            return '1h';
        }

        $hours = floor($time / 60);
        $minutes = ($time % 60);
        
        return sprintf($format, $hours, $minutes);
    }

    private function updateJobFields($job)
    {
        $job['status'] = 'pending';
        $job['created_at'] = Carbon::now();
        $job['updated_at'] = Carbon::now();
        $job['will_expire_at'] = TeHelper::willExpireAt($job['due'], date('Y-m-d H:i:s'));
        $job['updated_at'] = date('Y-m-d H:i:s');
        $job['cust_16_hour_email'] = 0;
        $job['cust_48_hour_email'] = 0;
        $job['admin_comments'] = 'This booking is a reopening of booking #' . $jobid;
        return $job;
    }

    private function getJobs($userMeta)
    {
        
        $jobType = $this->getJobType($userMeta->translator_type);

        $languages = UserLanguages::where('userId', '=', $userId)->get();
        $userLanguage = collect($languages)->pluck('lang_id')->all();
        $gender = $userMeta->gender;
        $translatorLevel = $userMeta->translator_level;
        $jobs = Job::getJobs($userId, $jobType, 'pending', $userLanguage, $gender, $translatorLevel);
        return $jobs;
    }

    private function updateJobDetails($job, $status)
    {
        $completedDate = date('Y-m-d H:i:s');
        $start = date_create($job->due);
        $end = date_create($completedDate);
        $diff = date_diff($end, $start);
        $interval = $diff->h . ':' . $diff->i . ':' . $diff->s;
        $job->end_at = $completedDate;
        $job->status = $status;
        $job->session_time = $interval;
        $job->save();
    }

    private function updteTranslator($job)
    {
        $translator = $job->translatorJobRel()->where('completed_at', Null)->where('cancel_at', Null)->first();
        $translator->completed_at = date('Y-m-d H:i:s');
        $translator->completed_by = $postData['userId'];
        $translator->save();
        return $translator;
    }

    private function sendSessionEndedMail($user, $job, $forText)
    {
        $subject = 'Information om avslutad tolkning för bokningsnummer # ' . $job->id;
        $sessionExplode = explode(':', $job->session_time);
        $sessionTime = $sessionExplode[0] . ' time ' . $sessionExplode[1] . ' min';
        $data = [
            'user'         => $user,
            'job'          => $job,
            'session_time' => $sessionTime,
            'for_text'     => $forText
        ];
        $mailer = new AppMailer();
        $mailer->send($user->email, $user->name, $subject, 'emails.session-ended', $data);
    }
}